=== Goplay Arena Facturation ===
Version : 1.14.6

Outil de devis et factures de GB GLOBAL (Goplay Arena), accessible sur /facturation.
Réservé aux administrateurs WordPress du site.

- Données enregistrées dans la base de données du site (table wp_gpf_records).
- Envoi des devis et factures par e-mail depuis le site, PDF joint.
- Lien de paiement SumUp automatique au bon montant (après saisie de la clé API).
- Facture marquée « Payée » automatiquement après paiement SumUp.
- Réglages : Réglages > Goplay Facturation.

La désactivation de l'extension ne supprime aucune donnée.

= 1.13.0 =
- Plusieurs adresses e-mail par client (séparées par une virgule, la première est la principale).
- À l'envoi : cases à cocher pour choisir le ou les destinataires, plus d'autres adresses possibles.
- La relance automatique d'un devis part aux mêmes destinataires que l'envoi.
- Conditions de réservation : cases à cocher sur chaque devis pour choisir celles qui s'impriment.
- Nouvelles conditions pour les factures (Réglages), à cocher aussi sur chaque facture.

= 1.13.1 =
- Réglages : le bouton « Enregistrer » reste visible en haut de l'écran pendant que tu descends.

= 1.13.2 =
- Réglages : une case par condition (devis et factures), bouton « + Ajouter une condition » et croix pour supprimer.

= 1.13.3 =
- Fiche client : une case par e-mail, bouton « + Ajouter un e-mail » et croix pour supprimer.

= 1.14.0 =
- Mises à jour instantanées : GitHub prévient le site à chaque nouvelle version (webhook), qui l'installe tout seul en moins d'une minute.
- Filet de sécurité : le site regarde aussi GitHub toutes les heures.

= 1.14.1 =
- Nouvelle icône pour l'écran d'accueil (iPhone et Android).

= 1.14.2 =
- Icône de l'écran d'accueil : le logo GoPlay Arena (hexagone néon).

= 1.14.3 =
- Icône : logo GoPlay Arena + facture néon.

= 1.14.4 =
- Sur téléphone : chaque ligne du devis ou de la facture devient une carte, avec la désignation en pleine largeur et des champs plus grands.
- La case « Détails » s'agrandit toute seule avec le texte.

= 1.14.5 =
- PDF : un nom de client ou une adresse trop longs passent à la ligne dans le cadre au lieu de dépasser.

= 1.14.6 =
- Factures : étiquette jaune « Chorus » dans la colonne Statut pour les factures Chorus (client public ou numéro d'engagement).
