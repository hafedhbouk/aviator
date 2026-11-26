# Rapport de fin de phase 4 — Parc, leasing et réservations

| Élément | Valeur |
| --- | --- |
| Phase | 4. Parc, leasing et réservations (lot 1) |
| Branche | `phase/04-parc-leasing-reservations`, créée le 19/11/2026 depuis `main` (`cbd9ff3`, après la fusion de la phase 3) |
| Dates | 19/11/2026 au 26/11/2026 |
| Statut | **Validée avec réserves** le 26/11/2026 par Hafedh (responsable technique), comme prévu le 25/11/2026 (plan §0 et §10) ; pull requests n° 5 et n° 6 fusionnées dans `main`. **Réserve n° 1 de la phase 4** : atelier avec les agences reporté, à tenir en recette selon `docs/rapports/atelier_phase_4_preparation.md`. **Réserve n° 2** : planning remesuré en recette (condition 3 de D35), avec un signalement au-delà de 300 ms. Les deux réserves héritées, installation de la recette et saisie du paramétrage, restent **prioritaires** et conditionnent les deux premières |
| Critère d'acceptation (plan, en-tête) | Scénarios 1, 2, 10 et 15 verts, machine à états du véhicule, alertes : **atteint** (§3). En plus, atelier avec les agences en fin de phase : **non tenu**, reporté (réserve n° 1) |
| Valideur | Responsable technique (rapport) ; agences et admin du siège (atelier, reporté) |
| Plan | `docs/plans/phase_4.md` (validé le 19/11/2026 ; suivi de la charge au §11) |

**En bref** :
- **Livré et testé** : le parc (véhicules, documents, statuts, kilométrage, historique), le leasing et le crédit, les alertes, la disponibilité sous verrou, les clients au comptoir, le devis, les réservations avec l'assistant du comptoir, le planning, le choix de l'agence active, ainsi que les droits et les traductions.
- **Tests** : 912 tests, dont 910 réussis et 2 ignorés, soit 249 de plus qu'à la fin de la phase 3 ; `composer check` est au vert.
- **Concurrence** : elle est testée sur des processus distincts.
- **Charge** : 29,1 j estimés pour un budget de 41,75 j (seuil d'alerte 46,8 j).
- **Revue de la branche** : aucun défaut bloquant (§10).
- **Atelier** : non tenu, faute de recette.

---

## 1. Exigences traitées

| Lot | Exigences | Statut | Où |
| --- | --- | --- | --- |
| L0. Prototype du planning | D35 : `guava/calendar` contre repli maison ; 8 critères obligatoires sur 8 tenus | **Fait**, D35 validée avec conditions le 19/11/2026 | `docs/plans/phase_4.md` §3 |
| L1. Verrous en base | D38 : verrous nommés en base (`named_locks`), aucun verrou de cache | **Fait** | `app/Support/Locking/DatabaseLock.php`, `tests/Arch/LockingTest.php` |
| L2. Véhicules | **B-01**, **B-02**, B-04 : fiche, documents, immatriculation canonique, localisation | **Fait** | `app/Domain/Fleet/Actions/SaveVehicle.php`, `app/Filament/Resources/Vehicles/` |
| L3. Fichiers privés | D21 : pièces des véhicules servies par un contrôleur, types contrôlés | **Fait** | `app/Http/Controllers/VehicleDocumentFileController.php`, `app/Support/Files/PrivateFiles.php` |
| L5. Machine à états | **B-05**, **B-10**, Q29 : table des transitions, journal, départ refusé sans documents valides (**scénario 10**), sortie du parc ; D41 proposée | **Fait** | `app/Domain/Fleet/VehicleStatusService.php`, `DepartureGuard.php`, `DocumentStatusSync.php` |
| L4. Kilométrage et historique | **B-03**, **B-06** | **Fait** | `app/Domain/Fleet/MileageLedger.php`, `VehicleHistory.php` |
| L9. Disponibilité | **C-01**, RG-01, D1 : occupations `vehicle_blocks`, délai de préparation, lectures verrouillantes | **Fait** | `app/Domain/Availability/` |
| L8. Clients au comptoir | D24 (recherche), D40 (création minimale, doublon), RG-15 (liste noire) ; `drivers` avancé de la phase 5 | **Fait** | `app/Domain/Crm/` |
| L10. Devis | **C-06**, Q32, Q33 : durée facturée, tarif par jour et par saison, options, remise, taxes du référentiel validé | **Fait** ; valeurs par défaut à confirmer à l'atelier | `app/Domain/Pricing/` |
| L11. Réservations | **C-03** à **C-05**, **C-07** à **C-10**, RG-13, Q28, Q36, Q39 : **scénarios 1 et 2**, assistant du comptoir ; D42 proposée | **Fait** | `app/Domain/Availability/Actions/`, `app/Filament/Resources/Reservations/` |
| L6. Leasing et crédit | **B-11** à **B-15** : échéancier, pointage, fin de contrat, charges par mois (**scénario 15**) | **Fait** ; données réelles attendues (Q12) | `app/Domain/Fleet/Financing/`, `app/Filament/Resources/VehicleFinancings/` |
| L7. Alertes | **B-07**, **B-13**, A-09 : échéances des documents et des leasings, centre de notifications | **Fait** ; délais à régler (Q90 d) | `app/Domain/Fleet/Alerts/DueDateAlerts.php` |
| L12. Planning | **C-02**, Q38, D35 et ses trois conditions | **Fait** en local ; remesure en recette à faire (réserve n° 2) | `app/Domain/Availability/Planning/`, `app/Filament/Planning/PlanningCalendar.php`, `app/Filament/Pages/Planning.php` |
| L13. Agence active | Q69 (report de la phase 2) | **Fait** | `app/Filament/Pages/SwitchAgency.php` |
| L14. Droits et tests transverses | Matrice par profil, **scénario 12** par type de document, D13, architecture, traductions de Filament | **Fait** | `tests/Feature/Rights/Phase4AccessMatrixTest.php`, `tests/Arch/VehicleWritesTest.php`, `lang/vendor/*/fr` |
| L15. Préparation de l'atelier | Données de démonstration, déroulé, fiche de recueil | **Fait** ; l'atelier lui-même est **reporté** | `database/seeders/DemoFleetSeeder.php`, `docs/rapports/atelier_phase_4_preparation.md` |
| L16. Rapport | Ce document, revue (§10) | **Fait** | `docs/rapports/phase_4.md` |

## 2. Fichiers créés ou modifiés

Environ 200 fichiers modifiés depuis `main` (environ 14 200 lignes ajoutées), en une quarantaine de commits.

| Domaine | Principaux fichiers |
| --- | --- |
| Domaine | `app/Domain/Fleet/` (véhicules, statuts, kilométrage, historique, alertes, financement), `app/Domain/Availability/` (périodes, verrous, occupations, disponibilité, réservations, planning), `app/Domain/Pricing/` (durée, devis), `app/Domain/Crm/` (recherche, création au comptoir, liste noire) |
| Modèles et policies | `Vehicle`, `VehicleDocument`, `VehicleStatusLog`, `VehicleMileageLog`, `VehicleBlock`, `VehicleFinancing`, `FinancingInstallment`, `Driver`, `CustomerRecommendation`, `Reservation`, `ReservationOption`, `NamedLock` ; policies des véhicules, documents, financements et réservations ; permission `fleet.manage` |
| Interface | Ressources Parc (véhicules, financements), Comptoir (réservations, assistant en 5 étapes), pages Planning et Agence active, entrée du menu utilisateur ; 158 traductions françaises de Filament |
| Base | 12 tables (plan §8), réglage `reservations.reserved_status_window_hours` ; `DemoFleetSeeder` (jamais en production) |
| Tâches planifiées | `fleet:check-status` (06:00), `fleet:alerts` (06:05), expiration des réservations (toutes les 5 min), fenêtre « Réservé » (chaque heure) |
| Paquets | `guava/calendar` 3.2.2 (figée, D35) ; correctifs de sécurité `league/csv` 9.29.1 et Symfony 7.4.24 |
| Documentation | `docs/plans/phase_4.md`, `docs/decisions.md` (D35 à D42), `docs/modele_donnees.md`, `docs/architecture.md` §6.1, `docs/compatibilite_paquets.md`, `docs/installation_recette.md`, `docs/questions_ouvertes.md` (Q6 répondue), `docs/rapports/tickets_guava_calendar.md`, `docs/rapports/atelier_phase_4_preparation.md` |

## 3. Tests

| Commande | Résultat |
| --- | --- |
| `composer check` sur le poste, dernier lancement sur `e487704` (L15) avant le commit | Pint passé ; Larastan 0 erreur ; Pest **912 tests, 910 réussis, 2 ignorés** (2 927 assertions) ; `composer audit` sans avis ; `npm run build` OK |
| CI GitHub (`composer check`, Ubuntu, MariaDB 10.11) | Verte sur `d2d3ab3` (fin de L12, run `45790206104`) et sur chaque poussée précédente. Les commits L13 à L16 ne sont pas encore poussés : à relever sur la pull request |

**Tests ignorés sur le poste** : les 2 mêmes que depuis la phase 2 (`tests/Feature/Release/ReleaseScriptsTest.php`), exécutés en CI. Aucun test de la phase 4 n'est ignoré.

Critère d'acceptation et règles clés :

| Exigence | Test | Fichier |
| --- | --- | --- |
| **Scénario 1** : deux saisies simultanées du même véhicule, une seule réussit, aucun numéro perdu | Processus distincts ; rafale ; instantané ancien (échoue sans lecture verrouillante : essai fait, puis code rétabli) | `tests/Feature/Concurrency/ReservationConcurrencyTest.php`, `VehicleBlockConcurrencyTest.php` |
| **Scénario 2** : annulation motivée, période de nouveau réservable | Annulation, libération, nouvelle réservation | `tests/Feature/Reservations/ReservationTest.php` |
| **Scénario 10** : départ refusé avec une assurance ou une visite expirée | Dernier jour accepté, lendemain refusé, jour de Tunis | `tests/Feature/Fleet/VehicleStatusTest.php` |
| **Scénario 15** : charges de leasing et de crédit comptées une seule fois par mois | Loyer HT ou intérêts seuls, au mois de l'échéance | `tests/Feature/Fleet/VehicleFinancingTest.php` |
| Machine à états | Table exacte, refus hors table, journal en ajout seul, écriture hors du service refusée, écriture de masse interdite | `VehicleStatusTest.php`, `tests/Arch/VehicleWritesTest.php` |
| Alertes | Une seule fois par échéance, destinataires par agence, aucune donnée client | `tests/Feature/Fleet/DueDateAlertsTest.php` |
| **Scénario 12** | Réservation, véhicule, financement : policy, domaine, requête Livewire falsifiée | `tests/Feature/Rights/Phase4AccessMatrixTest.php`, `ReservationTest.php`, `VehicleTest.php` |
| Planning (D35) | Projection Q38, nombre de requêtes constant, trois témoins des contournements du paquet, heure de Tunis depuis un navigateur à New York | `tests/Feature/Planning/PlanningTest.php` |

**Vérifications dans le navigateur** (poste, données fictives supprimées ensuite) : parc et documents (L2, L3, L5) ; assistant de réservation (L11) ; planning sur 48 véhicules, avec le navigateur réglé sur `America/New_York` (L12). **Non vérifié dans le navigateur** : le menu « Agence active » (L13), couvert par les tests de page seulement.

## 4. Écarts au cahier des charges et décisions

| Sujet | Écart ou décision | Statut |
| --- | --- | --- |
| D35 | Planning sur `guava/calendar` 3.2.2 figé ; trois défauts contournés et gardés par des tests ; tickets 1 à 3 prêts à publier par Hafedh | Validée avec conditions ; conditions 1 et 2 remplies, condition 3 remplie en local, remesure en recette à faire |
| Fuseau du planning | **Défaut trouvé au lot L12** : le prototype supposait que la bibliothèque affichait l'heure de Tunis. Avec un navigateur à New York, les blocs étaient en fait décalés de 6 h. Corrigé par l'option `timeZone` d'EventCalendar | Corrigé et testé |
| D36 à D40 | Dérogations et remises au-delà du seuil refusées jusqu'aux approbations de la phase 5 ; acompte en phase 6 ; verrous en base ; planning en lecture seule ; création minimale au comptoir | Validées |
| D41 | Statut déduit des documents (`fleet:check-status`) | Proposée, à présenter à l'atelier (Q28, Q29) |
| D42 | Brouillon sans capacité, compte de capacité prudent, non-présentation manuelle, affectation sans nouveau devis | Proposée, à présenter à l'atelier |
| `drivers` | Avancé de la phase 5 (permis et pièce chiffrés) pour la recherche D24 | Fait (−0,5 j en phase 5) |
| B-10 | Sortie du parc déplacée du lot L2 au lot L5 (elle passe par la machine à états) | Fait |
| Sécurité des paquets | Deux séries d'avis publiés pendant la phase (`league/csv` le 22/11, Symfony le 25/11), corrigés sur la branche ; branches `fix/league-csv` et `fix/symfony-7.4.24` préparées pour `main` | À ouvrir en pull request par Hafedh |

## 5. Charge consommée

Budget 41,75 j (référence 39 j et aléas), seuil d'alerte 46,8 j. Détail par lot au plan §11.

| Lot | Prévu (j) | Estimé (j) |
| --- | ---: | ---: |
| Plan et conditions de D35 | — | 0,5 |
| L0. Prototype du planning | 1,25 | 1,25 |
| L1. Verrous en base | 0,25 | 0,5 |
| L2. Véhicules | 4 | 3,25 |
| L3. Fichiers privés | 0,5 | 0,5 |
| L5. Machine à états | 3 | 2,5 |
| L4. Kilométrage et historique | 1,25 | 1 |
| Correctifs de sécurité | — | 0,1 |
| L9. Disponibilité | 4,5 | 3,5 |
| L8. Clients au comptoir | 2,75 | 2,25 |
| L10. Devis | 3 | 1,5 |
| L11. Réservations | 6 | 4,5 |
| L6. Leasing et crédit | 4,5 | 2,5 |
| L7. Alertes | 1,75 | 1 |
| L12. Planning | 3 | 2 |
| L13. Agence active | 0,25 | 0,25 |
| L14. Droits et tests transverses | 1,25 | 1 |
| L15. Préparation de l'atelier | 1 | 0,5 |
| L16. Rapport et revue | 1 | 0,5 |
| **Total** | **39,25** | **29,1** |

Ce sont des estimations du temps passé, non un relevé horaire. **Reste dû à la phase** : la tenue de l'atelier et son compte rendu, ainsi que la remesure du planning en recette, soit environ 1 j, à prendre quand les réserves héritées seront levées. Le total reste alors sous le budget, à environ 30 j.

## 6. Risques nouveaux

| Risque | Parade |
| --- | --- |
| `guava/calendar` figé à 3.2.2 avec trois contournements | Témoins qui échouent si le paquet change ; tickets chez le mainteneur ; toute montée de version relit `docs/rapports/tickets_guava_calendar.md` |
| Décalage fixe `+01:00` du planning | La Tunisie n'a pas d'heure d'été depuis 2009. Si elle en adoptait une, le décalage étant calculé à chaque affichage, seule une vue à cheval sur le changement serait décalée d'une heure |
| Avis de sécurité fréquents (deux en quatre jours) | `composer audit` bloquant en CI ; branche corrective vers `main` à chaque avis |
| Aller-retour du planning sur un mois : médiane de 205 ms sur le serveur de développement | Partie données entre 97 et 151 ms ; remesure en recette (réserve n° 2) ; au-delà de 300 ms, pagination par agence possible |
| Atelier reporté : les valeurs par défaut de Q28, Q32, Q33, Q36, Q38, Q39, Q90 sont utilisées sans confirmation des agences | Toutes paramétrables ; fiche de recueil prête ; tout changement de mécanisme chiffré avant développement |

## 7. Questions pour la direction

Reprises dans la fiche de recueil de l'atelier (`docs/rapports/atelier_phase_4_preparation.md` §4) : Q3, Q12, Q13, Q28, Q29, Q31, Q32, Q33, Q36, Q37, Q38, Q39, Q41, Q49, Q90, ainsi que le glisser-déposer du planning (D39) et les décisions D41 et D42.

Hors atelier : les données réelles des leasings en cours (Q12) peuvent être transmises dès maintenant pour la saisie.

## 8. Réserves héritées

| Réserve | Origine | État |
| --- | --- | --- |
| Installation de la recette sur `recette.aviator.tn` | Phase 2, n° 1 | **Non levée** ; Q6 répondue le 25/11/2026 (domaine `aviator.tn`) ; prérequis DNS dans `docs/installation_recette.md` |
| Saisie du paramétrage en recette | Phase 3, n° 1 | **Non levée**, en attente de la recette |
| Ateliers avec les agences | Phase 1, n° 1 | Fusionnée avec la réserve n° 1 de la phase 4 |

## 9. À valider

1. **Ce rapport**, au statut proposé « Validée avec réserves » (réserves n° 1 et 2 de la phase 4 ; réserves héritées prioritaires).
2. La conclusion de la revue (§10).
3. Les pull requests vers `main` : `fix/symfony-7.4.24` (qui contient `fix/league-csv`), puis `phase/04-parc-leasing-reservations`.
4. La publication des tickets `guava/calendar` 1 à 3 par Hafedh.
5. Le plan de la phase 5, après la fusion.

## 10. Revue de la branche (26/11/2026)

Revue de `phase/04-parc-leasing-reservations` (`cbd9ff3..HEAD`) par Claude Code, sur cinq axes.

| Axe | Constat | Preuve |
| --- | --- | --- |
| Sécurité | Toutes les écritures passent par `app/Domain` : aucune création, modification ni suppression directe dans les ressources Filament ; aucun affichage non échappé (`{!!`) dans les vues ajoutées ; aucun SQL brut ; aucun `float` sur un montant. Les fichiers privés sont désignés par leur document, jamais par un chemin, avec le type réel contrôlé. Les champs falsifiés côté Livewire sont refusés (agence, client, véhicule hors de portée) | `DatabaseAccessTest`, `NoFloatAmountsTest`, `VehicleDocumentFileTest`, `Phase4AccessMatrixTest` |
| Droits par profil | 14 écrans × 3 profils, code HTTP attendu pour chacun ; financements réservés au super admin et à l'admin ; parc en lecture pour l'agent ; recherche globale fermée sur les trois ressources | `Phase4AccessMatrixTest` |
| Portée par agence | Les levées de portée (`withoutGlobalScopes`) sont dans le domaine, pour les verrous et la cohérence (statut, kilométrage, disponibilité). Côté Filament, il n'y en a que trois : l'assistant ne reprend un véhicule que si son agence fait partie de celles de l'utilisateur ; les deux autres sont dans les financements, réservés au super admin et à l'admin. Le planning ne charge pas les réservations des autres agences : il les affiche en « Occupé » | `PlanningTest` (véhicule hors de portée non prérempli, blocs des autres agences sans donnée), `SwitchAgencyPageTest`, `AgencyScopeTest` |
| Concurrence | Verrous en base (D38), ordre catégorie puis véhicule, lectures verrouillantes ; tests sur processus distincts qui échouent si l'on retire la lecture verrouillante (essai fait) | `tests/Feature/Concurrency/`, `tests/Arch/LockingTest.php` |
| Documentation et tests | Chaque « fait » du rapport renvoie à un test ; les vérifications dans le navigateur sont nommées, et ce qui n'a pas été vérifié aussi (§3) ; `docs/modele_donnees.md` décrit les 12 tables | §3 de ce rapport |

**Observations non bloquantes** :

1. Le planning montre à un agent les véhicules de toutes les agences (immatriculation, catégorie), jamais leurs clients. C'est conforme à la valeur par défaut de Q37 (disponibilité des autres agences en lecture), mais à confirmer à l'atelier avec Q38.
2. Le ticket 2 du paquet a été reproduit par un appel automatisé de la méthode que le navigateur appelle au clic, et non par un clic manuel.
3. Les données de démonstration ne se suppriment pas, puisque les réservations numérotées sont protégées (RG-11). Elles ne vont qu'en recette, qui sera réinstallée si besoin.
4. Le menu « Agence active » n'a pas été vérifié dans un navigateur.
5. La pagination des véhicules du planning n'est pas faite ; elle n'est utile qu'au-delà d'une centaine de véhicules.

**Conclusion** : aucun défaut bloquant. La branche peut être fusionnée dans `main` après la branche corrective, avec les réserves n° 1 et 2.
