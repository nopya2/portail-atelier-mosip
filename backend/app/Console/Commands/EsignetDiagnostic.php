<?php

namespace App\Console\Commands;

use App\Services\Esignet\EsignetClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Vérifie la configuration eSignet du portail contre l'instance eSignet réelle,
 * avant l'ouverture au public ou après un changement de configuration.
 *
 *   php artisan esignet:diagnostic
 *   docker exec portail_prod_backend php artisan esignet:diagnostic
 */
class EsignetDiagnostic extends Command
{
    protected $signature = 'esignet:diagnostic';

    protected $description = 'Contrôle la configuration eSignet (découverte, clés, claims, acr, redirect_uri)';

    private int $echecs = 0;

    public function handle(EsignetClient $client): int
    {
        Cache::forget('esignet.decouverte');
        Cache::forget('esignet.jwks');

        $this->verifier('ESIGNET_ISSUER_URL renseigné', (bool) config('esignet.issuer_url'), config('esignet.issuer_url'));
        $this->verifier('ESIGNET_CLIENT_ID renseigné', (bool) config('esignet.client_id'), config('esignet.client_id'));
        $this->verifier('ESIGNET_KEY_ID renseigné', (bool) config('esignet.key_id'), config('esignet.key_id') ?: 'kid absent du client_assertion', bloquant: false);

        $redirect = (string) config('esignet.redirect_uri');
        $this->verifier('redirect_uri sur le portail', str_starts_with($redirect, config('portail.frontend_url').'/'), $redirect);
        if (app()->isProduction()) {
            $this->verifier('redirect_uri en HTTPS', str_starts_with($redirect, 'https://'), $redirect);
        }

        $cle = config('esignet.private_key_path');
        $this->verifier('Clé privée du client lisible', is_readable($cle), $cle);

        try {
            $doc = $client->decouverte();
        } catch (Throwable $e) {
            $this->verifier('Découverte OIDC joignable', false, $e->getMessage());

            return $this->conclure();
        }

        $this->verifier('Découverte OIDC joignable', true, config('esignet.discovery_url') ?: config('esignet.internal_url').'/.well-known/openid-configuration');
        $this->verifier('issuer identique à ESIGNET_ISSUER_URL', $doc['issuer'] === config('esignet.issuer_url'), "découverte : {$doc['issuer']}");
        $this->verifier('PAR disponible', ! empty($doc['pushed_authorization_request_endpoint']), $doc['pushed_authorization_request_endpoint'] ?? 'absent');

        $algs = $doc['token_endpoint_auth_signing_alg_values_supported'] ?? null;
        $this->verifier('Algorithme du client_assertion accepté', $algs === null || in_array(config('esignet.assertion_alg'), $algs, true),
            config('esignet.assertion_alg').' ; acceptés : '.($algs ? implode(', ', $algs) : 'non publié'));

        $pkce = $doc['code_challenge_methods_supported'] ?? null;
        $this->verifier('PKCE S256', $pkce === null || in_array('S256', $pkce, true), $pkce ? implode(', ', $pkce) : 'non publié', bloquant: false);

        $acrs = $doc['acr_values_supported'] ?? null;
        $communs = $acrs === null ? config('esignet.acr_creation') : array_intersect(config('esignet.acr_creation'), $acrs);
        $this->verifier('acr acceptés publiés par eSignet', $communs !== [], implode(', ', $communs ?: config('esignet.acr_creation')));

        $claims = $doc['claims_supported'] ?? null;
        $this->verifier('Claim NIP ('.config('esignet.claim_nip').') supporté', $claims === null || in_array(config('esignet.claim_nip'), $claims, true),
            $claims === null ? 'non publié' : 'claims : '.implode(', ', $claims));

        foreach (['token_endpoint', 'jwks_uri'] as $point) {
            $url = $client->urlInterne($doc[$point]);
            try {
                $statut = Http::timeout(config('esignet.http_timeout'))->get($url)->status();
                $this->verifier("{$point} joignable depuis le backend", $statut < 500, "{$url} → HTTP {$statut}");
            } catch (Throwable $e) {
                $this->verifier("{$point} joignable depuis le backend", false, "{$url} : {$e->getMessage()}");
            }
        }

        if (is_readable($cle) && config('esignet.client_id')) {
            try {
                $client->clientAssertion($doc['token_endpoint']);
                $this->verifier('Signature du client_assertion', true, config('esignet.assertion_alg'));
            } catch (Throwable $e) {
                $this->verifier('Signature du client_assertion', false, $e->getMessage());
            }
        }

        return $this->conclure();
    }

    private function verifier(string $libelle, bool $ok, ?string $detail = null, bool $bloquant = true): void
    {
        if (! $ok && $bloquant) {
            $this->echecs++;
        }
        $marque = $ok ? '<info>OK</info>  ' : ($bloquant ? '<error>KO</error>  ' : '<comment>!!</comment>  ');
        $this->line($marque.$libelle.($detail ? "  <fg=gray>{$detail}</>" : ''));
    }

    private function conclure(): int
    {
        $this->newLine();
        if ($this->echecs > 0) {
            $this->error("{$this->echecs} contrôle(s) en échec.");

            return self::FAILURE;
        }
        $this->info('Configuration eSignet cohérente. Dérouler ensuite les scénarios de recette (fiche, section 10).');

        return self::SUCCESS;
    }
}
