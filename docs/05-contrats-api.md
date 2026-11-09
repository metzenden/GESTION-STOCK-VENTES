# 05 — Contrats d'API REST

**Version :** 1.0 — 4 novembre 2026
**Base :** `https://<domaine>/api/v1` · JSON UTF-8 · OpenAPI 3.1 (contract-first, D-035)

Le contrat de référence sera maintenu dans `openapi/openapi.yaml` (fractionné par module). Ce document en fixe les conventions, l'inventaire des ressources et un extrait normatif.

---

## 1. Conventions

### 1.1. Authentification et contexte

| Élément | Règle |
|---|---|
| Authentification | Sanctum SPA : `GET /sanctum/csrf-cookie` puis `POST /auth/login` ; cookie de session HttpOnly ; en-tête `X-XSRF-TOKEN` sur toute requête non `GET`. |
| Entreprise active | Déduite du serveur (D-004). Super-admin : `POST /context/company {company_id}` ; renvoyée dans `/auth/me`. **Aucun endpoint métier n'accepte `company_id` dans le corps ou la requête.** |
| Ressource d'une autre entreprise | `404 not_found` (D-054). |

### 1.2. En-têtes

| En-tête | Sens | Obligatoire |
|---|---|---|
| `Idempotency-Key` | ULID/UUID généré par le client, 24 h de validité | **Oui** sur les actions marquées ⚡ ; recommandé sur tout `POST` |
| `If-Match` | `"<version>"` pour la mise à jour d'un brouillon (verrouillage optimiste) | Oui sur `PATCH` de documents versionnés |
| `X-Request-Id` | Renvoyé par le serveur ; corrélation des journaux | — |
| `Accept-Language` | Ignoré (français uniquement) | — |

### 1.3. Formats

| Type | Représentation |
|---|---|
| Identifiant | ULID chaîne, 26 caractères |
| Montant | Objet `{"amount": "100000", "currency": "XOF"}` ; `amount` = chaîne décimale normalisée à la précision de la devise (calculs internes) ; les coûts unitaires peuvent avoir jusqu'à 8 décimales |
| Quantité | Entier en unité de base + objet d'affichage optionnel `{"base": 15, "breakdown": [{"unit":"carton","qty":2},{"unit":"piece","qty":3}]}` |
| Date | `YYYY-MM-DD` ; horodatage ISO 8601 UTC `2026-11-04T10:15:00Z` |
| Énumérations | Codes anglais en minuscules (`partially_paid`) ; libellés français fournis par `GET /meta/enums` |
| Champs sensibles | Absents de la réponse (pas `null`) sans permission `*.view_sensitive` ; la liste des champs masqués est indiquée dans `meta.redacted` |

### 1.4. Listes

```
GET /sales-orders?page=2&per_page=25&sort=-confirmed_at&filter[status]=confirmed,in_delivery
    &filter[customer_id]=01J…&filter[date_from]=2026-11-01&filter[date_to]=2026-11-30&q=dupont
```

Réponse :

```json
{
  "data": [ { "id": "01J…", "number": "CMD-2026-0042", "...": "..." } ],
  "meta": { "page": 2, "per_page": 25, "total": 312, "last_page": 13 },
  "links": { "next": "…?page=3", "prev": "…?page=1" }
}
```

`per_page` ≤ 100. Tri sur liste blanche de champs. Les exports utilisent **les mêmes filtres** (`GET …/export?format=xlsx&<filtres>`) et sont asynchrones au-delà de 2 000 lignes.

### 1.5. Actions métier

Les transitions d'état sont des sous-ressources **POST** explicites (jamais un `PATCH status`) :
`POST /sales-orders/{id}/submit`, `/ship`, `/cancel`… Chaque action renvoie la ressource à jour (200) ou un document créé (201 + `Location`).

### 1.6. Erreurs (RFC 9457 `application/problem+json`)

```json
{
  "type": "https://fds.example/errors/insufficient_stock",
  "title": "Stock insuffisant",
  "status": 409,
  "code": "insufficient_stock",
  "detail": "Disponible : 5 pièces, demandé : 7 pièces.",
  "instance": "/api/v1/sales-orders/01J…/submit",
  "request_id": "01J…",
  "errors": [ { "pointer": "/lines/0/qty_units", "code": "insufficient_stock", "meta": { "available": 5, "requested": 7, "variant_id": "01J…" } } ]
}
```

| Cas CDC §23.3 | HTTP | `code` |
|---|---|---|
| Donnée invalide | 422 | `validation_failed` (détail par champ dans `errors`) |
| Authentification absente | 401 | `unauthenticated` |
| Session expirée | 401 | `session_expired` |
| Permission insuffisante | 403 | `forbidden` |
| Ressource inexistante (ou autre entreprise) | 404 | `not_found` |
| Conflit de stock | 409 | `insufficient_stock` |
| Opération déjà effectuée / transition invalide | 409 | `already_processed`, `invalid_transition` |
| Conflit de version | 412 | `version_mismatch` |
| Clé d'idempotence réutilisée avec un autre corps | 409 | `idempotency_conflict` |
| Approbation nécessaire | 202 | `approval_required` (corps : la demande créée) — ce n'est pas une erreur |
| Entreprise suspendue | 423 | `company_suspended` |
| Règle métier violée | 422 | code spécifique (`payment_method_locked`, `overpayment`, `has_history`, `cbm_missing`, `credit_exceeds_balance`…) |
| Limitation de débit | 429 | `too_many_requests` (+ `Retry-After`) |
| Erreur technique | 500 / 503 | `internal_error` (aucun détail interne) |

---

## 2. Inventaire des ressources

Légende : ⚡ = `Idempotency-Key` obligatoire · 🔒 = permission sensible · 🅐 = peut renvoyer `202 approval_required`.

### 2.1. Authentification, contexte, plateforme

| Méthode | Chemin | Description | Permission |
|---|---|---|---|
| POST | `/auth/login` | Connexion (limitée en débit) | public |
| POST | `/auth/logout` | Déconnexion | authentifié |
| GET | `/auth/me` | Utilisateur, entreprise active, permissions, préférences | authentifié |
| POST | `/auth/forgot-password` | Demande de réinitialisation (réponse constante) | public |
| POST | `/auth/reset-password` | Réinitialisation par jeton | public |
| POST | `/auth/accept-invitation` | Activation d'un compte invité | public (jeton) |
| PATCH | `/auth/me/preferences` | Thème | authentifié |
| POST | `/context/company` | Choisir l'entreprise active (audité) | super-admin |
| GET | `/admin/companies` · POST | Lister / créer (atomique, ADM-001) ⚡ | `platform.companies.*` |
| GET/PATCH | `/admin/companies/{id}` | Consulter / modifier l'identité | `platform.companies.*` |
| POST | `/admin/companies/{id}/suspend` · `/reactivate` | Suspension / réactivation | `platform.companies.manage` |
| POST | `/admin/companies/{id}/invitations` | Inviter un administrateur | `platform.companies.manage` |
| GET | `/admin/consolidated/dashboard?presentation_currency=&rate_date=` | Vue consolidée (CONS-001) | super-admin |
| GET | `/admin/audit-logs` | Audit global | super-admin |
| GET | `/admin/backups` · POST | Historique / déclencher une sauvegarde ⚡ | super-admin |
| GET | `/admin/health` | Santé détaillée | super-admin |
| GET | `/health` | Santé minimale | public |
| GET | `/meta/enums` | Libellés français des énumérations | authentifié |

### 2.2. Utilisateurs et rôles (entreprise active)

| Méthode | Chemin | Description |
|---|---|---|
| GET/POST | `/users` | Lister / inviter un utilisateur |
| GET/PATCH | `/users/{id}` | Consulter / modifier (nom, rôles) |
| POST | `/users/{id}/disable` · `/enable` | Désactiver (révoque les sessions) / réactiver |
| GET/POST | `/roles` · GET/PATCH/DELETE `/roles/{id}` | Rôles de l'entreprise |
| GET | `/permissions` | Catalogue des permissions |
| GET/PATCH | `/settings/company` | Identité, mentions légales, paramètres |
| GET/PATCH | `/settings/document-sequences` | Préfixes, largeur (pas le compteur) |
| GET/POST | `/exchange-rates` | Taux de change datés |
| GET/POST/PATCH | `/tax-rates` | Taux de taxe (vide par défaut) |
| GET/POST/PATCH | `/approval-rules` | Règles d'approbation |

### 2.3. Catalogue

| Méthode | Chemin | Description |
|---|---|---|
| GET/POST | `/categories` · PATCH/DELETE `/categories/{id}` | Arborescence |
| GET/POST | `/attributes` · `/attributes/{id}/values` | Attributs de variantes |
| GET/POST | `/products` | Liste (recherche, filtres catégorie/statut, photo) / création avec variantes |
| GET/PATCH/DELETE | `/products/{id}` | DELETE ⇒ `409 has_history` si historique (CAT-006) |
| POST | `/products/{id}/archive` · `/activate` · `/deactivate` | Statut |
| GET/POST | `/products/{id}/variants` · PATCH `/product-variants/{id}` | Variantes |
| GET/POST | `/product-variants/{id}/units` | Conditionnements, codes-barres |
| GET/POST | `/product-variants/{id}/prices` | Prix datés par grille et unité |
| POST/DELETE | `/products/{id}/images` · `/product-images/{id}` | Photos (multipart) |
| GET | `/product-variants/lookup?barcode=` | Résolution code-barres → variante + unité |
| GET | `/price-lists` · POST/PATCH | Grilles tarifaires |
| GET | `/pricing/quote?variant_id=&unit_id=&price_list_id=&date=` | Prix proposé |

### 2.4. Stocks

| Méthode | Chemin | Description |
|---|---|---|
| GET/POST/PATCH | `/warehouses` | Dépôts |
| GET | `/stock/positions?filter[warehouse_id]=&filter[variant_id]=&filter[low]=1` | Compartiments par dépôt × variante (disponible, réservé, bloqué, en livraison, attendu, en transit) |
| GET | `/stock/lots?filter[variant_id]=` | Lots et soldes par dépôt (🔒 coûts) |
| GET | `/stock/movements` | Historique filtrable (export) |
| GET | `/stock/valuation?date=` | Valeur du stock 🔒 |
| GET/POST | `/stock-adjustments` · GET/PATCH `/{id}` | Ajustements |
| POST | `/stock-adjustments/{id}/submit` ⚡🅐 | Comptabiliser ou demander approbation |
| POST | `/stock-adjustments/{id}/cancel` | |
| GET/POST | `/inventories` · GET/PATCH `/{id}` | Inventaires |
| POST | `/inventories/{id}/start` ⚡ · `/close-counting` · `/reopen` · `/validate` ⚡🅐 · `/cancel` | Transitions |
| PUT | `/inventories/{id}/lines/{lineId}` | Saisie d'un comptage |
| GET | `/inventories/{id}/sheet.pdf` | Feuille imprimable |
| GET/POST | `/stock-transfers` · GET/PATCH `/{id}` | Transferts |
| POST | `/stock-transfers/{id}/confirm` ⚡ · `/ship` ⚡ · `/receive` ⚡ · `/cancel` | Transitions |

### 2.5. Achats, arrivages, réceptions, coûts

| Méthode | Chemin | Description |
|---|---|---|
| GET/POST/PATCH | `/suppliers` | Fournisseurs (🔒 informations fournisseurs) |
| GET | `/suppliers/{id}/statement` | Engagements, règlements, solde par devise 🔒 |
| GET/POST | `/purchase-orders` · GET/PATCH `/{id}` | Commandes d'achat |
| POST | `/purchase-orders/{id}/confirm` ⚡ · `/close` · `/cancel` | Transitions |
| GET/POST | `/supplier-bills` | Engagements manuels / avoirs fournisseurs 🔒 |
| GET/POST | `/supplier-payments` ⚡ · POST `/{id}/reverse` ⚡🅐 | Règlements fournisseurs 🔒 |
| GET/POST | `/shipments` · GET/PATCH `/{id}` | Arrivages |
| POST | `/shipments/{id}/transition` `{to: shipped|in_transit|arrived|closed|cancelled}` ⚡ | États logistiques |
| GET/POST | `/shipments/{id}/containers` | Conteneurs |
| GET/POST/PATCH | `/shipments/{id}/lines` | Articles attendus |
| POST | `/shipments/{id}/packing-lists` (multipart) | Téléverser une packing list |
| GET | `/packing-lists/{id}` · `/lines` | Résultat d'extraction |
| PATCH | `/packing-lists/{id}/lines/{lineId}` | Correction / rapprochement variante |
| POST | `/packing-lists/{id}/validate` ⚡ · `/reject` | Alimente les lignes attendues |
| GET/POST | `/import-mapping-templates` | Modèles de correspondance |
| GET/POST | `/goods-receipts` · GET `/{id}` | Réceptions |
| PUT | `/goods-receipts/{id}/lines/{lineId}` | Saisie quantités comptées/acceptées/endommagées |
| POST | `/goods-receipts/{id}/scans` | Événement de scan (dédoublonné par `client_event_id`) |
| POST | `/goods-receipts/{id}/validate` ⚡ | Lots + mouvements (une seule fois) |
| GET/POST/PATCH | `/shipments/{id}/costs` | Frais d'approche 🔒 |
| GET/POST | `/cost-models` · POST `/cost-models/{id}/versions` · `/activate` · `/test` | Modèles de formules 🔒 |
| POST | `/shipments/{id}/landed-costs` ⚡ | Lancer un calcul (révision) 🔒 |
| GET | `/landed-costs/{id}` | Détail par ligne, allocations, anomalies 🔒 |
| POST | `/landed-costs/{id}/finalize` ⚡🅐 | Coût définitif + ajustements de valorisation 🔒 |

### 2.6. Ventes

| Méthode | Chemin | Description |
|---|---|---|
| GET/POST/PATCH | `/customers` | Clients |
| GET | `/customers/{id}/statement` · `/statement.pdf` | Relevé (factures, règlements, avoirs, solde) |
| GET | `/customers/{id}/credits` | Avoirs disponibles |
| GET/POST | `/quotations` · GET/PATCH `/{id}` | Devis |
| POST | `/quotations/{id}/send` · `/accept` · `/cancel` · `/convert` ⚡ | Transitions |
| GET | `/quotations/{id}/pdf` · `/share-link` | PDF / lien WhatsApp signé |
| GET/POST | `/sales-orders` · GET/PATCH `/{id}` | Commandes (brouillon modifiable) |
| POST | `/sales-orders/{id}/submit` ⚡🅐 | Confirmer (réserve + facture) ou demander approbation |
| POST | `/sales-orders/{id}/cancel` ⚡🅐 | Annuler (libère + avoir) |
| POST | `/sales-orders/{id}/cancel-remaining` ⚡🅐 | Annuler le reliquat |
| POST | `/sales-orders/{id}/disputes` · `/disputes/{id}/resolve` | Litiges |
| POST | `/counter-sales` ⚡🅐 | Vente comptoir (commande + facture + remise + paiement) |
| GET/POST | `/sales-orders/{id}/deliveries` | Créer une livraison (lignes et quantités) |
| GET | `/deliveries` · `/deliveries/{id}` | |
| POST | `/deliveries/{id}/ready` · `/ship` ⚡ · `/deliver` ⚡ · `/fail` ⚡ · `/return-to-warehouse` ⚡ · `/cancel` | Transitions ; `ship`/`deliver` acceptent une affectation manuelle des lots |
| GET | `/deliveries/{id}/pdf` | Bon de livraison |
| GET/POST | `/returns` · GET `/{id}` | Retours |
| POST | `/returns/{id}/receive` ⚡ · `/inspect` ⚡ (décision par ligne) · `/close` ⚡🅐 · `/cancel` | Transitions |

### 2.7. Facturation, paiements, avoirs

| Méthode | Chemin | Description |
|---|---|---|
| GET | `/invoices` · `/invoices/{id}` | Factures (filtres : statut paiement, en retard, client, période) |
| GET | `/invoices/{id}/pdf` · `/share-link` · `/whatsapp-message` | PDF, lien signé, message prérempli |
| GET/POST | `/invoices/{id}/payments` ⚡ | Enregistrer un règlement (PAY-001…005) |
| GET | `/payments` · `/payments/{id}` · `/payments/{id}/receipt.pdf` | Règlements, reçus |
| POST | `/payments/{id}/reverse` ⚡🅐 | Contre-passation |
| POST | `/invoices/{id}/change-payment-method` ⚡🅐 | Procédure D-059 |
| GET | `/receivables?filter[overdue]=1` | Créances (vue) |
| GET | `/receivables/{invoiceId}/reminder` | Message de relance WhatsApp prérempli |
| GET/POST | `/credit-notes` · GET/PATCH `/{id}` | Avoirs |
| POST | `/credit-notes/{id}/issue` ⚡🅐 | Émettre |
| POST | `/credit-notes/{id}/apply` ⚡ `{invoice_id, amount}` | Imputer sur une facture |
| GET | `/credit-notes/{id}/pdf` | |

### 2.8. Trésorerie

| Méthode | Chemin | Description |
|---|---|---|
| GET/POST/PATCH | `/financial-accounts` | Comptes 🔒 soldes |
| GET | `/financial-accounts/{id}/movements` | Mouvements 🔒 |
| GET | `/financial/pending` | Rubrique « En attente » (approbations à effet financier) 🔒 |
| GET/POST | `/account-transfers` ⚡ | Transferts entre comptes |
| POST | `/financial-corrections` ⚡🅐 | Correction financière (mouvement correctif justifié) |
| GET/POST/PATCH | `/expense-categories` | |
| GET/POST | `/expenses` ⚡ · POST `/expenses/{id}/reverse` ⚡🅐 | Dépenses |
| GET/PUT | `/opening` · POST `/opening/post` ⚡ | Document d'ouverture (soldes, stock) |

### 2.9. Approbations, notifications, audit, imports, rapports

| Méthode | Chemin | Description |
|---|---|---|
| GET | `/approval-requests?filter[status]=pending&filter[mine]=1` | Demandes |
| GET | `/approval-requests/{id}` | Détail (avant/après, effets provisoires, historique) |
| POST | `/approval-requests/{id}/approve` ⚡ · `/reject` ⚡ · `/cancel` | Décision (idempotente) |
| GET | `/notifications` · POST `/notifications/{id}/read` · `/read-all` | In-app |
| GET | `/audit-logs?filter[subject_type]=&filter[subject_id]=&filter[user_id]=` | Audit de l'entreprise |
| GET | `/search?q=` | Recherche globale (produits, clients, documents par numéro) |
| POST | `/imports` (multipart, `type`) | Créer un import |
| PUT | `/imports/{id}/mapping` | Correspondance des colonnes |
| POST | `/imports/{id}/validate` · `/simulate` · `/commit` ⚡ · `/cancel` | Étapes |
| GET | `/imports/{id}` · `/rows?filter[status]=error` · `/report` | Prévisualisation, erreurs, rapport |
| GET | `/imports/templates/{type}.xlsx` | Modèles vierges |
| GET | `/dashboard?period=today|week|month|year|custom&from=&to=` | Indicateurs entreprise (champs sensibles selon permissions) |
| GET | `/dashboard/alerts` | Stock faible, créances échues, marge négative, arrivages attendus, approbations |
| GET | `/reports/{report}` | `sales`, `margins`🔒, `profit`🔒, `stock`, `stock-valuation`🔒, `receivables`, `payables`🔒, `cash`🔒, `expenses`, `top-products`, `slow-movers`, `top-customers`, `shipment-profitability`🔒, `approvals` |
| POST | `/reports/{report}/exports` `{format: csv|xlsx|pdf, filters}` | Export asynchrone |
| GET | `/exports/{id}` · `/exports/{id}/download` | Statut / téléchargement contrôlé |
| GET | `/files/{id}/download` | Téléchargement contrôlé (policy) |
| GET | `/public/documents/{token}` | Lien de partage signé (hors session ; limité en débit ; révocable) |

---

## 3. Extrait normatif OpenAPI

```yaml
openapi: 3.1.0
info:
  title: FDS Business API
  version: 1.0.0
  description: API REST de gestion commerciale multi-entreprises. Documentation en français.
servers:
  - url: https://{host}/api/v1
    variables: { host: { default: app.fds-business.example } }
security:
  - sessionCookie: []
tags:
  - { name: Ventes, description: Devis, commandes, livraisons, retours }
  - { name: Facturation, description: Factures, règlements, avoirs }

components:
  securitySchemes:
    sessionCookie: { type: apiKey, in: cookie, name: __Host-fds_session }
  parameters:
    IdempotencyKey:
      name: Idempotency-Key
      in: header
      required: true
      description: Clé unique générée par le client ; rejoue la réponse initiale pendant 24 h.
      schema: { type: string, minLength: 16, maxLength: 64 }
    Id:
      name: id
      in: path
      required: true
      schema: { $ref: '#/components/schemas/Ulid' }
  schemas:
    Ulid: { type: string, pattern: '^[0-9A-HJKMNP-TV-Z]{26}$' }
    Decimal: { type: string, pattern: '^-?\d+(\.\d{1,8})?$', description: Nombre décimal exact transmis en chaîne. }
    CurrencyCode: { type: string, pattern: '^[A-Z]{3}$' }
    Money:
      type: object
      required: [amount, currency]
      properties:
        amount: { $ref: '#/components/schemas/Decimal' }
        currency: { $ref: '#/components/schemas/CurrencyCode' }
    Problem:
      type: object
      required: [type, title, status, code]
      properties:
        type: { type: string, format: uri }
        title: { type: string }
        status: { type: integer }
        code:
          type: string
          enum: [validation_failed, unauthenticated, session_expired, forbidden, not_found,
                 insufficient_stock, already_processed, invalid_transition, version_mismatch,
                 idempotency_conflict, company_suspended, payment_method_locked, overpayment,
                 has_history, cbm_missing, credit_exceeds_balance, too_many_requests, internal_error]
        detail: { type: string }
        instance: { type: string }
        request_id: { $ref: '#/components/schemas/Ulid' }
        errors:
          type: array
          items:
            type: object
            properties:
              pointer: { type: string }
              code: { type: string }
              message: { type: string }
              meta: { type: object, additionalProperties: true }
    SalesOrderStatus:
      type: string
      enum: [draft, pending_approval, confirmed, preparing, ready, in_delivery, partially_delivered, delivered, cancelled]
    SalesOrderLine:
      type: object
      required: [id, variant_id, unit, qty_units, qty_base, unit_price, line_total]
      properties:
        id: { $ref: '#/components/schemas/Ulid' }
        variant_id: { $ref: '#/components/schemas/Ulid' }
        description: { type: string }
        unit: { type: object, properties: { id: { $ref: '#/components/schemas/Ulid' }, code: { type: string }, factor: { type: integer } } }
        qty_units: { type: integer, minimum: 1 }
        qty_base: { type: integer, minimum: 1 }
        list_unit_price: { $ref: '#/components/schemas/Money' }
        unit_price: { $ref: '#/components/schemas/Money' }
        discount_amount: { $ref: '#/components/schemas/Money' }
        line_total: { $ref: '#/components/schemas/Money' }
        qty_delivered: { type: integer }
        estimated_unit_cost: { $ref: '#/components/schemas/Money', description: 'Présent uniquement avec products.view_sensitive' }
    SalesOrder:
      type: object
      required: [id, number, status, customer, warehouse_id, lines, total, version]
      properties:
        id: { $ref: '#/components/schemas/Ulid' }
        number: { type: string, examples: ['CMD-2026-0042'] }
        status: { $ref: '#/components/schemas/SalesOrderStatus' }
        fulfilment_mode: { type: string, enum: [pickup, delivery] }
        fulfilment_status: { type: string, enum: [none, preparing, partially_delivered, delivered] }
        customer: { type: object, properties: { id: { $ref: '#/components/schemas/Ulid' }, name: { type: string } } }
        warehouse_id: { $ref: '#/components/schemas/Ulid' }
        lines: { type: array, items: { $ref: '#/components/schemas/SalesOrderLine' } }
        subtotal: { $ref: '#/components/schemas/Money' }
        discount_total: { $ref: '#/components/schemas/Money' }
        tax_total: { $ref: '#/components/schemas/Money' }
        total: { $ref: '#/components/schemas/Money' }
        invoice: { type: ['object', 'null'], properties: { id: { $ref: '#/components/schemas/Ulid' }, number: { type: string } } }
        approval_request_id: { oneOf: [ { $ref: '#/components/schemas/Ulid' }, { type: 'null' } ] }
        has_dispute: { type: boolean }
        version: { type: integer }
    ApprovalRequest:
      type: object
      required: [id, number, operation_type, status]
      properties:
        id: { $ref: '#/components/schemas/Ulid' }
        number: { type: string }
        operation_type: { type: string, enum: [discount, price_override, order_cancellation, stock_adjustment, credit_note, financial_correction] }
        status: { type: string, enum: [draft, pending, approved, rejected, cancelled] }
        subject: { type: object, properties: { type: { type: string }, id: { $ref: '#/components/schemas/Ulid' } } }
        pending_amount: { $ref: '#/components/schemas/Money' }
    PaymentMethod: { type: string, enum: [cash, wave, orange_money, bank_transfer] }
    RecordPaymentRequest:
      type: object
      required: [method, financial_account_id, amount, paid_at]
      properties:
        method: { $ref: '#/components/schemas/PaymentMethod' }
        financial_account_id: { $ref: '#/components/schemas/Ulid' }
        amount: { $ref: '#/components/schemas/Decimal' }
        paid_at: { type: string, format: date-time }
        external_reference: { type: string, maxLength: 64 }
    Payment:
      type: object
      properties:
        id: { $ref: '#/components/schemas/Ulid' }
        number: { type: string, examples: ['REC-2026-0105'] }
        invoice_id: { $ref: '#/components/schemas/Ulid' }
        method: { $ref: '#/components/schemas/PaymentMethod' }
        amount: { $ref: '#/components/schemas/Money' }
        status: { type: string, enum: [posted, reversed] }
        invoice_balance_due: { $ref: '#/components/schemas/Money' }
        invoice_payment_status: { type: string, enum: [unpaid, partially_paid, paid] }
  responses:
    Problem:
      description: Erreur normalisée
      content: { application/problem+json: { schema: { $ref: '#/components/schemas/Problem' } } }

paths:
  /sales-orders/{id}/submit:
    post:
      tags: [Ventes]
      summary: Soumettre une commande (confirmation ou demande d'approbation)
      description: |
        Réserve l'intégralité des quantités (ST-003, CMD-004). Si une règle d'approbation s'applique,
        la réservation est provisoire, aucune facture n'est émise et la réponse est 202 (SAL-001).
        Sinon, la commande est confirmée et la facture FAC-AAAA-NNNN est émise atomiquement (SAL-002).
      parameters:
        - $ref: '#/components/parameters/Id'
        - $ref: '#/components/parameters/IdempotencyKey'
        - { name: If-Match, in: header, required: true, schema: { type: string } }
      responses:
        '200':
          description: Commande confirmée et facturée
          content: { application/json: { schema: { type: object, properties: { data: { $ref: '#/components/schemas/SalesOrder' } } } } }
        '202':
          description: Approbation nécessaire ; réservation provisoire en place
          content: { application/json: { schema: { type: object, properties: { data: { $ref: '#/components/schemas/SalesOrder' }, approval_request: { $ref: '#/components/schemas/ApprovalRequest' } } } } }
        '404': { $ref: '#/components/responses/Problem' }
        '409': { $ref: '#/components/responses/Problem' }
        '412': { $ref: '#/components/responses/Problem' }
        '422': { $ref: '#/components/responses/Problem' }
        '423': { $ref: '#/components/responses/Problem' }

  /invoices/{id}/payments:
    post:
      tags: [Facturation]
      summary: Enregistrer un règlement client
      description: |
        PAY-001 : le premier règlement fixe le moyen de paiement de la facture.
        PAY-003 : montant > 0 et ≤ solde dû. Génère le reçu REC-AAAA-NNNN et le mouvement financier.
      parameters:
        - $ref: '#/components/parameters/Id'
        - $ref: '#/components/parameters/IdempotencyKey'
      requestBody:
        required: true
        content: { application/json: { schema: { $ref: '#/components/schemas/RecordPaymentRequest' } } }
      responses:
        '201':
          description: Règlement enregistré
          headers: { Location: { schema: { type: string } } }
          content: { application/json: { schema: { type: object, properties: { data: { $ref: '#/components/schemas/Payment' } } } } }
        '409': { $ref: '#/components/responses/Problem' }
        '422': { $ref: '#/components/responses/Problem' }
        '423': { $ref: '#/components/responses/Problem' }

  /approval-requests/{id}/approve:
    post:
      tags: [Approbations]
      summary: Approuver une demande (idempotent)
      description: Exécute l'opération et lève les effets provisoires dans une seule transaction (APR-005).
      parameters:
        - $ref: '#/components/parameters/Id'
        - $ref: '#/components/parameters/IdempotencyKey'
      requestBody:
        content: { application/json: { schema: { type: object, properties: { comment: { type: string, maxLength: 1000 } } } } }
      responses:
        '200': { description: Demande approuvée et opération exécutée }
        '403': { $ref: '#/components/responses/Problem' }
        '409': { $ref: '#/components/responses/Problem' }
```

---

## 4. Exemples d'échanges

### 4.1. Vente à crédit (TEST-011)

```http
POST /api/v1/invoices/01JC…/payments
Idempotency-Key: 01JCXK8H0V3Q8T1Q4M7R2N5W6Y
Content-Type: application/json

{ "method": "wave", "financial_account_id": "01JA…", "amount": "40000", "paid_at": "2026-11-04T09:30:00Z" }
```

```json
HTTP/1.1 201 Created
{ "data": { "number": "REC-2026-0105", "method": "wave",
            "amount": { "amount": "40000", "currency": "XOF" },
            "invoice_balance_due": { "amount": "60000", "currency": "XOF" },
            "invoice_payment_status": "partially_paid" } }
```

Second règlement en espèces (TEST-012) :

```json
HTTP/1.1 422 Unprocessable Content
{ "code": "payment_method_locked", "title": "Moyen de paiement verrouillé",
  "detail": "Cette facture est réglée par Wave. Utilisez Wave ou la procédure de changement de moyen de paiement.", "status": 422 }
```

### 4.2. Rejeu idempotent (TEST-008)

Un second `POST /goods-receipts/{id}/validate` avec la **même** `Idempotency-Key` renvoie la réponse initiale (200) sans écriture ; avec une **autre** clé, il renvoie `409 already_processed`.

---

## 5. Gouvernance du contrat

- Toute modification du contrat passe par une revue (fichier `openapi/` dans la même PR que le code).
- Spectral (règles : opérations documentées en français, `operationId` unique, réponses d'erreur présentes, pas de `company_id` dans les entrées métier).
- Tests backend : chaque test de fonctionnalité valide la réponse contre le schéma (`league/openapi-psr7-validator`).
- Frontend : `openapi-typescript` régénère `schema.d.ts` ; la CI échoue si le fichier n'est pas à jour.
- Rupture de compatibilité ⇒ `/api/v2` (non prévu en V1, SPA et API étant déployées ensemble).
