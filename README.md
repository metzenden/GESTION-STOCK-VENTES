# FDS Business

Plateforme de gestion commerciale multi-entreprises : stocks, achats et importations, coût de revient,
ventes, facturation, paiements, trésorerie et tableaux de bord.

- **Backend** : Laravel (PHP 8.4), monolithe modulaire — `backend/`
- **Frontend** : React + TypeScript (Vite) — `frontend/`
- **Contrat d'API** : OpenAPI 3.1 — `openapi/`
- **Base de données** : MySQL 8.4 · Redis · Gotenberg (PDF)
- **Documentation de conception** : `docs/` (analyse, architecture, modèle de données, règles métier,
  contrats d'API, backlog, tests, registre des décisions)

## Prérequis

Docker Engine avec le plugin Compose, `make`, `git`, `openssl`. Aucun PHP ni Node n'est requis sur la machine :
tout s'exécute dans des conteneurs.

```bash
sudo apt install -y docker.io docker-compose-v2 make
sudo usermod -aG docker $USER   # puis fermer et rouvrir la session
```

## Démarrage

```bash
make init      # crée .env (secrets aléatoires), construit les images, installe les dépendances
make up        # démarre la pile de développement
make migrate   # crée le schéma
```

| Service | Adresse |
|---|---|
| Application (Vite) | http://localhost:5173 |
| API | http://localhost:8080/api/v1/health |
| E-mails de test (Mailpit) | http://localhost:8025 |
| MySQL | 127.0.0.1:3306 |

Les ports ne sont ouverts que sur la machine locale.

### Comptes

- **Démonstration (développement uniquement)** : `make fresh` recrée la base avec deux entreprises et des
  comptes de chaque rôle (`superadmin@fds.local`, `admin@fds.local`, `vendeur@fds.local`,
  `magasinier@fds.local`, `comptable@fds.local`, `admin@thies.local`). Le mot de passe commun figure dans
  `backend/database/seeders/DatabaseSeeder.php`. Ce jeu de données refuse de s'exécuter en production.
- **Super-administrateur réel** : `make artisan c="app:create-super-admin adresse@exemple.com 'Nom Prénom'"`
  (le mot de passe est demandé de façon masquée). Aucun super-admin ne peut être créé depuis l'API.
- Les autres comptes sont créés par invitation : le super-admin crée l'entreprise et invite son
  administrateur, qui invite à son tour ses utilisateurs. Les e-mails arrivent dans Mailpit en développement.

## Commandes utiles

```bash
make test       # tests backend (Pest, MySQL réel) et frontend (Vitest)
make lint       # Pint + ESLint/Prettier
make stan       # analyse statique (Larastan niveau 8, flottants interdits dans les domaines)
make openapi    # validation du contrat (Redocly + Spectral)
make types      # régénère les types TypeScript depuis le contrat
make sh         # shell dans le conteneur PHP
make help       # liste complète
```

## Organisation du backend

Chaque module (`backend/app/Modules/<Module>`) contient `Domain/` (règles pures, sans Laravel),
`Application/`, `Infrastructure/`, `Http/`, `Contracts/` (seule partie visible des autres modules) et
`Database/Migrations/`. Les frontières sont vérifiées par les tests d'architecture
(`backend/tests/Architecture`).

## Production

```bash
PHP_BUILD_TARGET=prod docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Seul Caddy est exposé (HTTPS automatique). Voir `docs/02-architecture-technique.md` §11–12.
