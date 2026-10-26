<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DemandeRecue;
use App\Models\Demande;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Service::where('actif', true)->orderBy('categorie')->orderBy('titre')->get());
    }

    public function show(Service $service): JsonResponse
    {
        abort_unless($service->actif, 404);

        return response()->json($service);
    }

    public function mesDemandes(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->demandes()->with('service:id,slug,titre,administration')->latest()->get()
        );
    }

    /**
     * Dépôt d'une demande : l'identité est reprise du profil de l'usager,
     * le NIP de son compte.
     */
    public function deposer(Request $request, Service $service): JsonResponse
    {
        abort_unless($service->actif, 404);
        $user = $request->user();

        $rules = [];
        foreach ($service->champs ?? [] as $champ) {
            $rule = [($champ['required'] ?? false) ? 'required' : 'nullable'];
            $rule[] = match ($champ['type'] ?? 'text') {
                'number' => 'integer',
                'date' => 'date',
                'select' => 'in:'.implode(',', $champ['options'] ?? []),
                default => 'string|max:500',
            };
            $rules['donnees.'.$champ['name']] = implode('|', $rule);
        }
        $data = $request->validate($rules);

        $manquants = array_values(array_filter(
            $service->infos_requises,
            fn ($info) => blank($user->{$info} ?? null) && $info !== 'nip',
        ));
        if ($manquants) {
            return response()->json([
                'message' => 'Complétez votre profil avant de déposer cette demande. Informations manquantes : '.implode(', ', $manquants).'.',
                'infos_manquantes' => $manquants,
            ], 422);
        }

        // toArray() applique les formats de sérialisation (birthdate en Y-m-d, sans fuseau)
        $profil = $user->toArray();
        $identite = ['nip' => $user->nip];
        foreach ($service->infos_requises as $info) {
            $identite[$info] = $profil[$info] ?? null;
        }

        $demande = Demande::create([
            'reference' => 'DEM-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'user_id' => $user->id,
            'service_id' => $service->id,
            'nip' => $user->nip,
            'identite' => $identite,
            'donnees' => $data['donnees'] ?? [],
        ]);

        if ($user->email) {
            try {
                Mail::to($user->email)->send(new DemandeRecue($demande->load('service')));
            } catch (Throwable $e) {
                // l'envoi du mail ne doit pas bloquer la demande, mais l'erreur est journalisée
                Log::error('Envoi de l\'accusé de réception impossible', ['exception' => $e, 'demande' => $demande->reference]);
            }
        }

        return response()->json($demande->load('service:id,slug,titre,administration'), 201);
    }
}
