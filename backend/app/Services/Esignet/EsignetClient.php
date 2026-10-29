<?php

namespace App\Services\Esignet;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Throwable;

/**
 * Protocole OIDC avec eSignet (côté serveur uniquement) : découverte, JWKS, client_assertion,
 * PAR, échange du code, /userinfo, vérification des JWS et déchiffrement des JWE.
 *
 * Équivalent PHP de mosip/esignet-mock-services/mock-relying-party-service (esignetService.js, utils.js).
 * Les points d'accès viennent de la découverte (URL publiques) ; les appels serveur à serveur
 * passent par config('esignet.internal_url') (nom du conteneur dans Docker).
 */
class EsignetClient
{
    public const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /** Algorithmes de signature acceptés pour les jetons d'eSignet (jamais « none » ni HS*). */
    private const ALGS_SIGNATURE = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384'];

    // ---------------------------------------------------------------- découverte et JWKS

    /** @return array<string, mixed> */
    public function decouverte(): array
    {
        return Cache::remember('esignet.decouverte', config('esignet.cache_ttl'), function () {
            $url = config('esignet.discovery_url') ?: config('esignet.internal_url').'/.well-known/openid-configuration';
            $doc = $this->json(fn () => $this->http()->get($url), 'decouverte');

            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $champ) {
                if (empty($doc[$champ])) {
                    throw new EsignetException('configuration_incomplete', "Découverte eSignet incomplète : « {$champ} » absent.", 503);
                }
            }

            return $doc;
        });
    }

    /** Point d'accès public (tel que publié par la découverte), ex. 'token_endpoint'. */
    public function pointAcces(string $nom): string
    {
        $url = $this->decouverte()[$nom] ?? null;
        if (! $url) {
            throw new EsignetException('configuration_incomplete', "Point d'accès eSignet « {$nom} » absent de la découverte.", 503);
        }

        return $url;
    }

    /** URL d'un point d'accès vue depuis le backend : l'origine publique est remplacée par l'URL interne. */
    public function urlInterne(string $urlPublique): string
    {
        $publique = $this->origine(config('esignet.issuer_url'));
        $interne = $this->origine(config('esignet.internal_url'));

        return $publique && $interne && str_starts_with($urlPublique, $publique)
            ? $interne.substr($urlPublique, strlen($publique))
            : $urlPublique;
    }

    /** @return array{keys: list<array<string, mixed>>} */
    private function jwks(bool $recharger = false): array
    {
        if ($recharger) {
            Cache::forget('esignet.jwks');
        }

        return Cache::remember('esignet.jwks', config('esignet.cache_ttl'), function () {
            $jwks = $this->json(fn () => $this->http()->get($this->urlInterne($this->pointAcces('jwks_uri'))), 'jwks');
            // Seules les clés de signature servent à vérifier les jetons
            $jwks['keys'] = array_values(array_filter($jwks['keys'] ?? [], fn ($k) => ($k['use'] ?? 'sig') === 'sig'));

            return $jwks;
        });
    }

    // ---------------------------------------------------------------- appels eSignet

    /**
     * Requête d'autorisation poussée (PAR) : renvoie le request_uri à passer à /authorize.
     *
     * @param  array<string, string>  $parametres
     * @return array{request_uri: string, expires_in: int}
     */
    public function pousserRequete(array $parametres): array
    {
        $endpoint = $this->pointAcces('pushed_authorization_request_endpoint');
        $reponse = $this->json(fn () => $this->http()->asForm()->post($this->urlInterne($endpoint), $parametres + [
            'client_id' => $this->clientId(),
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => $this->clientAssertion($endpoint),
        ]), 'par');

        if (empty($reponse['request_uri']) || ! str_starts_with($reponse['request_uri'], 'urn:ietf:params:oauth:request_uri:')) {
            throw new EsignetException('esignet_indisponible', 'Réponse PAR d’eSignet invalide (request_uri absent).', 502);
        }

        return ['request_uri' => $reponse['request_uri'], 'expires_in' => (int) ($reponse['expires_in'] ?? 60)];
    }

    /** PAR publiée par la découverte ? (absente sur certaines instances, ex. eSignet 1.6.2 du pilote Gabon) */
    public function supportePar(): bool
    {
        return ! empty($this->decouverte()['pushed_authorization_request_endpoint']);
    }

    /**
     * URL d'autorisation classique (sans PAR) : les paramètres passent par le navigateur ;
     * state, nonce et PKCE restent générés et vérifiés par le backend.
     *
     * @param  array<string, string>  $parametres
     */
    public function urlAutorisationDirecte(array $parametres): string
    {
        return $this->pointAcces('authorization_endpoint').'?'.http_build_query(
            ['client_id' => $this->clientId()] + $parametres, '', '&', PHP_QUERY_RFC3986,
        );
    }

    /** URL vers laquelle rediriger le navigateur après la PAR. */
    public function urlAutorisation(string $requestUri): string
    {
        return $this->pointAcces('authorization_endpoint').'?'.http_build_query([
            'client_id' => $this->clientId(),
            'request_uri' => $requestUri,
            'ui_locales' => config('esignet.ui_locales'),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Échange du code (private_key_jwt + PKCE).
     *
     * @return array<string, mixed>
     */
    public function echangerCode(string $code, string $codeVerifier): array
    {
        $endpoint = $this->pointAcces('token_endpoint');

        return $this->json(fn () => $this->http()->asForm()->post($this->urlInterne($endpoint), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('esignet.redirect_uri'),
            'client_id' => $this->clientId(),
            'code_verifier' => $codeVerifier,
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => $this->clientAssertion($endpoint),
        ]), 'token');
    }

    /** Réponse brute de /userinfo : JWS (signé) ou JWE (chiffré puis signé). */
    public function userinfo(string $accessToken): string
    {
        try {
            $reponse = $this->http()->withToken($accessToken)->get($this->urlInterne($this->pointAcces('userinfo_endpoint')));
        } catch (ConnectionException $e) {
            throw $this->injoignable($e);
        }

        if ($reponse->failed()) {
            $this->json(fn () => $reponse, 'userinfo'); // lève l'erreur eSignet
        }

        return trim($reponse->body());
    }

    // ---------------------------------------------------------------- jetons

    /**
     * Vérifie la signature d'un JWS avec le JWKS d'eSignet (rechargé une fois si « kid » inconnu,
     * pour suivre une rotation des clés) et les dates (exp, nbf, iat avec tolérance).
     *
     * @return array<string, mixed> claims
     */
    public function verifierJws(string $jws): array
    {
        $entete = $this->entete($jws);
        $alg = $entete['alg'] ?? null;
        if (! in_array($alg, self::ALGS_SIGNATURE, true)) {
            throw new EsignetException('jeton_invalide', "Algorithme de signature refusé : {$alg}.", 401);
        }

        $kid = $entete['kid'] ?? null;
        $jwks = $this->jwks();
        if ($kid !== null && ! $this->contientCle($jwks, $kid)) {
            $jwks = $this->jwks(recharger: true);
        }

        try {
            JWT::$leeway = config('esignet.jwt_leeway');
            $claims = JWT::decode($jws, JWK::parseKeySet($jwks, $alg));
        } catch (Throwable $e) {
            throw new EsignetException('jeton_invalide', 'Signature ou validité du jeton eSignet incorrecte.', 401, [
                'motif' => $e->getMessage(),
                'kid' => $kid,
            ], $e);
        }

        return json_decode(json_encode($claims), true);
    }

    /**
     * Déchiffre un JWE compact (RSA-OAEP-256 ou RSA-OAEP, contenu A128/A192/A256GCM)
     * avec la clé privée de chiffrement du client. Renvoie le JWS contenu.
     */
    public function dechiffrerJwe(string $jwe): string
    {
        $chemin = config('esignet.userinfo_private_key_path');
        if (! $chemin || ! is_readable($chemin)) {
            throw new EsignetException('configuration_incomplete', 'Réponse /userinfo chiffrée mais clé de déchiffrement absente (ESIGNET_USERINFO_PRIVATE_KEY_PATH).', 503, ['chemin' => $chemin]);
        }

        try {
            [$enteteB64, $cleB64, $ivB64, $chiffreB64, $tagB64] = explode('.', $jwe);
            $entete = json_decode(JWT::urlsafeB64Decode($enteteB64), true, flags: JSON_THROW_ON_ERROR);

            $hash = match ($entete['alg'] ?? null) {
                'RSA-OAEP-256' => 'sha256',
                'RSA-OAEP' => 'sha1',
                default => throw new EsignetException('jeton_invalide', 'Algorithme JWE non pris en charge : '.($entete['alg'] ?? '?'), 401),
            };
            $taille = match ($entete['enc'] ?? null) {
                'A128GCM' => 128, 'A192GCM' => 192, 'A256GCM' => 256,
                default => throw new EsignetException('jeton_invalide', 'Chiffrement JWE non pris en charge : '.($entete['enc'] ?? '?'), 401),
            };

            /** @var RSA\PrivateKey $rsa */
            $rsa = PublicKeyLoader::load(file_get_contents($chemin));
            $cek = $rsa->withPadding(RSA::ENCRYPTION_OAEP)->withHash($hash)->withMGFHash($hash)
                ->decrypt(JWT::urlsafeB64Decode($cleB64));

            $clair = openssl_decrypt(
                JWT::urlsafeB64Decode($chiffreB64), "aes-{$taille}-gcm", $cek, OPENSSL_RAW_DATA,
                JWT::urlsafeB64Decode($ivB64), JWT::urlsafeB64Decode($tagB64), $enteteB64,
            );
        } catch (EsignetException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new EsignetException('jeton_invalide', 'Réponse /userinfo chiffrée illisible.', 401, ['motif' => $e->getMessage()], $e);
        }

        if ($clair === false) {
            throw new EsignetException('jeton_invalide', 'Réponse /userinfo chiffrée illisible (intégrité).', 401);
        }

        return trim($clair);
    }

    /**
     * client_assertion (private_key_jwt) : iss = sub = client_id, aud = point d'accès appelé,
     * exp = +2 min, jti unique. Signé PS256 (eSignet 2.x refuse RS256).
     */
    public function clientAssertion(string $audience): string
    {
        $chemin = config('esignet.private_key_path');
        if (! is_readable($chemin)) {
            throw new EsignetException('configuration_incomplete', 'Clé privée du client OIDC introuvable (ESIGNET_PRIVATE_KEY_PATH).', 503, ['chemin' => $chemin]);
        }

        $maintenant = time();

        return JWT::encode([
            'iss' => $this->clientId(),
            'sub' => $this->clientId(),
            'aud' => $audience,
            'iat' => $maintenant,
            'exp' => $maintenant + 120,
            'jti' => (string) Str::uuid(),
        ], file_get_contents($chemin), config('esignet.assertion_alg'), config('esignet.key_id') ?: null);
    }

    public function clientId(): string
    {
        $clientId = config('esignet.client_id');
        if (! $clientId) {
            throw new EsignetException('configuration_incomplete', 'ESIGNET_CLIENT_ID non renseigné.', 503);
        }

        return $clientId;
    }

    /** @return array<string, mixed> */
    public function entete(string $jwt): array
    {
        $entete = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]), true);

        return is_array($entete) ? $entete : [];
    }

    // ---------------------------------------------------------------- outils

    private function http(): PendingRequest
    {
        return Http::timeout(config('esignet.http_timeout'))->acceptJson();
    }

    /**
     * Corps JSON d'une réponse eSignet ; les erreurs OAuth ({error, error_description})
     * sont relayées avec leur code (invalid_grant, invalid_assertion…).
     *
     * @param  Closure(): Response  $requete
     * @return array<string, mixed>
     */
    private function json(Closure $requete, string $etape): array
    {
        try {
            $reponse = $requete();
        } catch (ConnectionException $e) {
            throw $this->injoignable($e);
        }

        $corps = $reponse->json();

        if ($reponse->successful() && is_array($corps)) {
            return $corps;
        }

        $code = $corps['error'] ?? $corps['errors'][0]['errorCode'] ?? null;
        $description = $corps['error_description'] ?? $corps['errors'][0]['errorMessage'] ?? null;

        if ($reponse->serverError() || $code === null) {
            // eSignet donne le motif d'un 401 dans WWW-Authenticate (ex. error="invalid_token"), corps vide
            throw new EsignetException('esignet_indisponible', "eSignet a répondu {$reponse->status()} ({$etape}).", 502, array_filter([
                'etape' => $etape, 'statut_http' => $reponse->status(), 'code' => $code,
                'www_authenticate' => $reponse->header('WWW-Authenticate') ?: null,
            ], fn ($v) => $v !== null));
        }

        // Erreur de configuration du portail (clé, aud, algorithme…) : alerte technique
        $technique = in_array($code, ['invalid_client', 'invalid_assertion', 'unauthorized_client', 'invalid_request'], true);

        throw new EsignetException($code, $description ?: "eSignet a refusé la requête ({$etape}).", $technique ? 502 : 400, [
            'etape' => $etape, 'statut_http' => $reponse->status(),
        ]);
    }

    private function injoignable(ConnectionException $e): EsignetException
    {
        return new EsignetException('esignet_indisponible', 'eSignet est injoignable.', 503, ['motif' => $e->getMessage()], $e);
    }

    /** @param  array{keys: list<array<string, mixed>>}  $jwks */
    private function contientCle(array $jwks, string $kid): bool
    {
        return collect($jwks['keys'])->contains(fn ($k) => ($k['kid'] ?? null) === $kid);
    }

    private function origine(?string $url): ?string
    {
        $p = $url ? parse_url($url) : false;

        return $p && isset($p['scheme'], $p['host'])
            ? $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '')
            : null;
    }
}
