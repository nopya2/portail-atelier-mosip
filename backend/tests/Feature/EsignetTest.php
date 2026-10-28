<?php

namespace Tests\Feature;

use App\Mail\CompteCree;
use App\Models\EsignetTransaction;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Tests\TestCase;

/**
 * Parcours eSignet de bout en bout contre un eSignet simulé (Http::fake) :
 * scénarios de recette de la fiche atelier, section 10.
 */
class EsignetTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'http://esignet.test/v1/esignet';

    private const CLIENT_ID = 'portail-test';

    private const NIP = '4A-0012-19870425';

    /** Clé de signature d'eSignet (id_token, userinfo) */
    private static string $cleEsignet;

    /** Clé du client OIDC (client_assertion) */
    private static string $cleClient;

    /** Clé de chiffrement /userinfo du client (JWE) */
    private static string $cleJwe;

    private string $kid = 'cle-1';

    /** Paramètres reçus par la PAR simulée */
    private array $par = [];

    /** Personnalisation des réponses simulées */
    private array $idTokenSurcharge = [];

    private array $userinfoSurcharge = [];

    private bool $userinfoJwe = false;

    private int $appelsJwks = 0;

    private bool $codeExpire = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$cleEsignet = self::nouvelleCle();
        self::$cleClient = self::nouvelleCle();
        self::$cleJwe = self::nouvelleCle();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $dossier = storage_path('framework/testing/esignet');
        @mkdir($dossier, 0777, true);
        file_put_contents("$dossier/client.pem", self::$cleClient);
        file_put_contents("$dossier/jwe.pem", self::$cleJwe);

        config([
            'esignet.issuer_url' => self::ISSUER,
            'esignet.internal_url' => 'http://esignet-interne:8088/v1/esignet',
            'esignet.discovery_url' => null,
            'esignet.client_id' => self::CLIENT_ID,
            'esignet.key_id' => 'client-kid',
            'esignet.private_key_path' => "$dossier/client.pem",
            'esignet.userinfo_private_key_path' => "$dossier/jwe.pem",
            'esignet.redirect_uri' => 'http://localhost:4210/auth/esignet/retour',
            'portail.admin_nips' => [],
        ]);

        Mail::fake();
        $this->simulerEsignet();
    }

    // ------------------------------------------------------------------ scénarios

    public function test_configuration_du_bouton(): void
    {
        $this->getJson('/api/auth/esignet/configuration')
            ->assertOk()
            ->assertExactJson([
                'authorize_uri' => 'http://esignet.test/authorize',
                'client_id' => self::CLIENT_ID,
                'redirect_uri' => 'http://localhost:4210/auth/esignet/retour',
                'scope' => 'openid profile',
                'ui_locales' => 'fr',
            ]);
    }

    public function test_demarrer_pousse_une_requete_par_signee_ps256_avec_pkce(): void
    {
        $reponse = $this->postJson('/api/auth/esignet/demarrer', ['mode' => 'creation'])->assertOk();

        $this->assertSame('urn:ietf:params:oauth:request_uri:abc', $reponse->json('request_uri'));
        $this->assertStringStartsWith('http://esignet.test/authorize?client_id=portail-test&request_uri=urn', $reponse->json('authorization_url'));
        $this->assertNotEmpty($reponse->getCookie(config('esignet.cookie'), false)?->getValue());

        // Appel serveur à serveur sur l'URL interne, assertion PS256 pour l'aud = endpoint PAR public
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://esignet-interne:8088/v1/esignet/oauth/par');
        $assertion = JWT::decode($this->par['client_assertion'], new Key($this->publique(self::$cleClient), 'PS256'));
        $this->assertSame(self::CLIENT_ID, $assertion->iss);
        $this->assertSame(self::CLIENT_ID, $assertion->sub);
        $this->assertSame(self::ISSUER.'/oauth/par', $assertion->aud);
        $this->assertLessThanOrEqual(120, $assertion->exp - $assertion->iat);

        $this->assertSame('S256', $this->par['code_challenge_method']);
        $this->assertSame('code', $this->par['response_type']);
        $claims = json_decode($this->par['claims'], true);
        $this->assertTrue($claims['userinfo']['individual_id']['essential']);

        $transaction = EsignetTransaction::sole();
        $this->assertSame($this->par['state'], $transaction->state);
        $this->assertSame(JWT::urlsafeB64Encode(hash('sha256', $transaction->code_verifier, true)), $this->par['code_challenge']);
    }

    public function test_creation_nominale_puis_connexion_suivante(): void
    {
        // 1. Création : identité vérifiée → écran de finalisation
        $retour = $this->parcours('creation')->assertOk()->assertJson(['statut' => 'finalisation']);
        $jeton = $retour->json('jeton');

        $this->getJson("/api/auth/esignet/inscription/$jeton")
            ->assertOk()
            ->assertJson(['nip' => self::NIP, 'name' => 'Marie Ndong', 'birthdate' => '1987-04-25', 'phone_number' => '+24107123456']);

        $this->postJson("/api/auth/esignet/inscription/$jeton", ['email' => 'marie@example.ga', 'telephone' => '07 12 34 56', 'cgu' => true])
            ->assertCreated()
            ->assertJsonPath('user.nip', self::NIP);

        $user = User::sole();
        $this->assertSame('sub-marie', $user->esignet_sub);
        $this->assertSame('esignet', $user->nip_source);
        $this->assertSame('mosip:idp:acr:generated-code', $user->acr);
        $this->assertNull($user->password);
        $this->assertNotNull($user->phone_verified_at);   // numéro du registre
        $this->assertNull($user->email_verified_at);      // email saisi à la main
        Mail::assertSent(CompteCree::class);

        // Les secrets du parcours sont effacés, le jeton d'inscription ne resert pas
        $this->assertNull(EsignetTransaction::first()->code_verifier);
        $this->postJson("/api/auth/esignet/inscription/$jeton", ['email' => 'x@example.ga', 'cgu' => true])
            ->assertStatus(410)->assertJson(['error' => 'inscription_expiree']);

        // 2. Connexion suivante : sub reconnu, données du registre mises à jour
        $this->userinfoSurcharge = ['name' => 'Marie Ndong Épouse Obiang'];
        $this->parcours('connexion')->assertOk()
            ->assertJson(['statut' => 'connecte'])
            ->assertJsonStructure(['token', 'expires_in', 'user']);
        $this->assertSame('Marie Ndong Épouse Obiang', $user->fresh()->name);
    }

    public function test_clic_sur_se_connecter_sans_compte_oriente_vers_la_finalisation(): void
    {
        $this->parcours('connexion')->assertOk()->assertJson(['statut' => 'finalisation']);
    }

    public function test_annulation_sur_l_ecran_esignet(): void
    {
        $cookie = $this->demarrer();
        $this->retour($cookie, ['state' => $this->par['state'], 'error' => 'access_denied', 'error_description' => 'Annulé'])
            ->assertStatus(400)->assertJson(['error' => 'access_denied']);

        $this->assertSame('echec', EsignetTransaction::sole()->statut);
    }

    public function test_rejeu_state_modifie_et_cookie_absent_sont_refuses(): void
    {
        $cookie = $this->demarrer();
        $state = $this->par['state'];

        $this->retour(null, ['state' => $state, 'code' => 'code-1'])->assertStatus(400)->assertJson(['error' => 'transaction_invalide']);
        $this->retour($cookie, ['state' => 'state-modifie', 'code' => 'code-1'])->assertStatus(400)->assertJson(['error' => 'transaction_invalide']);
        $this->retour($cookie, ['state' => $state, 'code' => 'code-1'])->assertOk();
        $this->retour($cookie, ['state' => $state, 'code' => 'code-1'])->assertStatus(400)->assertJson(['error' => 'transaction_invalide']);
    }

    public function test_parcours_expire(): void
    {
        $cookie = $this->demarrer();
        $this->travel(11)->minutes();

        $this->retour($cookie, ['state' => $this->par['state'], 'code' => 'code-1'])
            ->assertStatus(400)->assertJson(['error' => 'transaction_expiree']);
    }

    public function test_code_expire_relaye_invalid_grant(): void
    {
        $this->codeExpire = true;

        $this->parcours('creation')->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_nonce_different_refuse(): void
    {
        $this->idTokenSurcharge = ['nonce' => 'autre-nonce'];
        $this->parcours('creation')->assertStatus(401)->assertJson(['error' => 'nonce_invalide']);
    }

    public function test_acr_insuffisant_refuse(): void
    {
        $this->idTokenSurcharge = ['acr' => 'mosip:idp:acr:password'];
        $this->parcours('creation')->assertStatus(403)->assertJson(['error' => 'acr_insuffisant']);
    }

    public function test_id_token_d_un_autre_emetteur_ou_client_refuse(): void
    {
        $this->idTokenSurcharge = ['iss' => 'http://pirate.test'];
        $this->parcours('creation')->assertStatus(401)->assertJson(['error' => 'jeton_invalide']);

        $this->idTokenSurcharge = ['aud' => 'autre-client'];
        $this->parcours('creation')->assertStatus(401)->assertJson(['error' => 'jeton_invalide']);
    }

    public function test_id_token_signe_par_une_autre_cle_refuse(): void
    {
        self::$cleEsignetSignature = self::nouvelleCle();

        try {
            $this->parcours('creation')->assertStatus(401)->assertJson(['error' => 'jeton_invalide']);
        } finally {
            self::$cleEsignetSignature = null;
        }
    }

    public function test_claim_nip_absent_refuse(): void
    {
        $this->userinfoSurcharge = ['individual_id' => null];
        $this->parcours('creation')->assertStatus(502)->assertJson(['error' => 'configuration_incomplete']);
    }

    public function test_date_de_naissance_incoherente_avec_le_nip_refusee(): void
    {
        $this->userinfoSurcharge = ['birthdate' => '1990/01/01'];
        $this->parcours('creation')->assertStatus(409)->assertJson(['error' => 'nip_incoherent']);
    }

    public function test_nip_deja_rattache_a_un_autre_sub_refuse_sans_fusion(): void
    {
        $existant = User::forceCreate(['nip' => self::NIP, 'esignet_sub' => 'ancien-sub', 'nip_source' => 'esignet', 'password' => null]);

        $this->parcours('creation')->assertStatus(409)->assertJson(['error' => 'nip_deja_rattache']);
        $this->assertSame('ancien-sub', $existant->fresh()->esignet_sub);
    }

    public function test_userinfo_chiffre_jwe(): void
    {
        $this->userinfoJwe = true;
        $this->parcours('creation')->assertOk()->assertJson(['statut' => 'finalisation']);
    }

    public function test_rotation_des_cles_recharge_le_jwks(): void
    {
        $this->parcours('creation')->assertOk();
        $this->assertSame(1, $this->appelsJwks);

        $this->kid = 'cle-2'; // eSignet signe désormais avec une nouvelle clé
        $this->parcours('connexion')->assertOk();
        $this->assertSame(2, $this->appelsJwks);
    }

    // ------------------------------------------------------------------ eSignet simulé

    private static ?string $cleEsignetSignature = null;

    private function simulerEsignet(): void
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => 'http://esignet.test/authorize',
                'pushed_authorization_request_endpoint' => self::ISSUER.'/oauth/par',
                'token_endpoint' => self::ISSUER.'/oauth/v2/token',
                'userinfo_endpoint' => self::ISSUER.'/oidc/userinfo',
                'jwks_uri' => self::ISSUER.'/oauth/.well-known/jwks.json',
            ]),
            '*/oauth/.well-known/jwks.json' => function () {
                $this->appelsJwks++;
                $details = openssl_pkey_get_details(openssl_pkey_get_private(self::$cleEsignet));

                return Http::response(['keys' => [[
                    'kty' => 'RSA', 'use' => 'sig', 'kid' => $this->kid, 'alg' => 'RS256',
                    'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
                    'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
                ]]]);
            },
            '*/oauth/par' => function (HttpRequest $r) {
                $this->par = $r->data();

                return Http::response(['request_uri' => 'urn:ietf:params:oauth:request_uri:abc', 'expires_in' => 60], 201);
            },
            '*/oauth/v2/token' => function (HttpRequest $r) {
                $data = $r->data();
                if ($this->codeExpire) {
                    return Http::response(['error' => 'invalid_grant', 'error_description' => 'Code expiré'], 400);
                }
                // PKCE : eSignet vérifie code_verifier contre le code_challenge de la PAR
                if (JWT::urlsafeB64Encode(hash('sha256', $data['code_verifier'], true)) !== $this->par['code_challenge']) {
                    return Http::response(['error' => 'invalid_grant'], 400);
                }
                JWT::decode($data['client_assertion'], new Key($this->publique(self::$cleClient), 'PS256'));

                return Http::response([
                    'access_token' => 'jeton-acces',
                    'token_type' => 'Bearer',
                    'expires_in' => 300,
                    'id_token' => $this->signer($this->idTokenSurcharge + [
                        'iss' => self::ISSUER,
                        'aud' => self::CLIENT_ID,
                        'sub' => 'sub-marie',
                        'nonce' => $this->par['nonce'],
                        'acr' => 'mosip:idp:acr:generated-code',
                        'iat' => time(),
                        'exp' => time() + 300,
                    ]),
                ]);
            },
            '*/oidc/userinfo' => function () {
                $claims = array_filter($this->userinfoSurcharge + [
                    'iss' => self::ISSUER,
                    'aud' => self::CLIENT_ID,
                    'sub' => 'sub-marie',
                    'individual_id' => '4a0012-19870425',
                    'name' => 'Marie Ndong',
                    'birthdate' => '1987/04/25',
                    'gender' => 'Féminin',
                    'phone_number' => '+24107123456',
                    'email' => 'marie.registre@example.ga',
                ], fn ($v) => $v !== null);
                $jws = $this->signer($claims);

                return Http::response($this->userinfoJwe ? $this->chiffrer($jws) : $jws, 200, ['Content-Type' => 'application/jwt']);
            },
        ]);
    }

    private function signer(array $claims): string
    {
        return JWT::encode($claims, self::$cleEsignetSignature ?? self::$cleEsignet, 'RS256', $this->kid);
    }

    /** JWE compact RSA-OAEP-256 / A256GCM, comme eSignet pour un client enregistré en JWE. */
    private function chiffrer(string $jws): string
    {
        $entete = JWT::urlsafeB64Encode(json_encode(['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM', 'cty' => 'JWT']));
        $cek = random_bytes(32);
        $iv = random_bytes(12);
        /** @var RSA\PublicKey $rsa */
        $rsa = PublicKeyLoader::load($this->publique(self::$cleJwe));
        $cleChiffree = $rsa->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->encrypt($cek);
        $chiffre = openssl_encrypt($jws, 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag, $entete);

        return implode('.', [$entete, JWT::urlsafeB64Encode($cleChiffree), JWT::urlsafeB64Encode($iv), JWT::urlsafeB64Encode($chiffre), JWT::urlsafeB64Encode($tag)]);
    }

    /** Paire RSA 2048 (PEM PKCS#8) ; phpseclib évite la dépendance à openssl.cnf. */
    private static function nouvelleCle(): string
    {
        return RSA::createKey(2048)->toString('PKCS8');
    }

    private function publique(string $privee): string
    {
        return openssl_pkey_get_details(openssl_pkey_get_private($privee))['key'];
    }

    // ------------------------------------------------------------------ navigateur simulé

    /** Démarre un parcours et renvoie la valeur du cookie HttpOnly posé par le portail. */
    private function demarrer(string $mode = 'creation'): string
    {
        $reponse = $this->postJson('/api/auth/esignet/demarrer', ['mode' => $mode])->assertOk();

        return $reponse->getCookie(config('esignet.cookie'), false)->getValue();
    }

    private function retour(?string $cookie, array $parametres)
    {
        // Les cookies ne sont joints aux requêtes JSON de test qu'avec withCredentials()
        $requete = $cookie ? $this->withCredentials()->withUnencryptedCookie(config('esignet.cookie'), $cookie) : $this;
        $reponse = $requete->postJson('/api/auth/esignet/retour', $parametres);
        $this->defaultCookies = $this->unencryptedCookies = [];

        return $reponse;
    }

    /** Démarrage puis retour d'eSignet avec un code, dans le même navigateur. */
    private function parcours(string $mode)
    {
        $cookie = $this->demarrer($mode);

        return $this->retour($cookie, ['state' => $this->par['state'], 'code' => 'code-'.$mode]);
    }
}
