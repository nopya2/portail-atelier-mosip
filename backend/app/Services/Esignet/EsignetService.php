<?php

namespace App\Services\Esignet;

use App\Mail\CompteCree;
use App\Models\EsignetInscription;
use App\Models\EsignetTransaction;
use App\Models\User;
use App\Services\Sms\SmsSender;
use App\Support\Nip;
use App\Support\Telephone;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  CRÉATION DE COMPTE ET CONNEXION AVEC ESIGNET
 * ─────────────────────────────────────────────────────────────────────────────
 *  Référence : docs/Fiche_atelier_Creer_un_compte_avec_eSignet.docx (sections 5 à 7).
 *  Modèle : mosip/esignet-mock-services (mock-relying-party-service), renforcé selon la fiche :
 *  PKCE, state/nonce générés et conservés par le backend, cookie liant le parcours au navigateur,
 *  validation complète de l'id_token et de /userinfo.
 *
 *  Le protocole (découverte, JWKS, PAR, token, userinfo, JWE) est dans EsignetClient ;
 *  ce service porte les contrôles et les décisions métier, étape par étape.
 *  Erreurs : EsignetException('<code>', '<message>', <statut>), journalisée et renvoyée au frontend.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class EsignetService
{
    public function __construct(private readonly EsignetClient $client) {}

    /**
     * Paramètres publics du bouton « Se connecter avec eSignet » (plugin @mosip/sign-in-with-esignet).
     * Aucun secret : state, nonce et PKCE sont générés au clic, côté serveur (étape A).
     *
     * @return array{authorize_uri: string, client_id: string, redirect_uri: string, scope: string, ui_locales: string}
     */
    public function configurationBouton(): array
    {
        return [
            'authorize_uri' => $this->client->pointAcces('authorization_endpoint'),
            'client_id' => $this->client->clientId(),
            'redirect_uri' => config('esignet.redirect_uri'),
            'scope' => config('esignet.scope'),
            'ui_locales' => config('esignet.ui_locales'),
        ];
    }

    // ======================================================================
    //  A. DÉMARRER — appelé par POST /api/auth/esignet/demarrer
    // ======================================================================

    /**
     * Prépare le parcours (state, nonce, PKCE S256, cookie HttpOnly), pousse la requête
     * d'autorisation (PAR, si eSignet la publie) et renvoie l'URL vers laquelle rediriger le navigateur.
     *
     * @param  'creation'|'connexion'  $mode
     * @return array{authorization_url: string, request_uri: ?string, transaction_id: string, cookie: string}
     */
    public function demarrer(string $mode, Request $request): array
    {
        $state = $this->aleatoire();
        $nonce = $this->aleatoire();
        $codeVerifier = $this->aleatoire();
        $navigateur = $this->aleatoire();

        $transaction = EsignetTransaction::create([
            'mode' => $mode,
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'navigateur_hash' => hash('sha256', $navigateur),
            'statut' => 'initiee',
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
            'expire_at' => now()->addMinutes(config('esignet.transaction_ttl')),
        ]);

        $parametres = [
            'redirect_uri' => config('esignet.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('esignet.scope'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
            'acr_values' => implode(' ', config('esignet.acr_creation')),
            'claims' => json_encode($this->claimsDemandes(), JSON_UNESCAPED_SLASHES),
            'ui_locales' => config('esignet.ui_locales'),
        ];

        try {
            // PAR si eSignet la publie, sinon requête d'autorisation classique (mêmes state, nonce et PKCE)
            $requestUri = $this->client->supportePar() ? $this->client->pousserRequete($parametres)['request_uri'] : null;
        } catch (Throwable $e) {
            $this->echec($transaction, $e);
            throw $e;
        }

        $transaction->forceFill(['request_uri' => $requestUri])->save();

        Log::info('eSignet : parcours démarré', ['transaction' => $transaction->id, 'mode' => $mode, 'par' => $requestUri !== null]);

        return [
            'authorization_url' => $requestUri
                ? $this->client->urlAutorisation($requestUri)
                : $this->client->urlAutorisationDirecte($parametres),
            'request_uri' => $requestUri,
            'transaction_id' => $transaction->id,
            'cookie' => $navigateur,
        ];
    }

    // ======================================================================
    //  B à F. RETOUR D'ESIGNET — appelé par POST /api/auth/esignet/retour
    // ======================================================================

    /**
     * Traite le retour d'eSignet relayé par la page Angular /auth/esignet/retour.
     *
     * @param  array{state: string, code: ?string, error: ?string, error_description: ?string}  $retour
     * @return array{statut: 'connecte', user: User}|array{statut: 'finalisation', jeton: string}
     */
    public function traiterRetour(array $retour, Request $request): array
    {
        $transaction = $this->controlerRetour($retour, $request);       // B

        try {
            $jetons = $this->echangerCode($transaction, $retour['code']);   // C
            $idToken = $this->validerIdToken($transaction, $jetons);        // D
            $claims = $this->lireUserinfo($jetons, $idToken);               // E

            return $this->decider($transaction, $idToken, $claims);         // F
        } catch (Throwable $e) {
            $this->echec($transaction, $e);
            throw $e;
        }
    }

    /**
     * B. Contrôle du retour : state connu, lié au navigateur (cookie), non expiré, usage unique.
     */
    protected function controlerRetour(array $retour, Request $request): EsignetTransaction
    {
        $transaction = EsignetTransaction::where('state', $retour['state'])->first();
        $cookie = (string) $request->cookie(config('esignet.cookie'), '');

        if (! $transaction) {
            throw new EsignetException('transaction_invalide', 'Retour eSignet avec un state inconnu (rejeu ou CSRF ?).', 400, [
                'securite' => true,
            ]);
        }

        if ($cookie === '' || ! $transaction->navigateur_hash || ! hash_equals($transaction->navigateur_hash, hash('sha256', $cookie))) {
            throw new EsignetException('transaction_invalide', 'Retour eSignet depuis un autre navigateur (cookie de parcours absent ou différent).', 400, [
                'transaction' => $transaction->id, 'securite' => true,
            ]);
        }

        if ($transaction->utilisee_at !== null) {
            throw new EsignetException('transaction_invalide', 'Retour eSignet déjà traité (rejeu).', 400, [
                'transaction' => $transaction->id, 'securite' => true,
            ]);
        }

        if ($transaction->expire_at->isPast()) {
            $transaction->forceFill(['statut' => 'echec', 'error_code' => 'transaction_expiree'])->save();
            throw new EsignetException('transaction_expiree', 'Parcours eSignet expiré.', 400, ['transaction' => $transaction->id]);
        }

        // Usage unique, de façon atomique : deux requêtes concurrentes ne peuvent pas échanger le même code
        $marquee = EsignetTransaction::whereKey($transaction->id)->whereNull('utilisee_at')
            ->update(['utilisee_at' => now(), 'statut' => 'retour_recu']);
        if ($marquee !== 1) {
            throw new EsignetException('transaction_invalide', 'Retour eSignet déjà traité (requêtes concurrentes).', 400, [
                'transaction' => $transaction->id, 'securite' => true,
            ]);
        }
        $transaction->refresh();

        if ($retour['error']) {
            $code = $retour['error'] === 'consent_rejected' ? 'consentement_refuse' : $retour['error'];
            $transaction->forceFill([
                'statut' => 'echec',
                'error_code' => mb_substr($retour['error'], 0, 128),
                'error_description' => $retour['error_description'],
                'nonce' => null,
                'code_verifier' => null,
            ])->save();
            throw new EsignetException($code, $retour['error_description'] ?: 'eSignet a renvoyé une erreur.', 400, [
                'transaction' => $transaction->id,
            ]);
        }

        if (! $retour['code']) {
            throw new EsignetException('transaction_invalide', 'Retour eSignet sans code d’autorisation.', 400, ['transaction' => $transaction->id]);
        }

        return $transaction;
    }

    /**
     * C. Échange du code (moins de 60 s après le retour), authentifié par client_assertion PS256.
     *
     * @return array{access_token: string, id_token: string, token_type: string, expires_in: int}
     */
    protected function echangerCode(EsignetTransaction $transaction, string $code): array
    {
        $jetons = $this->client->echangerCode($code, (string) $transaction->code_verifier);

        if (empty($jetons['id_token']) || empty($jetons['access_token'])) {
            throw new EsignetException('jeton_invalide', 'Réponse /token sans id_token ou access_token.', 502, ['transaction' => $transaction->id]);
        }

        $transaction->forceFill(['statut' => 'jetons_recus'])->save();

        return $jetons;
    }

    /**
     * D. Validation de l'id_token — signature (JWKS), iss, aud, exp/iat, nonce, acr.
     *
     * @return array<string, mixed> claims de l'id_token
     */
    protected function validerIdToken(EsignetTransaction $transaction, array $jetons): array
    {
        $claims = $this->client->verifierJws($jetons['id_token']);
        $contexte = ['transaction' => $transaction->id];

        if (($claims['iss'] ?? null) !== config('esignet.token_issuer')) {
            throw new EsignetException('jeton_invalide', 'id_token : émetteur (iss) inattendu.', 401, $contexte + ['iss' => $claims['iss'] ?? null]);
        }

        $aud = (array) ($claims['aud'] ?? []);
        if (! in_array($this->client->clientId(), $aud, true)
            || (count($aud) > 1 && ($claims['azp'] ?? null) !== $this->client->clientId())) {
            throw new EsignetException('jeton_invalide', 'id_token : audience (aud) inattendue.', 401, $contexte);
        }

        if (! isset($claims['exp'], $claims['iat'], $claims['sub'])) {
            throw new EsignetException('jeton_invalide', 'id_token : exp, iat ou sub absent.', 401, $contexte);
        }

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals((string) $transaction->nonce, $claims['nonce'])) {
            throw new EsignetException('nonce_invalide', 'id_token : nonce différent de celui du parcours (rejeu ?).', 401, $contexte + ['securite' => true]);
        }

        // eSignet peut renvoyer plusieurs acr séparés par des espaces
        $acrs = preg_split('/\s+/', trim((string) ($claims['acr'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $acr = collect($acrs)->first(fn ($a) => in_array($a, config('esignet.acr_creation'), true));
        if (! $acr) {
            throw new EsignetException('acr_insuffisant', 'Niveau d’authentification insuffisant.', 403, $contexte + ['acr' => $claims['acr'] ?? null]);
        }

        $claims['acr'] = $acr;
        $transaction->forceFill(['acr' => $acr])->save();

        Log::info('eSignet : id_token validé', $contexte + ['acr' => $acr]);

        return $claims;
    }

    /**
     * E. Lecture de /userinfo : JWS vérifié (JWE déchiffré d'abord), sub identique à l'id_token,
     * NIP présent et valide, date de naissance cohérente avec le NIP.
     *
     * @return array<string, mixed> claims userinfo vérifiés (le NIP normalisé sous la clé 'nip')
     */
    protected function lireUserinfo(array $jetons, array $idToken): array
    {
        $reponse = $this->client->userinfo($jetons['access_token']);
        $nbParties = substr_count($reponse, '.') + 1;

        $jws = match ($nbParties) {
            5 => $this->client->dechiffrerJwe($reponse),
            3 => $reponse,
            default => throw new EsignetException('jeton_invalide', 'Réponse /userinfo ni signée (JWS) ni chiffrée (JWE).', 401),
        };
        $claims = $this->client->verifierJws($jws);

        if (($claims['sub'] ?? null) !== $idToken['sub']) {
            throw new EsignetException('jeton_invalide', '/userinfo : sub différent de celui de l’id_token.', 401, ['securite' => true]);
        }
        if (isset($claims['iss']) && $claims['iss'] !== config('esignet.token_issuer')) {
            throw new EsignetException('jeton_invalide', '/userinfo : émetteur (iss) inattendu.', 401);
        }
        if (isset($claims['aud']) && ! in_array($this->client->clientId(), (array) $claims['aud'], true)) {
            throw new EsignetException('jeton_invalide', '/userinfo : audience (aud) inattendue.', 401);
        }

        $nipBrut = $this->claim($claims, config('esignet.claim_nip'));
        if ($nipBrut === null || $nipBrut === '') {
            throw new EsignetException('configuration_incomplete', 'Claim « '.config('esignet.claim_nip').' » (NIP) absent de /userinfo : vérifier les claims autorisés du client eSignet.', 502,
                ['claims_recus' => array_keys($claims)]); // noms seulement, jamais les valeurs
        }

        $nip = Nip::normalize(is_scalar($nipBrut) ? (string) $nipBrut : null);
        if (! $nip) {
            throw new EsignetException('nip_invalide', 'NIP transmis par le registre au format invalide.', 422);
        }

        $naissance = $this->date($this->claim($claims, 'birthdate'));
        if ($naissance && $naissance !== Nip::birthdate($nip)) {
            throw new EsignetException('nip_incoherent', 'Date de naissance du registre incohérente avec le NIP.', 409, ['securite' => true]);
        }

        $identite = ['nip' => $nip, 'sub' => $claims['sub']];
        foreach (['name', 'given_name', 'family_name', 'gender', 'email', 'phone_number', 'address', 'picture', 'locale'] as $nom) {
            $identite[$nom] = $this->claim($claims, $nom);
        }
        $identite['birthdate'] = Nip::birthdate($nip);
        $identite['email'] = is_string($identite['email']) ? mb_strtolower(trim($identite['email'])) : null;
        $identite['phone_number'] = is_string($identite['phone_number']) ? Telephone::normalize($identite['phone_number']) : null;
        $identite['address'] = match (true) {
            is_array($identite['address']) => $identite['address'],
            is_string($identite['address']) && $identite['address'] !== '' => ['formatted' => $identite['address']],
            default => null,
        };

        return $identite;
    }

    /**
     * F. Matrice de décision (fiche, section 6) — jamais de fusion automatique de comptes.
     */
    protected function decider(EsignetTransaction $transaction, array $idToken, array $claims): array
    {
        $sub = $idToken['sub'];
        $nip = $claims['nip'];
        $contexte = ['transaction' => $transaction->id, 'mode' => $transaction->mode];

        $user = User::where('esignet_sub', $sub)->first();

        // sub connu → connexion, données du registre resynchronisées
        if ($user) {
            if ($user->nip !== $nip) {
                Log::alert('eSignet : NIP du registre différent de celui du compte', $contexte + ['user_id' => $user->id]);
                throw new EsignetException('nip_incoherent', 'Le NIP vérifié ne correspond pas à celui du compte.', 409, $contexte);
            }

            DB::transaction(function () use ($user, $claims, $idToken, $transaction) {
                $user->fill($this->donneesRegistre($claims));
                $user->forceFill([
                    'nip_source' => 'esignet',
                    'nip_verifie_at' => $user->nip_verifie_at ?? now(),
                    'acr' => $idToken['acr'],
                    'registre_synchronise_at' => now(),
                    'is_admin' => in_array($user->nip, Nip::admins(), true),
                ])->save();
                $this->terminer($transaction, $user);
            });

            Log::info('eSignet : connexion', $contexte + ['user_id' => $user->id]);

            return ['statut' => 'connecte', 'user' => $user];
        }

        // sub inconnu mais NIP déjà rattaché à un compte : anomalie, refus + alerte
        if (User::where('nip', $nip)->exists()) {
            Log::alert('eSignet : NIP déjà associé à un autre compte (sub différent)', $contexte);
            throw new EsignetException('nip_deja_rattache', 'Ce NIP est déjà associé à un autre compte.', 409, $contexte + ['securite' => true]);
        }

        // sub et NIP inconnus → écran de finalisation (jeton à usage unique)
        $jeton = $this->aleatoire();
        DB::transaction(function () use ($transaction, $jeton, $sub, $nip, $claims, $idToken) {
            EsignetInscription::create([
                'transaction_id' => $transaction->id,
                'jeton_hash' => EsignetInscription::hacherJeton($jeton),
                'sub' => $sub,
                'nip' => $nip,
                'claims' => $claims,
                'acr' => $idToken['acr'],
                'expire_at' => now()->addMinutes(config('esignet.inscription_ttl')),
            ]);
            $this->terminer($transaction);
        });

        Log::info('eSignet : identité vérifiée, finalisation du compte', $contexte);

        return ['statut' => 'finalisation', 'jeton' => $jeton];
    }

    // ======================================================================
    //  ÉCRAN DE FINALISATION — GET puis POST /api/auth/esignet/inscription/{jeton}
    // ======================================================================

    /**
     * Identité à afficher en lecture seule sur l'écran « Finaliser mon compte ».
     */
    public function lireInscription(string $jeton): array
    {
        $inscription = $this->inscription($jeton);
        $c = $inscription->claims;

        return [
            'nip' => $inscription->nip,
            'name' => $c['name'] ?? null,
            'given_name' => $c['given_name'] ?? null,
            'family_name' => $c['family_name'] ?? null,
            'birthdate' => $c['birthdate'] ?? null,
            'gender' => $c['gender'] ?? null,
            'email' => $c['email'] ?? null,
            'phone_number' => $c['phone_number'] ?? null,
            'address' => $c['address'] ?? null,
            'expire_dans' => max(0, (int) now()->diffInSeconds($inscription->expire_at)),
        ];
    }

    /**
     * G. Création du compte après l'écran de finalisation, puis H. notification.
     *
     * @param  array{email: ?string, telephone: ?string, cgu: bool}  $contact
     */
    public function finaliser(string $jeton, array $contact): User
    {
        $user = DB::transaction(function () use ($jeton, $contact) {
            $inscription = EsignetInscription::where('jeton_hash', EsignetInscription::hacherJeton($jeton))
                ->lockForUpdate()->first();
            if (! $inscription || ! $inscription->estUtilisable()) {
                throw new EsignetException('inscription_expiree', 'Jeton d’inscription inconnu, expiré ou déjà utilisé.', 410);
            }
            $inscription->forceFill(['utilisee_at' => now()])->save();

            if (User::where('nip', $inscription->nip)->orWhere('esignet_sub', $inscription->sub)->exists()) {
                Log::alert('eSignet : compte déjà créé pour ce NIP ou ce sub pendant la finalisation', ['inscription' => $inscription->id]);
                throw new EsignetException('nip_deja_rattache', 'Un compte existe déjà pour cette identité.', 409);
            }

            $claims = $inscription->claims;
            $user = new User($this->donneesRegistre($claims) + [
                'nip' => $inscription->nip,
                'email' => $contact['email'],
                'phone_number' => $contact['telephone'],
                'password' => null,
                'locale' => 'fr',
            ]);
            $user->forceFill([
                'esignet_sub' => $inscription->sub,
                'nip_source' => 'esignet',
                'nip_verifie_at' => now(),
                'acr' => $inscription->acr,
                'registre_synchronise_at' => now(),
                'is_admin' => in_array($inscription->nip, Nip::admins(), true),
                // Contact identique à celui du registre : déjà vérifié par le registre
                'email_verified_at' => $contact['email'] && $contact['email'] === ($claims['email'] ?? null) ? now() : null,
                'phone_verified_at' => $contact['telephone'] && $contact['telephone'] === ($claims['phone_number'] ?? null) ? now() : null,
            ])->save();

            $inscription->transaction?->forceFill(['user_id' => $user->id])->save();

            return $user;
        });

        Log::info('eSignet : compte créé', ['user_id' => $user->id, 'acr' => $user->acr]);
        $this->notifier($user);

        return $user;
    }

    // ======================================================================
    //  Outils
    // ======================================================================

    /** Claims demandés à /userinfo : le NIP et ceux de config('esignet.claims_essentiels') sont « essential ». */
    private function claimsDemandes(): array
    {
        $essentiels = [...config('esignet.claims_essentiels'), config('esignet.claim_nip')];
        $userinfo = [];
        foreach (array_unique([...config('esignet.claims'), config('esignet.claim_nip')]) as $nom) {
            $userinfo[$nom] = ['essential' => in_array($nom, $essentiels, true)];
        }

        return ['userinfo' => $userinfo, 'id_token' => (object) []];
    }

    /** Champs du compte issus du registre (non modifiables par l'usager). */
    private function donneesRegistre(array $claims): array
    {
        return array_filter([
            'name' => $this->texte($claims['name'] ?? null),
            'given_name' => $this->texte($claims['given_name'] ?? null),
            'family_name' => $this->texte($claims['family_name'] ?? null),
            'birthdate' => $claims['birthdate'] ?? null,
            'gender' => mb_substr((string) $this->texte($claims['gender'] ?? null), 0, 32) ?: null,
            'address' => $claims['address'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Valeur d'un claim, y compris sa variante localisée (« name#fr », « name#fra »…)
     * quand eSignet renvoie les claims par langue.
     */
    private function claim(array $claims, string $nom): mixed
    {
        if (array_key_exists($nom, $claims)) {
            return $claims[$nom];
        }
        foreach ($claims as $cle => $valeur) {
            if (str_starts_with((string) $cle, $nom.'#')) {
                return $valeur;
            }
        }

        return null;
    }

    private function texte(mixed $valeur): ?string
    {
        if (is_array($valeur)) {
            $valeur = $valeur['value'] ?? reset($valeur);
        }

        return is_scalar($valeur) && trim((string) $valeur) !== '' ? trim((string) $valeur) : null;
    }

    /** Date du registre (AAAA/MM/JJ, AAAA-MM-JJ ou JJ/MM/AAAA) au format Y-m-d. */
    private function date(mixed $valeur): ?string
    {
        $valeur = $this->texte($valeur);
        if (! $valeur) {
            return null;
        }
        if (preg_match('#^(\d{4})[-/](\d{2})[-/](\d{2})#', $valeur, $m) || preg_match('#^(\d{2})[-/](\d{2})[-/](\d{4})$#', $valeur, $n)) {
            [$a, $mo, $j] = isset($m[1]) ? [$m[1], $m[2], $m[3]] : [$n[3], $n[2], $n[1]];

            return checkdate((int) $mo, (int) $j, (int) $a) ? "{$a}-{$mo}-{$j}" : null;
        }

        return null;
    }

    private function inscription(string $jeton): EsignetInscription
    {
        $inscription = EsignetInscription::where('jeton_hash', EsignetInscription::hacherJeton($jeton))->first();
        if (! $inscription || ! $inscription->estUtilisable()) {
            throw new EsignetException('inscription_expiree', 'Jeton d’inscription inconnu, expiré ou déjà utilisé.', 410);
        }

        return $inscription;
    }

    /** Parcours terminé : les secrets du parcours sont effacés. */
    private function terminer(EsignetTransaction $transaction, ?User $user = null): void
    {
        $transaction->forceFill([
            'statut' => 'terminee',
            'nonce' => null,
            'code_verifier' => null,
            'user_id' => $user?->id,
        ])->save();
    }

    private function echec(EsignetTransaction $transaction, Throwable $e): void
    {
        $transaction->forceFill([
            'statut' => 'echec',
            'error_code' => $e instanceof EsignetException ? $e->code_erreur : 'erreur_interne',
            'nonce' => null,
            'code_verifier' => null,
        ])->save();
    }

    private function notifier(User $user): void
    {
        try {
            if ($user->email) {
                Mail::to($user->email)->send(new CompteCree($user));
            }
            if ($user->phone_number) {
                app(SmsSender::class)->send($user->phone_number, 'Portail des services : votre compte a été créé avec votre identité numérique (NIP '.$user->nip.').');
            }
        } catch (Throwable $e) {
            // Le compte est créé : un échec de notification ne doit pas faire échouer le parcours
            Log::warning('eSignet : notification de création de compte non envoyée', ['user_id' => $user->id, 'exception' => $e]);
        }
    }

    /** Valeur aléatoire base64url de 256 bits (state, nonce, code_verifier, secrets). */
    private function aleatoire(): string
    {
        return JWT::urlsafeB64Encode(random_bytes(32));
    }
}
