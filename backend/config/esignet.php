<?php

/*
| Configuration du portail comme client OIDC (relying party) d'eSignet.
| Les valeurs viennent du .env (section « eSignet ») ; voir la fiche atelier, section 3.
*/
return [

    // URL publique d'eSignet = issuer de la découverte (et claim « iss » des jetons, sauf ESIGNET_TOKEN_ISSUER)
    'issuer_url' => rtrim((string) env('ESIGNET_ISSUER_URL', ''), '/'),

    // Claim « iss » attendu dans l'id_token et /userinfo, s'il diffère de l'issuer de la découverte
    // (eSignet 1.x du pilote Gabon : <issuer>/v1/esignet). Vide = ESIGNET_ISSUER_URL.
    'token_issuer' => rtrim((string) (env('ESIGNET_TOKEN_ISSUER') ?: env('ESIGNET_ISSUER_URL', '')), '/'),

    // URL utilisée par le backend pour ses appels serveur à serveur.
    // Identique à l'issuer hors Docker ; dans Docker, le nom du conteneur (ex. http://esignet_mosip:8080).
    'internal_url' => rtrim((string) (env('ESIGNET_INTERNAL_URL') ?: env('ESIGNET_ISSUER_URL', '')), '/'),

    // Document de découverte OIDC (URL des points d'accès, algorithmes, acr…), lu par le backend.
    // Par défaut : <internal_url>/.well-known/openid-configuration
    'discovery_url' => env('ESIGNET_DISCOVERY_URL') ?: null,
    // Durée de mise en cache de la découverte et du JWKS (secondes ; le JWKS est rechargé si « kid » inconnu)
    'cache_ttl' => (int) env('ESIGNET_CACHE_TTL', 3600),

    // Client OIDC enregistré dans eSignet (POST /client-mgmt/client)
    'client_id' => env('ESIGNET_CLIENT_ID'),
    'private_key_path' => env('ESIGNET_PRIVATE_KEY_PATH', storage_path('app/private/esignet/client-private.pem')),
    'key_id' => env('ESIGNET_KEY_ID'),
    // Algorithme du client_assertion : eSignet 2.x accepte PS256 (pas RS256)
    'assertion_alg' => env('ESIGNET_ASSERTION_ALG', 'PS256'),

    // Clé privée de déchiffrement de /userinfo quand le client est enregistré en JWE (production).
    // Vide : réponse JWS attendue (signée seulement).
    'userinfo_private_key_path' => env('ESIGNET_USERINFO_PRIVATE_KEY_PATH') ?: null,

    // Page Angular qui reçoit le retour d'eSignet (doit figurer dans redirectUris du client)
    'redirect_uri' => env('ESIGNET_REDIRECT_URI', 'http://localhost:4210/auth/esignet/retour'),

    // Requête d'autorisation
    'scope' => env('ESIGNET_SCOPE', 'openid profile'),
    'claims' => array_values(array_filter(explode(',', (string) env('ESIGNET_CLAIMS', 'individual_id,name,given_name,family_name,birthdate,gender,email,phone_number,address')))),
    'claim_nip' => env('ESIGNET_CLAIM_NIP', 'individual_id'),
    // Claims demandés comme « essential » sur l'écran de consentement (le NIP l'est toujours)
    'claims_essentiels' => array_values(array_filter(explode(',', (string) env('ESIGNET_CLAIMS_ESSENTIELS', 'name,birthdate')))),
    'ui_locales' => env('ESIGNET_UI_LOCALES', 'fr'),

    // Niveaux d'authentification acceptés pour CRÉER un compte (claim « acr » de l'id_token)
    'acr_creation' => array_values(array_filter(explode(',', (string) env('ESIGNET_ACR_CREATION', 'mosip:idp:acr:generated-code,mosip:idp:acr:biometrics')))),

    // Durées de vie (minutes)
    'transaction_ttl' => (int) env('ESIGNET_TRANSACTION_TTL', 10),
    'inscription_ttl' => (int) env('ESIGNET_INSCRIPTION_TTL', 15),

    // Cookie HttpOnly liant le parcours au navigateur (protection contre le login CSRF)
    'cookie' => env('ESIGNET_COOKIE', 'esignet_parcours'),

    // Tolérance d'horloge pour la validation des JWT (secondes)
    'jwt_leeway' => (int) env('ESIGNET_JWT_LEEWAY', 10),
    'http_timeout' => (int) env('ESIGNET_HTTP_TIMEOUT', 10),
];
