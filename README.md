# Portail des services — base pour l'atelier MOSIP / eSignet

Portail de démarches administratives (Angular + Laravel) servant de support à l'intégration d'eSignet.
L'intégration eSignet est **implémentée** (bouton officiel MOSIP + backend OIDC) ; production :
https://portail-mosip.benga.online — procédure : `docs/MISE_EN_PRODUCTION_ESIGNET.md`.

| Brique     | Techno                                   | Dossier     |
|------------|------------------------------------------|-------------|
| Frontend   | Angular 22 (standalone, zoneless, signals) | `frontend/` |
| Backend    | Laravel 12 (API REST, Sanctum)            | `backend/`  |
| Base       | PostgreSQL **mutualisé** `pg_mutualise`, base `esignet_portail` | — |
| Mail       | Mailpit **mutualisé** `mailpit_mutualise` (http://localhost:8125) | — |

## Fonctionnalités

- **Comptes usagers avec NIP obligatoire** (Numéro d'Identification Personnel, unique, non modifiable).
  Création de compte **et** connexion **uniquement avec eSignet** (identité vérifiée par le registre,
  aucun mot de passe géré par le portail), profil.
- **Catalogue de services** et dépôt de **demandes**, pré-remplies avec le profil de l'usager
  (accusé de réception envoyé par mail via Mailpit).
- **Logger centralisé** des erreurs backend **et** frontend, consultable dans le portail (menu *Journal*, admins).

## Création de compte

Un compte ne se crée **qu'avec eSignet** : le NIP et l'état civil viennent du registre national, il n'existe plus
d'inscription par téléphone ou email (formulaire, code de vérification et API correspondants supprimés).
Parcours : `/inscription` → eSignet → `/auth/esignet/retour` → `/inscription/finaliser` (voir la section
« Création de compte avec eSignet »). La **connexion** passe aussi uniquement par eSignet
(`/connexion` → eSignet → `/auth/esignet/retour`) : le portail ne stocke aucun mot de passe.


- **SMS** (notifications) : `SMS_DRIVER=mailpit` (défaut) dépose les SMS dans Mailpit (http://localhost:8125,
  destinataire `24107123456@sms.local`) ; `SMS_DRIVER=log` les écrit dans les logs. Un opérateur réel s'ajoute en
  implémentant `App\Services\Sms\SmsSender`.

## NIP

Format de l'attestation d'inscription au Registre Biométrique des Personnes Physiques :

```
CC-NNNN-AAAAMMJJ        ex. (fictif) 4A-0012-19870425
│  │    └ date de naissance
│  └ numéro d'ordre (4 chiffres)
└ clé de contrôle (2 caractères alphanumériques)
```

- Saisie tolérante (minuscules, espaces, tirets absents), stockage en **forme canonique** majuscules avec tirets
  → l'unicité est garantie quelle que soit l'écriture.
- Rejet des dates impossibles ou futures ; si une date de naissance est saisie, elle doit correspondre à celle du NIP
  (sinon elle est déduite du NIP).
- La clé de contrôle n'est pas recalculée (algorithme non documenté) : seule sa forme est vérifiée.
- Règles : `backend/app/Support/Nip.php` + `app/Rules/ValidNip.php`, miroir Angular `frontend/src/app/core/nip.ts`
  (formatage automatique pendant la saisie). Tests : `php artisan test --filter=NipTest`, `npx ng test`.

## Logger

```
Angular                                   Laravel
───────                                   ───────
GlobalErrorHandler (erreurs JS,           Handler d'exceptions ─┐
  promesses rejetées)          ─┐                               ├─► Monolog ─► daily (storage/logs/laravel-*.log)
Intercepteur HTTP (réseau, 5xx,  ├─► LoggerService ─► POST /api/logs ─► app_logs   └─► database (table app_logs)
  4xx inattendues)               │    (lots, dédoublonnage,        └─► storage/logs/frontend-*.log
LoggerService.error()/warn()    ─┘     sendBeacon à la fermeture)
```

- **Corrélation** : chaque appel HTTP porte un en-tête `X-Request-Id` (généré par Angular, repris par
  Laravel dans tous ses logs et renvoyé dans la réponse). Dans le journal, cliquer sur l'identifiant
  affiche toutes les entrées frontend + backend de la même requête.
- Contexte enregistré : URL, méthode, statut HTTP, usager, IP, user-agent, trace, contexte JSON.
- Les secrets (`password`, `*_token`, `authorization`, `code`, `pin`, `otp`…) sont **masqués** avant stockage.
- Connexion BD dédiée (`logs`) : une entrée survit au rollback de la transaction métier.
  Si la BD est indisponible, repli sur `error_log()` sans faire échouer la requête.
- Réglages (`backend/.env`) : `LOG_STACK=daily,database`, `LOG_DB_LEVEL=warning` (niveau minimal en base),
  `LOG_DB_RETENTION_DAYS=30` (purge quotidienne via `schedule:work`).
  Côté Angular : `src/environments/environment.ts` (`remoteLogging`, `remoteLogLevel`).
- Boutons **« Tester une erreur frontend / backend »** dans la page Journal pour vérifier la chaîne.

## Démarrage

Prérequis : services mutualisés démarrés (`C:\Users\user\Documents\PROJECTS\BD_mutualisée`) —
au minimum `pg_mutualise` et `mailpit_mutualise`. La base `esignet_portail` a été créée avec
`scripts\nouvelle-base.ps1 -Nom esignet_portail`.

### Option A — Docker (réseau `bd_mutualisee_net`)

```powershell
Copy-Item .env.docker.example .env.docker   # renseigner APP_KEY et DB_PASSWORD
docker compose --env-file .env.docker up -d --build
```

- Portail : http://localhost:4210 · API : http://localhost:8010/api
- Conteneurs : `portail_backend` (migrations + seed au démarrage), `portail_scheduler`, `portail_frontend` (nginx, relaie `/api`).

### Option B — en local (développement)

```powershell
cd backend
composer install
Copy-Item .env.example .env ; php artisan key:generate   # puis DB_PASSWORD
php artisan migrate --seed
php artisan serve --port=8010

cd ..\frontend
npm install
npx ng serve            # http://localhost:4210, /api relayé vers :8010 (proxy.conf.json)
```

(Option A et B utilisent les mêmes ports : n'en lancer qu'une à la fois.)

### Administrateurs

Aucun compte n'est créé par le seeder. Un usager est **administrateur** (accès au Journal) si son NIP figure dans
`PORTAIL_ADMIN_NIPS` : son compte est créé à sa première connexion eSignet, comme pour tout usager.
Tant qu'eSignet n'est pas configuré (`php artisan esignet:diagnostic`), personne ne peut donc se connecter au portail.

## API

| Méthode | Route | Accès |
|---|---|---|
| GET  | `/api/services`, `/api/services/{slug}` | public |
| GET  | `/api/auth/nip-format` | public |
| POST | `/api/auth/esignet/demarrer`, `/api/auth/esignet/retour` · GET/POST `/api/auth/esignet/inscription/{jeton}` | public (limité) |
| POST | `/api/logs` | public (limité 60/min) — erreurs frontend |
| GET  | `/api/auth/me` · PUT `/api/auth/profile` · POST `/api/auth/logout` | authentifié |
| GET  | `/api/demandes` · POST `/api/services/{slug}/demandes` | authentifié |
| GET  | `/api/admin/logs`, `/api/admin/logs/stats`, `/api/admin/logs/{id}` · POST `/api/admin/logs/test` | admin |

## Création de compte avec eSignet

Méthodologie : `docs/Fiche_atelier_Creer_un_compte_avec_eSignet.docx` · mise en production :
`docs/MISE_EN_PRODUCTION_ESIGNET.md`. Intégration calquée sur les applications de référence MOSIP
`esignet-mock-services` (`mock-relying-party-ui` pour le bouton, `mock-relying-party-service` pour le backend),
renforcée selon la fiche (PKCE, state/nonce côté serveur, cookie liant le parcours au navigateur).

```
Angular                                  Laravel                                   eSignet
───────                                  ───────                                   ───────
bouton officiel (@mosip/sign-in-with-esignet)
  └ par_callback ─► POST /demarrer ─────► A  state, nonce, PKCE, cookie HttpOnly
                                            └ PAR signée (private_key_jwt PS256) ──► /oauth/par
  ◄── request_uri ◄────────────────────────┘
  └ redirection ──────────────────────────────────────────────────────────────────► /authorize
/auth/esignet/retour ◄── ?code&state ─────────────────────────────────────────────┘
  └ POST /retour ───────────────────────► B  state = cookie, non expiré, usage unique
                                          C  code + code_verifier + client_assertion ► /token
                                          D  id_token : JWKS, iss, aud, exp, nonce, acr
                                          E  userinfo (JWS, ou JWE déchiffré) : sub, NIP, date de naissance
                                          F  sub connu → connecté · inconnu → finalisation · NIP déjà pris → refus
/inscription/finaliser ─► POST /inscription/{jeton} ► G  création du compte (+ mail / SMS)
```

| Élément | Où |
|---|---|
| Protocole (découverte, JWKS, PAR, token, userinfo, JWE) | `backend/app/Services/Esignet/EsignetClient.php` |
| Étapes A–G et matrice de décision | `backend/app/Services/Esignet/EsignetService.php` |
| API | `EsignetController` → `GET /api/auth/esignet/configuration`, `POST /demarrer`, `POST /retour`, `GET` / `POST /inscription/{jeton}` |
| Configuration | `backend/config/esignet.php`, section « eSignet » de `backend/.env.example` / `.env.docker.production.example` |
| Bouton officiel | `frontend/src/app/core/esignet/esignet-button.ts` |
| Outils | `php artisan esignet:cles` (clé du client + JWK à enregistrer), `php artisan esignet:diagnostic` |
| Tests (eSignet simulé, scénarios de recette) | `backend/tests/Feature/EsignetTest.php` |

## Structure

```
backend/
  app/Logging/                 DatabaseHandler, RequestContextProcessor, LogSanitizer…
  app/Http/Middleware/         AssignRequestId (X-Request-Id), EnsureAdmin
  app/Http/Controllers/Api/    Auth, Service, ClientLog (ingestion frontend), Admin/Log
  app/Support/Nip.php          format, normalisation et contrôle du NIP
  config/portail.php           admins, durées
frontend/src/app/
  core/logging/                LoggerService, GlobalErrorHandler
  core/http.interceptor.ts     Bearer + X-Request-Id + journalisation des erreurs HTTP
  core/auth/                   AuthService, guards
  pages/                       accueil, connexion, inscription, service, demandes, profil, admin/journal
docker-compose.yml             backend + scheduler + frontend sur bd_mutualisee_net
```
