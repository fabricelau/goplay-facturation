# Goplay Arena Facturation

Extension WordPress de devis et factures de GB GLOBAL (Goplay Arena) : acomptes, avoirs, paiements SumUp, signature des devis en ligne, factures au format Factur-X (PDF/A-3, profil EN 16931).

L'outil s'ouvre sur `/facturation` du site et n'est accessible qu'aux administrateurs. Aucune donnée (clients, factures, clés) n'est stockée dans ce dépôt : tout reste dans la base WordPress du site.

## Mises à jour

Chaque version est déposée dans `dist/` et annoncée dans `update.json`. L'extension installée lit `update.json` et se met à jour toute seule.

Polices : Liberation Sans (SIL Open Font License 1.1, voir `app/fonts/OFL.txt`). Bibliothèque pdf-lib (MIT, voir `app/lib/pdf-lib-LICENSE.md`).
