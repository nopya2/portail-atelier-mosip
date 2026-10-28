<?php

namespace App\Console\Commands;

use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use phpseclib3\Crypt\RSA;

/**
 * Génère la paire de clés du client OIDC (private_key_jwt, PS256) et affiche la clé publique (JWK)
 * à enregistrer dans eSignet (POST /client-mgmt/client). Option --jwe : clé de chiffrement de /userinfo.
 *
 *   php artisan esignet:cles
 *   php artisan esignet:cles --jwe
 */
class EsignetCles extends Command
{
    protected $signature = 'esignet:cles
        {--jwe : générer la clé de déchiffrement de /userinfo (client enregistré en JWE)}
        {--force : remplacer une clé existante (le client devra être mis à jour dans eSignet)}';

    protected $description = 'Génère la paire de clés RSA du client eSignet et affiche la clé publique (JWK)';

    public function handle(): int
    {
        $jwe = (bool) $this->option('jwe');
        $chemin = $jwe ? config('esignet.userinfo_private_key_path') : config('esignet.private_key_path');

        if (! $chemin) {
            $this->error('ESIGNET_USERINFO_PRIVATE_KEY_PATH non renseigné (ex. storage/app/private/esignet/userinfo-private.pem).');

            return self::FAILURE;
        }

        if (is_file($chemin) && ! $this->option('force')) {
            $this->warn("Une clé existe déjà : {$chemin} (clé publique ci-dessous). --force pour la remplacer.");
            $this->afficher(RSA::loadPrivateKey(file_get_contents($chemin)), $jwe);

            return self::SUCCESS;
        }

        @mkdir(dirname($chemin), 0700, true);
        $cle = RSA::createKey(2048);
        file_put_contents($chemin, $cle->toString('PKCS8'));
        @chmod($chemin, 0600);

        $this->info("Clé privée écrite : {$chemin} (ne jamais la versionner ni la copier hors du serveur).");
        $this->afficher($cle, $jwe);

        return self::SUCCESS;
    }

    private function afficher(RSA\PrivateKey $cle, bool $jwe): void
    {
        $publique = json_decode($cle->getPublicKey()->toString('JWK'), true)['keys'][0];
        // kid : celui de la configuration, sinon l'empreinte RFC 7638 de la clé
        $empreinte = JWT::urlsafeB64Encode(hash('sha256', json_encode(['e' => $publique['e'], 'kty' => 'RSA', 'n' => $publique['n']]), true));
        $jwk = [
            'kty' => 'RSA',
            'use' => $jwe ? 'enc' : 'sig',
            'alg' => $jwe ? 'RSA-OAEP-256' : config('esignet.assertion_alg'),
            'kid' => $jwe ? $empreinte : (config('esignet.key_id') ?: $empreinte),
            'e' => $publique['e'],
            'n' => $publique['n'],
        ];

        $this->newLine();
        $this->line($jwe ? 'Clé publique de chiffrement (JWK) à déclarer pour le client dans eSignet :' : 'Clé publique (JWK) à enregistrer dans eSignet :');
        $this->line(json_encode($jwk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($jwe) {
            return;
        }

        if (! config('esignet.key_id')) {
            $this->newLine();
            $this->warn("Renseigner ESIGNET_KEY_ID={$jwk['kid']} (en-tête « kid » du client_assertion).");
        }

        $this->newLine();
        $this->line('Exemple de requête d’enregistrement (POST <eSignet>/client-mgmt/client, à adapter à la version d’eSignet) :');
        $this->line(json_encode([
            'requestTime' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'request' => [
                'clientId' => config('esignet.client_id') ?: '<client_id>',
                'clientName' => config('app.name'),
                'relyingPartyId' => 'portail-services',
                'logoUri' => config('portail.frontend_url').'/sceau.png',
                'redirectUris' => [config('esignet.redirect_uri')],
                'grantTypes' => ['authorization_code'],
                'clientAuthMethods' => ['private_key_jwt'],
                'userClaims' => array_values(array_unique([...config('esignet.claims'), config('esignet.claim_nip')])),
                'authContextRefs' => config('esignet.acr_creation'),
                'publicKey' => $jwk,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
