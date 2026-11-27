# Rapport de fin de phase 5 — Contrats et états des lieux

| Élément | Valeur |
| --- | --- |
| Phase | 5. Contrats et états des lieux (lot 1) |
| Branche | `phase/05-contrats-etats-des-lieux`, créée le 26/11/2026 depuis `main` (`8fb17aa`) |
| Dates | 26/11/2026 au 27/11/2026 |
| Statut | **Validée avec réserves le 27/11/2026** par Hafedh (responsable technique) ; revue acceptée. Rappel du statut proposé (§9) : saisie sur tablette vérifiée dans un navigateur réglé en tablette, **pas sur une vraie tablette** (Q72) ; atelier avec les agences toujours reporté (réserve de la phase 4) |
| Critère d'acceptation (plan, en-tête) | Scénarios 3 et 4 verts : **atteint** ; PDF : **atteint** ; saisie sur tablette : **atteinte en émulation** (Chromium 800 × 1 280), vraie tablette à faire en recette. En plus : scénario 12 sur le contrat et scénario 10 au départ : **atteints** |
| Valideur | Responsable technique ; agences et conseil juridique pour les points listés au §7 |
| Plan | `docs/plans/phase_5.md` (validé le 26/11/2026 ; suivi de la charge au §7) |

**En bref** :
- **Livré et testé** : approbations du super admin (remise, dérogation), contrats depuis une réservation ou au comptoir, conducteurs, conditions figées, états des lieux (photos, signature), départ, prolongation, retour et frais, dossier de dommage, clôture et avenants, PDF, alerte de retard, téléphone du client en cours de location.
- **Tests** : 1 060 tests, dont 1 058 réussis et 2 ignorés (les mêmes que depuis la phase 2), soit 148 de plus qu'à la fin de la phase 4 ; `composer check` vert ; CI verte.
- **Trois défauts trouvés et corrigés pendant la phase** : un interblocage MariaDB entre prolongation et réservation simultanées, une CI rouge pendant trois envois sans que le poste le montre, un départ en avance qui ne protégeait pas l'avance (§4).
- **Charge** : 24 j estimés pour un budget de 30 j (seuil d'alerte 36 j).
- **Revue de la branche** : aucun défaut bloquant (§10).

---

## 1. Exigences traitées

| Lot | Exigences | Statut | Où |
| --- | --- | --- | --- |
| L1. Approbations | **RG-10**, **RG-15**, D46 (remplace D36), Q52 | **Fait** | `app/Domain/Approvals/`, `app/Filament/Resources/ApprovalRequests/` |
| L2. Contrats | **D-01**, **RG-03**, D49 | **Fait** | `app/Domain/Rental/Actions/OpenContract.php`, `CancelContract.php`, `app/Filament/Resources/Contracts/` |
| L3. Conducteurs | **D-02**, **D-03**, Q42, Q47, Q48 | **Fait** | `app/Domain/Rental/DriverEligibility.php`, `SaveContractDriver.php`, `ContractDrivers.php` |
| L4. Conditions | **D-05**, D50, D51, Q43, Q44, Q91, Q92 | **Fait** ; paramètres vides par défaut | `OpenContract.php`, réglages de tarification, fiches véhicule et catégorie |
| L5. États des lieux | **D-06**, **RG-04**, D44, D45, Q73, scénario 10 | **Fait** | `RecordInspection.php`, `StartRental.php`, `app/Support/Files/ImageReencoder.php` |
| L6. Écrans tablette | **D-06**, parcours P3 et P4, Q72 | **Fait**, vérifié en émulation de tablette | `app/Filament/Pages/DailyMovements.php`, `app/Filament/Resources/Contracts/Pages/InspectContract.php`, `resources/js/inspection.js` |
| L7. Prolongation | **D-07**, **scénario 3**, D48, Q93, Q94 | **Fait** | `ExtendContract.php` |
| L8. Retour et frais | **D-08** (P2), **D-09**, **RG-09**, **scénario 4**, D47, Q14, Q45 | **Fait** | `ReturnCharges.php`, `RegisterReturn.php`, `app/Domain/Fleet/Actions/EndMaintenance.php` |
| L9. Dossier de dommage | Analyse du cabinet **N2** | **Fait** | `ManageDamageFile.php` |
| L10. Clôture et avenants | **D-11**, D49, RG-11 | **Fait** | `CloseContract.php`, `ContractClosingConditions.php`, `AmendContract.php` |
| L11. PDF | **D-10**, D43, Q15 | **Fait** ; « Modèle non validé » tant que Q15 n'est pas réglée | `app/Domain/Rental/Documents/ContractDocuments.php`, `resources/views/pdf/` |
| L12. Alerte et téléphone | A-09 (retard), Q4 | **Fait** | `app/Domain/Rental/Alerts/LateReturnAlerts.php`, `ContractCustomerPhone.php` |
| L13. Droits | **RG-13**, **scénario 12** sur le contrat | **Fait** | `tests/Feature/Rights/Phase5AccessMatrixTest.php`, `tests/Arch/RentalWritesTest.php` |
| Hors lots | Jeu de données de développement (demande du 26/11) | **Fait** | `database/seeders/DevSampleDataSeeder.php` |

**Hors phase, comme prévu** : caution au départ et à la restitution, facture et encaissement (phase 6, qui ajoutera ses conditions à `ContractClosingConditions`) ; notifications push (phase 9) ; ordres de maintenance (phase 8, D47 en attendant).

## 2. Fichiers créés ou modifiés

154 fichiers depuis `main` (environ 13 100 lignes ajoutées), en une trentaine de commits.

| Domaine | Principaux fichiers |
| --- | --- |
| Domaine | `app/Domain/Approvals/` (demande, décision sous verrou, effets, notifications) ; `app/Domain/Rental/` (contrat, conducteurs, état des lieux, départ, prolongation, retour et frais, dossier de dommage, clôture, avenants, documents, alerte, téléphone) ; `EndMaintenance` |
| Modèles | `ApprovalRequest`, `Contract`, `ContractOption`, `ContractExtension`, `ContractCharge`, `ContractAmendment`, `Inspection`, `InspectionDamage`, `InspectionPhoto`, `DamageFile`, `DamageFileDocument` ; données figées et suppressions refusées par les modèles |
| Interface | Approbations (super admin) ; Contrats (fiche, menu Imprimer, conducteurs, prolongation, clôture, avenant, dossier de dommage, téléphone) ; état des lieux sur tablette ; Départs et retours du jour ; réglages Contrats et États des lieux ; « Louer maintenant » et confirmation d'un brouillon dans les réservations |
| Base | 8 migrations (`approval_requests`, contrats, conditions, états des lieux, prolongations, frais, dossiers de dommage, avenants) et 5 migrations de réglages |
| Fichiers privés | Photos, signatures, exemplaires signés, pièces des dossiers : servis par contrôleur après la policy du contrat |
| Tâche planifiée | `contracts-late-returns` (toutes les 15 minutes) |
| Paquets | `barryvdh/laravel-dompdf` 3.1.2 et `dompdf/dompdf` 3.1.6 (Composer) ; `signature_pad` 5.1.7 (npm) |
| Documentation | Plan, `docs/decisions.md` (D43 à D51), `docs/questions_ouvertes.md` (Q91 à Q94), `docs/modele_donnees.md`, `docs/architecture.md`, `docs/compatibilite_paquets.md`, complément de l'atelier (`docs/rapports/atelier_phase_4_preparation.md` §6) |

## 3. Tests

| Commande | Résultat |
| --- | --- |
| `composer check` sur le poste, dernier lancement avant ce rapport | Pint passé ; Larastan 0 erreur ; Pest **1 060 tests, 1 058 réussis, 2 ignorés** (3 770 assertions) ; `composer audit` sans avis ; `npm run build` OK |
| CI GitHub (`composer check`, Ubuntu, MariaDB 10.11) | **Rouge** sur les envois des lots L7 à L11 (§4, défaut 2), **verte** depuis `9e06f78` ; à relever sur la pull request pour les derniers commits |

**Tests ignorés sur le poste** : les 2 mêmes que depuis la phase 2 (`tests/Feature/Release/ReleaseScriptsTest.php`), exécutés en CI.

Critère d'acceptation et règles clés :

| Exigence | Test | Fichier |
| --- | --- | --- |
| **Scénario 3** : prolongation avec contrôle de disponibilité | Période libre acceptée au tarif figé ; chevauchement refusé, bornes du délai de préparation (08:00 accepté, 08:01 refusé) ; prolongation et réservation simultanées sur deux processus : une seule aboutit | `tests/Feature/Rental/ContractExtensionTest.php`, `tests/Feature/Concurrency/ExtensionConcurrencyTest.php` |
| **Scénario 4** : retard, frais selon les paramètres | Tolérance pile sans frais, une minute après une journée, plusieurs journées, à l'heure ; frais figés après changement d'un paramètre | `tests/Feature/Rental/ContractReturnTest.php` |
| **Scénario 10** au départ | Assurance expirée le jour du départ : refus, kilométrage inchangé | `tests/Feature/Rental/InspectionTest.php`, `ContractTest.php` |
| **Scénario 12** sur le contrat | Policy, 10 actions du domaine, pages Livewire appelées directement ; essai de mutation fait | `tests/Feature/Rights/Phase5AccessMatrixTest.php` |
| Approbations (RG-10, RG-15) | Demande figée, notification sans donnée sensible, double décision sur deux processus (échoue sans le verrou : essai fait), effets d'un refus | `tests/Feature/Approvals/ApprovalTest.php`, `tests/Feature/Concurrency/ApprovalConcurrencyTest.php` |
| Conversion d'une réservation | Deux conversions simultanées sur deux processus : un seul contrat | `tests/Feature/Concurrency/ContractConcurrencyTest.php` |
| Photos et signature (D44, D45) | Position GPS retirée, image ramenée à 1 600 px, fichier PHP renommé refusé, signature illisible refusée | `InspectionTest.php` |
| PDF (D43, Q15) | Contenu attendu, aucune coordonnée du client, mention retirée quand le modèle est validé, exemplaire signé inchangé après un changement de réglage, conditions générales échappées | `tests/Feature/Rental/ContractDocumentsTest.php` |
| Architecture | Documents de location et approbations écrits seulement depuis `App\Domain` | `tests/Arch/RentalWritesTest.php` |

**Vérifications dans le navigateur** (poste, base de développement, Chromium réglé en tablette 800 × 1 280 portrait, agent de Djerba) : départs du jour, état des lieux complet jusqu'au contrat « En cours », vraie photo de 3 000 px reçue en 1 600 × 1 067 ; aucune erreur de console ni requête en échec. **PDF lus à l'œil** : contrat (37 Ko, 0,8 s) et état des lieux (45 Ko, 1,7 s). **Non vérifié** : une vraie tablette, son appareil photo et la signature au doigt ; l'envoi d'une photo dans le test Livewire (le fichier simulé est vide ; le contenu réel est couvert par les tests du domaine et le navigateur).

## 4. Défauts trouvés pendant la phase et corrigés

| Défaut | Comment il a été trouvé | Correction |
| --- | --- | --- |
| 1. Un départ en avance n'avançait pas l'occupation du véhicule (lot L6) | Vérification dans le navigateur : départ à 22:10 pour 22:38 prévu | `StartRental` avance l'occupation sous verrou et refuse une avance qui chevauche une autre occupation ; deux tests |
| 2. **CI rouge pendant trois envois** (lots L7 à L11) alors que le poste était vert | Signalé par Hafedh le 27/11/2026 | La page d'état des lieux appelle `@vite` ; les tests passent avant `npm run build` ; le poste gardait un manifeste ancien. `withoutVite()` dans `TestCase`, échec reproduit sans `public/build` puis corrigé. Désormais, la CI est vérifiée après chaque envoi |
| 3. Interblocage MariaDB (1213) entre une prolongation et une réservation simultanées (lot L7) | Contrôle du lot L9 : le test de concurrence échouait environ une fois sur trois | Ordre des verrous catégorie puis véhicule (D38) rétabli dans la prolongation, le départ, le retour et l'annulation ; test relancé 10 fois sur 10 sans échec |
| 4. Alerte de retard plantée sans profil destinataire (lot L12) | Relecture des erreurs de Larastan | Liste vide gérée, test ajouté |
| 5. Compteur de numérotation créé dans une transaction qui tient déjà un autre compteur : délai de verrou dépassé (lot L2) | Tests du lot L2 | `SequenceAllocator::prepare()` crée le compteur avant la transaction |

## 5. Écarts au plan et décisions

| Sujet | Écart ou décision | Statut |
| --- | --- | --- |
| D43 à D51 | Appliquées comme validées le 26/11/2026 | Validées |
| D36 | Remplacée par D46 : remise et dérogation soumises au super admin au lieu d'être refusées ; un test d'écran de la phase 4 réécrit en conséquence | Validée (D46) |
| Ajouts au plan | Action « Confirmer » d'un brouillon de réservation (manquait en phase 4) ; franchise figée sur le contrat dès le lot L4 ; page de réglages « États des lieux » ; véhicule « Réservé » jusqu'au départ d'un contrat en brouillon ; frais figés au retour même sans facture | Faits, sans charge à part |
| Retard à l'heure (Q91) | Quand l'unité « heure » est choisie, les heures entamées de tout le retard sont facturées, au-delà de la tolérance. Valeur par défaut, à confirmer avec Q91 ; l'unité reste refusée tant qu'un tarif horaire manque | À confirmer |
| Contenu du contrat PDF | Ni téléphone, ni courriel, ni adresse du client (D13) | À confirmer par le conseil juridique (Q15) |
| Restitution de la caution au départ (P3, étape 4) | Non demandée au départ en phase 5 | Phase 6 |
| Paquets non retenus | `saade/filament-autograph` et `intervention/image` (prévus en phase 1) remplacés par `signature_pad` et GD (D44, D45) | `docs/compatibilite_paquets.md` à jour |

## 6. Charge consommée

Budget 30 j, seuil d'alerte 36 j. Détail au plan §7.

| Lot | Prévu (j) | Estimé (j) |
| --- | ---: | ---: |
| Jeu de données de développement (hors lots) | — | 0,5 |
| L0. Plan | 0,5 | 0,5 |
| L1. Approbations | 3 | 2,5 |
| L2. Contrats | 3,5 | 3 |
| L3. Conducteurs | 1,5 | 1,25 |
| L4. Conditions | 0,75 | 0,5 |
| L5. États des lieux | 2,5 | 2,25 |
| L6. Écrans tablette | 4,5 | 3,5 |
| L7. Prolongation | 1,5 | 1,25 |
| L8. Retour et frais | 3 | 2,5 |
| L9. Dossier de dommage | 0,5 | 0,5 |
| Correctif de verrouillage | — | 0,25 |
| L10. Clôture et avenants | 1 | 0,75 |
| L11. PDF | 2,5 | 1,75 |
| L12. Alerte et téléphone | 0,75 | 0,5 |
| Correctif de la CI | — | 0,25 |
| L13. Droits | 1,25 | 0,75 |
| L14. Rapport et revue | 1,5 | 1,5 |
| **Total** | **28,25** | **24** |

Estimations du temps passé, non un relevé horaire. **Reste dû** : l'essai sur une vraie tablette et la partie phase 5 de l'atelier (§6 de la préparation de l'atelier), environ 0,5 j, à prendre quand la recette sera installée.

## 7. Risques

| Risque | Parade |
| --- | --- |
| Génération des PDF sur le mutualisé : 0,8 à 1,7 s et 96 Mo de mémoire au plus sur le poste ; l'exemplaire signé est généré dans la transaction du départ et du retour | Police en sous-ensemble (870 Ko ramenés à 40 Ko) ; photos réduites à 6 par PDF ; à remesurer en recette avec la limite de mémoire d'Oxahost |
| Tablettes non choisies (Q72), comportement réel de l'appareil photo et de la signature | Essai en recette avant la mise en service ; papier toujours possible (Q46) |
| Tests plus lents sur le poste (8 à 15 minutes selon les passages) depuis la génération des PDF | La CI reste sous 3 minutes ; rien à faire tant qu'elle reste rapide |
| Valeurs par défaut non confirmées (Q4, Q14, Q15, Q42 à Q48, Q52, Q91 à Q94) | Toutes paramétrables ; fiche de recueil de l'atelier complétée |
| Données de test dans la base de développement (contrat `CTR-2026-00001`, créé pour la vérification dans le navigateur, avant le correctif du départ en avance) | Base de développement seulement ; réinitialisable par `migrate:fresh --seed` |

## 8. Questions pour la direction et le conseil juridique

Reprises dans la fiche de recueil de l'atelier (`docs/rapports/atelier_phase_4_preparation.md` §6) : Q4, Q14, Q42 à Q48, Q52, Q72, Q73, Q91 à Q94. Pour le conseil juridique : **Q15** (modèle de contrat, conditions générales, contenu du PDF) et **Q46** (valeur de la signature sur tablette).

## 9. Réserves

| Réserve | Origine | État |
| --- | --- | --- |
| **Phase 5, n° 1** : saisie sur une vraie tablette (appareil photo, signature au doigt) | Critère d'acceptation | Vérifiée en émulation seulement ; à faire en recette |
| Atelier avec les agences (phases 4 et 5) | Phase 4, n° 1 | Non tenu ; déroulé complété pour P3 et P4 |
| Remesure du planning en recette | Phase 4, n° 2 | Non faite |
| Installation de la recette | Phase 2, n° 1 | **Non levée**, prioritaire |
| Saisie du paramétrage en recette | Phase 3, n° 1 | Non levée |

**À valider** :
1. Ce rapport, au statut proposé « Validée avec réserves » (réserve n° 1 de la phase 5 ; réserves héritées prioritaires).
2. La conclusion de la revue (§10).
3. La pull request `phase/05-contrats-etats-des-lieux` vers `main`.
4. Les questions ouvertes de la phase (§8).

## 10. Revue de la branche (27/11/2026)

Revue de `phase/05-contrats-etats-des-lieux` (`8fb17aa..HEAD`) par Claude Code, sur cinq axes.

| Axe | Constat | Preuve |
| --- | --- | --- |
| Sécurité | Toutes les écritures des documents de location et des approbations passent par `app/Domain` ; aucun SQL brut ; aucun `float` ; aucun `{!! !!}` dans les vues ajoutées. Fichiers privés désignés par leur document, jamais par un chemin ; images et pièces contrôlées sur leur contenu, métadonnées retirées ; PDF sans ressource distante, conditions générales échappées | `RentalWritesTest`, `DatabaseAccessTest`, `NoFloatAmountsTest`, `InspectionTest`, `DamageFileTest`, `ContractDocumentsTest` |
| Droits par profil | 12 écrans × 3 profils ; approbations et réglages réservés au super admin ; fin d'intervention réservée à l'admin et au super admin ; téléphone du client seulement sur un contrat en cours de l'agence | `Phase5AccessMatrixTest`, `ApprovalTest`, `ContractReturnTest`, `LateReturnAndPhoneTest` |
| Portée par agence | Levées de portée hors du domaine limitées à l'écran des approbations (super admin seul) et aux relations d'un contrat déjà visible (réservation, véhicule, approbation) ; fichiers et PDF servis après la policy du contrat (404 sinon) | `Phase5AccessMatrixTest`, tests des contrôleurs de fichiers |
| Concurrence | Verrous en base dans l'ordre catégorie puis véhicule (D38), y compris pour les contrats depuis le correctif du lot L7 ; trois tests sur processus distincts (double décision, double conversion, prolongation contre réservation) | `tests/Feature/Concurrency/` |
| Documentation et tests | Chaque « fait » renvoie à un test ; ce qui n'a pas été vérifié est nommé (§3) ; les défauts trouvés sont tracés (§4) | Ce rapport, plan §7 |

**Observations non bloquantes** :

1. La liste des dossiers de dommage de la fiche du contrat produit du HTML (liens vers les pièces) ; chaque valeur passe par `e()`. C'est sûr, mais plus fragile qu'une vue Blade : à surveiller si la liste évolue.
2. Pour le test de conversion simultanée d'une réservation, l'essai de mutation n'a pas été fait : un second contrôle (l'occupation du véhicule) bloquerait aussi le doublon.
3. La règle « heures entamées de tout le retard » pour l'unité « heure » est une valeur par défaut (§5), à confirmer avec Q91.
4. Le JavaScript de la tablette n'est couvert par aucun test automatique : il a été vérifié dans le navigateur.
5. La génération de l'exemplaire signé dans la transaction du départ et du retour allonge ces validations d'une à deux secondes.

**Conclusion** : aucun défaut bloquant. La branche peut être fusionnée dans `main` après validation de ce rapport.
