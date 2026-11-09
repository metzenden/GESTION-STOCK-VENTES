# 08 — Registre des risques et des décisions

**Version :** 1.0 — 4 novembre 2026
**Règle de tenue :** toute décision nouvelle prise pendant le développement est ajoutée ici (identifiant suivant, date, statut). Une décision n'est jamais supprimée : elle est marquée *Remplacée par D-xxx*.

**Statuts :** `Proposée` (applicable par défaut, en attente de confirmation) · `À valider` (arbitrage métier requis avant implémentation de la fonctionnalité) · `Validée` (confirmée par le maître d'ouvrage) · `Remplacée`.

---

## 1. Registre des décisions (ADR condensé)

### 1.1. Architecture et technique

| ID | Décision | Justification | Alternative écartée | Statut |
|---|---|---|---|---|
| D-001 | MySQL 8.4 LTS (pas MariaDB) | `CHECK` appliqués, colonnes générées dans les contraintes, `SKIP LOCKED`, homogénéité dev/prod | MariaDB : divergences JSON/CHECK, moins de garanties sur les invariants en base | **Validée** (2026-11-04) |
| D-002 | Clés primaires ULID (`CHAR(26)` ascii_bin) | Identifiants non énumérables exposés à l'API, triables, générables hors base | BIGINT exposé (révèle volumes) ; double identifiant (complexité) | **Validée** (2026-11-04) |
| D-003 | Clés étrangères composites `(company_id, id)` | Rend **impossible en base** toute relation interentreprises | Filtrage applicatif seul | **Validée** (2026-11-04) |
| D-004 | Entreprise active résolue côté serveur ; super-admin via session serveur ; `company_id` client ignoré | MT-007, CDC §21.3 | En-tête `X-Company-Id` (manipulable) | **Validée** (2026-11-04) |
| D-005 | Sanctum SPA (cookie), même origine, session 240 min, Argon2id | Cookies HttpOnly + CSRF, pas de jeton en JS | Jetons Bearer en `localStorage` (vol XSS) | **Validée** (2026-11-04) |
| D-006 | `spatie/laravel-permission` mode teams (équipe = entreprise) ; rôles copiés de modèles | Rôles configurables par entreprise | Développement maison | **Validée** (2026-11-04) |
| D-013 | Décimaux exacts (`brick/math`), stockage `DECIMAL`, arrondi HALF_UP par ligne à la précision de la devise ; montants JSON en chaîne | Exactitude, traçabilité | Flottants ; arrondi au total seulement | **Validée** (2026-11-04) |
| D-014 | Réconciliation des allocations par la méthode du plus fort reste | CR-008 : Σ parts = frais exactement | Ajustement sur la dernière ligne (biais) | **Validée** (2026-11-04) |
| D-015 | Moteur de formules maison (AST + BigDecimal, liste blanche) | Sécurité et précision ; pas d'exécution arbitraire | Symfony ExpressionLanguage (flottants, surface plus large) | **Validée** (2026-11-04) |
| D-030 | Numérotation par ligne de séquence verrouillée dans la transaction du document | Pas de collision ni de trou en cas d'annulation de transaction | `AUTO_INCREMENT` / Redis (trous, non transactionnel) | **Validée** (2026-11-04) |
| D-032 | PDF par Gotenberg (Chromium) ; données figées + empreinte | Rendu A4 fiable, isolé ; régénération identique | dompdf (CSS limité), Browsershot (Chromium dans le conteneur PHP) | **Validée** (2026-11-04) |
| D-033 | Extraction PDF : `pdftotext -layout` + modèles par fournisseur + revue humaine | Pile unique ; fiabilité impossible à garantir de toute façon (CDC §8.3) | Microservice Python (pdfplumber) — option de repli si les exemples réels l'exigent | **Validée** (2026-11-04) |
| D-034 | OpenSpout pour Excel/CSV | Lecture en flux, mémoire faible | PhpSpreadsheet (mémoire) | **Validée** (2026-11-04) |
| D-035 | OpenAPI 3.1 contract-first, validation des réponses en test, types TS générés | Contrat unique back/front | Génération depuis le code (dérive possible) | **Validée** (2026-11-04) |
| D-036 | React 19 + TS strict + Vite + TanStack Query + React Router + RHF/Zod + Tailwind/shadcn | Écosystème mature, thème sombre, accessibilité | — | **Validée** (2026-11-04) |
| D-037 | Monolithe modulaire `app/Modules`, frontières vérifiées par tests d'architecture | Maintenabilité | Microservices (coût d'exploitation injustifié) | **Validée** (2026-11-04) |
| D-038 | Déclencheurs d'immutabilité sur tables append-only | Défense en profondeur (CDC §17.3) | Contrôle applicatif seul | **Validée** (2026-11-04) |
| D-039 | Sauvegardes restic chiffrées externes, 30 jours ; restauration en CLI uniquement ; test mensuel | Sécurité, sobriété | Bouton de restauration dans l'interface (risque d'écrasement global) | **Validée** (2026-11-04) |
| D-043 | SPA et API sur la même origine (Caddy) | Cookies SameSite, pas de CORS | Sous-domaines séparés | **Validée** (2026-11-04) |
| D-051 | `Idempotency-Key` obligatoire sur les actions critiques | Double clic, rejeu réseau | Désactivation du bouton seule | **Validée** (2026-11-04) |
| D-052 | PHP 8.4 ; dernière version majeure stable de Laravel au lot 0 | Support long | — | **Validée** (2026-11-04) |
| D-054 | Ressource d'une autre entreprise ⇒ 404 | Ne pas révéler l'existence | 403 | **Validée** (2026-11-04) |
| D-056 | Ordre de verrouillage déterministe + reprise sur interblocage (3 essais) | Pas d'interblocage durable | — | **Validée** (2026-11-04) |
| D-057 | Créances et dettes = vues SQL | Pas de divergence avec les documents | Tables dédiées (CDC §22.6) | **Validée** (2026-11-04) |
| D-058 | « En retard » calculé, non stocké | Toujours exact | Tâche nocturne de mise à jour | **Validée** (2026-11-04) |
| D-064 | `idempotency_keys` : colonne `owner` (`user:{id}` ou `anon:{ip}`), `user_id` facultatif, clé unique `(owner, idempotency_key)`, réponse stockée en texte (`MEDIUMTEXT`) avec en-têtes `Content-Type`/`Location`, reprise d'une clé « processing » abandonnée après 120 s | Couvre les routes sans session (connexion, mot de passe oublié) et rejoue la réponse octet pour octet | `user_id NOT NULL` + `JSON` (03 v1.0) | Proposée (2026-11-05, lot 0) |
| D-065 | `created_by` / `updated_by` sans clé étrangère vers `users` | `users` n'existe qu'au lot 1 ; l'audit (`audit_logs`) fait foi ; évite des FK circulaires sur `companies` et `users` | FK vers `users` | Proposée (2026-11-05, lot 0) |
| D-066 | `currencies` et `companies` créées au lot 0 (au lieu de L1-01) ; FK `companies.logo_file_id → files` ajoutée au lot 1 | La numérotation (L0-12) et les fichiers (L0-13) en dépendent | — | Proposée (2026-11-05, lot 0) |
| D-067 | brick/math 1.x (énumération `RoundingMode::HalfUp`) ; Laravel 13 | Versions stables au lancement du lot 0 (D-052) | — | Proposée (2026-11-05, lot 0) |
| D-068 | Contrat OpenAPI découpé en fichiers ; Spectral lint le contrat assemblé par Redocly (`openapi/dist/`, non versionné) | Spectral 6 valide mal les `$ref` externes de `paths` en OAS 3.1 | Contrat monofichier | Proposée (2026-11-05, lot 0) |
| D-069 | Révocation des sessions par « époque » : `users.session_epoch` est mémorisée dans la session à la connexion ; désactivation et réinitialisation du mot de passe l'incrémentent, et le middleware `session.current` rejette toute session antérieure (401 `session_expired`) | Sessions en Redis non indexées par utilisateur : invalidation immédiate (ADM-003) sans parcourir le stockage des sessions | Table `sessions` en base indexée par utilisateur | Proposée (2026-11-05, lot 1) |
| D-070 | Table `model_has_permissions` créée mais toujours vide | spatie/laravel-permission la lit ; les permissions ne sont accordées que par des rôles (docs/03 v1.0) | Fork ou surcharge de spatie | Proposée (2026-11-05, lot 1) |
| D-071 | Route métier appelée sans entreprise active (super-admin n'ayant pas choisi) ⇒ `409 company_context_required` | Le contexte est obligatoire et jamais deviné (D-004) | Entreprise par défaut | Proposée (2026-11-05, lot 1) |
| D-072 | L'invitation ne crée pas de compte : le compte est créé, actif, à l'acceptation ; une adresse déjà utilisée par un compte (toute entreprise) est refusée à l'invitation ; une nouvelle invitation révoque la précédente ; l'acceptation ouvre directement la session | Pas de compte fantôme « invité » sans mot de passe ; règle « un e-mail = un compte » (docs/03) | Compte `invited` créé dès l'invitation | Proposée (2026-11-05, lot 1) |
| D-073 | Formats de numérotation (préfixe, largeur) dans `document_sequence_formats` ; une modification s'applique aux séquences de l'année en cours et suivantes, jamais au compteur | Préfixes modifiables sans code (L1-14) sans toucher aux numéros émis | Préfixe stocké dans `company_settings.extra` | Proposée (2026-11-05, lot 1) |
| D-074 | Rôle « Administrateur » verrouillé (toujours toutes les permissions d'entreprise) ; autres rôles système modifiables mais non supprimables ; ADM-005 évalué sur ce rôle | Garantit qu'un administrateur peut toujours tout gérer | Rôles système entièrement figés | Proposée (2026-11-05, lot 1) |
| D-075 | Alerte de connexion super-admin envoyée à l'adresse du compte concerné | D-040 ne précise pas le destinataire ; pas d'adresse d'exploitation définie à ce stade | Adresse d'exploitation dédiée (à ajouter quand elle existera) | Proposée (2026-11-05, lot 1) |
| D-076 | Tests : variables forcées dans `phpunit.xml` déclarées aussi en `<server>` | Le conteneur définit `APP_ENV=local`, `CACHE_STORE=redis`… qui l'emportaient sur `<env>` : les tests ne tournaient pas en environnement `testing` | — | Proposée (2026-11-05, lot 1) |
| D-077 | Inventaire compté par lot : les lots reçus après le figeage n'apparaissent pas dans la feuille ; la validation exige que toutes les lignes soient comptées | Écart calculable lot par lot (INV-102) sans ambiguïté | Comptage global par variante | Proposée (2026-11-05, lot 2) |
| D-078 | Réception d'un transfert : toute la quantité envoyée entre au dépôt d'arrivée, puis l'écart en sort par un ajustement « perte » (soumis aux règles d'approbation) | Perte traçable sur un lot précis, total entreprise inchangé hors perte (TRF-004, TRF-005) | Mouvement de perte « en transit » sans dépôt | Proposée (2026-11-05, lot 2) |
| D-079 | Livraison échouée : la quantité revient de « en livraison » à « réservé » (la commande reste servie en priorité) | La réservation de la commande n'est pas perdue | Retour au disponible | Proposée (2026-11-05, lot 2) |
| D-080 | Nouveau prix daté : le prix ouvert antérieur est clos la veille ; tout autre chevauchement est refusé (`price_overlap`) | Saisie simple d'un changement de tarif sans chevauchement | Refus systématique | Proposée (2026-11-05, lot 2) |
| D-081 | Photos réencodées en WebP (1 600 px max.) avec miniature de 320 px ; 10 photos max. par produit | Suppression des métadonnées EXIF, poids réduit pour mobile (L2-05) | Stockage de l'original | Proposée (2026-11-05, lot 2) |
| D-082 | Nouvelle permission `warehouses.manage` (Administrateur) ; consultation des dépôts avec `stock.view` | La matrice 04 §2 ne couvrait pas la gestion des dépôts | Rattacher à `settings.manage` | Proposée (2026-11-05, lot 2) |
| D-083 | `variant_units.locked_at` posé par `VariantCatalog::lockUnit()` au premier usage dans un document (CAT-007) | Les modules de vente et d'achat figent le conditionnement qu'ils utilisent | Calcul à la volée depuis les documents | Proposée (2026-11-05, lot 2) |
| D-084 | Recherche produits par `LIKE` (référence, nom, SKU) et code-barres exact ; l'index FULLTEXT ngram est créé pour une évolution ultérieure | Résultats prévisibles sur les références courtes | FULLTEXT dès le lot 2 | Proposée (2026-11-05, lot 2) |
| D-085 | Horodatages des documents de stock et des mouvements écrits à la microseconde | Le constructeur de requêtes tronquait à la seconde, ce qui faussait l'écart d'inventaire (mouvements de la même seconde que le figeage) | — | Proposée (2026-11-05, lot 2) |
| D-086 | Une ressource d'une autre entreprise répond 404 avant toute validation du corps de la requête | Ne rien révéler, même par un 422 (D-054) ; vérifié par le test générique de non-fuite | — | Proposée (2026-11-05, lot 2) |

### 1.2. Multi-entreprises et administration

| ID | Décision | Justification | Statut |
|---|---|---|---|
| D-040 | Super-admin : limiteur dédié, alerte de connexion ; TOTP recommandé mais non imposé | PV-14 ; double authentification non retenue au CDC | **Validée** (2026-11-04) |
| D-041 | Lecture seule d'une entreprise suspendue opposable aussi au super-admin (sauf réactivation, comptes) | Cohérence (C-09) | **Validée** (2026-11-04) |
| D-042 | Consolidation par devise ; conversion seulement avec devise de présentation et taux daté explicites | CDC §15.4, TEST-023 | **Validée** (2026-11-04) |
| D-061 | Auto-approbation journalisée (`self_approved`) ; option par règle pour l'interdire, désactivée par défaut | C-08 | **Validée** (2026-11-04) |

### 1.3. Stocks

| ID | Décision | Justification | Statut |
|---|---|---|---|
| D-007 | Quantités entières en unité de base ; conditionnements à facteur entier | Exactitude, pas de stock fractionnaire | **Validée** (2026-11-04) |
| D-008 | Toute fiche produit a au moins une variante ; stock/prix/lots par variante | Modèle unique | **Validée** (2026-11-04) |
| D-009 | Mouvements = source de vérité ; positions et soldes de lot = projections verrouillables ; rapprochement nocturne | Performance + vérifiabilité | **Validée** (2026-11-04) |
| D-010 | Seuls les mouvements définitifs modifient le physique ; réservations, blocages et en-livraison sont des compartiments exclusifs ; expédition = réservé → en livraison | C-07, ST-007 | **Validée** (2026-11-04) |
| D-011 | Affectation de lot à la sortie, proposée en FIFO, modifiable ; valorisation au coût du lot affecté | PV-03, C-06 | **Validée** (2026-11-04) |
| D-046 | Endommagés à la réception : entrent dans le lot et sont bloqués | Traçabilité physique | **Validée** (2026-11-04) |
| D-047 | Retour dans le lot d'origine au coût d'origine | RET-003 | **Validée** (2026-11-04) |
| D-048 | Ajustement positif : lot d'ajustement au coût du dernier lot définitif (modifiable) | Valorisation cohérente | **Validée** (2026-11-04) |
| D-055 | Réservation au niveau dépôt × variante (lot affecté à l'expédition) | Simplicité, FIFO au plus tard | **Validée** (2026-11-04) |
| D-060 | Transferts : `transfer_out` à l'expédition, transit porté par le transfert, `transfer_in` à la réception | Total entreprise exact | **Validée** (2026-11-04) |
| D-062 | Codes-barres par conditionnement de variante | M-05 | **Validée** (2026-11-04) |

### 1.4. Achats et coûts

| ID | Décision | Justification | Statut |
|---|---|---|---|
| D-012 | Réception validée vendable avec coût provisoire ; ajustements de valorisation à la finalisation | Ne pas bloquer la vente ; CDC §8.5 « à valider » | **Validée** (2026-11-04) |
| D-026 | Dette fournisseur = engagements (confirmation du BC, frais d'approche) − règlements, par devise ; écart de change affiché, non comptabilisé | PV-08, A-07, A-13 | **Validée** (2026-11-04) |
| D-027 | Frais d'approche exclus des charges du résultat | Éviter la double déduction (§14.5) | **Validée** (2026-11-04) |
| D-087 | Règlements fournisseurs (L3-03) reportés au lot 5a ; le lot 3 livre engagements, avoirs manuels et relevé par devise | Un règlement débite un compte financier (`financial_accounts`, L5a-02) qui n'existe pas encore ; `amount_paid` reste à 0 d'ici là | Proposée (2026-11-06, lot 3) |
| D-088 | Relevé fournisseur et engagements réservés à la permission `supplier_payments.manage` | Dettes et soldes sont des informations financières sensibles (GEN-005) ; le magasinier voit les fournisseurs, pas leur dette | Proposée (2026-11-06, lot 3) |
| D-089 | Réception directe d'une commande (sans arrivage) : lot au coût **définitif** = prix d'achat converti, sans frais d'approche | Aucun calcul de coût de revient n'existe hors arrivage ; taux : dernier enregistré, sinon taux estimé du BC, sinon refus | Proposée (2026-11-06, lot 3) |
| D-090 | Coût provisoire à la réception d'un arrivage : achat au dernier taux enregistré (sinon taux estimé du BC) + quote-part des frais connus au CBM **attendu** et frais directs, par pièce attendue | Mise en œuvre de CR-013 ; validation refusée s'il n'existe aucun taux (jamais de taux inventé) | Proposée (2026-11-06, lot 3) |
| D-091 | Extraction des packing lists PDF (L3-07) reportée ; un PDF est refusé avec un message explicite | Les modèles d'extraction exigent des exemples réels de fournisseurs (PV-09) | Proposée (2026-11-06, lot 3) — **exemples à fournir** |
| D-092 | Lecture des packing lists synchrone (5 000 lignes maximum) ; file d'attente et simulation d'import généralisées avec les imports initiaux (L6-08) | Fichiers de quelques centaines de lignes ; la revue humaine est de toute façon obligatoire avant écriture | Proposée (2026-11-06, lot 3) |
| D-093 | Ligne partiellement reçue : le volume (CBM) total de la ligne est conservé pour la répartition des frais | Le CDC ne prévoit pas de proratisation ; le transport a été payé pour le volume expédié | **À valider** (2026-11-06, lot 3) |
| D-094 | Ajustements de valorisation calculés sur toutes les sorties et contre-écritures d'un lot, hors réception et transferts internes ; leur imputation au coût des ventes se fera au lot 5 | Un transfert déplace le même lot au même coût : aucun impact entreprise | Proposée (2026-11-06, lot 3) |
| D-095 | Finalisation refusée (409) si les entrées du calcul ont changé depuis (empreinte `inputs_hash`) | Garantit que le coût définitif est celui affiché et reproductible | Proposée (2026-11-06, lot 3) |
| D-096 | Anomalies bloquantes (CR-006) : réception incomplète, CBM ou prix manquant, taux manquant ou seulement estimé, frais en brouillon ou estimé, frais direct sur une ligne non reçue | Liste explicite renvoyée au calcul ; le calcul reste consultable | Proposée (2026-11-06, lot 3) |
| D-097 | Nouvelle permission `goods_receipts.accept_surplus` (IMP-008), attribuée par défaut au seul administrateur | Le surplus engage une quantité non commandée | Proposée (2026-11-06, lot 3) |
| D-098 | Prix d'achat des arrivages et packing lists visibles avec `purchase_orders.view` ; sinon masqués (`meta.redacted`) | Même règle que les commandes d'achat (GEN-005) | Proposée (2026-11-06, lot 3) |
| D-099 | Une packing list validée met à jour l'article attendu déjà issu d'une commande pour la même variante, au lieu d'en créer un second | Évite de compter deux fois la même marchandise (BC + packing list) | Proposée (2026-11-06, lot 3) |
| D-100 | Clôture d'une commande partiellement reçue : option « abandonner le reliquat » qui crée un engagement négatif de la valeur non reçue | PUR-005 « engagement ajustable » ; sinon l'engagement reste entier (facture fournisseur complète) | Proposée (2026-11-06, lot 3) |
| D-101 | Formules de coût : moteur maison restreint (nombres, 9 variables, + − × ÷, min, max, round) ; activation possible seulement après un test | CR-014, aucune exécution de code ; version active immuable en base (déclencheur) | Proposée (2026-11-06, lot 3) |

### 1.5. Ventes, facturation, paiements

| ID | Décision | Justification | Statut |
|---|---|---|---|
| D-102 | Trésorerie de base (L5a-01, L5a-02) livrée avec le lot 4 : 4 comptes par défaut, mouvements en ajout seul avec solde après mouvement, découvert refusé sous verrou et par contrainte en base | Prérequis des paiements clients (ordre des lots) | Proposée (2026-11-07, lot 4) |
| D-103 | Prix de vente saisis hors taxes ; taxe par ligne choisie parmi les taux saisis par l'entreprise, jamais de taux par défaut | BIL-005 ; VR-01 encore ouvert | Proposée (2026-11-07, lot 4) |
| D-104 | Les lignes de prestation (frais de livraison) ne passent pas par les livraisons : elles sont réputées rendues à la première remise | Pas de stock à sortir ; CA reconnu avec la marchandise | Proposée (2026-11-07, lot 4) |
| D-105 | Remise partielle d'une livraison : la quantité non remise redevient automatiquement réservée | Évite un échec fictif pour un manquant ponctuel | Proposée (2026-11-07, lot 4) |
| D-106 | Statut « payée » atteint quand règlements + avoirs imputés = total | BIL-007 appliqué au solde réellement dû | Proposée (2026-11-07, lot 4) |
| D-107 | Une contre-passation est un règlement négatif numéroté REC, lié au règlement d'origine, avec son reçu | Traçabilité PAY-006 | Proposée (2026-11-07, lot 4) |
| D-108 | Nouvelles permissions : `customer_payments.reverse` (administrateur, comptable), `returns.manage` (administrateur, vendeur, magasinier), `financial_accounts.manage` (administrateur) | Séparer l'encaissement de sa correction | Proposée (2026-11-07, lot 4) |
| D-109 | Lien de partage signé valable 30 jours ; signature absente ou expirée → 404 ; PDF régénéré à la demande | Partage WhatsApp sans compte, sans rien révéler (D-054) | Proposée (2026-11-07, lot 4) |
| D-110 | Vente comptoir : refusée si une approbation est requise ; paiement facultatif (vente à crédit possible) | SAL-008 exige une transaction unique et complète | Proposée (2026-11-07, lot 4) |
| D-111 | Un client « Client comptoir » (code COMPTOIR) est créé par entreprise et ne peut pas être désactivé | Vente comptoir sans saisie de client | Proposée (2026-11-07, lot 4) |
| D-112 | Avant le moteur d'approbation (L6) : commande soumise à règle → « en attente » avec réservation provisoire ; annulation, contre-passation et finalisation de coût → refus `approval_required` | Les points d'appel sont en place ; aucune règle n'existe encore | Proposée (2026-11-07, lot 4) |
| D-113 | Échéance : vente comptoir = date d'émission ; sinon délai du client, à défaut délai de l'entreprise | BIL-004 | Proposée (2026-11-07, lot 4) |
| D-114 | Les documents n'affichent que les mentions saisies par l'entreprise ; aucune mention réglementaire n'est ajoutée d'office | VR-02 encore ouvert | Proposée (2026-11-07, lot 4) — **à valider par l'expert-comptable** |
| D-115 | Téléphones normalisés en E.164 ; un numéro à 9 chiffres commençant par 3 ou 7 reçoit +221 | Recherche par téléphone et liens WhatsApp fiables | Proposée (2026-11-07, lot 4) |
| D-116 | Indicateurs de gestion calculés à la volée sur les tables des modules (module Reporting en lecture seule) ; pas de tables d'agrégats en V1 | Volumétrie V1 modeste ; une seule définition par indicateur (docs/04 §11.1) | Proposée (2026-11-08, lot 5) |
| D-117 | CA de gestion des prestations rattaché à la date de la première remise de la commande | Cohérent avec D-104 | Proposée (2026-11-08, lot 5) |
| D-118 | Document d'ouverture : soldes positifs ou nuls uniquement ; stock initial en lots « opening » au coût saisi | FIN-007 ; un découvert d'ouverture se saisit par correction approuvée | Proposée (2026-11-08, lot 5) |
| D-119 | Règles d'approbation par opération (remise en %, prix modifié, annulation, ajustement en quantité, avoir et correction financière en montant), rôle facultatif, priorité | APR-001, APR-002 ; seuils initiaux à fournir (PV-13) | Proposée (2026-11-09, lot 6) — **seuils à fournir** |
| D-120 | Contre-passation de paiement, changement de moyen et contre-passation de dépense relèvent de la règle « correction financière » | APR-001 | Proposée (2026-11-09, lot 6) |
| D-121 | Une opération soumise à approbation répond normalement avec `meta.approval_request_id` (et l'en-tête `X-Approval-Request`) ; rien n'est exécuté avant la décision | Le client sait qu'une demande est déposée sans traiter une erreur | Proposée (2026-11-09, lot 6) |
| D-122 | Notifications in-app en base et e-mails mis en file ; la recherche globale et les rapports respectent les permissions de chaque type de document | APR-009, TEST-017 | Proposée (2026-11-09, lot 6) |
| D-123 | Exports CSV UTF-8 à séparateur point-virgule, cellules commençant par = + - @ neutralisées ; exports synchrones jusqu'à 20 000 lignes | Excel français ; injection de formules ; volumétrie V1 | Proposée (2026-11-09, lot 6) |
| D-124 | Imports initiaux tout ou rien, simulation par défaut ; le stock d'ouverture passe par le document d'ouverture | TEST-019 ; une seule voie d'ouverture (FIN-007) | Proposée (2026-11-09, lot 6) |
| D-016 | État « En attente d'approbation » avant confirmation ; réservation provisoire ; facture à l'approbation | C-01 | **Validée** (2026-11-04) |
| D-017 | Commande confirmée non modifiable : avoir partiel / commande complémentaire | C-03 | **Validée** (2026-11-04) |
| D-018 | CA et marge de gestion reconnus à la remise, au prorata ; facturé et encaissé présentés séparément | C-04, PV-02 | **Validée** (2026-11-04) + VÉRIF. RÉGL. |
| D-019 | Avoir imputé d'abord sur la facture d'origine ; excédent = crédit client | C-02, PV-05 | **Validée** (2026-11-04) |
| D-020 | Imputation d'avoir ≠ moyen de paiement (hors PAY-001) | C-05 | **Validée** (2026-11-04) |
| D-021 | Trop-perçu refusé | Simplicité, pas de crédit implicite | **Validée** (2026-11-04) |
| D-022 | Livraisons partielles en V1 | PV-15 | **Validée** (2026-11-04) |
| D-023 | Un dépôt source par commande | Simplicité | **Validée** (2026-11-04) |
| D-024 | Retrait sur place sans état « en livraison » | A-10 | **Validée** (2026-11-04) |
| D-025 | Litige = indicateur + dossier, pas un état | A-11 | **Validée** (2026-11-04) |
| D-028 | Effets en attente hors tables définitives (demandes/sources) ; rubrique « En attente » calculée | §16.4, FIN-002 | **Validée** (2026-11-04) |
| D-029 | Transitions d'approbation conditionnelles + unicité « une demande ouverte par sujet » | §16.5 | **Validée** (2026-11-04) |
| D-031 | Moteur de taxes présent, aucun taux préconfiguré | M-01, pas de règle inventée | **Validée** (2026-11-04) + VÉRIF. RÉGL. |
| D-044 | Solde négatif de compte interdit par défaut (paramétrable) | Cohérence de caisse | **Validée** (2026-11-04) |
| D-045 | Pas de remboursement en espèces (avoirs uniquement) | CDC §12.5 | Validée (CDC) |
| D-049 | Grille tarifaire par défaut par client, modifiable par commande ; prix par unité | A-03 | **Validée** (2026-11-04) |
| D-050 | Frais de livraison = ligne produit « service » | PV-16 | **Validée** (2026-11-04) + VÉRIF. RÉGL. |
| D-053 | Échec de livraison : retour au dépôt, réservation reconstituée | A-12 | **Validée** (2026-11-04) |
| D-059 | Contre-passation de paiement et changement de moyen sous approbation « correction financière » | M-08, M-09, PAY-001 | **Validée** (2026-11-04) |
| D-063 | Vente comptoir = composition transactionnelle des services standards | M-06 | **Validée** (2026-11-04) |

---

## 2. Registre des risques

Probabilité (P) et impact (I) : 1 faible — 3 élevé. Criticité = P × I.

| ID | Risque | P | I | C | Mesures | Responsable | Statut |
|---|---|:-:|:-:|:-:|---|---|---|
| R-01 | **Fuite de données entre entreprises** | 2 | 3 | **6** | D-003, D-004, D-054, portée globale *fail closed*, test générique de non-fuite sur toutes les routes, fichiers privés, cache préfixé, jobs `TenantAware`, revue sécurité systématique | Tech lead | Ouvert |
| R-02 | **Survente / stock négatif en concurrence** | 2 | 3 | **6** | Verrous ordonnés, `CHECK` SQL, tests de concurrence réels répétés | Dev Inventory | Ouvert |
| R-03 | **Doubles écritures** (réception, paiement, approbation) | 2 | 3 | **6** | Idempotence HTTP, transitions conditionnelles, unicité des mouvements | Tech lead | Ouvert |
| R-04 | **Non-conformité fiscale** des factures/avoirs (taxes, mentions, numérotation, facturation électronique éventuelle) | 2 | 3 | **6** | Points VÉRIF. RÉGL. levés par un expert-comptable avant L7 ; paramétrage sans code | Maître d'ouvrage | Ouvert — **bloquant mise en production** |
| R-05 | Erreurs d'arrondi / calculs financiers | 2 | 2 | 4 | Décimaux exacts, tests de propriétés, scénario de référence calculé à la main | Dev Billing | Ouvert |
| R-06 | Marges faussées par coût provisoire | 2 | 2 | 4 | Statut visible, ajustements traçables, alerte arrivages non finalisés | Dev Costing | Ouvert |
| R-07 | Extraction PDF de packing lists insuffisante | 3 | 1 | 3 | Revue humaine, modèles fournisseurs, préférence Excel, repli Python | Dev Imports | Ouvert |
| R-08 | Ambiguïtés métier non tranchées retardant les lots (D-0xx « À valider ») | 3 | 2 | **6** | Atelier de validation avant chaque lot (`06` §4) ; décisions par défaut appliquées sinon | Maître d'ouvrage | Clos (décisions validées le 2026-11-04) |
| R-09 | Périmètre large sans mise en production partielle (délai, effet tunnel) | 3 | 2 | **6** | Démonstrations par lot en préproduction, recette progressive, possibilité de pilote interne sur données réelles en préproduction | Chef de projet | Ouvert |
| R-10 | Perte de données (VPS unique) | 1 | 3 | 3 | Sauvegardes externes chiffrées, test de restauration mensuel, RPO/RTO définis | Exploitant | Ouvert |
| R-11 | Auto-approbation affaiblissant le contrôle | 2 | 2 | 4 | Journalisation, rapport, option d'interdiction | Maître d'ouvrage | Accepté (CDC) |
| R-12 | Écarts de change non comptabilisés | 2 | 2 | 4 | Affichage, définition avec l'expert-comptable | Maître d'ouvrage | Ouvert |
| R-13 | Volume d'avoirs (facturation à la confirmation) gênant la lecture | 2 | 1 | 2 | Avoirs typés, rapports séparés | — | Accepté |
| R-14 | Performance mobile en réseau faible (photos) | 2 | 2 | 4 | Miniatures, chargement progressif, pagination, mesures k6/Lighthouse | Dev Front | Ouvert |
| R-15 | Délivrabilité des e-mails (invitations, réinitialisation) | 2 | 2 | 4 | SMTP transactionnel avec SPF/DKIM/DMARC | Exploitant | Ouvert |
| R-16 | Agents IA introduisant des règles non spécifiées | 2 | 2 | 4 | Règle CDC §30 ; tout écart au `04` doit référencer une décision de ce registre ; revue obligatoire | Tech lead | Ouvert |
| R-17 | Dépendance à des bibliothèques tierces (Gotenberg, spatie…) | 1 | 2 | 2 | Versions épinglées, audit, encapsulation derrière des interfaces | Tech lead | Ouvert |

---

## 3. Points à vérifier (réglementaire) — suivi

| Réf. | Sujet | Interlocuteur | Échéance souhaitée | Statut |
|---|---|---|---|---|
| VR-01 | Taxes applicables (TVA, taux, exonérations) et présentation | Expert-comptable | Avant L4 (moteur), contenu avant L7 | Ouvert |
| VR-02 | Mentions obligatoires factures / avoirs / reçus | Expert-comptable | Avant L7 | Ouvert |
| VR-03 | Règles de numérotation (continuité, séries) | Expert-comptable | Avant L4 | Ouvert |
| VR-04 | Obligation éventuelle de facturation électronique / certifiée | Expert-comptable / administration fiscale | Avant L4 | Ouvert |
| VR-05 | Rattachement du CA pour facture avant livraison | Expert-comptable | Avant L5 | Ouvert |
| VR-06 | Traitement des avoirs (annulation, commerciaux) | Expert-comptable | Avant L4 | Ouvert |
| VR-07 | Écarts de change sur dettes en devise | Expert-comptable | Avant L5 | Ouvert |
| VR-08 | Droits et taxes de douane dans le coût de revient | Expert-comptable / transitaire | Avant L3 | Ouvert |
| VR-09 | Durée de conservation des documents et journaux | Juriste / expert-comptable | Avant L8 | Ouvert |
| VR-10 | Protection des données personnelles clients | Juriste | Avant L8 | Ouvert |

---

## 4. Journal des modifications

| Date | Version | Modification |
|---|---|---|
| 2026-11-04 | 1.0 | Création du registre (phase de conception) |
| 2026-11-04 | 1.1 | Validation par le maître d'ouvrage de **toutes** les décisions, avec l'option recommandée pour chacune. Précisions : D-040 TOTP proposé mais non imposé ; D-061 option anti-auto-approbation disponible, désactivée par défaut ; D-044 solde négatif interdit par défaut ; D-049 sans bascule automatique au tarif de gros ; RPO 24 h / RTO 4 h ; plafond de crédit client (M-10) hors V1 |
| 2026-11-05 | 1.2 | Lot 0 exécuté : ajout de D-064 à D-068 (écarts techniques constatés pendant l'implémentation) |
| 2026-11-05 | 1.3 | Lot 1 implémenté : ajout de D-069 à D-076 |
| 2026-11-05 | 1.4 | Lot 2 implémenté : ajout de D-077 à D-086 |
| 2026-11-06 | 1.5 | Lot 3 implémenté : ajout de D-087 à D-101 |
| 2026-11-07 | 1.6 | Lot 4 implémenté : ajout de D-102 à D-115 |
| 2026-11-09 | 1.7 | Lots 5 et 6 implémentés : ajout de D-116 à D-124 |
