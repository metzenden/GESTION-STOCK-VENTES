# 07 — Stratégie de tests

**Version :** 1.0 — 4 novembre 2026

---

## 1. Objectifs

1. Prouver automatiquement les invariants critiques : **isolation**, **pas de stock négatif**, **pas de double écriture**, **exactitude financière**.
2. Couvrir les 24 scénarios du CDC (TEST-001 à TEST-024) par au moins un test automatisé.
3. Empêcher les régressions à chaque PR (CI bloquante).

---

## 2. Niveaux et outils

| Niveau | Portée | Outil | Base | Volume visé |
|---|---|---|---|---|
| Unitaire | `Domain/` : calculs, arrondis, FIFO, allocation, machines à états, moteur d'expressions | Pest | aucune | ~50 % des tests |
| Intégration | Handlers applicatifs (transaction, verrous, contraintes SQL, audit) | Pest + `RefreshDatabase` | **MySQL 8.4 réel** (jamais SQLite : CHECK, verrous, colonnes générées diffèrent) | ~30 % |
| API / contrat | Routes HTTP : validation, permissions, codes d'erreur, conformité OpenAPI | Pest + validateur OpenAPI | MySQL | ~15 % |
| Concurrence | Courses réelles entre processus | Pest + processus parallèles (`pcntl_fork` / `Process::pool`) | MySQL | ciblé |
| Frontend | Composants, formulaires, formatage monétaire | Vitest + Testing Library | — | — |
| Bout en bout | Parcours utilisateurs complets dans le navigateur | Playwright (Chromium + WebKit mobile) | Pile Docker | ~40 scénarios |
| Sécurité | Isolation, authz, OWASP | Pest + ZAP baseline + revue manuelle | Préproduction | par lot + L7 |
| Performance | Temps de réponse, charge | k6 | Préproduction, volumétrie ×3 | L6, L7 |
| Mutation | Qualité des tests des modules critiques | Infection (Inventory, Billing, Payments, Costing, Shared/Money) | — | MSI ≥ 80 % |
| Restauration | Sauvegarde → restauration → vérification | Script + test de fumée | Préproduction | mensuel |

### 2.1. Couverture

- Lignes : ≥ 85 % sur `Domain/` et `Application/` des modules Inventory, Costing, Sales, Billing, Payments, Finance, Approvals, Tenancy ; ≥ 70 % global.
- 100 % des règles identifiées dans `04` (un test au moins référence l'ID de règle dans son nom : `it('ST-006 refuse une réservation dépassant le disponible')`).

### 2.2. Données de test

- **Factories** par module, toujours créées dans une entreprise explicite (`Company::factory()->withContext()`).
- **Scénario de référence** (`ReferenceScenarioSeeder`) : 2 entreprises (XOF et EUR), 3 dépôts, 50 produits avec variantes et cartons, 1 arrivage avec frais, 20 commandes à divers états. Les montants attendus (CA, marge, valeur de stock) sont **calculés à la main** dans un tableur versionné (`tests/fixtures/reference-scenario.xlsx`) et comparés au franc près.
- **Jeu de volumétrie** : 2 000 produits, 3 dépôts, 1 an d'activité à 100 ventes/jour.

---

## 3. Tests des règles métier critiques

### 3.1. Stock

| Test | Niveau | Vérifie |
|---|---|---|
| Compartiments : toutes transitions (réserver, expédier, remettre, échouer, libérer, bloquer) préservent `physique = dispo + réservé + bloqué + en livraison` | Unitaire + propriété (séquences aléatoires de 1 000 opérations) | ST-007, C-07 |
| Réservation > disponible ⇒ `InsufficientStock`, aucune ligne écrite | Intégration | ST-006, TEST-005 |
| CHECK SQL : UPDATE direct rendant le disponible négatif ⇒ erreur | Intégration | Défense en base |
| Double mouvement même source ⇒ erreur d'unicité | Intégration | IMP-004 |
| FIFO : 3 lots, sortie de 2,5 lots ⇒ affectation et coût exacts | Unitaire | INV-002 |
| Transfert avec écart ⇒ total entreprise inchangé hors perte déclarée | Intégration | TRF-005 |
| Rapprochement détecte un écart injecté | Intégration | INV-008 |
| Carton/pièce : 2 cartons ×6 + 3 pièces ⇒ 15 réservées puis 15 sorties | Intégration | TEST-022 |

### 3.2. Financier

| Test | Vérifie |
|---|---|
| Arrondi HALF_UP par devise (0 décimale XOF, 2 EUR) ; tables de cas limites (x,5 ; négatifs) | D-013 |
| Allocation plus fort reste : propriété Σ parts = total pour 10 000 jeux aléatoires | CR-008 |
| TEST-009 : 2 m³ / 3 m³, 500 000 ⇒ 200 000 / 300 000 | CR |
| Coût unitaire pièce et carton, taux enregistré, reproductibilité (`inputs_hash`) | CR-003, CR-004 |
| CBM manquant / nul ⇒ finalisation bloquée, message explicite | TEST-010, CR-001, CR-006 |
| Ajustements de valorisation provisoire → final : Σ Δ = (coût final − provisoire) × quantités sorties | CR-013 |
| Facture : Σ lignes arrondies = total ; remise globale répartie ; taxe nulle par défaut | SAL-007, BIL-005 |
| TEST-011 : 100 000 − 40 000 ⇒ solde 60 000, `partially_paid` | BIL-007 |
| TEST-012 : Wave puis espèces ⇒ 422 `payment_method_locked` | PAY-001 |
| Trop-perçu refusé (applicatif + CHECK) | PAY-003 |
| TEST-013 : avoir 20 000, imputation 8 000 ⇒ disponible 12 000 ; imputation 13 000 ⇒ refus | CRN-003 |
| Annulation d'une commande payée ⇒ avoir imputé, excédent en crédit client | SAL-004 |
| Solde de compte = Σ mouvements ; opérations en attente sans effet | FIN-001, FIN-002 |
| Indicateurs : scénario de référence (CA net, CMV, marge, charges, résultat) au franc près | `04` §11.1 |
| Frais d'approche exclus des charges (pas de double déduction) | D-027 |

### 3.3. Machines à états

Pour chaque machine (`04`) : test **table-driven** de toutes les paires (état, action) — transitions autorisées réussissent, toutes les autres renvoient `409 invalid_transition` sans effet de bord.

### 3.4. Approbations

| Test | Vérifie |
|---|---|
| TEST-015 : prix > seuil ⇒ 202, réservation provisoire, aucune facture, notification | SAL-001, APR-004 |
| TEST-016 : rejet ⇒ réservation libérée, commande en brouillon, décision journalisée | APR-006 |
| Approbation ⇒ facture émise une seule fois ; double approbation concurrente ⇒ un seul effet | APR-005 |
| Auto-approbation marquée `self_approved` ; interdite si option active | APR-003 |
| Entreprise suspendue ⇒ décision refusée | APR-011 |

---

## 4. Isolation multi-entreprises (TEST-001, TEST-002, MT-*)

### 4.1. Test générique de non-fuite (automatique, toutes routes)

Principe : le test **énumère la table de routage** Laravel et, pour chaque route du préfixe `/api/v1` hors liste blanche (`auth/*`, `health`, `public/*`) :

1. Crée deux entreprises A et B et, via un registre de *factories* par paramètre de route (`{product}` → `Product::factory()`), une ressource dans B.
2. Appelle la route en tant qu'administrateur de A (toutes permissions), avec l'identifiant de la ressource de B.
3. Exige `404` (ou `403` pour les routes plateforme), et vérifie qu'aucune donnée de B n'apparaît dans la réponse (recherche des identifiants et libellés de B dans le corps).
4. Pour les listes : crée des données dans A et B et vérifie que seules celles de A sont renvoyées.

Une route nouvelle **sans entrée dans le registre** fait échouer le test : impossible d'ajouter une route non couverte.

### 4.2. Autres vecteurs

| Vecteur | Test |
|---|---|
| Injection de `company_id` dans le corps / la requête | Ignoré : la ressource est créée dans l'entreprise du contexte |
| Clé étrangère croisée (ligne de commande pointant un produit de B) | Rejet par validation **et** par FK composite (test SQL direct) |
| Fichiers | Téléchargement d'un fichier de B ⇒ 404 ; chemin deviné ⇒ inaccessible (pas d'URL publique) |
| Exports | Export par A ne contient aucune ligne de B |
| Jobs | Job sérialisé pour A puis exécuté : n'écrit que dans A ; job sans contexte ⇒ échec |
| Cache | Clé de A non lisible depuis B (préfixe) |
| Recherche globale | Ne renvoie que A |
| Notifications | Utilisateur de A ne voit pas celles de B |
| Super-admin | TEST-002 : bascule A → B ; données conformes ; journal MT-008 ; utilisateur ordinaire ⇒ 403 sur `/context/company` et `/admin/*` |
| Consolidation | TEST-023 : devises différentes ⇒ sous-totaux par devise ; conversion seulement avec taux explicite affiché |

---

## 5. Concurrence et idempotence

Méthode : N processus PHP indépendants (connexions MySQL distinctes) synchronisés par une barrière (fichier/Redis) puis lancés simultanément ; chaque test est répété 20 fois en CI nocturne.

| Test | Attendu |
|---|---|
| TEST-006 : 10 processus réservent 5 pièces sur un stock de 5 | Exactement 1 succès, 9 `insufficient_stock` ; réservé = 5 |
| Réservations croisées sur 2 variantes dans des ordres opposés | Aucun interblocage non rattrapé (ordre de verrouillage + reprise) |
| TEST-021 : 50 factures simultanées | 50 numéros distincts consécutifs, sans trou |
| TEST-008 : double validation de réception (même clé, clés différentes, sans clé) | Un seul jeu de lots/mouvements |
| Double paiement simultané du solde total | Un succès, un refus `overpayment` |
| Double imputation d'un même avoir | Σ imputations ≤ total |
| Double approbation simultanée | Un seul effet |
| Paiements simultanés sur un même compte avec `allow_negative = false` | Solde jamais négatif |

---

## 6. Sécurité

| Domaine | Tests |
|---|---|
| Authentification | Force brute (429), énumération d'e-mails (réponses identiques), expiration 240 min, régénération de session, révocation à la désactivation, jeton de réinitialisation usage unique / expiré |
| CSRF | Requête d'écriture sans `X-XSRF-TOKEN` ⇒ 419 |
| Autorisation | Matrice rôle × endpoint générée depuis les permissions : chaque rôle par défaut sur chaque route ⇒ code attendu ; TEST-017 (vendeur ⇒ rapport bénéfices 403 en API **et** export) |
| Champs sensibles | Vendeur ⇒ aucune clé `unit_cost`, `margin`, `balance` dans les réponses JSON, PDF et exports (recherche récursive des clés interdites) |
| Suspension | TEST-018 : POST/PATCH/DELETE ⇒ 423 ; GET autorisés selon permissions ; import et job refusés |
| Entrées | Fuzzing des FormRequest (valeurs extrêmes, types, Unicode), injection SQL sur filtres/tri (liste blanche), XSS stocké dans les libellés rendus en PDF et dans la SPA |
| Fichiers | Polyglotte, extension falsifiée, archive Zip bombe dans un XLSX, PDF malveillant, taille maximale, nom avec `../` |
| Moteur d'expressions | Tentatives d'appel de fonction non autorisée, variables inconnues, profondeur excessive, division par zéro ⇒ rejet propre |
| Partage | Lien signé expiré/révoqué ⇒ 404 ; limitation de débit |
| Dépendances | `composer audit`, `npm audit` bloquants (sévérité haute) |
| En-têtes | CSP, HSTS, cookies `Secure/HttpOnly/SameSite` vérifiés en E2E |
| Pentest | ZAP baseline automatisé en préproduction + revue manuelle ASVS niveau 2 sur auth, multi-tenant, fichiers (L7-03) |

---

## 7. Bout en bout (Playwright)

Exécutés sur la pile Docker, viewport bureau **et** mobile (390×844).

| Scénario | Parcours |
|---|---|
| E2E-01 | Super-admin crée une entreprise ; l'admin invité active son compte, crée un vendeur |
| E2E-02 | Import initial produits + stock d'ouverture (erreurs affichées, correction, simulation, import) — TEST-019 |
| E2E-03 | Commande d'achat → arrivage → packing list Excel → réception par scan (95/100) → coût de revient final — TEST-007, TEST-009 |
| E2E-04 | Packing list PDF avec revue humaine des lignes |
| E2E-05 | Devis → PDF → partage WhatsApp (lien) → conversion → confirmation → facture |
| E2E-06 | Commande livraison : préparation → expédition → remise partielle → reliquat → remise — TEST-003, TEST-004 |
| E2E-07 | Vente comptoir mobile (cartons + pièces), paiement Orange Money, reçu |
| E2E-08 | Vente à crédit, deux règlements Wave, relance WhatsApp, relevé PDF — TEST-011/012 |
| E2E-09 | Remise au-delà du seuil → approbation par admin → facture ; rejet dans une seconde variante — TEST-015/016 |
| E2E-10 | Annulation d'une vente livrée et payée : retour, décision de remise en stock, avoir, crédit client utilisé sur une nouvelle vente — TEST-014, TEST-013 |
| E2E-11 | Inventaire partiel sur mobile, écarts, validation, ajustement |
| E2E-12 | Transfert entre dépôts avec écart |
| E2E-13 | Dépenses, transfert entre comptes, tableau de bord (marge vs résultat vs trésorerie vs créances) |
| E2E-14 | Correction d'une opération validée et consultation de l'audit — TEST-024 |
| E2E-15 | Vendeur : boutons absents **et** appels directs refusés |
| E2E-16 | Entreprise suspendue : lecture seule — TEST-018 |
| E2E-17 | Thème sombre, responsive, accessibilité de base (axe-core sans violation critique) |

Les PDF générés sont vérifiés par extraction de texte (numéro, totaux, mentions) et comparaison visuelle de référence (tolérance) pour les gabarits.

---

## 8. Performance (CDC §24.2)

- **Conditions de mesure** (validées le 2026-11-04) : jeu de volumétrie, VPS de production cible, réseau simulé 4G (latence 100 ms, 10 Mbit/s), navigateur Chromium.
- **Objectifs** : pages courantes < 3 s (LCP) ; API p95 < 800 ms, p99 < 1,5 s ; confirmation de commande p95 < 500 ms ; 20 utilisateurs simultanés par entreprise sans dégradation.
- **Scénarios k6** : liste produits avec photos, recherche, saisie de commande, confirmation concurrente, tableau de bord mensuel, export.
- **Contrôles automatiques** : détection N+1 (`Model::preventLazyLoading()` en test), journal des requêtes lentes MySQL (> 200 ms) analysé en L7.

---

## 9. Restauration (TEST-020)

Mensuel, automatisé en préproduction :
1. Récupération du dernier instantané restic de production (ou de préproduction).
2. Restauration dans un environnement isolé (base + fichiers + paramètres).
3. Contrôles : migrations à jour, `inventory:reconcile` sans écart, soldes financiers = Σ mouvements, ouverture d'un PDF de facture, connexion d'un compte de test.
4. Rapport enregistré dans `backup_records` (`restore_test`) ; échec ⇒ alerte.

---

## 10. Intégration continue

| Étape | Bloquant | Fréquence |
|---|---|---|
| Lint, statique, contrat, types TS | Oui | Chaque PR |
| Unitaires + intégration + API + non-fuite | Oui | Chaque PR |
| Concurrence (1 répétition) | Oui | Chaque PR |
| Concurrence (20 répétitions), mutation | Oui pour `main` | Nocturne |
| E2E Playwright | Oui | Chaque PR touchant le frontend ou un workflow ; nocturne complet |
| ZAP baseline, k6 | Non (rapport) | Hebdomadaire en préproduction |
| Restauration | Alerte | Mensuel |

Critère de mise en production : **aucune anomalie critique ou majeure ouverte** [RECO-CDC §28], tous les tests TEST-001…024 automatisés verts.

---

## 11. Traçabilité TEST-xxx → tests

| CDC | Tests automatisés |
|---|---|
| TEST-001 | Non-fuite générique §4.1 |
| TEST-002 | `Tenancy/Feature/SuperAdminContextTest`, E2E-01 |
| TEST-003/004 | `Sales/Feature/OrderReservationTest`, `DeliveryTest`, E2E-06 |
| TEST-005 | `Inventory/Integration/ReserveStockTest` |
| TEST-006 | `Inventory/Concurrency/LastUnitsTest` |
| TEST-007/008 | `Logistics/Feature/GoodsReceiptValidationTest`, concurrence §5 |
| TEST-009/010 | `Costing/Unit/StandardFormulaTest`, `LandedCostFinalizeTest` |
| TEST-011/012 | `Payments/Feature/RecordPaymentTest` |
| TEST-013 | `Billing/Feature/CreditNoteApplicationTest` |
| TEST-014 | `Sales/Feature/CancelDeliveredPaidOrderTest`, E2E-10 |
| TEST-015/016 | `Approvals/Feature/PriceOverrideApprovalTest`, E2E-09 |
| TEST-017 | Matrice d'autorisation §6, E2E-15 |
| TEST-018 | `Tenancy/Feature/SuspendedCompanyTest`, E2E-16 |
| TEST-019 | `Imports/Feature/InitialImportTest`, E2E-02 |
| TEST-020 | Procédure §9 |
| TEST-021 | `Shared/Concurrency/DocumentSequenceTest` |
| TEST-022 | `Sales/Feature/CartonPieceTest` |
| TEST-023 | `Reporting/Feature/ConsolidationTest` |
| TEST-024 | `Audit/Feature/CorrectionAuditTest`, E2E-14 |
