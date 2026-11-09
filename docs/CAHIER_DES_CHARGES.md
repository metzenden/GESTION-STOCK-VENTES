# CAHIER DES CHARGES FONCTIONNEL ET TECHNIQUE
## FDS Business — Plateforme de gestion commerciale multi-entreprises

**Version :** 1.0 — Spécification initiale  
**Date :** 3 novembre 2026  
**Statut :** Base de développement à valider  
**Langue :** Français  
**Devise principale :** Franc CFA (XOF)  
**Périmètre :** Version 1 complète avant mise en production

---

# 1. Présentation générale

## 1.1. Contexte

FDS Business est une application web de gestion commerciale destinée à des activités de négoce, de vente en gros et au détail, et d'importation de marchandises, notamment depuis la Chine.

L'application doit centraliser les opérations commerciales, logistiques et financières de plusieurs entreprises indépendantes.

Chaque entreprise dispose de ses propres produits, dépôts, stocks, clients, fournisseurs, commandes, factures, comptes financiers et tableaux de bord.

Le propriétaire de la plateforme dispose d'un compte super-administrateur permettant de créer et d'administrer les entreprises et de consulter leurs statistiques consolidées.

## 1.2. Objectifs

Les objectifs principaux sont :

1. Connaître les quantités réellement disponibles par produit et par dépôt.
2. Suivre les achats, fournisseurs, conteneurs et arrivages.
3. Calculer précisément le coût de revient des marchandises importées.
4. Gérer les ventes au détail et en gros, à la pièce ou au carton.
5. Suivre les commandes, réservations, livraisons et retours.
6. Gérer les factures, paiements, créances, avoirs et dettes fournisseurs.
7. Mesurer les marges, bénéfices, dépenses et mouvements de trésorerie.
8. Fournir des tableaux de bord fiables par entreprise et consolidés.
9. Garantir une séparation stricte des données entre entreprises.
10. Assurer une traçabilité complète des opérations sensibles.

## 1.3. Stratégie de livraison

**Validé :** toutes les fonctionnalités définies pour la V1 doivent être développées et testées avant l'ouverture en production.

Le développement pourra être organisé en lots techniques successifs, sans mise en production partielle.

Aucune date limite n'est imposée.

## 1.4. Identification commerciale initiale

| Paramètre | Valeur |
|---|---|
| Nom commercial | FDS Business |
| Adresse | Mariste, Dakar, Sénégal |
| Téléphone | +221 76 179 43 51 |
| E-mail | metzenden@gmail.com |
| NINEA / RCCM | Non renseignés |
| Logo | Ajout ultérieur |
| Factures | FAC-AAAA-0001 |

Ces paramètres doivent être modifiables sans intervention sur le code source.

Dans une architecture multi-entreprises, ces informations correspondent à l'entreprise FDS Business initiale et non nécessairement à l'identité de toutes les entreprises hébergées.

---

# 2. Périmètre fonctionnel

La V1 comprend les modules suivants :

| Code | Module | Priorité |
|---|---|---|
| MOD-01 | Super-administration et entreprises | Obligatoire |
| MOD-02 | Authentification, utilisateurs et permissions | Obligatoire |
| MOD-03 | Catalogue produits et variantes | Obligatoire |
| MOD-04 | Dépôts, stocks et inventaires | Obligatoire |
| MOD-05 | Fournisseurs et achats | Obligatoire |
| MOD-06 | Conteneurs, packing lists et réceptions | Obligatoire |
| MOD-07 | Coût de revient et valorisation | Obligatoire |
| MOD-08 | Clients et devis | Obligatoire |
| MOD-09 | Commandes, ventes et facturation | Obligatoire |
| MOD-10 | Livraisons, retours et avoirs | Obligatoire |
| MOD-11 | Paiements, crédits et dettes | Obligatoire |
| MOD-12 | Trésorerie et dépenses | Obligatoire |
| MOD-13 | Tableaux de bord et indicateurs | Obligatoire |
| MOD-14 | Rapports et exports | Obligatoire |
| MOD-15 | Approbations et audit | Obligatoire |
| MOD-16 | Paramétrage et notifications | Obligatoire |
| MOD-17 | Importation initiale des données | Obligatoire |
| MOD-18 | Sauvegarde, restauration et supervision | Obligatoire |

Les fonctionnalités natives Android et iOS, le fonctionnement hors ligne et l'intégration automatique avec les opérateurs de paiement ne font pas partie des besoins validés.

---

# 3. Architecture organisationnelle multi-entreprises

## 3.1. Modèle d'administration

La plateforme comprend deux niveaux d'administration.

### Super-administrateur

Le super-administrateur peut :

- Créer, activer, suspendre et administrer les entreprises.
- Inviter les administrateurs d'entreprise.
- Accéder aux entreprises au moyen d'un sélecteur.
- Consulter les statistiques consolidées.
- Administrer les paramètres techniques globaux.
- Consulter les audits globaux.
- Gérer les sauvegardes et restaurations.
- Superviser les utilisateurs administrateurs.

### Administrateur d'entreprise

L'administrateur d'entreprise peut, dans la limite de ses autorisations :

- Gérer les utilisateurs de son entreprise.
- Configurer les rôles et permissions.
- Gérer les produits, stocks, achats, ventes et finances.
- Consulter les rapports de son entreprise.
- Configurer les approbations et paramètres locaux.

### Utilisateurs ordinaires

Les profils prévus sont :

- Vendeur / caissier.
- Magasinier.
- Comptable.

Un utilisateur ordinaire est obligatoirement rattaché à une seule entreprise.

Il ne peut pas changer d'entreprise ni consulter des informations appartenant à une autre entreprise.

## 3.2. Isolation des données

**Règle MT-001 — Validée :** chaque entreprise possède ses données métier indépendantes.

**Règle MT-002 — Validée :** toutes les recettes, dépenses, créances, dettes, stocks et documents sont isolés par entreprise.

**Règle MT-003 — Validée :** seul le super-administrateur peut utiliser le sélecteur d'entreprise.

**Règle MT-004 — Validée :** seul le super-administrateur peut consulter les indicateurs consolidés.

**Règle MT-005 — Validée :** aucun transfert direct de marchandises entre entreprises n'est autorisé.

**Règle MT-006 — Validée :** les numérotations de documents sont indépendantes pour chaque entreprise.

**Règle MT-007 — Recommandée :** toute requête métier doit être exécutée dans le contexte explicite d'une entreprise autorisée, déterminé côté serveur.

**Règle MT-008 — Recommandée :** le changement d'entreprise par le super-administrateur doit être journalisé.

**Règle MT-009 — Recommandée :** les exports, images, pièces jointes, fichiers PDF et sauvegardes doivent respecter les mêmes règles d'isolation que les données SQL.

## 3.3. Paramètres par entreprise

Chaque entreprise dispose de :

- Son identité commerciale.
- Ses dépôts et stocks.
- Ses comptes financiers.
- Ses tarifs de vente.
- Ses formules de coût de revient.
- Sa devise de référence et ses taux de change.
- Ses seuils d'alerte.
- Ses préfixes et séquences documentaires.
- Ses utilisateurs et permissions.
- Ses règles d'approbation.

## 3.4. Création d'une entreprise

Le super-administrateur saisit :

1. Nom commercial.
2. Coordonnées.
3. Devise de référence.
4. Administrateur principal.
5. Dépôt initial.

Le système crée l'entreprise dans un état initial cohérent, puis envoie une invitation sécurisée à son administrateur.

**Recommandé :** la création de l'entreprise, de son dépôt initial, de ses paramètres et de son invitation doit être atomique ou compensable en cas d'échec.

## 3.5. Suspension

Une entreprise suspendue passe en lecture seule.

Les utilisateurs autorisés peuvent consulter les données existantes, mais ne peuvent plus créer, modifier, annuler ou valider d'opérations.

**Recommandé :** bloquer également les modifications par API, les imports, les traitements différés et les approbations qui produiraient des écritures.

---

# 4. Authentification, rôles et permissions

## 4.1. Authentification

**Validé :**

- Connexion par e-mail et mot de passe.
- Expiration après 240 minutes d'inactivité.
- Réinitialisation du mot de passe par lien sécurisé envoyé par e-mail.
- Protection contre les tentatives de connexion répétées.
- Mots de passe hachés.
- HTTPS obligatoire.

**Recommandé :**

- Hachage Argon2id ou mécanisme Laravel équivalent correctement configuré.
- Cookies de session sécurisés, HttpOnly et SameSite.
- Protection CSRF si authentification par cookies.
- Révocation des sessions lors de la désactivation d'un compte.
- Jetons de réinitialisation à durée limitée et à usage unique.
- Rotation de session après authentification.
- Protection renforcée du compte super-administrateur.

La double authentification n'a pas été retenue comme obligatoire dans le cadrage.

## 4.2. Permissions

Les permissions sont configurables par module et par action.

Actions prévues :

- Consulter.
- Créer.
- Modifier.
- Annuler.
- Exporter.
- Supprimer, uniquement lorsque les règles métier l'autorisent.
- Approuver.

Les permissions sont vérifiées par Laravel sur chaque opération sensible.

La simple dissimulation d'un bouton dans React n'est jamais considérée comme une protection suffisante.

## 4.3. Permissions initiales

### Vendeur

- Créer des ventes et commandes.
- Gérer les clients.
- Enregistrer des paiements.
- Consulter et imprimer des factures.
- Consulter les stocks autorisés.

### Magasinier

- Consulter et gérer les fiches produits.
- Réaliser des inventaires.
- Enregistrer les réceptions.
- Effectuer des transferts entre dépôts.
- Préparer les livraisons.

### Comptable

- Consulter les mouvements financiers.
- Enregistrer les dépenses.
- Suivre les créances et dettes.
- Consulter et exporter les rapports financiers.
- Enregistrer les paiements.

Ces permissions constituent des profils par défaut modifiables.

## 4.4. Informations sensibles

Les données suivantes nécessitent des autorisations spécifiques :

- Prix d'achat.
- Coûts fournisseurs.
- Marges et bénéfices.
- Soldes financiers.
- Formules de coût de revient.
- Informations fournisseurs.

**Recommandé :** appliquer ces restrictions aux réponses API, aux exports et aux documents, et pas uniquement aux écrans.

---

# 5. Catalogue produits

## 5.1. Fiche produit

Chaque produit comprend au minimum :

- Identifiant interne.
- Référence unique dans l'entreprise.
- Désignation.
- Description.
- Catégorie.
- Photos.
- Variantes éventuelles.
- Unité de vente.
- Nombre de pièces par carton, si applicable.
- Prix de vente en gros.
- Prix de vente au détail.
- Seuil d'alerte.
- Statut actif/inactif.

Les informations de coût de revient et de stock sont liées aux lots et aux dépôts plutôt que conservées uniquement comme une valeur globale du produit.

## 5.2. Variantes

Les variantes peuvent représenter :

- Couleur.
- Taille.
- Modèle.
- Autres attributs configurables.

**Recommandé :** chaque variante stockable doit posséder un identifiant propre et, si nécessaire, une référence commerciale distincte.

## 5.3. Prix

Le système propose un prix à partir des tarifs configurés.

Le prix peut être modifié lors d'une vente, sous réserve des permissions et règles d'approbation.

Les prix historiques des ventes ne doivent pas être modifiés rétroactivement lorsque le tarif catalogue change.

## 5.4. Suppression

Un produit sans opération associée peut être supprimé.

Un produit ayant un historique de stock, d'achat ou de vente doit être désactivé ou archivé.

**Critère d'acceptation :** la suppression d'un produit déjà vendu doit être refusée, même si son stock actuel est nul.

---

# 6. Dépôts et gestion des stocks

## 6.1. Dépôts

Une entreprise peut créer un nombre configurable de dépôts, sans limite métier fixe.

Chaque dépôt possède :

- Un identifiant.
- Un nom.
- Une adresse facultative.
- Un statut.
- Son stock par produit, variante et lot.

Un dépôt par défaut peut être configuré pour les ventes.

## 6.2. États du stock

Le système distingue :

- Stock physiquement présent.
- Stock réservé.
- Stock disponible à la vente.
- Stock en livraison.
- Stock endommagé ou bloqué.
- Stock définitivement sorti.
- Stock attendu, non encore réceptionné.

**Règle ST-001 — Validée :** une quantité attendue dans un conteneur ne peut pas être vendue comme stock disponible.

**Règle ST-002 — Validée :** seule une réception validée alimente le stock physique.

**Règle ST-003 — Validée :** une commande confirmée réserve automatiquement les quantités nécessaires.

**Règle ST-004 — Validée :** une marchandise expédiée passe à l'état « en livraison » et n'est plus vendable.

**Règle ST-005 — Validée :** la sortie définitive du stock est constatée lors de la remise effective au client.

**Règle ST-006 — Validée :** une vente dépassant le stock disponible doit être bloquée.

**Règle ST-007 — Recommandée :** une même unité ne peut appartenir simultanément à plusieurs états exclusifs.

## 6.3. Formule de disponibilité

Pour un dépôt et une variante donnés :

**Stock disponible = stock physiquement présent − stock réservé − stock bloqué − stock en livraison.**

Cette formule suppose que les marchandises en livraison restent comptabilisées dans le stock physique jusqu'à la remise au client.

L'implémentation doit utiliser une définition unique de chaque état pour éviter les doubles soustractions.

## 6.4. Mouvements de stock

Types de mouvements :

- Réception.
- Vente livrée.
- Retour accepté.
- Transfert entrant.
- Transfert sortant.
- Ajustement positif.
- Ajustement négatif.
- Déclaration de perte ou de dommage.
- Correction ou contre-écriture.

Chaque mouvement contient :

- Entreprise.
- Produit et variante.
- Lot.
- Dépôt.
- Quantité signée.
- Type de mouvement.
- Référence de l'opération d'origine.
- Horodatage.
- Auteur.
- Informations d'audit.

Les mouvements validés sont conservés dans l'historique.

## 6.5. Inventaires

Fonctionnalités :

- Inventaire complet.
- Inventaire partiel.
- Feuille d'inventaire imprimable.
- Saisie des quantités comptées.
- Calcul des écarts.
- Ajustement après validation.
- Historique avant/après.

Le cadrage autorise les ajustements avec historique sans imposer systématiquement un motif de saisie.

**Recommandé :** conserver un motif facultatif et rendre le motif obligatoire lorsque la règle d'approbation applicable l'exige.

## 6.6. Transferts entre dépôts

Un transfert concerne deux dépôts d'une même entreprise.

États recommandés :

1. Brouillon.
2. Confirmé.
3. En transit.
4. Réceptionné.
5. Annulé, si l'état le permet.

Un transfert ne crée ni vente ni chiffre d'affaires.

La quantité totale de l'entreprise reste inchangée, hors perte ou écart explicitement constaté.

## 6.7. Vente au carton et à la pièce

Le système gère les conversions.

Exemple :

- 1 carton = 6 pièces.
- Vente de 2 cartons = 12 pièces.
- Vente de 3 pièces = 3 pièces.

**Recommandé :** utiliser une unité de stock de référence, généralement la pièce, avec une conversion exacte pour les cartons.

---

# 7. Fournisseurs et achats

## 7.1. Fournisseurs

Une fiche fournisseur comprend :

- Nom.
- Coordonnées.
- Pays.
- Devise habituelle.
- Historique des achats.
- Documents.
- Solde dû.

## 7.2. Commandes fournisseurs

Une commande fournisseur contient :

- Entreprise.
- Fournisseur.
- Référence.
- Date.
- Articles et variantes.
- Quantités.
- Prix unitaires.
- Devise.
- Montant total.
- Statut.
- Paiements associés.

États recommandés :

Brouillon → Confirmée → Partiellement reçue → Reçue → Clôturée.

Des états d'annulation sont prévus lorsque cela reste possible.

## 7.3. Paiements fournisseurs

Le système accepte :

- Paiement intégral.
- Acompte.
- Plusieurs règlements successifs.
- Solde restant.

Les dettes fournisseurs doivent être calculées à partir des engagements et règlements enregistrés, sans confusion avec les dépenses déjà décaissées.

---

# 8. Conteneurs, packing lists et réceptions

## 8.1. Fiche conteneur / arrivage

Champs :

- Numéro de conteneur.
- Transporteur.
- Fournisseurs.
- Commandes d'achat liées.
- Date d'expédition.
- Date d'arrivée prévue.
- Date d'arrivée réelle.
- Statut.
- Documents associés.
- Coûts.
- Articles attendus.
- Dépôts de destination.

## 8.2. États recommandés

- Préparation.
- Expédié.
- En transit.
- Arrivé.
- Déchargement en cours.
- Réception partielle.
- Réception terminée.
- Clôturé.

## 8.3. Importation des packing lists

Formats acceptés :

- Excel.
- PDF.

Les données recherchées incluent :

- Référence produit.
- Désignation.
- Image, si disponible.
- Nombre de cartons.
- Quantité totale.
- Pièces par carton.
- CBM.
- Prix unitaire.
- Montant.

**Recommandé :** prévoir un moteur d'import avec correspondance configurable des colonnes, car les packing lists fournisseurs ne possèdent pas nécessairement le même format.

Pour les PDF, la reconnaissance automatique doit être suivie d'une vérification humaine. Une extraction parfaite de tous les PDF ne peut pas être garantie.

## 8.4. Déchargement

Deux modes :

1. Saisie manuelle des quantités.
2. Lecture de références ou codes-barres avec un lecteur compatible.

Le système compare :

- Quantité attendue.
- Quantité comptée.
- Quantité acceptée.
- Quantité endommagée.
- Quantité manquante.

## 8.5. Validation

**Règle IMP-001 :** l'import d'une packing list ne modifie pas le stock disponible.

**Règle IMP-002 :** la saisie d'un comptage non validé ne modifie pas le stock disponible.

**Règle IMP-003 :** la validation d'une réception génère les lots et mouvements de stock correspondants.

**Règle IMP-004 :** une réception ne doit jamais être comptabilisée deux fois.

**Règle IMP-005 :** les écarts doivent rester consultables après clôture.

**Recommandé :** permettre la validation d'une réception physique indépendamment de la finalisation du coût de revient, tout en bloquant la valorisation définitive tant que les données financières indispensables sont absentes.

Ce dernier point devra être validé fonctionnellement.

---

# 9. Coût de revient et valorisation

## 9.1. Principes validés

Le coût de revient doit être calculé :

- Par lot d'arrivage.
- Par produit ou variante.
- À la pièce.
- Au carton.
- Avec répartition des frais communs au CBM.
- Avec taux de change modifiable.
- Avec formule standard et modèles personnalisables.

## 9.2. Frais inclus

Frais sélectionnés :

1. Fret maritime.
2. Douane.
3. Manutention.
4. Transport local.
5. Autres frais configurables.

Les frais d'assurance, de stockage et bancaires ne font pas partie des catégories sélectionnées par défaut. Ils peuvent être ajoutés ultérieurement dans « Autres frais » si nécessaire.

## 9.3. Formule standard

Pour une ligne d'arrivage i :

**Prix d'achat convertiᵢ = prix d'achat en devise × taux de change enregistré.**

**Part des fraisᵢ = frais communs × (CBMᵢ / CBM total éligible).**

**Coût total de la ligneᵢ = achat convertiᵢ + part des fraisᵢ + frais directs éventuels.**

**Coût unitaire pièceᵢ = coût total de la ligneᵢ / quantité de pièces acceptées.**

**Coût unitaire cartonᵢ = coût unitaire pièceᵢ × nombre de pièces par carton.**

Le CBM d'une ligne doit correspondre au volume total des marchandises concernées, et non à un CBM unitaire utilisé sans conversion.

## 9.4. Règles de calcul

**CR-001 :** le CBM total utilisé pour la répartition doit être strictement positif.

**CR-002 :** les frais ne peuvent pas être répartis deux fois.

**CR-003 :** les taux de change effectivement utilisés doivent être conservés avec le calcul.

**CR-004 :** un lot conserve son coût historique après validation.

**CR-005 :** une modification de formule ne doit pas recalculer automatiquement les ventes historiques.

**CR-006 :** les données manquantes bloquent la validation du coût définitif.

**CR-007 :** le coût affiché est arrondi au franc CFA le plus proche.

**CR-008 — Recommandée :** les calculs intermédiaires utilisent une précision décimale suffisante ; les écarts d'arrondi sont réconciliés pour que la somme des coûts alloués corresponde aux frais d'origine.

## 9.5. Gestion des lots

Chaque réception crée un ou plusieurs lots identifiables.

Un lot possède :

- Référence.
- Produit/variante.
- Arrivage d'origine.
- Date de réception.
- Quantité initiale.
- Quantité restante.
- Coût unitaire.
- Devise et taux utilisés.
- Détail des frais répartis.

**Recommandé :** utiliser FIFO pour l'affectation comptable des sorties, avec traçabilité du lot physique lorsque celui-ci est connu.

Si le lot réellement livré diffère du lot prévu, la règle de valorisation devra rester cohérente avec le suivi physique.

## 9.6. Formules personnalisables

L'administrateur peut enregistrer plusieurs modèles de calcul.

Chaque modèle comprend :

- Nom.
- Version.
- Variables autorisées.
- Expression.
- Conditions d'utilisation.
- Date d'activation.

**Recommandé :** utiliser un moteur d'expressions limité et validé, sans exécution de code PHP ou JavaScript arbitraire.

---

# 10. Clients, devis et commandes

## 10.1. Clients

Champs :

- Nom ou raison sociale.
- Téléphone.
- Adresse.
- E-mail facultatif.
- Historique des achats.
- Créances.
- Avoirs disponibles.
- Statut.

Un client est propre à une entreprise.

## 10.2. Devis

Le système permet :

- Création.
- Modification avant validation.
- Génération PDF.
- Partage WhatsApp.
- Conversion en commande.

## 10.3. Commandes clients

Une commande contient :

- Client.
- Articles.
- Quantités.
- Prix appliqués.
- Remises.
- Dépôt source.
- Mode de retrait ou livraison.
- Coordonnées du destinataire.
- Statut.
- Facture liée.
- Paiements liés.

## 10.4. Workflow

États recommandés :

Brouillon → Confirmée → Préparée → En livraison → Livrée.

Des branches permettent :

- Annulation.
- Échec de livraison.
- Retour.
- Litige.

**Règle CMD-001 — Validée :** la confirmation réserve les quantités.

**Règle CMD-002 — Validée :** l'expédition rend les quantités indisponibles à d'autres ventes.

**Règle CMD-003 — Validée :** la remise au client déclenche la sortie définitive du stock.

**Règle CMD-004 — Recommandée :** une commande ne peut être confirmée si la réservation complète ne peut être réalisée, sauf règle métier spécifique ultérieure.

---

# 11. Facturation

## 11.1. Déclenchement

**Validé :** la facture définitive est générée dès la confirmation de commande.

La facture peut donc précéder la livraison.

La comptabilisation du chiffre d'affaires et de la marge doit être définie séparément de la simple émission du document.

**Recommandé :** distinguer :

- Facture émise.
- Paiement reçu.
- Livraison réalisée.
- Vente économiquement réalisée.
- Créance exigible.

Cette séparation évite de confondre une commande facturée mais non livrée avec une vente totalement exécutée.

## 11.2. Numérotation

Format initial :

**FAC-AAAA-0001**

Exemple :

FAC-2026-0001

La séquence est indépendante pour chaque entreprise et chaque année.

**Recommandé :** attribuer le numéro définitif de façon atomique et interdire sa réutilisation.

## 11.3. Contenu

Les factures affichent notamment :

- Identité de l'entreprise.
- Numéro et date.
- Client.
- Produits.
- Quantités.
- Prix unitaires.
- Remises.
- Montant total.
- Montant payé.
- Solde restant.
- Échéance, si applicable.
- Informations de règlement.
- Mentions légales configurées.

## 11.4. Annulation

Une facture émise ne doit pas disparaître de l'historique.

**Recommandé :** gérer les annulations par document correctif ou avoir selon la situation et les règles applicables.

La séquence de numérotation reste traçable.

---

# 12. Paiements, créances et avoirs

## 12.1. Moyens de paiement

Moyens acceptés :

- Espèces.
- Wave.
- Orange Money.
- Virement bancaire.

## 12.2. Un seul moyen par facture

**Validé :** une facture utilise un seul moyen de paiement.

Les paiements partiels restent possibles.

**Règle PAY-001 — Recommandée :** lors du premier paiement, le moyen choisi devient celui de la facture et tous les paiements suivants doivent utiliser le même moyen.

Une modification exceptionnelle nécessite une correction traçable.

## 12.3. Créances

Chaque créance comprend :

- Client.
- Facture.
- Montant initial.
- Montant réglé.
- Solde.
- Date d'échéance.
- Statut.
- Historique des paiements.

Statuts :

- Non payée.
- Partiellement payée.
- Payée.
- En retard.

## 12.4. Reçus

Chaque règlement peut produire un reçu PDF numéroté et rattaché à sa facture.

## 12.5. Avoirs

**Validé :** les remboursements commerciaux sont gérés sous forme d'avoirs clients, et non par décaissement financier automatique.

Un avoir peut être utilisé pour régler une prochaine vente.

**Recommandé :** un avoir ne doit jamais être utilisé pour un montant supérieur à son solde disponible.

## 12.6. Relances

Le système permet :

- Identification des créances échues.
- Préparation d'un message WhatsApp.
- Consultation du relevé client.
- Export PDF du relevé.

**Recommandé :** utiliser un lien WhatsApp prérempli plutôt qu'une intégration d'envoi automatique tant qu'aucune API de messagerie n'a été retenue.

---

# 13. Livraisons, retours et échanges

## 13.1. Livraison

Le système gère :

- Retrait sur place.
- Livraison au client.
- Coordonnées du destinataire.
- Préparation.
- Expédition.
- Remise effective.
- Échec de livraison.
- Historique.

## 13.2. Bon de livraison

Le bon de livraison PDF contient :

- Référence.
- Commande.
- Client.
- Adresse.
- Produits.
- Quantités.
- Date.
- Statut.

## 13.3. Retours

Un retour peut concerner :

- Marchandise non livrée.
- Produit refusé.
- Produit défectueux.
- Échange.

**Règle RET-001 — Validée :** la remise en stock vendable est une décision explicite.

**Règle RET-002 — Recommandée :** un retour endommagé est placé en stock bloqué ou endommagé.

**Règle RET-003 — Recommandée :** un retour doit conserver la référence de la vente et du lot d'origine lorsque disponible.

---

# 14. Trésorerie et finances

## 14.1. Comptes financiers

Chaque entreprise dispose de comptes distincts :

- Caisse.
- Wave.
- Orange Money.
- Banque.

Le nombre de comptes pourra être configurable.

## 14.2. Mouvements

Types :

- Encaissement client.
- Paiement fournisseur.
- Dépense.
- Transfert entre comptes.
- Solde d'ouverture.
- Correction.
- Autre mouvement justifié.

## 14.3. Soldes

**Solde du compte = solde d'ouverture + entrées validées − sorties validées.**

Les opérations en attente d'approbation ne doivent pas modifier le solde définitif.

Le rapprochement manuel des soldes réels n'a pas été retenu.

## 14.4. Dépenses

Une dépense comprend :

- Catégorie.
- Montant.
- Date.
- Compte débité.
- Libellé.
- Justificatif facultatif.
- Entreprise.

Les catégories sont prédéfinies et personnalisables.

## 14.5. Marges et bénéfices

**Marge commerciale brute = chiffre d'affaires net des marchandises vendues − coût des marchandises vendues.**

**Résultat net de gestion = revenus reconnus − coûts des marchandises vendues − charges imputables à la période.**

La distinction entre marge, bénéfice, trésorerie et créances doit être visible dans l'interface.

**Recommandé :** définir explicitement les règles de reconnaissance du revenu, les dépenses déjà incorporées au coût de revient et les autres charges, afin d'éviter les doubles déductions.

## 14.6. Clôtures

Aucune clôture journalière ou mensuelle obligatoire.

Les rapports restent filtrables par période.

---

# 15. Tableaux de bord

## 15.1. Indicateurs par entreprise

- Chiffre d'affaires.
- Marge commerciale.
- Bénéfice net de gestion.
- Quantités en stock.
- Valeur du stock.
- Créances clients.
- Dettes fournisseurs.
- Soldes des comptes financiers.
- Produits les plus vendus.
- Produits à faible rotation.
- Dépenses par catégorie.
- Évolution mensuelle.
- Meilleurs clients.
- Rentabilité des arrivages.

## 15.2. Alertes

- Stock faible.
- Créance échue.
- Vente à marge négative.
- Arrivage attendu.
- Produit inactif.
- Demande d'approbation en attente.

## 15.3. Filtres

- Aujourd'hui.
- Cette semaine.
- Ce mois.
- Cette année.
- Période personnalisée.

## 15.4. Vue consolidée

Réservée au super-administrateur.

Elle présente des indicateurs agrégés sur les entreprises autorisées.

**Recommandé :** ne pas additionner directement des montants exprimés dans des devises différentes. Les consolidations monétaires nécessitent une devise de présentation et des règles de conversion explicites.

---

# 16. Approbations

## 16.1. Opérations concernées

- Remises.
- Annulations.
- Ajustements de stock.
- Avoirs clients.
- Corrections financières.
- Modification des prix de vente.

## 16.2. Paramétrage

Les règles d'approbation sont configurables selon :

- Entreprise.
- Opération.
- Rôle.
- Seuil.
- Permission.

Un utilisateur disposant de l'autorisation nécessaire peut approuver sa propre opération.

## 16.3. États

- Brouillon.
- En attente.
- Approuvée.
- Rejetée.
- Annulée.

Aucune expiration automatique.

## 16.4. Effets provisoires

**Validé :** les opérations peuvent produire des effets provisoires.

**Règles retenues :**

- Les quantités concernées sont réservées ou bloquées.
- Aucun mouvement de stock définitif n'est généré avant approbation.
- Les montants financiers apparaissent dans une rubrique « En attente ».
- Les soldes définitifs restent inchangés.
- Le rejet annule les effets provisoires.
- L'historique est conservé.

Les effets provisoires ne doivent jamais permettre de vendre deux fois la même marchandise.

## 16.5. Notifications

- Notification dans l'application.
- Notification par e-mail.
- Compteur sur le tableau de bord.
- Historique des décisions.
- Filtres et rapports des demandes.

**Recommandé :** les traitements d'approbation doivent être idempotents afin qu'une double validation ne produise pas deux mouvements.

---

# 17. Audit et corrections

## 17.1. Journal d'audit

Informations conservées :

- Date et heure.
- Utilisateur.
- Action.
- Anciennes valeurs.
- Nouvelles valeurs.
- Motif, lorsqu'il existe.
- Entreprise concernée.

## 17.2. Corrections

**Validé :** les opérations définitives sont corrigées par contre-écritures ou opérations compensatoires.

L'opération initiale reste consultable.

Une correction doit préserver les relations entre :

- Vente.
- Facture.
- Paiement.
- Stock.
- Créance.
- Trésorerie.

## 17.3. Suppression

Une donnée sans opération liée peut être supprimée.

Une donnée ayant un historique métier doit être archivée, désactivée ou conservée.

**Recommandé :** les écritures financières et mouvements de stock validés sont immuables.

---

# 18. Importation des données initiales

## 18.1. Données concernées

- Catalogue produits.
- Stocks par dépôt.
- Clients.
- Fournisseurs.

Les anciennes ventes et anciennes dépenses ne sont pas reprises dans le périmètre initial validé.

## 18.2. Soldes d'ouverture

Le système permet de définir :

- Une date de démarrage.
- Des soldes financiers initiaux.
- Les quantités initiales par dépôt.
- Le coût de revient initial par produit ou lot.

Les soldes d'ouverture ne doivent pas être interprétés comme des ventes ou des achats nouveaux.

## 18.3. Workflow d'import

1. Téléversement du fichier.
2. Lecture et correspondance des colonnes.
3. Validation des données.
4. Prévisualisation.
5. Affichage des erreurs et doublons.
6. Correction.
7. Confirmation.
8. Importation définitive.
9. Rapport de résultat.

**Recommandé :** permettre un mode simulation sans écriture en base.

---

# 19. Documents, exports et communication

## 19.1. Documents PDF

- Factures.
- Devis.
- Bons de commande.
- Bons de livraison.
- Reçus.
- Relevés clients.
- Feuilles d'inventaire.
- Rapports.

Format principal : A4.

## 19.2. Exports

- CSV.
- Excel.
- PDF selon les modules.

Les exports doivent respecter les filtres, permissions et limites de l'entreprise active.

## 19.3. WhatsApp

Fonctions :

- Partage de facture.
- Partage de devis.
- Partage d'informations de commande.
- Préparation de relances clients.

Aucun envoi automatique via l'API WhatsApp Business n'est imposé dans cette version.

---

# 20. Interface utilisateur

## 20.1. Principes

- Interface responsive.
- Compatible ordinateur et smartphone.
- Français uniquement.
- Thème clair et sombre.
- Navigation rapide.
- Recherche globale.
- Filtres avancés.
- Pagination.
- Actions groupées.
- Photos produits dans les listes.
- Saisie rapide des ventes.

## 20.2. Écrans principaux

1. Connexion.
2. Tableau de bord.
3. Entreprises.
4. Utilisateurs et rôles.
5. Produits.
6. Catégories et variantes.
7. Dépôts.
8. Stocks.
9. Inventaires.
10. Transferts.
11. Fournisseurs.
12. Achats.
13. Conteneurs.
14. Packing lists.
15. Réceptions.
16. Coûts de revient.
17. Clients.
18. Devis.
19. Commandes.
20. Ventes et factures.
21. Livraisons.
22. Retours et avoirs.
23. Paiements.
24. Créances.
25. Dépenses.
26. Comptes financiers.
27. Rapports.
28. Approbations.
29. Audit.
30. Paramètres.

## 20.3. Ergonomie

**Recommandé :** les écrans de vente, de réception et d'inventaire doivent être conçus prioritairement autour de parcours courts, de validations explicites et de messages d'erreur compréhensibles.

---

# 21. Architecture technique

## 21.1. Technologies validées

| Couche | Technologie |
|---|---|
| Backend | Laravel |
| Frontend | React + TypeScript |
| Base de données | MySQL ou MariaDB |
| Infrastructure | VPS Linux |
| Conteneurisation | Docker Compose |
| Fichiers | Stockage sur VPS |
| Sauvegardes | Externes au VPS |
| Communication | API REST |
| Documentation API | OpenAPI |
| Langue interface | Français |

## 21.2. Architecture recommandée

**Monolithe modulaire Laravel + application React séparée.**

Organisation logique :

- Identity & Access.
- Tenancy.
- Catalog.
- Inventory.
- Procurement.
- Imports.
- Costing.
- Sales.
- Billing.
- Payments.
- Finance.
- Reporting.
- Approvals.
- Audit.
- Notifications.
- Settings.

Chaque module possède ses règles métier, services applicatifs, contrôleurs, validations et tests.

## 21.3. Isolation multi-tenant

**Recommandé :** base MySQL partagée avec colonne `company_id` sur les tables métier, associée à des politiques d'accès systématiques.

Conditions :

- Résolution sécurisée de l'entreprise active.
- Filtrage côté backend.
- Contraintes relationnelles cohérentes.
- Tests de non-fuite interentreprises.
- Validation des accès aux fichiers.
- Aucune confiance dans un `company_id` envoyé librement par le frontend.

Les tables globales nécessaires à l'administration sont séparées conceptuellement des données métier.

## 21.4. Composants complémentaires recommandés

- Redis pour files de tâches et cache.
- Laravel Queue pour traitements différés.
- Laravel Scheduler pour sauvegardes et tâches planifiées.
- Génération PDF côté serveur.
- OpenAPI pour les contrats d'API.
- Outil de migrations Laravel.
- Stockage privé pour les documents sensibles.
- Service SMTP pour invitations et notifications.

Le choix des bibliothèques précises devra être arrêté lors de la conception technique détaillée.

## 21.5. Transactions

Les opérations suivantes doivent utiliser des transactions de base de données :

- Confirmation de commande et réservation.
- Réception et création des lots.
- Transfert de stock.
- Validation d'une livraison.
- Enregistrement d'un paiement.
- Utilisation d'un avoir.
- Validation d'une approbation.
- Contre-écriture.

**Recommandé :** utiliser verrouillage approprié, contraintes d'unicité et clés d'idempotence pour empêcher les doubles écritures concurrentes.

---

# 22. Modèle de données conceptuel

## 22.1. Administration

- companies
- users
- roles
- permissions
- role_permissions
- user_roles
- company_settings
- invitations
- sessions
- audit_logs

## 22.2. Catalogue

- categories
- products
- product_variants
- product_images
- product_prices
- product_units
- unit_conversions

## 22.3. Stock

- warehouses
- stock_lots
- stock_balances
- stock_movements
- stock_reservations
- inventories
- inventory_lines
- stock_transfers
- stock_transfer_lines

## 22.4. Achats et imports

- suppliers
- purchase_orders
- purchase_order_lines
- supplier_payments
- shipments
- containers
- packing_lists
- packing_list_lines
- goods_receipts
- goods_receipt_lines
- shipment_costs
- landed_cost_models
- landed_cost_calculations
- exchange_rates

## 22.5. Ventes

- customers
- quotations
- quotation_lines
- sales_orders
- sales_order_lines
- deliveries
- delivery_lines
- invoices
- invoice_lines
- customer_payments
- customer_credits
- credit_applications
- returns
- return_lines

## 22.6. Finances

- financial_accounts
- financial_movements
- expense_categories
- expenses
- opening_balances
- supplier_liabilities
- customer_receivables

## 22.7. Contrôle

- approval_rules
- approval_requests
- approval_decisions
- notifications
- document_sequences
- generated_documents
- import_jobs
- import_errors
- backup_records

**Important :** cette liste est un modèle conceptuel recommandé, pas encore le schéma SQL définitif. Certaines entités pourront être fusionnées, séparées ou représentées par des vues selon les invariants retenus.

## 22.8. Contraintes transversales

- Clés primaires stables.
- Clés étrangères.
- `company_id` sur les entités concernées.
- Index sur les recherches fréquentes.
- Unicité des références dans leur périmètre.
- Horodatage des créations et modifications.
- Conservation des historiques.
- Types décimaux exacts pour les montants.
- Protection contre les doublons d'import et de validation.

---

# 23. API REST

## 23.1. Principes

- Versionnement : `/api/v1`.
- Échanges JSON.
- Validation côté serveur.
- Authentification obligatoire pour les endpoints privés.
- Autorisation par rôle, action et entreprise.
- Pagination.
- Filtres.
- Tri.
- Gestion normalisée des erreurs.
- Documentation OpenAPI.

## 23.2. Familles d'API recommandées

| Domaine | Exemples |
|---|---|
| Authentification | `/auth/login`, `/auth/logout`, `/auth/me` |
| Entreprises | `/companies`, `/companies/{id}` |
| Utilisateurs | `/users`, `/roles`, `/permissions` |
| Produits | `/products`, `/product-variants` |
| Dépôts | `/warehouses` |
| Stocks | `/stocks`, `/stock-movements` |
| Inventaires | `/inventories` |
| Achats | `/purchase-orders`, `/suppliers` |
| Imports | `/shipments`, `/containers`, `/packing-lists` |
| Réceptions | `/goods-receipts` |
| Coûts | `/landed-costs`, `/cost-models` |
| Clients | `/customers` |
| Commandes | `/sales-orders` |
| Factures | `/invoices` |
| Paiements | `/payments` |
| Livraisons | `/deliveries` |
| Finances | `/financial-accounts`, `/expenses` |
| Rapports | `/reports` |
| Approbations | `/approval-requests` |
| Audit | `/audit-logs` |

Ces chemins sont des propositions de conception, à stabiliser dans le contrat OpenAPI.

## 23.3. Erreurs

L'API doit distinguer :

- Donnée invalide.
- Authentification absente.
- Permission insuffisante.
- Ressource inexistante.
- Conflit de stock.
- Opération déjà effectuée.
- Approbation nécessaire.
- Entreprise suspendue.
- Erreur technique.

Les messages publics ne doivent pas exposer de secrets ni de détails internes sensibles.

---

# 24. Performances et exploitation

## 24.1. Volumétrie cible

- 500 à 2 000 références produits.
- 20 à 100 ventes quotidiennes.
- Plusieurs entreprises.
- Plusieurs dépôts par entreprise.

## 24.2. Performance

**Objectif validé :** chargement des pages courantes en moins de 3 secondes dans des conditions de test représentatives.

**Recommandé :**

- Pagination serveur.
- Index SQL.
- Éviter les requêtes N+1.
- Cache ciblé.
- Traitement asynchrone des imports et rapports lourds.
- Chargement progressif des images.
- Mesure des temps de réponse.

## 24.3. Sauvegardes

- Sauvegarde quotidienne.
- Conservation de 30 jours.
- Copie externe au VPS.
- Procédure de restauration.
- Test de restauration.
- Journalisation des échecs.

**Recommandé :** sauvegarder conjointement la base de données, les fichiers nécessaires et les paramètres de restauration.

## 24.4. Monitoring

- Santé de l'application.
- Santé de MySQL.
- Espace disque.
- Files de tâches.
- Échecs de sauvegarde.
- Erreurs applicatives.
- Disponibilité HTTP.
- Ressources CPU et mémoire.

## 24.5. Environnements

Deux environnements distincts :

1. Préproduction.
2. Production.

La préproduction doit permettre de tester les migrations, les imports, les workflows et les restaurations sans affecter les données réelles.

---

# 25. Sécurité

## 25.1. Exigences

- HTTPS.
- Mots de passe sécurisés.
- Contrôle d'accès serveur.
- Isolation multi-entreprises.
- Validation stricte des entrées.
- Protection des imports.
- Audit.
- Sauvegardes.
- Journalisation.
- Expiration de session.
- Limitation des connexions.

## 25.2. Sécurité des fichiers

**Recommandé :**

- Vérifier types et tailles des fichiers.
- Utiliser des noms de stockage non prédictibles.
- Stocker les documents sensibles hors du répertoire public.
- Contrôler l'entreprise et les permissions avant chaque téléchargement.
- Limiter les formats autorisés.
- Analyser les fichiers à risque lorsque l'infrastructure le permet.

## 25.3. Secrets

Les secrets applicatifs ne doivent jamais être enregistrés dans Git.

Utiliser des variables d'environnement et une gestion adaptée des accès au VPS.

---

# 26. Matrice de cohérence métier

| Événement | Stock | Finance | Document | Audit |
|---|---|---|---|---|
| Création d'un devis | Aucun effet | Aucun effet | Devis | Oui |
| Confirmation commande | Réservation | Créance/facturation selon règles | Facture | Oui |
| Paiement client | Aucun effet stock | Encaissement et réduction du solde dû | Reçu | Oui |
| Expédition | Passage en livraison | Pas de nouveau paiement implicite | Bon de livraison | Oui |
| Remise au client | Sortie définitive | Coût des marchandises vendues | Livraison confirmée | Oui |
| Réception validée | Entrée en stock | Valorisation du lot | Bon de réception | Oui |
| Transfert dépôt | Déplacement interne | Pas de revenu | Bon de transfert | Oui |
| Retour accepté | Entrée ou blocage selon décision | Avoir si applicable | Document de retour | Oui |
| Ajustement approuvé | Mouvement définitif | Impact de valorisation si applicable | Justificatif | Oui |
| Opération en attente | Réservation/blocage provisoire | Montant en attente | Demande | Oui |
| Rejet d'approbation | Libération des effets provisoires | Aucun impact définitif | Décision | Oui |
| Annulation validée | Compensation adaptée | Contre-écriture adaptée | Document correctif | Oui |

**Point à vérifier :** la facturation à la confirmation et la reconnaissance du chiffre d'affaires à la livraison nécessitent une règle comptable et réglementaire explicite.

---

# 27. Scénarios de tests fonctionnels

## TEST-001 — Isolation multi-entreprises

**Étant donné** deux entreprises A et B et un utilisateur de A,  
**quand** cet utilisateur tente d'accéder par API à un produit de B,  
**alors** l'accès est refusé et aucune donnée de B n'est exposée.

**Critère :** zéro fuite interentreprises.

## TEST-002 — Super-administration

Un super-administrateur sélectionne A, consulte son tableau de bord, puis sélectionne B.

**Critère :** les données correspondent à l'entreprise active ; la vue consolidée reste réservée au super-administrateur.

## TEST-003 — Réservation de stock

Stock disponible : 20 pièces.

Une commande confirmée réserve 8 pièces.

**Résultat attendu :** 12 pièces disponibles, 8 réservées, aucune sortie définitive.

## TEST-004 — Livraison

La commande précédente est livrée intégralement.

**Résultat attendu :** 8 pièces sorties définitivement, réservation soldée, historique conservé.

## TEST-005 — Vente impossible

Stock disponible : 5 pièces.

Une commande tente de réserver 7 pièces.

**Résultat attendu :** refus sans stock négatif ni réservation partielle non autorisée.

## TEST-006 — Concurrence

Deux utilisateurs tentent simultanément de réserver les 5 dernières pièces.

**Résultat attendu :** la quantité totale réservée ne dépasse jamais 5.

## TEST-007 — Réception

Une packing list annonce 100 pièces ; 95 sont acceptées et 5 manquantes.

**Résultat attendu :** 95 pièces entrent en stock, 5 sont enregistrées en écart, aucune entrée supplémentaire.

## TEST-008 — Double validation

Un opérateur clique deux fois sur « Valider la réception ».

**Résultat attendu :** un seul ensemble de mouvements est créé.

## TEST-009 — Coût de revient

Deux lignes occupent respectivement 2 m³ et 3 m³. Les frais communs sont de 500 000 FCFA.

**Résultat attendu :** répartition de 200 000 FCFA et 300 000 FCFA.

## TEST-010 — CBM manquant

Une ligne n'a pas de CBM valide.

**Résultat attendu :** validation du coût définitif bloquée avec message explicite.

## TEST-011 — Vente à crédit

Facture de 100 000 FCFA, paiement de 40 000 FCFA.

**Résultat attendu :** solde dû de 60 000 FCFA et statut « Partiellement payée ».

## TEST-012 — Moyen de paiement

Premier règlement par Wave.

Tentative de deuxième règlement en espèces.

**Résultat attendu :** refus ou procédure de correction explicite, conformément à la règle du moyen unique par facture.

## TEST-013 — Avoir client

Création d'un avoir de 20 000 FCFA, utilisation de 8 000 FCFA.

**Résultat attendu :** solde d'avoir de 12 000 FCFA.

## TEST-014 — Annulation

Annulation d'une vente livrée et payée.

**Résultat attendu :** aucun effacement d'historique ; documents correctifs, mouvements de stock et écritures financières cohérents.

## TEST-015 — Approbation

Une modification de prix dépasse un seuil.

**Résultat attendu :** demande d'approbation, effet provisoire, notification et absence de modification définitive avant décision.

## TEST-016 — Rejet

La demande précédente est rejetée.

**Résultat attendu :** suppression des effets provisoires, conservation du journal de décision.

## TEST-017 — Permissions

Un vendeur demande un rapport de bénéfices sans permission.

**Résultat attendu :** accès refusé, y compris par appel direct de l'API et export.

## TEST-018 — Entreprise suspendue

Un utilisateur d'une entreprise suspendue tente de créer une facture.

**Résultat attendu :** refus ; consultation des documents existants autorisée selon ses permissions.

## TEST-019 — Import initial

Import d'un fichier comportant des références en doublon et des quantités invalides.

**Résultat attendu :** erreurs affichées avant confirmation ; aucune écriture non souhaitée.

## TEST-020 — Restauration

Restauration d'une sauvegarde sur environnement isolé.

**Résultat attendu :** base, documents et paramètres restaurés et exploitables.

## TEST-021 — Facturation concurrente

Deux factures sont émises simultanément pour la même entreprise.

**Résultat attendu :** numéros distincts, sans collision.

## TEST-022 — Stock carton/pièce

Produit de 6 pièces par carton. Vente de 2 cartons et 3 pièces.

**Résultat attendu :** 15 pièces réservées ou sorties selon l'étape.

## TEST-023 — Consolidation

Deux entreprises possèdent des comptes en devises différentes.

**Résultat attendu :** pas d'addition monétaire sans conversion explicite et taux identifié.

## TEST-024 — Audit

Une opération validée est corrigée.

**Résultat attendu :** opération initiale, correction, auteur, horodatage et valeurs historiques consultables.

---

# 28. Critères d'acceptation de la V1

La V1 est acceptable lorsque :

1. Tous les modules obligatoires sont implémentés.
2. L'isolation multi-entreprises est vérifiée par des tests automatisés.
3. Les règles de stock ne permettent ni vente excessive ni double réception.
4. Les mouvements financiers restent cohérents avec les paiements et corrections.
5. Les calculs de coût de revient sont reproductibles.
6. Les commandes, factures, livraisons et retours suivent les workflows validés.
7. Les permissions sont appliquées côté serveur.
8. Les opérations provisoires sont correctement isolées.
9. Les imports Excel/CSV sont prévisualisables et validables.
10. Les packing lists PDF/Excel peuvent être importées avec contrôle humain des résultats.
11. Les documents PDF et rapports Excel sont correctement générés.
12. L'interface est utilisable sur ordinateur et smartphone.
13. Les tests automatisés critiques réussissent.
14. La restauration d'une sauvegarde est démontrée.
15. Les journaux d'audit sont exploitables.
16. Les erreurs techniques sont journalisées.
17. La préproduction est validée avant le déploiement.
18. Les principales pages respectent l'objectif de performance dans les conditions de mesure définies.

**Recommandé :** aucune anomalie critique ou majeure bloquante ne doit rester ouverte lors de la décision de mise en production.

---

# 29. Plan de développement recommandé

La livraison est unique, mais le travail est organisé en phases internes.

## Phase 1 — Fondations

- Architecture Laravel/React.
- Docker Compose.
- Authentification.
- Entreprises.
- Utilisateurs.
- Permissions.
- Audit.
- Paramètres.

## Phase 2 — Catalogue et stocks

- Produits.
- Variantes.
- Dépôts.
- Lots.
- Mouvements.
- Inventaires.
- Transferts.
- Réservations.

## Phase 3 — Achats et importations

- Fournisseurs.
- Commandes d'achat.
- Conteneurs.
- Packing lists.
- Réceptions.
- Écarts.
- Coûts de revient.

## Phase 4 — Ventes et livraisons

- Clients.
- Devis.
- Commandes.
- Factures.
- Livraisons.
- Retours.
- Avoirs.

## Phase 5 — Finances

- Comptes financiers.
- Paiements.
- Créances.
- Dettes.
- Dépenses.
- Marges.
- Bénéfices.

## Phase 6 — Contrôles et reporting

- Approbations.
- Notifications.
- Rapports.
- Tableaux de bord.
- Exports.
- Import initial.

## Phase 7 — Stabilisation

- Tests automatisés.
- Tests de concurrence.
- Tests d'isolation.
- Tests de sécurité.
- Tests de performance.
- Restauration.
- Préproduction.
- Corrections.

## Phase 8 — Production

- Configuration du VPS.
- HTTPS.
- Sauvegardes externes.
- Monitoring.
- Migration.
- Mise en service.

---

# 30. Organisation du développement avec des agents IA

**Recommandé :** utiliser une organisation à responsabilités séparées.

### Agent 1 — Développement

- Implémente les fonctionnalités.
- Écrit les migrations.
- Développe les API.
- Développe les interfaces.
- Ajoute les tests unitaires.

### Agent 2 — Revue et qualité

- Vérifie le respect du cahier des charges.
- Contrôle les règles métier.
- Analyse les risques de sécurité.
- Vérifie les performances.
- Revoit le code.
- Identifie les régressions.

### Agent 3 — Tests de bout en bout

- Exécute les scénarios utilisateurs.
- Vérifie les workflows complets.
- Teste les droits et les accès.
- Contrôle les documents générés.
- Produit les rapports d'anomalies.

### Règles de collaboration

- Une tâche possède des critères d'acceptation.
- Toute modification critique nécessite des tests.
- Les migrations sont versionnées.
- Les changements de contrat API sont documentés.
- Les règles métier ne sont pas inventées par les agents.
- Les décisions nouvelles sont enregistrées.
- Les changements sont revus avant intégration.
- La production n'est pas modifiée directement par les agents sans validation humaine.

---

# 31. Points restant à vérifier

Ces points ne doivent pas être considérés comme définitivement tranchés.

| Référence | Sujet | Décision ou vérification attendue |
|---|---|---|
| PV-01 | Obligations fiscales et mentions légales | Vérifier les règles sénégalaises applicables aux factures, avoirs, taxes et conservation |
| PV-02 | Facturation avant livraison | Définir le moment de reconnaissance du chiffre d'affaires et des créances |
| PV-03 | Sortie de stock et FIFO | Confirmer l'articulation entre lot physique livré et lot comptable valorisé |
| PV-04 | Paiement unique | Valider la règle d'un moyen unique pour tous les versements d'une facture |
| PV-05 | Avoirs | Définir précisément les conséquences sur factures, créances et revenus |
| PV-06 | Réception sans coût finalisé | Définir les conditions de disponibilité et de valorisation provisoire |
| PV-07 | Frais d'import | Préciser la répartition des frais directs, écarts, pertes et dommages |
| PV-08 | Devises multiples | Fixer les règles de conversion des soldes et rapports consolidés |
| PV-09 | Documents PDF fournisseurs | Définir les modèles réels à supporter et les taux d'extraction acceptables |
| PV-10 | E-mails | Choisir le service SMTP et la politique de délivrabilité |
| PV-11 | Infrastructure | Dimensionner le VPS selon le volume réel de fichiers, images et traitements |
| PV-12 | Sauvegardes | Définir les objectifs de perte de données et de délai de restauration |
| PV-13 | Règles d'approbation | Définir les seuils initiaux et les effets provisoires par type d'opération |
| PV-14 | Sécurité du super-administrateur | Décider d'une éventuelle authentification renforcée |
| PV-15 | Livraisons partielles | Confirmer si elles doivent être prises en charge dès la V1 |
| PV-16 | Frais de livraison | Préciser leur traitement commercial et comptable |
| PV-17 | Droits des utilisateurs | Vérifier les permissions par défaut avant activation de comptes employés |
| PV-18 | Import historique | Définir les modèles Excel/CSV d'ouverture et leurs règles de rapprochement |

---

# 32. Décisions techniques recommandées

Les décisions suivantes sont recommandées pour la conception, sans être présentées comme des choix métier explicitement validés :

1. Monolithe modulaire Laravel.
2. React et TypeScript avec composants réutilisables.
3. API REST versionnée et documentée par OpenAPI.
4. Base MySQL avec isolation multi-tenant contrôlée côté serveur.
5. Transactions atomiques pour toutes les écritures critiques.
6. Verrouillage et idempotence pour les stocks et paiements.
7. Historique immuable des mouvements validés.
8. Calculs financiers avec types décimaux exacts.
9. Valorisation des sorties selon une politique FIFO documentée.
10. Files de tâches pour imports, notifications et rapports.
11. Stockage privé des documents.
12. Sauvegardes externes avec tests de restauration.
13. Tests automatisés unitaires, d'intégration et de bout en bout.
14. Déploiement en préproduction avant production.
15. Revue de sécurité systématique des fonctionnalités multi-entreprises.

---

# 33. Livrables techniques attendus

Le développement doit produire :

- Code source backend Laravel.
- Code source frontend React/TypeScript.
- Schéma de base de données.
- Migrations versionnées.
- Documentation OpenAPI.
- Documentation des règles métier.
- Documentation d'installation.
- Configuration Docker Compose.
- Documentation de déploiement.
- Documentation des sauvegardes et restaurations.
- Tests automatisés.
- Scénarios de tests de bout en bout.
- Documentation utilisateur.
- Journal des décisions d'architecture.
- Rapport final de recette.

---

# 34. Conclusion

FDS Business doit devenir une plateforme de gestion commerciale multi-entreprises permettant de suivre l'intégralité du cycle de vie des marchandises :

**Achat → Importation → Réception → Valorisation → Stockage → Commande → Réservation → Facturation → Livraison → Encaissement → Analyse financière.**

La fiabilité des données repose sur quatre principes :

1. **Isolation stricte des entreprises.**
2. **Traçabilité intégrale des mouvements.**
3. **Cohérence transactionnelle des stocks et finances.**
4. **Validation des règles métier par des tests automatisés.**

Le présent cahier des charges constitue la base de référence pour la conception détaillée, le développement et la recette de FDS Business.

**Statut final : spécification fonctionnelle et technique initiale complète, sous réserve de résolution des points PV-01 à PV-18 avant validation définitive des fonctionnalités concernées.**
