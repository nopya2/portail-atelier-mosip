<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Nip;
use App\Support\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Session et profil de l'usager connecté.
 * Création de compte ET connexion se font uniquement avec eSignet (EsignetController) :
 * le portail ne gère aucun mot de passe.
 */
class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    /**
     * Mise à jour du profil.
     * Non modifiables : le NIP et l'identité issue du registre (nom, prénom, genre, date de naissance),
     * ainsi que le contact (email ou téléphone) vérifié. Modifiables : l'adresse et le contact non vérifié.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        // L'identité vient du registre via eSignet : elle n'est jamais modifiée ici
        $request->replace($request->except(['nip', 'name', 'given_name', 'family_name', 'gender', 'birthdate']));

        if ($request->filled('phone_number')) {
            $request->merge(['phone_number' => Telephone::normalize($request->input('phone_number')) ?? $request->input('phone_number')]);
        }

        $data = $request->validate([
            'email' => $user->email_verified_at
                ? ['prohibited_unless:email,'.$user->email]
                : ['nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_number' => $user->phone_verified_at
                ? ['prohibited_unless:phone_number,'.$user->phone_number]
                : ['nullable', 'string', 'regex:/^\+241\d{7,9}$/', Rule::unique('users', 'phone_number')->ignore($user->id)],
            'address' => ['nullable', 'array'],
            'address.street_address' => ['nullable', 'string', 'max:255'],
            'address.locality' => ['nullable', 'string', 'max:120'],
            'address.region' => ['nullable', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.country' => ['nullable', 'string', 'max:60'],
        ], [
            'email.prohibited_unless' => 'L\'adresse email vérifiée ne peut pas être modifiée.',
            'phone_number.prohibited_unless' => 'Le numéro vérifié ne peut pas être modifié.',
            'phone_number.regex' => 'Numéro de téléphone invalide (ex. 07 12 34 56).',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'phone_number.unique' => 'Ce numéro est déjà utilisé.',
        ]);

        $user->fill($data)->save();

        return response()->json(['user' => $user]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /** Format du NIP (affichage côté Angular). */
    public function nipFormat(): JsonResponse
    {
        return response()->json([
            'pattern' => Nip::INPUT_PATTERN,
            'format' => Nip::FORMAT,
            'example' => Nip::EXAMPLE,
        ]);
    }
}
