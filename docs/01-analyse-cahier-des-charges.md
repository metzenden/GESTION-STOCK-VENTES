# 01 — Analyse du cahier des charges

**Projet :** FDS Business — Plateforme de gestion commerciale multi-entreprises
**Document analysé :** `CAHIER_DES_CHARGES.md` v1.0 du 3 novembre 2026
**Version de l'analyse :** 1.0 — 4 novembre 2026
**Statut :** Validé par le maître d'ouvrage le 4 novembre 2026 — toutes les options recommandées sont retenues (voir `08`). Seuls les points [VÉRIF. RÉGL.] restent ouverts.

---

## 0. Légende

| Marqueur | Signification |
|---|---|
| **[VALIDÉ-CDC]** | Règle explicitement validée dans le cahier des charges (CDC). Non remise en cause. |
| **[RECO-CDC]** | Recommandation déjà présente dans le CDC, reprise telle quelle. |
| **[DÉCISION]** | Décision proposée par l'architecture pour combler un vide. Consignée dans `08-risques-et-decisions.md` (identifiant `D-xxx`). Applicable par défaut sauf objection. |
| **[À VALIDER]** | Point qui exige un arbitrage métier explicite avant implémentation de la fonctionnalité concernée. |
| **[VÉRIF. RÉGL.]** | Point fiscal, comptable ou réglementaire **à faire vérifier par un expert-comptable ou juriste sénégalais**. Aucune règle n'est inventée. |

---

## 1. Synthèse

Le cahier des charges est **de bonne qualité** : il distingue déjà les règles validées des recommandations, identifie lui-même 18 points ouverts (PV-01 à PV-18) et fournit des scénarios de test exploitables. Le périmètre V1 est **large** (18 modules obligatoires, livraison unique sans mise en production partielle).

Les principales difficultés identifiées sont :

1. **Une tension structurelle entre « facture définitive à la confirmation » et le reste du cycle de vie** (approbations, annulations, livraisons partielles, retours). Elle impose une conception rigoureuse des avoirs et des états de commande (§2, C-01 à C-04).
2. **Un vide sur la fiscalité** (TVA, mentions légales, conservation) : le CDC ne mentionne aucune taxe. Le système doit être capable d'en gérer sans en imposer (§4, M-01).
3. **La gestion multi-devises** (achats en devise étrangère, dettes fournisseurs, consolidation) n'a pas de règles d'écart de change (§3, A-07).
4. **La valorisation des stocks** combine lots physiques, FIFO et coût provisoire : il faut une règle unique et cohérente (§2, C-06 ; §3, A-04).
5. **Le volume fonctionnel** sans mise en production partielle allonge le délai avant premier retour terrain (§5, R-10).

Aucune contradiction n'est bloquante : toutes reçoivent une proposition de résolution ci-dessous.

---

## 2. Contradictions et tensions internes

### C-01 — Facture définitive à la confirmation vs approbation des remises et prix

- **Constat :** la facture définitive est générée dès la confirmation [VALIDÉ-CDC §11.1]. Or une remise ou une modification de prix au-delà d'un seuil exige une approbation, sans modification définitive avant décision [VALIDÉ-CDC §16.4, TEST-015]. Si la confirmation intervenait avant l'approbation, une facture définitive porterait un prix non approuvé.
- **Résolution [DÉCISION D-016] :** introduire l'état de commande **« En attente d'approbation »** entre *Brouillon* et *Confirmée*. Dans cet état :
  - les quantités sont **réservées à titre provisoire** (effet provisoire autorisé par §16.4, compté dans le stock réservé pour empêcher la double vente) ;
  - **aucune facture n'est émise**, aucun numéro FAC n'est consommé ;
  - l'approbation déclenche la confirmation (facture + réservation définitive) dans la même transaction ;
  - le rejet ramène la commande en *Brouillon* et libère la réservation.

### C-02 — Facture à la confirmation vs annulation d'une commande non livrée

- **Constat :** une commande confirmée peut être annulée (§10.4) mais une facture émise ne disparaît jamais [VALIDÉ-CDC §11.4]. Chaque annulation après confirmation produit donc un **avoir**.
- **Résolution [DÉCISION D-019] :** l'annulation d'une commande confirmée génère automatiquement un **avoir total** imputé en priorité sur le solde restant de la facture d'origine. Si la facture a été payée (même partiellement), l'excédent devient un **crédit client disponible** (pas de décaissement, conformément à §12.5).
- **Conséquence à accepter :** volume d'avoirs plus élevé qu'avec une facturation à la livraison. Le tableau de bord distingue « avoirs d'annulation » et « avoirs commerciaux ».

### C-03 — Facture à la confirmation vs modification d'une commande confirmée

- **Constat :** le CDC ne dit pas si une commande confirmée peut être modifiée (ajout d'un article, changement de quantité).
- **Résolution [DÉCISION D-017] :** une commande confirmée **n'est pas modifiable**. Deux procédures :
  - *réduction* : avoir partiel sur les lignes concernées + libération de la réservation correspondante ;
  - *augmentation* : nouvelle commande (donc nouvelle facture) liée à la première.
- **[Validé (2026-11-04)]** si le métier préfère un mécanisme de « facture rectificative » — dépend aussi de PV-01.

### C-04 — Facture émise vs reconnaissance du chiffre d'affaires

- **Constat :** le CDC demande de distinguer facture émise, livraison réalisée et vente économiquement réalisée [RECO-CDC §11.1] mais ne fixe pas le moment de reconnaissance du CA (PV-02).
- **Résolution [DÉCISION D-018, Validé (2026-11-04)] :** pour les **rapports de gestion**, le CA et la marge sont reconnus **à la remise effective au client**, au prorata des quantités remises, net des avoirs liés aux retours. Les rapports affichent en parallèle le « montant facturé » (date d'émission) et le « montant encaissé ». Ce choix est cohérent avec ST-005 et la matrice §26 (coût des marchandises vendues constaté à la remise).
- **[VÉRIF. RÉGL.]** la règle comptable et fiscale officielle (date d'exigibilité de la TVA le cas échéant, date de rattachement du produit) doit être confirmée par l'expert-comptable. Le choix ci-dessus ne concerne que les indicateurs de gestion.

### C-05 — Moyen de paiement unique vs utilisation d'un avoir

- **Constat :** une facture n'utilise qu'un seul moyen de paiement [VALIDÉ-CDC §12.2] ; un avoir peut régler une prochaine vente [VALIDÉ-CDC §12.5]. Utiliser un avoir sur une facture déjà réglée en partie par Wave semblerait violer la règle.
- **Résolution [DÉCISION D-020] :** l'**imputation d'un avoir n'est pas un moyen de paiement**. C'est un document distinct (`credit_applications`) qui réduit le solde dû. La règle PAY-001 ne s'applique qu'aux règlements monétaires (espèces, Wave, Orange Money, virement). Une facture peut donc être soldée par « avoir + un seul moyen monétaire ».

### C-06 — FIFO comptable vs traçabilité du lot physique

- **Constat :** le CDC recommande FIFO pour la valorisation « avec traçabilité du lot physique lorsque celui-ci est connu » et note que les deux doivent rester cohérents (§9.5, PV-03). Deux méthodes de valorisation simultanées créent des écarts entre valeur comptable et stock physique par lot.
- **Résolution [DÉCISION D-011] :** **une seule logique** : chaque sortie est **affectée à des lots précis** (identification spécifique). L'affectation est **proposée automatiquement en FIFO** (lot le plus ancien du dépôt) et peut être modifiée par l'utilisateur habilité lorsqu'il connaît le lot réellement prélevé. La valorisation de la sortie utilise toujours le coût du lot effectivement affecté. Il n'existe donc jamais d'écart entre lot physique et lot valorisé.

### C-07 — Stock « en livraison » et formule de disponibilité

- **Constat :** la formule `disponible = physique − réservé − bloqué − en livraison` [VALIDÉ-CDC §6.3] risque une double soustraction si la quantité expédiée reste aussi comptée comme réservée.
- **Résolution [DÉCISION D-010] :** à l'expédition, la quantité **passe** du compartiment « réservé » au compartiment « en livraison » (la réservation est consommée d'autant). Les compartiments sont **mutuellement exclusifs** (ST-007). Invariant contrôlé en base :
  `physique = disponible + réservé + bloqué + en_livraison`, chaque terme ≥ 0.

### C-08 — Auto-approbation vs contrôle interne

- **Constat :** « un utilisateur disposant de l'autorisation nécessaire peut approuver sa propre opération » [VALIDÉ-CDC §16.2]. Cela réduit l'intérêt du contrôle pour les opérations sensibles (avoirs, corrections financières).
- **Résolution :** la règle validée est respectée. **[DÉCISION D-061]** : l'auto-approbation est **toujours journalisée comme telle** (indicateur `self_approved`) et visible dans les rapports d'approbation. **[Validé (2026-11-04)]** option par règle d'approbation « interdire l'auto-approbation » (désactivée par défaut).

### C-09 — Super-administrateur et entreprise suspendue

- **Constat :** une entreprise suspendue passe en lecture seule (§3.5) ; le super-administrateur peut « administrer » toutes les entreprises.
- **Résolution [DÉCISION D-041] :** la lecture seule s'applique **aussi au super-administrateur** pour les opérations métier. Seules restent possibles : réactivation, consultation, export, gestion des comptes utilisateurs (désactivation). Cela protège la cohérence des données d'une entreprise en litige ou en fin de contrat.

### C-10 — Tables conceptuelles `customer_receivables` / `supplier_liabilities`

- **Constat :** le modèle conceptuel (§22.6) prévoit des tables de créances et de dettes, alors que ces valeurs se déduisent des factures, avoirs et paiements. Les stocker séparément crée un risque de divergence.
- **Résolution [DÉCISION D-057] :** créances et dettes sont des **vues SQL** calculées à partir des documents sources. Seule la date d'échéance est portée par la facture.

---

## 3. Ambiguïtés et imprécisions

| ID | Sujet | Ambiguïté | Proposition | Statut |
|---|---|---|---|---|
| A-01 | Unité de stock | Pièce/carton seulement ? Produits au mètre ou au kilo ? | Quantités stockées en **entiers dans l'unité de base** (pièce). Si un produit se vend au poids ou à la longueur, l'unité de base devient la plus petite unité (gramme, centimètre). Conversions par facteurs entiers. | D-007 |
| A-02 | Produit sans variante | Le stock est-il porté par le produit ou la variante ? | **Tout produit possède au moins une variante** (variante par défaut pour un produit simple). Le stock, les prix et les lots sont toujours rattachés à la variante. | D-008 |
| A-03 | Tarif gros / détail | Comment choisir le tarif applicable ? | Chaque client a une **grille tarifaire par défaut** (Détail ou Gros) ; le vendeur peut changer la grille sur la commande ; le prix proposé dépend de la grille et de l'unité (pièce ou carton). Seuil de quantité automatique : **[Validé (2026-11-04)]**. | D-049 |
| A-04 | Réception sans coût finalisé (PV-06) | Le stock reçu est-il vendable avant le coût définitif ? | Réception validée ⇒ stock **disponible** avec **coût provisoire** (prix d'achat converti + frais estimés). À la finalisation, les sorties déjà valorisées au provisoire reçoivent un **ajustement de valorisation** traçable. Les rapports de marge signalent les montants provisoires. | D-012, **Validé (2026-11-04)** |
| A-05 | Marchandise endommagée à la réception | Entre-t-elle en stock ? | Elle entre dans le lot et est immédiatement placée en **stock bloqué « endommagé »** : présente physiquement, non vendable, traçable. Option « refuser l'entrée » au niveau de la ligne. | D-046 |
| A-06 | Livraisons partielles (PV-15) | Supportées en V1 ? | **Oui.** Le modèle (lignes de livraison, quantités livrées par ligne) l'impose de toute façon ; le coût marginal est faible et le besoin est fréquent en vente en gros. | D-022, **Validé (2026-11-04)** |
| A-07 | Devises multiples (PV-08) | Dettes fournisseurs en devise ? Écarts de change ? | Dettes suivies **dans la devise de l'engagement** ; chaque règlement enregistre le taux du jour et le montant en devise du compte débité. L'écart entre taux de l'engagement et taux du règlement est **affiché** mais **aucune écriture d'écart de change** n'est générée. | D-026, **VÉRIF. RÉGL.** |
| A-08 | Arrondi « au franc le plus proche » (CR-007) | Règle pour 0,5 ? | Arrondi **demi supérieur** (HALF_UP) à la précision de la devise (0 décimale pour XOF). | D-013, **Validé (2026-11-04)** |
| A-09 | Dépôt source d'une commande | Une commande peut-elle puiser dans plusieurs dépôts ? | **Un seul dépôt source par commande** en V1. Le transfert préalable reste possible. | D-023 |
| A-10 | Retrait sur place | Passe-t-il par l'état « en livraison » ? | Non : *Prête → Remise* directement ; la sortie définitive a lieu à la remise. | D-024 |
| A-11 | Litige | État de commande ou attribut ? | **Indicateur orthogonal** avec dossier de litige (motif, ouverture, résolution) ; n'interrompt pas le workflow. | D-025 |
| A-12 | Échec de livraison | Que devient la marchandise ? | Elle revient au dépôt d'origine : « en livraison » → « réservé ». La commande repasse en *Préparée* ; nouvelle tentative ou annulation (avoir). | D-053 |
| A-13 | Engagement fournisseur | Quand naît la dette ? | À la **confirmation de la commande d'achat** (engagement), ajustable par avenant ou avoir fournisseur ; les frais d'approche dus à un transitaire créent leur propre engagement. | D-026, **Validé (2026-11-04)** |
| A-14 | Frais d'approche et dépenses | Un paiement de fret est-il une dépense ? | Non : les frais incorporés au coût de revient sont **exclus des charges** du résultat de gestion pour éviter la double déduction (§14.5). Ils sont suivis comme « paiements de frais d'approche ». | D-027 |
| A-15 | Ajustement positif d'inventaire | Quel lot et quel coût ? | Création d'un **lot d'ajustement** valorisé au coût du dernier lot définitif de la variante, modifiable par un utilisateur habilité, avec approbation selon règles. | D-048, **Validé (2026-11-04)** |
| A-16 | Retour client | Nouveau lot ou lot d'origine ? | Retour dans le **lot d'origine** (RET-003), au coût d'origine. | D-047 |
| A-17 | Solde négatif d'un compte financier | Autorisé ? | Interdit par défaut (paramètre par compte). | D-044 |
| A-18 | Trop-perçu client | Paiement supérieur au solde ? | **Refusé.** L'utilisateur saisit le montant exact ; aucun crédit implicite. | D-021 |
| A-19 | Remboursement en espèces | Possible ? | Hors périmètre : uniquement par avoir [VALIDÉ-CDC §12.5]. | D-045 |
| A-20 | Restauration depuis l'interface | Le super-admin restaure-t-il en un clic ? | L'interface **déclenche et supervise** les sauvegardes. La **restauration** est une procédure d'exploitation en ligne de commande, documentée et testée, jamais un bouton (risque d'écrasement global). Pas de restauration par entreprise en V1. | D-039 |
| A-21 | Vente « 2 cartons et 3 pièces » (TEST-022) | Une ligne ou deux ? | Une ligne = une unité. La saisie rapide permet « cartons + pièces » et génère deux lignes de la même variante (prix carton et prix pièce distincts). | — |
| A-22 | Consolidation | Quelles « entreprises autorisées » pour le super-admin ? | Toutes les entreprises actives et suspendues ; regroupement **par devise** ; conversion uniquement si une devise de présentation et un taux daté sont choisis explicitement. | D-042 |
| A-23 | Mode de paiement ↔ compte | Comment savoir quel compte crédite un paiement Wave ? | Chaque compte financier a un **type** (caisse, Wave, Orange Money, banque) ; un paiement ne peut créditer qu'un compte du type correspondant au moyen. | — |

---

## 4. Fonctionnalités manquantes ou sous-spécifiées

| ID | Manque | Proposition | Statut |
|---|---|---|---|
| M-01 | **Taxes (TVA, autres)** : aucune mention dans le CDC. | Prévoir un référentiel de taux de taxe par entreprise et un taux par ligne de facture, **désactivé par défaut (0 %)**. Le moteur de calcul HT/TVA/TTC est implémenté mais aucun taux n'est préconfiguré. | **VÉRIF. RÉGL.** (PV-01) |
| M-02 | **Mentions légales** obligatoires des factures et avoirs, NINEA/RCCM. | Champs configurables, figés (snapshot) dans chaque document émis. Contenu exact à fournir par l'expert-comptable. | **VÉRIF. RÉGL.** |
| M-03 | **Facturation électronique / certifiée** éventuellement exigée. | Vérifier si une obligation de facture normalisée ou de transmission à l'administration s'applique. L'architecture isole la génération de factures pour permettre une intégration ultérieure. | **VÉRIF. RÉGL.** |
| M-04 | **Durée de conservation** des documents et journaux. | Aucune purge automatique en V1. Durée légale à confirmer. | **VÉRIF. RÉGL.** |
| M-05 | **Codes-barres** : le déchargement par lecteur est demandé (§8.4) mais aucun champ code-barres n'existe dans la fiche produit. | Champ `barcode` par variante et par conditionnement (pièce/carton), unique dans l'entreprise. | D-062 |
| M-06 | **Vente comptoir rapide** (« saisie rapide des ventes » §20.1). | Parcours « vente comptoir » qui enchaîne en **une transaction** : commande confirmée + facture + remise immédiate + paiement. Réutilise les mêmes services métier, sans règle spécifique. | D-063 |
| M-07 | **Conditions de paiement / échéance par défaut.** | Délai de paiement par client (jours), défaut entreprise ; vente comptoir = échéance immédiate. | — |
| M-08 | **Annulation d'un paiement erroné.** | Contre-passation du règlement (approbation « correction financière »), reçu d'annulation, réouverture du solde. | D-059 |
| M-09 | **Changement exceptionnel de moyen de paiement** (PAY-001 « correction traçable »). | Procédure : contre-passation des règlements existants puis ré-enregistrement avec le nouveau moyen, sous approbation. | D-059 |
| M-10 | **Plafond de crédit client.** | Non demandé ; non implémenté en V1. Signalé comme évolution possible. | Validé (2026-11-04) |
| M-11 | **Historique des prix catalogue.** | Prix datés (`valid_from`/`valid_to`) ; les lignes de vente figent le prix appliqué. | — |
| M-12 | **Transitaires et prestataires** pour les frais d'approche. | Type de fournisseur (marchandises, transitaire, transporteur, douane, autre). | D-026 |
| M-13 | **Statut « en transit » des transferts.** | Quantité en transit portée par le transfert, hors stock disponible des deux dépôts ; le total entreprise inclut le transit. | D-060 |
| M-14 | **Désactivation d'un produit avec réservations actives.** | Autorisée ; bloque les nouvelles ventes mais pas l'exécution des commandes en cours. | — |
| M-15 | **Rapprochement stock** (projection vs mouvements). | Tâche planifiée qui vérifie que les soldes de stock égalent la somme des mouvements ; alerte super-admin en cas d'écart. | D-009 |
| M-16 | **Clés d'idempotence côté API.** | En-tête `Idempotency-Key` obligatoire sur les commandes critiques (confirmation, paiement, réception…). | D-051 |
| M-17 | **Frais de livraison** (PV-16). | Gérés comme une ligne de **produit de type « service »** (non stocké) sur la commande/facture. Traitement comptable à confirmer. | D-050, **VÉRIF. RÉGL.** |
| M-18 | **Journal de connexion** et des changements d'entreprise du super-admin. | Inclus dans l'audit (MT-008). | — |
| M-19 | **Protection renforcée du super-admin** (PV-14). | Limitation de débit dédiée, notification de connexion par e-mail, **TOTP recommandé** (non obligatoire). | D-040, **Validé (2026-11-04)** |
| M-20 | **Modèles d'import** (PV-18). | Modèles Excel téléchargeables par type (produits, stock d'ouverture, clients, fournisseurs) + correspondance de colonnes mémorisable. | — |

---

## 5. Risques métier

| ID | Risque | Gravité | Mesure |
|---|---|---|---|
| R-01 | Fuite de données entre entreprises (requête non filtrée, fichier, export, cache, file de tâches). | **Critique** | Défense en profondeur : contexte serveur, portée globale Eloquent, clés étrangères composites `(company_id, id)`, test automatique de non-fuite sur **toutes** les routes, fichiers privés contrôlés. |
| R-02 | Survente en concurrence. | **Critique** | Verrou de ligne sur la position de stock, contrainte `CHECK` disponible ≥ 0, test de concurrence réel. |
| R-03 | Double écriture (double clic, double approbation, reprise de tâche). | **Critique** | Transitions d'état conditionnelles, contraintes d'unicité sur les mouvements, clés d'idempotence. |
| R-04 | Erreurs d'arrondi financier. | **Majeure** | Arithmétique décimale exacte (aucun flottant), règle d'arrondi unique, réconciliation des allocations. |
| R-05 | Non-conformité fiscale des factures. | **Majeure** | Points VÉRIF. RÉGL. à lever avant mise en production ; paramétrage sans code. |
| R-06 | Coût de revient provisoire faussant les marges. | **Majeure** | Statut provisoire visible, ajustements de valorisation traçables, alerte des arrivages non finalisés. |
| R-07 | Extraction PDF de packing lists peu fiable. | **Majeure** | Vérification humaine obligatoire, modèles par fournisseur, import Excel privilégié. |
| R-08 | Auto-approbation des opérations sensibles. | Modérée | Journalisation explicite, rapport dédié, option d'interdiction. |
| R-09 | Volume d'avoirs lié à la facturation à la confirmation. | Modérée | Avoirs automatiques, typés, rapports séparés. |
| R-10 | Périmètre V1 large sans mise en production partielle : délai long avant retour terrain. | Modérée | Préproduction alimentée en continu, démonstrations par lot, recette métier progressive. |
| R-11 | Écarts de change non comptabilisés. | Modérée | Affichage des écarts ; règle à définir avec l'expert-comptable. |
| R-12 | Perte de données (VPS unique). | **Majeure** | Sauvegardes chiffrées externes quotidiennes, test de restauration mensuel automatisé en préproduction. |

---

## 6. Correspondance avec les points ouverts du CDC (PV)

| PV | Sujet | Position proposée | Bloque quoi ? |
|---|---|---|---|
| PV-01 | Fiscalité, mentions | Moteur de taxes paramétrable, mentions configurables ; contenu à fournir | Mise en production (pas le développement) |
| PV-02 | Facturation avant livraison | D-018 (CA de gestion à la remise) | Rapports financiers |
| PV-03 | FIFO / lot physique | D-011 (identification spécifique, proposition FIFO) | Lot 2 |
| PV-04 | Paiement unique | PAY-001 + D-020 (avoir ≠ moyen) + D-059 (correction) | Lot 5 |
| PV-05 | Avoirs | D-019 (imputation facture d'origine puis crédit client) | Lot 4 |
| PV-06 | Réception sans coût finalisé | D-012 (coût provisoire + ajustement) | Lot 3 |
| PV-07 | Frais directs, pertes, dommages | Frais directs affectés à une ligne ; coût des manquants réparti sur les quantités acceptées ; endommagés valorisés comme acceptés puis bloqués | Lot 3 — **Validé (2026-11-04)** |
| PV-08 | Devises | D-026, D-042 | Lots 3, 5, 6 |
| PV-09 | PDF fournisseurs | Fournir 3 à 5 exemples réels avant le lot 3 | Lot 3 |
| PV-10 | SMTP | Service SMTP transactionnel au choix de l'exploitant ; Mailpit en dev | Lot 8 |
| PV-11 | Dimensionnement VPS | Estimation fournie dans `02` §12 | Lot 8 |
| PV-12 | RPO / RTO | Proposition : RPO 24 h (+ binlogs optionnels pour 1 h), RTO 4 h | Lot 8 — **Validé (2026-11-04)** |
| PV-13 | Seuils d'approbation | Règles livrées **désactivées** ; seuils à saisir par l'administrateur | Mise en production |
| PV-14 | Sécurité super-admin | D-040 | Lot 1 |
| PV-15 | Livraisons partielles | D-022 (oui) | Lot 4 |
| PV-16 | Frais de livraison | D-050 (ligne service) | Lot 4 |
| PV-17 | Droits par défaut | Matrice proposée dans `04` §2 | Mise en production |
| PV-18 | Import historique | Modèles fournis au lot 6 | Lot 6 |

---

## 7. Points nécessitant une vérification réglementaire (récapitulatif)

Ces points **ne sont pas tranchés** par l'architecture. Le logiciel est conçu pour s'y adapter par paramétrage.

1. Taxes applicables aux ventes et achats (TVA, taux, exonérations, retenues éventuelles) et leur présentation sur facture.
2. Mentions obligatoires sur factures, avoirs et reçus (identifiants NINEA, RCCM, adresse, conditions de règlement…).
3. Règles de numérotation (continuité, séries par année, traitement des factures annulées).
4. Existence d'une obligation de facturation électronique ou certifiée.
5. Date de rattachement du chiffre d'affaires et exigibilité de la taxe pour une facture émise avant livraison.
6. Traitement comptable des avoirs d'annulation et des avoirs commerciaux.
7. Traitement des écarts de change sur dettes fournisseurs en devise.
8. Traitement des droits et taxes de douane dans le coût de revient (récupérabilité éventuelle).
9. Durée légale de conservation des pièces et journaux.
10. Protection des données personnelles des clients (déclaration éventuelle auprès de l'autorité compétente).
