# 11 — Recette : traçabilité des scénarios du CDC

**Version :** 1.0 — 9 novembre 2026 (lot 7, L7-05)

Chaque scénario TEST-xxx du cahier des charges est couvert par au moins un test automatisé (Pest, `backend/tests`)
exécuté à chaque intégration (CI). Les parcours de bout en bout (Playwright, E2E-01…16) restent à écrire : ils
rejouent ces scénarios dans le navigateur et sont un **prérequis de la recette métier en préproduction**.

| CDC | Règle | Test automatisé (fichier) | État |
|---|---|---|---|
| TEST-001 | Isolation des données entre entreprises | `Feature/Tenancy/NonLeakTest.php` (toutes les routes, identifiants de l'entreprise B) | Vert |
| TEST-002 | Bascule du super-administrateur, journalisée | `Feature/Tenancy/SuperAdminContextTest.php` | Vert |
| TEST-003/004 | Réservation à la confirmation, sortie à la remise, livraisons partielles | `Feature/Sales/SalesFlowTest.php` (« livre en deux fois… ») | Vert |
| TEST-005 | Réservation > disponible refusée sans écriture | `Feature/Inventory/StockLedgerTest.php`, `SalesFlowTest` (CMD-004) | Vert |
| TEST-006 | Dernières unités : un seul gagnant sur 10 | `Concurrency/LastUnitsTest.php` | Vert |
| TEST-007/008 | Réception validée une seule fois, rejeu idempotent | `Feature/Logistics/ShipmentReceiptTest.php`, `Feature/Shared/IdempotencyTest.php` | Vert |
| TEST-009/010 | Coût de revient de référence, blocage sans CBM | `Unit/Costing/LandedCostCalculatorTest.php`, `Feature/Costing/LandedCostTest.php` | Vert |
| TEST-011/012 | Vente à crédit, verrou du moyen de paiement | `Feature/Sales/PaymentsCreditsTest.php` | Vert |
| TEST-013 | Imputation d'avoir 20 000 / 8 000 / 12 000 | `Feature/Sales/PaymentsCreditsTest.php` | Vert |
| TEST-014 | Retour au lot d'origine, contrôle, avoir | `Feature/Sales/PaymentsCreditsTest.php` (retour) | Vert |
| TEST-015/016 | Approbation / rejet d'une remise | `Feature/Approvals/ApprovalEngineTest.php` | Vert |
| TEST-017 | Rapport financier refusé au vendeur (API et export) | `Feature/Reporting/ReportingTest.php` | Vert |
| TEST-018 | Entreprise suspendue en lecture seule | `Feature/Tenancy/SuspensionTest.php` | Vert |
| TEST-019 | Import initial : simulation sans écriture, erreurs, import | `Feature/Reporting/ReportingTest.php` | Vert |
| TEST-020 | Restauration de sauvegarde | `docker/backup/restore-test.sh` — **à démontrer en préproduction** | À faire (serveur requis) |
| TEST-021 | 50 factures simultanées, numéros continus | `Concurrency/DocumentSequenceConcurrencyTest.php` | Vert |
| TEST-022 | 2 cartons + 3 pièces = 15 | `Feature/Sales/SalesFlowTest.php` | Vert |
| TEST-023 | Consolidation par devise, super-administrateur seul | `Feature/Reporting/ReportingTest.php` | Vert |
| TEST-024 | Correction tracée dans l'audit | `Feature/Audit/AuditLogTest.php` (journal append-only) ; corrections par contre-écriture dans les tests de paiements et dépenses | Vert (E2E-14 à écrire) |

## Campagnes du lot 7

| Campagne | Contenu | Résultat |
|---|---|---|
| Concurrence (L7-01) | Dernières unités, séquences de factures, 8 règlements simultanés sur une facture, 5 approbations simultanées | `Concurrency/*` : un seul effet, aucun trop-perçu |
| Isolation (L7-02) | Toutes les routes `/api/v1` avec les identifiants d'une autre entreprise, lien public signé, rapports, recherche | `NonLeakTest`, `ReportingTest` (recherche) |
| Sécurité (L7-03) | Voir `12-securite.md` | 0 vulnérabilité connue dans les dépendances livrées |
| Performance (L7-04) | `perf/k6/api-load.js` (p95 < 800 ms, < 1 % d'erreurs) | **À exécuter en préproduction** sur la volumétrie cible × 3 |
| Documents (L7-06) | Factures, avoirs, reçus avec les mentions saisies par l'entreprise | **Revue par l'expert-comptable requise** (VR-01 à VR-06) |

## Critère de mise en production (CDC §28)

Aucune anomalie critique ou majeure ouverte, tous les TEST-001…024 verts, restauration démontrée (TEST-020),
mentions légales validées (L7-06), parcours E2E verts en préproduction.
