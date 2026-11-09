# 02 — Architecture technique

**Version :** 1.0 — 4 novembre 2026
**Références :** `01-analyse-cahier-des-charges.md`, `08-risques-et-decisions.md`

---

## 1. Vue d'ensemble

**Style retenu [RECO-CDC §21.2] :** monolithe modulaire Laravel exposant une API REST JSON versionnée, consommée par une application React + TypeScript distincte (SPA), servie depuis **la même origine** (D-043).

```
                    ┌──────────────────────────── VPS Linux ─────────────────────────────┐
 Navigateur         │                                                                    │
 (PC / mobile) ───HTTPS──► Caddy (TLS, HTTP/2) ──┬──► /            fichiers statiques SPA │
                    │                            └──► /api/v1/*    Nginx ► PHP-FPM (Laravel)
                    │                                                     │                │
                    │             ┌────────────┬──────────────┬───────────┼───────────┐    │
                    │             ▼            ▼              ▼           ▼           ▼    │
                    │          MySQL 8.4    Redis 7      Worker queue  Scheduler  Gotenberg │
                    │          (données)   (queue,cache, (imports, PDF, (backups,  (PDF A4) │
                    │                       sessions)    e-mails)      alertes)            │
                    │             │                                                       │
                    │       Volume privé /storage (documents, images, imports)           │
                    └─────────────┼──────────────────────────────────────────────────────┘
                                  ▼
                       Stockage objet externe (sauvegardes chiffrées restic)
```

### 1.1. Choix technologiques

| Couche | Choix | Justification | Décision |
|---|---|---|---|
| Langage backend | PHP 8.4 | Version supportée, typage moderne, enums | D-052 |
| Framework | Laravel (dernière version majeure stable au démarrage du lot 0, 12.x ou 13.x) | Imposé ; écosystème (queue, scheduler, policies) | D-052 |
| Base de données | **MySQL 8.4 LTS** (pas MariaDB) | `CHECK` appliqués, `SELECT … FOR UPDATE SKIP LOCKED`, colonnes générées, JSON, comportement homogène prod/dev | D-001 |
| Cache, files, sessions | Redis 7 | Recommandé CDC §21.4 | — |
| Calcul décimal | `brick/math` (BigDecimal) | Aucun flottant dans les calculs monétaires | D-013 |
| Permissions | `spatie/laravel-permission` en mode *teams* (équipe = entreprise) | Rôles par entreprise, éprouvé | D-006 |
| Authentification | Laravel Sanctum, mode SPA (cookie de session) | Cookies HttpOnly, CSRF natif | D-005 |
| PDF | Gotenberg (Chromium headless) en conteneur | Rendu A4 fidèle HTML/CSS, isolé du processus PHP | D-032 |
| Excel / CSV | OpenSpout | Lecture/écriture en flux, faible mémoire | D-034 |
| PDF packing lists | `pdftotext -layout` (poppler) + modèles d'extraction | Une seule pile technique ; validation humaine obligatoire | D-033 |
| Contrat API | OpenAPI 3.1, **contract-first** | Génère les types TS, valide les réponses en test | D-035 |
| Frontend | React 19, TypeScript strict, Vite, TanStack Query, React Router, React Hook Form + Zod, Tailwind + shadcn/ui (Radix) | Accessibilité, thème clair/sombre, composants réutilisables | D-036 |
| Tests | Pest + Paratest, Playwright, k6, Larastan niveau 8, Infection (modules critiques) | Voir `07` | — |
| Reverse proxy TLS | Caddy | HTTPS automatique (Let's Encrypt), configuration minimale | — |
| Sauvegardes | restic vers stockage objet S3 compatible externe | Chiffrement, déduplication, rétention 30 jours | D-039 |

---

## 2. Découpage modulaire

### 2.1. Modules

| Module | Responsabilité | Dépend de |
|---|---|---|
| `Shared` | Types valeur (Money, Quantity, Ulid), exceptions métier, idempotence, séquences documentaires, fichiers | — |
| `Tenancy` | Entreprises, contexte d'entreprise, suspension, paramètres entreprise | Shared |
| `IdentityAccess` | Utilisateurs, invitations, authentification, rôles, permissions | Shared, Tenancy |
| `Audit` | Journal d'audit append-only | Shared, Tenancy |
| `Settings` | Paramètres globaux et entreprise, devises, taux de change, taxes | Shared, Tenancy |
| `Catalog` | Catégories, produits, variantes, attributs, unités, prix, images | Shared, Tenancy |
| `Inventory` | Dépôts, lots, positions, mouvements, réservations, blocages, inventaires, ajustements, transferts | Catalog |
| `Procurement` | Fournisseurs, commandes d'achat, engagements et paiements fournisseurs | Catalog, Finance (ports) |
| `Imports` | Moteur d'import (packing lists, données initiales), modèles de correspondance | Catalog, Inventory, Procurement |
| `Logistics` | Arrivages, conteneurs, réceptions | Procurement, Inventory |
| `Costing` | Frais d'approche, modèles de calcul, calculs de coût de revient, ajustements de valorisation | Logistics, Inventory |
| `Sales` | Clients, devis, commandes, livraisons, retours, litiges | Catalog, Inventory, Billing (ports) |
| `Billing` | Factures, avoirs, imputations | Sales (lecture), Shared |
| `Payments` | Règlements clients, reçus | Billing, Finance |
| `Finance` | Comptes financiers, mouvements, transferts, dépenses, ouverture | Shared |
| `Approvals` | Règles, demandes, décisions, effets provisoires | Shared ; appelle les modules via interface `ApprovableOperation` |
| `Reporting` | Tableaux de bord, rapports, exports, consolidation | Lecture seule sur tous les modules |
| `Notifications` | In-app, e-mail | Shared |
| `Operations` | Sauvegardes, supervision, santé | Shared |

### 2.2. Règles de dépendance

- Un module n'accède **jamais** directement aux tables d'un autre module en écriture : il passe par un **service applicatif public** (contrat dans `Contracts/`).
- La lecture croisée pour le reporting passe par des **requêtes dédiées** (module `Reporting`) ou des vues SQL.
- Les dépendances cycliques sont interdites ; les inversions passent par des **interfaces** (ex. `Sales` dépend de `Billing\Contracts\InvoiceIssuer`).
- Ces règles sont vérifiées par des **tests d'architecture Pest** (`arch()`), exécutés en CI (D-037).

### 2.3. Structure interne d'un module

```
app/Modules/Inventory/
├── Domain/                 # Règles pures, sans Laravel ni base
│   ├── Enums/              # MovementType, ReservationStatus…
│   ├── ValueObjects/       # StockBuckets, LotAllocation…
│   ├── Services/           # FifoAllocator, AvailabilityCalculator
│   └── Exceptions/         # InsufficientStockException…
├── Application/            # Cas d'usage transactionnels
│   ├── Commands/           # ReserveStock, PostMovement, ValidateTransfer… (DTO)
│   ├── Handlers/           # Orchestration + transaction + verrous + audit
│   └── Queries/            # Lectures optimisées
├── Infrastructure/
│   ├── Models/             # Eloquent (trait BelongsToCompany)
│   ├── Repositories/       # Verrouillage, requêtes complexes
│   └── Listeners/
├── Http/
│   ├── Controllers/        # Minces : validation → handler → resource
│   ├── Requests/           # FormRequest (validation)
│   ├── Resources/          # Transformation JSON (masquage champs sensibles)
│   └── routes.php
├── Policies/
├── Contracts/              # Interfaces publiques exposées aux autres modules
└── Database/
    ├── Migrations/
    ├── Factories/
    └── Seeders/
tests/ (miroir par module : Unit/, Feature/, Concurrency/)
```

**Règle :** un contrôleur ne contient aucune règle métier ; un handler applicatif est le **seul** point d'écriture d'un agrégat.

---

## 3. Multi-tenant : isolation stricte

### 3.1. Modèle

Base partagée, colonne `company_id` sur toutes les tables métier [RECO-CDC §21.3]. Défense en **cinq couches** :

| Couche | Mécanisme |
|---|---|
| 1. Contexte serveur | `TenantContext` (singleton requête) résolu par le middleware `ResolveCompanyContext`. Utilisateur ordinaire : `users.company_id`, immuable. Super-admin : entreprise choisie via `POST /api/v1/context/company`, stockée **dans la session serveur**, journalisée (MT-008). Aucun `company_id` n'est lu dans le corps ou les paramètres d'une requête métier (D-004). |
| 2. ORM | Trait `BelongsToCompany` : portée globale `where company_id = ?` + remplissage automatique à la création + exception si le contexte est absent (*fail closed*). Les modèles globaux (`companies`, `currencies`, `permissions`…) n'utilisent pas ce trait. |
| 3. Base de données | Chaque table métier porte `UNIQUE (company_id, id)` ; toute relation intra-entreprise utilise une **clé étrangère composite** `(company_id, x_id) → parent(company_id, id)`. Une ligne de commande ne peut physiquement pas référencer un produit d'une autre entreprise (D-003). |
| 4. Autorisation | Policies Laravel sur chaque action ; ressource d'une autre entreprise ⇒ **404** (pas 403), pour ne pas révéler son existence (D-054). |
| 5. Tests | Test générique qui parcourt **toutes les routes** de l'API et vérifie qu'un utilisateur de A ne peut lire/modifier aucune ressource de B (voir `07` §4). |

### 3.2. Points de fuite traités explicitement

| Vecteur | Traitement |
|---|---|
| Files de tâches | Chaque job implémente `TenantAware` : `company_id` sérialisé, contexte rétabli dans `handle()`, vérification de suspension avant écriture. |
| Scheduler | Les tâches multi-entreprises itèrent explicitement entreprise par entreprise et rétablissent le contexte. |
| Cache | Préfixe de clé `c:{company_id}:` imposé par un `TenantCache` ; aucune clé métier sans préfixe (test d'architecture). |
| Fichiers | Disque privé `storage/app/private/companies/{company_id}/…`, noms aléatoires (ULID), téléchargement uniquement via contrôleur avec policy ; aucune URL publique directe (MT-009). |
| Exports / PDF | Générés par jobs `TenantAware`, stockés sous le répertoire de l'entreprise, téléchargement contrôlé. |
| Recherche globale | Passe par les mêmes requêtes Eloquent filtrées. |
| Requêtes brutes (`DB::table`) | Interdites hors `Reporting` et `Repositories` ; dans ces cas, clause `company_id` obligatoire revue en code review + test. |
| Notifications | `notifications.company_id` ; l'endpoint ne renvoie que les notifications de l'utilisateur et de l'entreprise active. |
| Sauvegardes | Globales, chiffrées, accessibles au seul exploitant. |

### 3.3. Suspension (D-041)

Middleware `EnsureCompanyWritable` sur toutes les routes non `GET` des modules métier : renvoie `423 company_suspended`. Les jobs vérifient l'état avant toute écriture. Les approbations qui produiraient des écritures sont refusées [RECO-CDC §3.5].

---

## 4. Cohérence transactionnelle et concurrence

### 4.1. Patron d'écriture critique

Toute opération listée au CDC §21.5 suit ce patron dans son handler :

```php
public function handle(ConfirmSalesOrder $cmd): SalesOrder
{
    return $this->idempotency->once($cmd->idempotencyKey, fn () =>
        DB::transaction(function () use ($cmd) {
            // 1. Verrouiller l'agrégat racine et vérifier la transition d'état
            $order = $this->orders->lockForUpdate($cmd->orderId);
            $order->assertCanTransitionTo(OrderStatus::Confirmed);

            // 2. Verrouiller les positions de stock dans un ordre déterministe
            //    (warehouse_id, variant_id) pour éviter les interblocages (D-056)
            $positions = $this->stock->lockPositions($order->stockKeysSorted());

            // 3. Contrôler les invariants (disponible >= demandé) et écrire
            $this->reservations->reserve($order, $positions);
            $invoice = $this->invoiceIssuer->issueFor($order); // numéro atomique

            // 4. Changer l'état (UPDATE … WHERE status = 'draft' : 1 ligne attendue)
            $order->markConfirmed($invoice);

            // 5. Audit + événements "after commit"
            $this->audit->record('sales_order.confirmed', $order);
            return $order;
        }, attempts: 3) // reprise automatique sur interblocage
    );
}
```

### 4.2. Garanties anti-doublons (R-03)

| Niveau | Mécanisme |
|---|---|
| HTTP | En-tête `Idempotency-Key` obligatoire sur les POST critiques ; réponse rejouée si même clé et même empreinte de requête ; `409 idempotency_conflict` si même clé, corps différent (D-051). |
| État | Transition conditionnelle `UPDATE … SET status = :new WHERE id = :id AND status = :expected` ; 0 ligne ⇒ `409 already_processed`. |
| Base | Unicité des mouvements par origine : `UNIQUE (company_id, source_type, source_line_id, movement_type, lot_id, reversal_seq)` ; une réception ou une livraison ne peut générer deux fois ses mouvements, même en cas de bogue applicatif. |
| Base | `CHECK` sur les positions : `on_hand ≥ 0`, `reserved ≥ 0`, `on_hand − reserved − blocked − in_delivery ≥ 0`. Aucun stock négatif possible, même en cas de bogue. |
| Base | Déclencheurs `BEFORE UPDATE/DELETE` sur `stock_movements`, `financial_movements`, `audit_logs` : immutabilité (D-038). |

### 4.3. Événements

- **Synchrones dans la transaction** : uniquement les effets qui doivent être atomiques (ex. réservation, facture) — appelés explicitement, pas via des listeners implicites.
- **Après commit** (`ShouldDispatchAfterCommit`) : notifications, génération PDF, recalcul de caches de tableau de bord, e-mails.

### 4.4. Projection de stock

- `stock_movements` est la **source de vérité** du stock physique (append-only).
- `stock_positions` (dépôt × variante) et `stock_lot_balances` (dépôt × lot) sont des **projections** mises à jour dans la même transaction que le mouvement ; elles servent de **point de verrouillage** et de lecture rapide.
- Une tâche nocturne `inventory:reconcile` vérifie `Σ mouvements = on_hand` et `Σ réservations actives = reserved` pour chaque position ; tout écart est alerté (M-15).

---

## 5. Calculs financiers

- Types : `DECIMAL(19,4)` pour les montants, `DECIMAL(24,8)` pour les coûts unitaires, `DECIMAL(20,10)` pour les taux (détail dans `03`).
- PHP : objets valeur `Money` (montant `BigDecimal` + devise) et `Quantity` (entier). **Aucun `float`** ; règle Larastan personnalisée interdisant `float` dans `Domain/` des modules financiers.
- Arrondi : `HALF_UP` à la précision de la devise (`currencies.decimals`, 0 pour XOF) au **niveau de la ligne** ; total document = somme des lignes arrondies (D-013).
- Allocation de frais : méthode du **plus fort reste** pour que la somme des parts arrondies égale exactement le montant d'origine (CR-008, D-014).
- JSON : montants transmis en **chaînes décimales** (`"100000"`), jamais en nombres JSON.
- Frontend : n'effectue que des **prévisualisations** ; le montant faisant foi est toujours celui renvoyé par le serveur.

---

## 6. Moteur de formules de coût de revient (D-015)

- Grammaire restreinte : nombres décimaux, variables d'une liste blanche, `+ − × ÷`, parenthèses, fonctions `min`, `max`, `round(x, n)`, `if(cond, a, b)` et comparaisons.
- Analyseur écrit spécifiquement (tokenizer + parser descendant récursif → AST), évalué en `BigDecimal`. Aucune évaluation PHP/JS (`eval`, `ExpressionLanguage` à flottants exclus).
- Validation à l'enregistrement : syntaxe, variables autorisées, division par zéro statiquement détectable, test sur jeu d'exemple.
- Chaque modèle est **versionné** ; un calcul conserve l'identifiant de version et un instantané des entrées (CR-003, CR-005).

Variables autorisées (V1) : `purchase_unit_price_foreign`, `exchange_rate`, `qty_accepted`, `qty_expected`, `line_cbm`, `total_cbm`, `common_fees`, `direct_fees`, `fees_<categorie>`, `pieces_per_carton`.

---

## 7. Sécurité

| Domaine | Mesure |
|---|---|
| Transport | HTTPS obligatoire (Caddy), HSTS, redirection HTTP→HTTPS. |
| Mots de passe | Argon2id (`HASH_DRIVER=argon2id`), longueur ≥ 12, vérification contre listes de mots de passe compromis (règle `Password::uncompromised()` si connectivité, sinon liste locale). |
| Session | Cookie `__Host-` HttpOnly, Secure, SameSite=Lax ; durée d'inactivité 240 min (`SESSION_LIFETIME=240`) [VALIDÉ-CDC] ; régénération après connexion ; invalidation de toutes les sessions à la désactivation d'un compte (sessions en base Redis indexées par utilisateur). |
| CSRF | Sanctum (cookie `XSRF-TOKEN` + en-tête `X-XSRF-TOKEN`). |
| Force brute | `RateLimiter` : 5 essais / 15 min par couple (e-mail, IP), 20 / 15 min par IP ; super-admin : limiteur dédié + e-mail d'alerte de connexion (D-040). |
| Réinitialisation | Jeton à usage unique, 60 min, haché en base, invalidé après usage et à chaque nouvelle demande. Réponse identique que l'e-mail existe ou non. |
| Autorisation | Policies + permissions `module.action` ; contrôle des **champs sensibles** (prix d'achat, coûts, marges, soldes, fournisseurs) dans les `Resources` via `permission:*.view_sensitive` — s'applique aussi aux exports et PDF [RECO-CDC §4.4]. |
| Entrées | FormRequest systématiques, ULID validés, énumérations strictes, tailles maximales. |
| Fichiers | Liste blanche MIME **détectée** (finfo) + extension, taille max (images 5 Mo, imports 20 Mo), réencodage des images (suppression EXIF), noms ULID, stockage hors webroot, antivirus ClamAV **optionnel** (profil Docker `scan`). |
| En-têtes | CSP stricte pour la SPA, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`. |
| Secrets | `.env` hors Git, `.env.example` sans valeurs ; secrets de production en variables d'environnement Docker / fichier protégé (0600). |
| Erreurs | Format problème normalisé ; aucune trace ni requête SQL renvoyée en production (`APP_DEBUG=false`). |
| Dépendances | `composer audit`, `npm audit` en CI ; Dependabot / Renovate. |

---

## 8. Documents, fichiers et imports

- **PDF** : vues Blade HTML/CSS A4 → Gotenberg. Pour les factures, avoirs et reçus, les données sont **figées** (instantané JSON dans le document) ; le PDF est stocké avec son empreinte SHA-256 dans `generated_documents`, et peut être régénéré à l'identique.
- **Partage WhatsApp** : lien `https://wa.me/<numéro>?text=<message>` prérempli contenant un **lien de téléchargement signé et temporaire** (7 jours, révocable) vers le PDF. Pas d'API WhatsApp [RECO-CDC §12.6].
- **Imports** : pipeline asynchrone `upload → parse → map → validate → preview → commit`, avec mode **simulation** (exécution complète dans une transaction annulée) [RECO-CDC §18.3].
- **Packing lists PDF** : extraction texte positionnelle ; modèle par fournisseur (zones de colonnes, motifs regex) ; chaque ligne passe en revue humaine avant validation (D-033).

---

## 9. Frontend

```
frontend/src/
├── app/                # Routeur, providers (QueryClient, thème, auth), layout
├── api/
│   ├── schema.d.ts     # Généré depuis openapi.yaml (openapi-typescript)
│   ├── client.ts       # openapi-fetch, CSRF, Idempotency-Key, gestion des erreurs
│   └── queries/        # Hooks TanStack Query par module
├── modules/            # Miroir des modules backend (catalog, inventory, sales…)
│   └── sales/
│       ├── pages/      # Écrans (liste, détail, saisie rapide)
│       ├── components/
│       └── schemas.ts  # Zod (validation de formulaire)
├── components/ui/      # Design system (shadcn/ui)
├── lib/                # money.ts (formatage Intl fr-SN), dates, permissions
└── tests/              # Vitest + Testing Library ; e2e/ Playwright
```

Principes : TypeScript `strict`, aucune donnée sensible en `localStorage`, permissions reçues par `/auth/me` utilisées **uniquement** pour l'affichage (le serveur fait foi), formulaires mobiles d'abord pour vente / réception / inventaire, mode lecteur code-barres (saisie clavier HID + touche Entrée), thème clair/sombre (`prefers-color-scheme` + choix utilisateur), pagination serveur, chargement progressif des images (miniatures générées côté serveur).

---

## 10. Structure du dépôt

```
fds-business/
├── backend/                     # Application Laravel
│   ├── app/
│   │   ├── Modules/             # Voir §2
│   │   └── Providers/
│   ├── bootstrap/ config/ routes/api.php (agrège les routes des modules)
│   ├── database/                # Migrations transverses, seeders de référence
│   ├── resources/views/pdf/     # Gabarits PDF
│   ├── tests/
│   ├── phpstan.neon  pest.php  composer.json
├── frontend/                    # Application React + TS (Vite)
├── openapi/
│   ├── openapi.yaml             # Contrat source de vérité
│   └── components/ paths/       # Fichiers fractionnés par module
├── docker/
│   ├── php/Dockerfile           # PHP-FPM 8.4 + extensions (pdo_mysql, redis, intl, bcmath, gd, zip) + poppler-utils
│   ├── nginx/default.conf
│   ├── caddy/Caddyfile
│   ├── mysql/conf.d/fds.cnf     # utf8mb4, sql_mode strict, transaction_isolation
│   └── backup/                  # Scripts restic
├── docker-compose.yml           # Base commune
├── docker-compose.dev.yml       # Surcharges développement
├── docker-compose.prod.yml      # Surcharges préproduction / production
├── .github/workflows/ci.yml
├── docs/                        # Présente documentation
├── Makefile                     # make up, make test, make migrate, make openapi…
└── .env.example
```

---

## 11. Docker Compose

### 11.1. `docker-compose.yml` (base)

```yaml
name: fds

x-php-common: &php-common
  build:
    context: .
    dockerfile: docker/php/Dockerfile
  env_file: .env
  volumes:
    - storage:/var/www/backend/storage
  depends_on:
    mysql: { condition: service_healthy }
    redis: { condition: service_healthy }
  restart: unless-stopped

services:
  app:
    <<: *php-common
    healthcheck:
      test: ["CMD", "php", "artisan", "app:health", "--quiet"]
      interval: 30s
      timeout: 5s
      retries: 3

  queue:
    <<: *php-common
    command: ["php", "artisan", "queue:work", "redis", "--queue=critical,default,imports,pdf,mail", "--tries=3", "--max-time=3600"]

  scheduler:
    <<: *php-common
    command: ["php", "artisan", "schedule:work"]

  nginx:
    image: nginx:1.27-alpine
    volumes:
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
      - storage:/var/www/backend/storage:ro
    depends_on: [app]
    restart: unless-stopped

  mysql:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_0900_ai_ci", "--log-bin-trust-function-creators=1"]
    volumes:
      - mysql-data:/var/lib/mysql
      - ./docker/mysql/conf.d:/etc/mysql/conf.d:ro
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p${DB_ROOT_PASSWORD}"]
      interval: 10s
      retries: 10
    restart: unless-stopped

  redis:
    image: redis:7.4-alpine
    command: ["redis-server", "--appendonly", "yes", "--requirepass", "${REDIS_PASSWORD}"]
    volumes: [redis-data:/data]
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD}", "ping"]
      interval: 10s
    restart: unless-stopped

  gotenberg:
    image: gotenberg/gotenberg:8
    command: ["gotenberg", "--api-timeout=60s", "--chromium-disable-javascript=true"]
    restart: unless-stopped

volumes:
  mysql-data:
  redis-data:
  storage:
```

> Les versions exactes d'images seront épinglées (tag + digest) au lot 0.

### 11.2. `docker-compose.dev.yml`

```yaml
services:
  app:
    volumes: ["./backend:/var/www/backend"]
    environment: { APP_ENV: local, APP_DEBUG: "true" }
  nginx:
    ports: ["8080:80"]
  mysql:
    ports: ["3306:3306"]
  frontend:
    image: node:22-alpine
    working_dir: /app
    command: ["sh", "-c", "npm ci && npm run dev -- --host 0.0.0.0"]
    volumes: ["./frontend:/app"]
    ports: ["5173:5173"]        # Vite proxifie /api vers nginx:80
  mailpit:
    image: axllent/mailpit:latest
    ports: ["8025:8025"]
```

### 11.3. `docker-compose.prod.yml`

```yaml
services:
  caddy:
    image: caddy:2-alpine
    ports: ["80:80", "443:443"]
    volumes:
      - ./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - ./frontend/dist:/srv/spa:ro          # build React
      - caddy-data:/data
    depends_on: [nginx]
    restart: unless-stopped
  app:
    environment: { APP_ENV: production, APP_DEBUG: "false" }
  backup:
    build: { context: ., dockerfile: docker/backup/Dockerfile }
    env_file: .env.backup                     # RESTIC_REPOSITORY, RESTIC_PASSWORD, clés S3
    volumes:
      - storage:/data/storage:ro
    profiles: ["ops"]                         # lancé par le scheduler via docker compose run
volumes:
  caddy-data:
```

Aucun port MySQL/Redis n'est exposé en production ; seul Caddy écoute sur 80/443.

---

## 12. Exploitation

### 12.1. Environnements

| Environnement | Rôle | Données |
|---|---|---|
| Local (dev) | Développement | Jeux d'essai générés (seeders) |
| CI | Tests automatisés | Base MySQL éphémère |
| Préproduction | Recette, tests de migration, d'import, de restauration [VALIDÉ-CDC §24.5] | Données d'essai ou copie **anonymisée** |
| Production | Exploitation | Réelles |

### 12.2. Dimensionnement indicatif (PV-11)

Pour la volumétrie cible (≤ 2 000 références, ≤ 100 ventes/jour/entreprise, quelques entreprises) : **4 vCPU, 8 Go RAM, 80 Go SSD** suffisent largement, dont MySQL `innodb_buffer_pool_size = 2G`. La préproduction peut être plus petite (2 vCPU, 4 Go). À réévaluer selon le volume d'images.

### 12.3. Sauvegardes (D-039)

- Quotidienne 02:00 : `mysqldump --single-transaction --routines --triggers` + volume `storage` + `.env` chiffré → `restic backup` vers stockage externe ; rétention `--keep-daily 30`.
- Chaque exécution est enregistrée dans `backup_records` ; échec ⇒ notification super-admin + e-mail.
- Restauration : script `docker/backup/restore.sh` documenté ; **test automatisé mensuel** en préproduction (restauration + `inventory:reconcile` + test de fumée) — TEST-020.
- Proposition RPO 24 h / RTO 4 h (**Validé (2026-11-04)**, PV-12) ; option binlogs pour RPO 1 h.

### 12.4. Supervision

- `GET /api/v1/health` (public minimal : `ok`) et `GET /api/v1/admin/health` (super-admin : MySQL, Redis, file d'attente — taille et ancienneté, espace disque, dernière sauvegarde, Gotenberg).
- Journaux JSON (stdout) avec `request_id`, `user_id`, `company_id` ; rotation.
- Erreurs applicatives : canal journal + notification super-admin ; intégration Sentry auto-hébergé optionnelle.
- Supervision externe de disponibilité HTTP (service de ping) et métriques hôte (node-exporter ou équivalent) — choix de l'outil au lot 8.
- Mesure des temps de réponse : middleware qui journalise la durée par route ; seuil d'alerte 1 s côté API pour respecter l'objectif 3 s par page.

### 12.5. CI/CD

1. Lint (Pint, ESLint, Prettier), analyse statique (Larastan 8, `tsc --noEmit`).
2. Validation du contrat OpenAPI (Spectral) + vérification que les types TS générés sont à jour.
3. Tests backend sur MySQL 8.4 réel (unitaires, intégration, isolation, concurrence).
4. Tests frontend (Vitest), build.
5. E2E Playwright sur la pile Docker complète.
6. Audit dépendances.
7. Déploiement préproduction automatique sur `main` ; production **manuelle** après validation humaine [CDC §30].

Migrations : réversibles, testées sur une copie de préproduction avant production ; aucune migration destructive sans sauvegarde préalable vérifiée.
