# Atelier de fin de phase 4 avec les agences — préparation

Préparé le 26/11/2026 (lot L15). **Atelier reporté** (décision de Hafedh du 25/11/2026, plan de la phase 4 §0 et §10) : il se tiendra en recette, une fois les deux préalables bloquants levés. Ce document sert le jour venu ; le compte rendu ira dans `docs/rapports/atelier_phase_4.md`.

## 1. Préalables (à cocher le jour J)

| N° | Préalable | Qui | État au 26/11/2026 |
| --- | --- | --- | --- |
| 1 | Recette installée sur `recette.aviator.tn` (`docs/installation_recette.md`) | Responsable technique | **Non fait** (bloquant) |
| 2 | Paramétrage réel saisi et validé (`docs/guide_saisie_parametrage.md`) : au moins une catégorie active **avec une grille tarifaire couvrant les deux semaines qui suivent l'atelier** | Admin du siège | **Non fait** (bloquant) |
| 3 | Archive de la phase 4 installée en recette | Responsable technique | À faire après 1 |
| 4 | Un compte par agent (4) et un pour l'admin du siège, double authentification active pour l'admin | Super admin | À faire |
| 5 | Données de démonstration chargées (§2) | Responsable technique | À faire après 2 et 3 |
| 6 | Planning remesuré en recette (condition 3 de D35) : au-delà de 300 ms, me le signaler avant l'atelier | Responsable technique, avec moi | À faire après 5 |
| 7 | Une tablette et un smartphone au moins, sur le réseau de la recette | Agences | À prévoir |

## 2. Données de démonstration

Toutes **fictives** et marquées « DEMO », chargées **en plus** du paramétrage réel, par les actions du domaine (mêmes contrôles qu'au comptoir). Jamais en production : le chargement y est refusé. Un second lancement ne fait rien.

Commande préparée pour le responsable technique, dans le terminal web de cPanel, depuis le dossier de l'application en recette :

```bash
php artisan db:seed --class=DemoFleetSeeder
```

Résultat attendu : « Démonstration chargée : … véhicules, … clients, … réservations, 1 leasing. » En cas de paramétrage incomplet, rien n'est chargé et le message dit quoi compléter.

| Contenu | Pour la démonstration |
| --- | --- |
| Par agence active : 4 véhicules `DEMO-<agence>-NN` de la première catégorie active, assurance et visite valides, dont 1 en maintenance | 1, 6 |
| Par agence : 2 clients « Client N <agence> DEMO » et 3 réservations sur les deux semaines à venir | 1, 2, 5 |
| `DEMO-<1re agence>-90` : assurance expirée la veille, donc **Indisponible** | 6 (scénario 10) |
| `DEMO-<1re agence>-91` : assurance qui expire dans 10 jours | 7 (alerte, si son délai est réglé) |
| « Client liste noire DEMO », inscrit dans la première agence, sur liste noire | 4 |
| `DEMO-<1re agence>-92` en leasing chez « Société de leasing DEMO », contrat commencé il y a 6 mois, 36 loyers | 8 |

Les réservations numérotées ne se suppriment pas (RG-11) : si la recette doit retrouver un état propre après l'atelier, elle est réinstallée.

## 3. Déroulé (2 h 30)

Écran partagé, les 4 agents et l'admin du siège ; Hafedh anime, je note dans la fiche (§4).

| Horaire | N° | Démonstration | Compte utilisé | Point d'attention |
| --- | --- | --- | --- | --- |
| 0:00 | — | Objectif, ce qui est fini, ce qui viendra en phase 5 (contrats, état des lieux, cautions) | — | Rien n'est encore facturé |
| 0:10 | 1 | **Planning** : sa propre agence, filtres agence et catégorie, autres agences en « Occupé », clic sur une case libre | Agent de la 1re agence | Heures à l'heure de Tunis sur tous les appareils. Q38, D39 (glisser-déposer) |
| 0:25 | 2 | **Réservation au comptoir** de bout en bout : recherche du client (et ses limites, D24), création d'un nouveau client avec doublon détecté, période, véhicule ou catégorie, devis, numéro `RES` | Agent | Q32, Q33 sur un exemple à cheval sur deux saisons ; Q49 |
| 0:50 | 3 | **Saisie simultanée en direct** : deux agents réservent le même véhicule au même moment ; un seul réussit | Deux agents de la même agence | Le message du perdant est-il clair ? |
| 1:00 | 4 | **Liste noire** : « Client liste noire DEMO » refusé dans une autre agence, sans motif affiché | Agent d'une autre agence | Dérogation prévue en phase 5 |
| 1:10 | 5 | Modification, annulation motivée, non-présentation, expiration d'une réservation non confirmée | Agent | Q39, Q90 c |
| 1:25 | 6 | **Fiche véhicule** : documents, kilométrage et correction motivée, historique des statuts, `DEMO-…-90` refusé à l'affectation | Admin du siège | Q28, Q29 |
| 1:40 | 7 | **Alertes** dans la cloche : assurance, visite, fin de leasing | Admin du siège | Q90 d (délais et destinataires) |
| 1:50 | 8 | **Leasing** : contrat `DEMO-LS-001`, échéancier, pointage d'un loyer, fin de contrat | Admin du siège | Q12, Q13 |
| 2:00 | 9 | **Tablette et smartphone** : planning et réservation en largeur réduite | Agents | Appareils réellement utilisés au comptoir |
| 2:10 | — | **Agence active** : un agent en remplacement change d'agence depuis son menu | Agent avec remplacement | Cas réels de remplacement |
| 2:15 | — | Prise en main libre, questions | Tous | — |

## 4. Fiche de recueil des décisions

Une ligne par question ; la réponse est reportée dans `docs/questions_ouvertes.md`. Toute réponse qui ajoute un mécanisme est **chiffrée avant d'être développée**.

| Question | Ce qu'on demande | Valeur actuelle (paramétrable) | Réponse | Ajoute un mécanisme ? |
| --- | --- | --- | --- | --- |
| Q3 | Retour possible dans une autre agence ? Transferts entre agences ? | Non : retour à l'agence de départ | | |
| Q28 | Que signifie « Réservé » pour un véhicule ? | Départ d'une réservation confirmée dans les 24 h | | |
| Q29 | Transitions manquantes (sinistre pendant une location, panne d'un véhicule réservé, épave…) | Table actuelle de l'architecture §7 | | |
| Q31 | Statuts « en attente de l'agence » et « non présenté » | Ajoutés | | |
| Q32 | Jours facturés : tranches de 24 h ou jours calendaires ? | Tranches de 24 h, tolérance de retard sur la dernière | | |
| Q33 | Tarif d'une location de 10 jours ; location à cheval sur deux saisons | Prix de la tranche de durée pour chaque jour, saison de chaque jour | | |
| Q36 | Surréservation tolérée pour une réservation par catégorie ? | Non | | |
| Q37 | Réserver au départ d'une autre agence ? | Non | | |
| Q38 | Nom du client visible sur le planning des autres agences ? | Non : « Occupé » | | |
| D39 | Glisser-déposer pour déplacer une réservation sur le planning ? | Non (planning en lecture seule) | | Oui : à chiffrer |
| Q39 | Délai avant « non présenté » ; sort de l'acompte | 120 min, confirmé par l'agence ; acompte au cas par cas | | |
| Q41 | Délai de préparation : unique ou par catégorie ? | 60 min, surchargeable par catégorie | | |
| Q49 | Format des numéros de réservation et de contrat | `RES-AAAA-NNNNN`, `CTR-AAAA-NNNNN` | | |
| Q90 a, b | Durée minimale ; préavis minimal avant le départ | Aucun | | |
| Q90 c | Expiration d'une réservation non confirmée au comptoir | Vide (pas d'expiration) | | |
| Q90 d | Délais et destinataires des alertes (assurance, visite, entretien, fin de leasing) | Vides : aucune alerte ne part | | |
| Q12 | Données réelles des leasings en cours | Saisie manuelle par l'admin | | |
| Q13 | Sens de « apports » | Apport initial seulement | | |

Remarques libres des agents (lisibilité, champs manquants, ordre des étapes) :

| Écran | Remarque | Agence | Suite proposée |
| --- | --- | --- | --- |
| | | | |

## 5. Après l'atelier

1. Compte rendu `docs/rapports/atelier_phase_4.md` (présents, réponses, remarques, chiffrages).
2. `docs/questions_ouvertes.md` mis à jour ; valeurs par défaut changées dans les réglages si la réponse le permet sans code.
3. Les réponses qui ajoutent un mécanisme sont chiffrées puis proposées en décision avant tout développement.
4. La réserve de la phase 4 (atelier reporté) est levée dans `docs/projet.md`.

## 6. Complément de la phase 5 (atelier unique, 1 h 15 de plus)

L'atelier de la phase 4, reporté, couvre aussi les parcours P3 (départ) et P4 (retour) livrés en phase 5. Préalables en plus du §1 : une **vraie tablette** de l'agence (Q72), chargée ; le prix du litre et la capacité du réservoir de deux véhicules saisis (Q44, Q92) ; un contrat ouvert pour chaque agent.

| Horaire | N° | Démonstration | Compte utilisé | Point d'attention |
| --- | --- | --- | --- | --- |
| 2:30 | 10 | **Remise au-delà du seuil** à la réservation, puis approbation sur le téléphone du super admin (centre de notifications) | Agent, super admin | Q52 (seuil, suppléant), Q93 |
| 2:40 | 11 | **Louer maintenant** et contrat depuis une réservation ; conducteurs (permis récent refusé, permis étranger signalé) | Agent | Q42, Q47, Q48 |
| 2:50 | 12 | **Départ sur la tablette** : relevés, carburant, dommage sur le schéma, photo, signature, validation ; PDF du contrat et de l'état des lieux | Agent | Q46 (signature), Q72, Q15 (contenu du contrat, mention « Modèle non validé ») |
| 3:10 | 13 | **Prolongation** sur une période libre, puis refusée sur une période réservée | Agent | Q93, Q94 (options par jour) |
| 3:20 | 14 | **Retour en retard** : aperçu des frais (retard, carburant, dommage plafonné par la franchise), véhicule à réparer, dossier de dommage, clôture, avenant | Agent, admin du siège | Q14, Q43, Q44, Q45, Q73, Q91, D47 |
| 3:35 | 15 | **Alerte de retard** dans la cloche ; téléphone du client sur un contrat en cours | Agent | Q4 |

Questions à ajouter à la fiche de recueil (§4) :

| Question | Ce qu'on demande | Valeur actuelle (paramétrable) | Réponse | Ajoute un mécanisme ? |
| --- | --- | --- | --- | --- |
| Q4 | Téléphone du client visible sur un contrat en cours ? | Oui, agence du contrat, consultation journalisée | | |
| Q14 | Tolérance, unité du retard, restitution anticipée | 60 min, une journée ; aucun remboursement | | |
| Q42 | Âge et ancienneté du permis par catégorie ; permis étrangers | 21 ans, 2 ans ; accepté et signalé | | |
| Q43 | Forfait kilométrique et prix du km par catégorie | Illimité tant que vide | | |
| Q44, Q92 | Carburant : prix du litre, frais de service, capacité des réservoirs | Vides : aucun frais | | |
| Q45 | Chiffrage des dommages, franchise | Montant saisi, plafonné par la franchise | | |
| Q46 | Signature sur tablette suffisante ? | Oui, intégrée au PDF, papier possible | | |
| Q47, Q48 | Conducteur principal ; contrôle des conducteurs additionnels | Obligatoire ; liste noire contrôlée | | |
| Q52 | Seuil de remise, suppléant du super admin | 0 %, aucun suppléant | | |
| Q72 | Modèle de tablette, navigateur | Tablette 10 pouces, Chrome ou Safari | | |
| Q73 | Retour hors horaires | Heure déclarée, motif, sans signature | | |
| Q91 | Retard à l'heure : tarif, plafond | Unité « jour » seule | | |
| Q93 | Remise sur un contrat en cours soumise au super admin ? | Oui, même seuil | | |
| Q94 | Options par jour lors d'une prolongation | Prolongées au prix figé, plafond respecté | | |
