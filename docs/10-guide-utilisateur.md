# 10 — Guide utilisateur par profil (L7-07)

**Version :** 1.0 — 9 novembre 2026

## Vendeur
- **Vente comptoir** (menu « Vente comptoir ») : scanner ou rechercher les articles, ajuster les quantités,
  choisir le moyen (espèces, Wave, Orange Money, virement), « Encaisser et remettre ». Tout est enregistré en une fois :
  facture, sortie de stock, reçu. En cas d'erreur, rien n'est enregistré.
- **Commande classique** : « Commandes clients » → « Nouvelle commande », choisir le client et les articles
  (le prix de la grille est proposé, modifiable), puis « Confirmer la commande » : le stock est réservé et la facture émise.
  Une remise au-delà du seuil part en approbation : la commande attend la décision.
- **Encaisser** : depuis la facture, « Enregistrer un règlement ». Le premier règlement fixe le moyen de paiement.
- **Relancer** : « Créances » → facture → « Préparer l'envoi WhatsApp » ouvre WhatsApp avec le message et le lien du PDF.

## Magasinier
- **Réceptions** : « Arrivages et réceptions » → arrivage → « Ouvrir une réception », scanner les articles ou saisir
  les quantités bon état / endommagé, puis « Valider la réception » (une seule fois).
- **Livraisons** : sur la commande, « Préparer une livraison », « Préparation terminée », puis « Expédier »
  et « Remettre au client », ou « Signaler un échec » et « Retour au dépôt ».
- **Inventaires, transferts, ajustements** : menus du même nom ; un ajustement important peut demander une approbation.

## Comptable
- **Trésorerie** : soldes par compte, transferts entre comptes, dépenses avec justificatif.
- **Coût de revient** : sur l'arrivage, saisir et confirmer les frais d'approche, « Calculer », corriger les anomalies,
  puis « Finaliser » : les lots passent au coût définitif.
- **Rapports** : choisir le rapport et la période, exporter en CSV. Le tableau de bord présente séparément
  CA net, marge, résultat et trésorerie.
- **Corrections** : contre-passation de règlement, changement de moyen de paiement, correction financière —
  soumises aux règles d'approbation.

## Administrateur de l'entreprise
- Utilisateurs, rôles et permissions ; paramètres (identité, mentions des documents, taxes, taux de change) ;
  règles d'approbation ; approbations en attente ; journal d'audit.

## Super-administrateur
- Création, suspension et réactivation des entreprises ; bascule d'entreprise (journalisée) ; vue consolidée par devise.
