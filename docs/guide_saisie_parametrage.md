# Guide de saisie du paramétrage

Pour l'admin du siège (et le super admin pour ce qui lui est réservé), avant les premières réservations. Tout se fait dans le back-office, menu **Paramétrage**. Chaque enregistrement est journalisé (qui, quand, avant, après). Rien ne se supprime : on désactive.

## Ce qui est déjà en place à l'installation

- Les 4 agences : Djerba (siège), Sfax, Sousse, Tunis.
- Les paramètres fiscaux du référentiel validé : 15 codes fiscaux, timbre de 1,000 DT. Vous les **consultez** ; seul le super admin les modifie.
- Les 5 options du cahier (conducteur supplémentaire, siège enfant, GPS, franchise réduite, livraison), **inactives et sans prix**.
- Les 5 modes de paiement (espèces, chèque, carte, virement, traite).

## Ordre de saisie

| Étape | Écran | Ce que vous saisissez | Points d'attention |
| --- | --- | --- | --- |
| 1 | Société | Raison sociale, matricule fiscal, identifiant RNE, adresse, téléphone, e-mail, logo | Logo en PNG, JPEG ou WebP, 1 Mo au plus. Les mentions des factures sont saisies par le **super admin**, à partir de la règle validée par le cabinet |
| 2 | Agences | Adresse, téléphone et e-mail de chaque agence ; puis, dans la fiche de l'agence, ses **lieux et horaires** | Un lieu sert à la prise en charge, à la restitution, ou aux deux. Horaires par jour, en heure de Tunis ; journée coupée = deux plages ; jour sans plage = fermé |
| 3 | Catégories | Citadine, berline, SUV, utilitaire… : code, nom ; caution, franchise, kilométrage inclus et prix du km si vous les fixez | Laissez vide ce qui n'est pas décidé : caution saisie à chaque contrat, kilométrage illimité, franchise sans plafond. Âge minimum, ancienneté du permis et délai de préparation : vide = valeur générale (21 ans, 2 ans, 60 min) |
| 4 | Saisons | Nom et dates de chaque saison | Les saisons ne se chevauchent pas. Un jour hors saison n'a pas de prix : couvrez toute l'année |
| 5 | Grilles tarifaires | Pour chaque catégorie et saison : tranches de durée et prix journalier **HT** | Tranches continues depuis 1 jour, la dernière sans limite (exemple : 1 à 6 jours, 7 à 29 jours, 30 jours et plus). Grille commune aux agences ; une grille d'agence la remplace pour cette agence. Pour changer un prix : fermez l'ancienne grille (date de fin) et créez-en une nouvelle |
| 6 | Options | Prix HT de chaque option, puis **Active** | Une option ne s'active qu'avec son code fiscal et son prix. « Livraison » reste inactive tant que le cabinet n'a pas donné son code fiscal (Q85) |
| 7 | Modes de paiement | Ajouts éventuels ; « référence exigée » pour les modes qui ont un numéro | Le code d'un mode est fixé à sa création |
| 8 | Fournisseurs | Garages, assureurs, sociétés de leasing, banques | Fiche simple : nom, matricule fiscal, contact |

## Réservé au super admin

- **Paramètres fiscaux** : nouvelle version d'une règle (jamais de modification sur place), activation. Une règle « Pièce attendue » (PENDING_DOCUMENTATION) ne s'active pas.
- **Réglages** : réservation, tarification, facturation, approbations, notifications, sécurité. Les valeurs non décidées par la direction sont vides (contrainte ou alerte désactivée) : voir Q90.
- **Rapport de configuration fiscale** (menu Paramètres fiscaux) : à imprimer et à faire signer par la direction avant la mise en production.

## Avant la mise en production

`php artisan aviator:check-environment` doit afficher « Règles fiscales : OK ». Sinon, il signale ce qui manque : référence validée, règle en attente de pièce active, mentions des factures vides.
