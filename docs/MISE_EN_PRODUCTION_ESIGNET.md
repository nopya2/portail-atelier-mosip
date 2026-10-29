# Mise en production — connexion avec eSignet

Portail : **https://portail-mosip.benga.online** · redirect_uri : **https://portail-mosip.benga.online/auth/esignet/retour**

Le bouton « Se connecter avec eSignet » est le bouton officiel MOSIP (`@mosip/sign-in-with-esignet`, comme
`mock-relying-party-ui`). Au clic, il appelle le backend (`POST /api/auth/esignet/demarrer`), qui fait la requête
PAR signée (`private_key_jwt`, PKCE S256) comme `mock-relying-party-service`, puis redirige vers eSignet.
Si la découverte d'eSignet ne publie pas `pushed_authorization_request_endpoint` (cas de l'eSignet 1.6.2 du
pilote Gabon), le backend prépare une requête d'autorisation classique (mêmes state, nonce et PKCE) et le
navigateur y est redirigé directement. Le navigateur ne voit jamais de jeton.

## 1. Côté eSignet (à obtenir de l'administrateur eSignet)

| Élément | Valeur pour le portail |
|---|---|
| URL publique (issuer) | → `ESIGNET_ISSUER_URL` (exactement l'`issuer` de la découverte, sans `/` final) |
| URL de la découverte | → `ESIGNET_DISCOVERY_URL` si ce n'est pas `<issuer>/.well-known/openid-configuration` |
| URL interne (si eSignet est sur le même hôte Docker) | → `ESIGNET_INTERNAL_URL`, ex. `http://esignet:8088` |
| redirectUris du client | `https://portail-mosip.benga.online/auth/esignet/retour` (URL exacte) |
| grantTypes / clientAuthMethods | `authorization_code` / `private_key_jwt` |
| Clé publique | JWK affichée par `php artisan esignet:cles` (étape 3) |
| Claims autorisés | `individual_id` (NIP, **obligatoire**), `name`, `given_name`, `family_name`, `birthdate`, `gender`, `email`, `phone_number`, `address` |
| acr | `mosip:idp:acr:generated-code`, `mosip:idp:acr:biometrics`, `mosip:idp:acr:linked-wallet` (pas de mot de passe seul) |
| Réponse /userinfo | JWS (défaut) ou JWE (voir étape 3 bis) |

## 2. Sur le serveur

```bash
cd /srv/projects/portail-atelier-mosip            # DEPLOY_PATH
cp .env.docker.production.example .env.docker      # modèle versionné dans le dépôt
chmod 600 .env.docker
# renseigner APP_KEY, DB_PASSWORD, PORTAIL_ADMIN_NIPS et la section eSignet
```

Clé privée du client OIDC (RSA 2048, jamais versionnée, jamais copiée hors du serveur) :

```bash
mkdir -p secrets/esignet
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out secrets/esignet/client-private.pem
# lisible par l'utilisateur www-data du conteneur (uid 82, image alpine) et par lui seul
sudo chown 82:82 secrets/esignet/client-private.pem && sudo chmod 400 secrets/esignet/client-private.pem
```

Le répertoire est monté en lecture seule sur `storage/app/private/esignet` (`docker-compose.deploy.yml`,
variable `ESIGNET_KEYS_DIR`).

## 3. Enregistrer le client dans eSignet

Après le premier déploiement (push sur `main`, puis approbation du job « Déploiement PRODUCTION ») :

```bash
docker exec portail_prod_backend php artisan esignet:cles
```

La commande affiche la **clé publique JWK** (avec son `kid`) et un exemple de requête
`POST /client-mgmt/client` (redirect_uri, claims, acr, `private_key_jwt`), à transmettre à l'administrateur eSignet.
Reporter ensuite dans `.env.docker` : `ESIGNET_CLIENT_ID` (attribué à l'enregistrement) et `ESIGNET_KEY_ID`
(le `kid` affiché), puis relancer :

```bash
docker compose --env-file .env.docker -f docker-compose.yml -f docker-compose.deploy.yml up -d --no-build backend scheduler
```

### 3 bis. Userinfo chiffré (JWE), si le client est enregistré ainsi

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out secrets/esignet/userinfo-private.pem
sudo chown 82:82 secrets/esignet/userinfo-private.pem && sudo chmod 400 secrets/esignet/userinfo-private.pem
# .env.docker :
# ESIGNET_USERINFO_PRIVATE_KEY_PATH=/var/www/html/storage/app/private/esignet/userinfo-private.pem
docker exec portail_prod_backend php artisan esignet:cles --jwe   # clé publique de chiffrement à déclarer
```

Algorithmes pris en charge : `RSA-OAEP-256` (ou `RSA-OAEP`) + `A128GCM` / `A192GCM` / `A256GCM`.

## 4. Vérifier avant d'ouvrir

```bash
docker exec portail_prod_backend php artisan esignet:diagnostic
```

Contrôle : variables renseignées, redirect_uri en HTTPS sur le portail, découverte joignable et `issuer` identique,
PAR disponible (avertissement seulement), algorithme `ESIGNET_ASSERTION_ALG` accepté (RS256 en eSignet 1.x), acr et claim NIP publiés, points d'accès joignables depuis le conteneur, clé
lisible et signature du `client_assertion`. Code de sortie ≠ 0 en cas d'échec.

Puis dérouler la recette de la fiche atelier (section 10) sur https://portail-mosip.benga.online :
création nominale puis connexion, annulation, rejeu de l'URL de retour, connexion sans compte, etc.
Chaque parcours est tracé dans le menu **Journal** (administrateurs), sans aucun jeton ni code.

## 5. Exploitation

- **Rotation de la clé du client** : générer une nouvelle clé, l'enregistrer dans eSignet, mettre à jour
  `ESIGNET_KEY_ID`, relancer le backend. Les clés d'eSignet (JWKS) sont suivies automatiquement (rechargement si
  `kid` inconnu ; cache `ESIGNET_CACHE_TTL`, 1 h par défaut).
- **Changement de configuration eSignet** : relancer le backend, puis `esignet:diagnostic`, qui vide aussi le
  cache de la découverte et du JWKS (stocké en base : un simple redémarrage ne le vide pas).
- **APP_KEY** : ne jamais la changer en production (elle chiffre nonce, code_verifier et les identités en attente
  de finalisation).
- **Horloge** : serveur synchronisé en NTP (le code d'autorisation vit 60 s, les JWT tolèrent 10 s).
- **Retour arrière** : `deployments.log` liste les versions ; `IMAGE_TAG=<sha précédent>` puis `up -d --no-build`.
