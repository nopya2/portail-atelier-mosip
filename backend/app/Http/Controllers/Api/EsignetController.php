<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EsignetTransaction;
use App\Models\User;
use App\Services\Esignet\EsignetService;
use App\Support\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Points d'entrée HTTP de la création de compte et de la connexion — uniquement avec eSignet.
 * Ce contrôleur valide les entrées et gère la session ; toute la logique OIDC
 * est dans App\Services\Esignet\EsignetService (à implémenter pendant l'atelier).
 */
class EsignetController extends Controller
{
    public function __construct(private readonly EsignetService $esignet) {}

    /** Étape A : « Créer mon compte avec eSignet » ou « Se connecter avec eSignet ». */
    public function demarrer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(EsignetTransaction::MODES)],
        ]);

        $resultat = $this->esignet->demarrer($data['mode'], $request);

        return response()
            ->json(['authorization_url' => $resultat['authorization_url'], 'transaction_id' => $resultat['transaction_id']])
            ->cookie($this->cookieParcours($resultat['cookie']));
    }

    /** Étapes B à F : retour d'eSignet relayé par la page Angular /auth/esignet/retour. */
    public function retour(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:2048'],
            'error' => ['nullable', 'string', 'max:128'],
            'error_description' => ['nullable', 'string', 'max:1024'],
        ]);

        $resultat = $this->esignet->traiterRetour([
            'state' => $data['state'],
            'code' => $data['code'] ?? null,
            'error' => $data['error'] ?? null,
            'error_description' => $data['error_description'] ?? null,
        ], $request);

        $reponse = match ($resultat['statut']) {
            'connecte' => ['statut' => 'connecte'] + $this->session($resultat['user']),
            'finalisation' => ['statut' => 'finalisation', 'jeton' => $resultat['jeton']],
        };

        // Le parcours est terminé : le cookie qui le liait au navigateur est supprimé
        return response()->json($reponse)->withoutCookie(config('esignet.cookie'), '/api/auth/esignet');
    }

    /** Écran de finalisation : identité vérifiée à afficher en lecture seule. */
    public function inscription(string $jeton): JsonResponse
    {
        return response()->json($this->esignet->lireInscription($jeton));
    }

    /** Étape G : création du compte après validation de l'écran de finalisation. */
    public function finaliser(Request $request, string $jeton): JsonResponse
    {
        if ($request->filled('telephone')) {
            $request->merge(['telephone' => Telephone::normalize($request->input('telephone')) ?? $request->input('telephone')]);
        }
        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'email' => ['nullable', 'required_without:telephone', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'telephone' => ['nullable', 'required_without:email', 'regex:/^\+241\d{7,9}$/', Rule::unique('users', 'phone_number')],
            'cgu' => ['accepted'],
        ], [
            'email.required_without' => 'Renseignez au moins un moyen de contact (email ou téléphone).',
            'telephone.required_without' => 'Renseignez au moins un moyen de contact (email ou téléphone).',
            'telephone.regex' => 'Numéro de téléphone invalide (ex. 07 12 34 56).',
            'email.unique' => 'Cette adresse email est déjà associée à un compte.',
            'telephone.unique' => 'Ce numéro est déjà associé à un compte.',
            'cgu.accepted' => 'Vous devez accepter les conditions d’utilisation.',
        ]);

        $user = $this->esignet->finaliser($jeton, [
            'email' => $data['email'] ?? null,
            'telephone' => $data['telephone'] ?? null,
            'cgu' => true,
        ]);

        return response()->json($this->session($user), 201);
    }

    /**
     * @return array{token: string, expires_in: int, user: User}
     */
    private function session(User $user): array
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('portail-esignet', ['*'], now()->addMinutes(config('portail.token_ttl')))->plainTextToken;

        return ['token' => $token, 'expires_in' => config('portail.token_ttl') * 60, 'user' => $user->fresh()];
    }

    /** Cookie HttpOnly liant le parcours au navigateur (limité aux routes eSignet). */
    private function cookieParcours(string $valeur): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            config('esignet.cookie'),
            $valeur,
            config('esignet.transaction_ttl'),
            '/api/auth/esignet',
            null,
            app()->isProduction(), // Secure en production (HTTPS)
            true,                  // HttpOnly
            false,
            'lax',
        );
    }
}
