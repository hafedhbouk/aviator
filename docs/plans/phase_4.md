# Plan de la phase 4 — Parc, leasing et réservations

| Élément | Valeur |
| --- | --- |
| Phase | 4. Parc, leasing et réservations (lot 1) |
| Branche | `phase/04-parc-leasing-reservations`, créée le 19/11/2026 depuis `main` (`cbd9ff3`, fusion de la pull request n° 4) |
| Budget | **41,75 j** : référence 39 j + reports de la phase 2 (2,75 j). **Seuil d'alerte : 46,8 j** (référence + 20 %), soit 5,05 j de marge |
| Statut | **Plan validé le 19/11/2026** par Hafedh, avec D1 et D36 à D40 (D40 avec condition, §4). Calendrier des réserves validé (§0) |
| Critère d'acceptation | Scénarios 1, 2, 10 et 15 verts ; machine à états du véhicule ; alertes (`references/phases.md` §12). **En plus** : atelier avec les agences en fin de phase (§9) |
| Sources | Cahier, modules B (B-01 à B-15) et C (C-01 à C-10), RG-01, RG-02, RG-13, RG-15, RG-17 ; `docs/architecture.md` §6.1 et §7 ; `docs/modele_donnees.md` §4 et §5 ; parcours P1 et P2 (`docs/maquettes/parcours.md`) ; décisions D1, D5, D8, D11, D13, D21, D24 ; revue de la phase 3 (`docs/rapports/phase_3.md` §10) |

## 0. Rappel : deux réserves non levées, à lever avant l'atelier

L'atelier de fin de phase (§9) se tient **en recette**, avec le paramétrage réel. Il ne peut pas avoir lieu tant que ces deux réserves restent ouvertes :

| Réserve | Ce qui manque | Qui | Bloque |
| --- | --- | --- | --- |
| **Phase 2, n° 1 — installation de la recette** (prioritaire) | Choix du domaine par la direction (Q6) ; installation selon `docs/installation_recette.md` ; résultat d'`aviator:check-environment` joint au rapport de la phase 2. La ligne « Règles fiscales » restera en ERREUR tant que les mentions des factures manquent : c'est attendu (D28) | Direction (Q6), puis Hafedh | Tout le reste |
| **Phase 3, n° 1 — saisie du paramétrage** | Saisie par l'admin du siège selon `docs/guide_saisie_parametrage.md` (société, lieux et horaires, catégories, saisons, grilles, prix des options, fournisseurs), puis validation par lui | Admin du siège, avec mon appui (0,25 j prévus en phase 3) | Les devis de l'atelier : sans saisons ni grilles, aucune réservation n'a de prix |

**Échéances validées le 19/11/2026** : recette installée **avant la fin du lot L9** (disponibilité), saisie du paramétrage terminée **avant le lot L15** (préparation de l'atelier). Je le rappellerai à chaque fin de lot tant que ce n'est pas fait. La réserve n° 1 de la **phase 1** (ateliers avec les 4 agences, prévus avant la phase 4) est levée par ce même atelier.

**Point au 25/11/2026 (fin du lot L9)** : échéance de la recette **dépassée**, recette non installée. Q6 est répondue : domaine `aviator.tn`, recette sur `recette.aviator.tn` (`docs/installation_recette.md`, prérequis DNS ajoutés). **Nouvelle échéance, acceptée par Hafedh le 25/11/2026** : recette installée **avant la fin du lot L11**, pour que le planning (L12) y soit mesuré (condition 3 de D35), et pour laisser la saisie du paramétrage se faire avant L15. Si elle glisse encore, la saisie, la mesure du planning et l'atelier glissent avec elle : la phase se clôturerait alors avec réserve et atelier reporté (§10). Le développement n'en dépend pas d'ici là.

**Point au 25/11/2026 (fin du lot L11)** : recette toujours non installée. **Décision de Hafedh** : la phase 4 sera **clôturée avec réserve** et l'**atelier avec les agences reporté** après l'installation de la recette et la saisie du paramétrage (§10). En attendant, les lots qui n'en dépendent pas continuent (L6, L7, L13, L14) ; la mesure du planning en recette (condition 3 de D35) et l'atelier (L15) restent en attente.

## 1. Périmètre

### Dans la phase (P1 d'abord)

1. **Prototype du planning** `guava/calendar` en tout premier, avec son repli (§3).
2. **Module B — parc** : fiche véhicule (B-01, B-02, B-04), kilométrage tracé (B-03), assurance et visite (B-05), historique consolidé (B-06, avec ce qui existe en phase 4 : réservations, statuts, kilométrage, financement ; contrats en phase 5, maintenances et dépenses en phase 8), alertes (B-07), pièces jointes servies par le contrôleur des fichiers privés (B-08, P2), machine à états (B-09), sortie du parc (B-10, P2).
3. **Module B — leasing et crédit** : contrat de leasing (B-11), échéancier généré et pointage (B-12), alertes de fin et de levée d'option (B-13), fin de leasing (B-14, P2), crédit bancaire (B-15, P2).
4. **Module C — réservations** : disponibilité (C-01), planning (C-02), création, modification, annulation motivée (C-03), dates, heures et agences (C-04), par catégorie ou véhicule (C-05), devis (C-06), anti-chevauchement sous saisies simultanées (C-07), statuts (C-08), expiration automatique (C-09, P2, désactivée tant que le délai est vide : D31, Q90).
5. **Reports des phases 2 et 3** (§2).

### Hors phase

| Élément | Phase | Raison |
| --- | --- | --- |
| Acompte à la réservation (C-10, P2) | 6 | C'est un encaissement (Q40) : il arrive avec les encaissements ; aucun document fiscal avant. Décision proposée **D37** |
| Dérogation à la liste noire et remise au-delà du seuil | 5 | Elles passent par `approval_requests` (phase 5). En phase 4 : refus net, colonnes `override_approval_id` et `discount_approval_id` créées vides. Décision proposée **D36** |
| Contrats, départs, états des lieux | 5 | Le scénario 10 est vérifié en phase 4 au niveau du domaine (§6), l'écran de départ vient en phase 5 |
| Maintenances, immobilisations, dépenses | 8 | `VehicleStatusService` et `vehicle_blocks` les acceptent déjà ; leurs écrans viennent en phase 8 |
| Fiche client complète, écran des recommandations, propositions de liste noire | 7 | En phase 4 : création minimale au comptoir et première version des recommandations (§2) |
| Push | 9 | Les alertes vont au centre de notifications (`database`) ; le push s'y ajoute en phase 9 |
| Statistiques de rentabilité | 9 | La phase 4 fournit la requête des charges de financement par véhicule et par mois (scénario 15) |

## 2. Reports des phases 2 et 3 intégrés

| Élément | Origine | Lot | Charge (j) | Contenu et preuve attendue |
| --- | --- | --- | ---: | --- |
| **Recherche de clients (D24)** | Phase 2 | L8 | 1 | Un critère par recherche : nom exact complet (tout ordre, casse et accents ignorés), pièce, permis (empreintes HMAC), téléphone harmonisé `+216XXXXXXXX` (`SearchNormalizer::phone` corrigé, `customers.phone_search` recalculé) ; 10 résultats au plus ; journal `customer_access` sans la valeur ; 31e recherche de l'heure refusée et super admin notifié sans donnée client. Tests pour chaque règle, dont « 0123 4567 », « 0123-4567 » et « 01234567 » |
| **Contrôleur des fichiers privés (D21)** | Phase 2 | L3 | 0,5 | Route qui sert un fichier du disque `private` après policy ; en-têtes (`Content-Type` réel, `nosniff`, `no-store`, `inline` ou `attachment`) ; tests par profil et par agence ; chemin détourné refusé. Premier usage : carte grise et attestation d'assurance (B-08) ; réutilisé en phase 5 |
| **Migration `drivers`** | Phase 2 (avancée de la phase 5) | L8 | 0,5 | Table, modèle, permis chiffré, empreinte `license_hash`, rechiffrement couvert par `aviator:reencrypt-secrets` et `aviator:rehash-blind-index` (D23) ; tests |
| **Test RG-15 entre agences** | Phase 2 | L8 | 0,5 | Première version de `customer_recommendations` (niveau, statut, ajout seul : RG-16) et `CustomerRiskGate` ; test « client mis sur liste noire à Sfax, réservation refusée à Sousse », sans motif affiché à l'agent |
| **Sélecteur d'agence active** | Phase 2 | L13 | 0,25 | Choix de l'agence active dans le menu utilisateur (`CurrentAgency::switchTo`), limité aux agences de l'utilisateur ; agence par défaut des nouvelles réservations ; test |
| **Scénario 12 rejoué** | Phase 2 | L14 | — | « Un agent de Sfax modifie une réservation de Sousse : refus » (policy, action de domaine et requête Livewire directe), puis pour les véhicules et les financements. Inclus dans le périmètre |
| **Prototype `guava/calendar`** | D5 | L0 | — | §3. Inclus dans le périmètre |
| **Verrou des saisons** | Revue de la phase 3, observation n° 1 | L1 | 0,25 (pris sur les aléas) | §5.3 |
| **Total des reports chiffrés** | | | **2,75** | + 0,25 j sur les aléas pour le verrou des saisons |

## 3. Prototype du planning (lot L0, en tout début de phase)

**Durée bornée : 1 j**, puis 0,25 j pour la note de décision. Le prototype vit dans une page Filament isolée, sur des données de démonstration (50 véhicules, 4 agences, 300 blocs sur un mois), sans aucune action d'écriture.

### Critères de choix

| N° | Critère | Obligatoire | Comment je le vérifie |
| --- | --- | --- | --- |
| 1 | Vue **frise par ressource** : une ligne par véhicule, regroupée par agence ou catégorie, en vue semaine et mois | Oui | Capture d'écran dans le navigateur |
| 2 | **50 véhicules × 1 mois, 300 blocs** : page affichée en moins de 2 s sur le poste, données servies en moins de 300 ms | Oui | Mesure dans le navigateur et test de la requête |
| 3 | Données **construites côté serveur** : l'agent d'une autre agence reçoit « Occupé », sans nom ni numéro (Q38). Aucun nom de client n'atteint le navigateur pour un bloc d'une autre agence | Oui | Test sur la charge utile JSON renvoyée, par profil |
| 4 | Clic sur un bloc de son agence → réservation ; clic sur une case libre → assistant prérempli (véhicule, date) | Oui | Navigateur |
| 5 | Délai de préparation **hachuré**, couleurs par type (réservation, contrat, maintenance, immobilisation), retard en bordure rouge | Oui | Navigateur |
| 6 | Français, fuseau `Africa/Tunis`, heures sur 24 h, semaine commençant le lundi | Oui | Navigateur |
| 7 | Glisser-déposer **désactivable** : aucune modification depuis le planning en phase 4 (D39) | Oui | Navigateur |
| 8 | Paquet MIT, compatible PHP 8.3 et Filament 5, assets compilés par `npm run build`, aucun appel à un CDN à l'exécution | Oui | `composer check`, `build-release.sh`, onglet réseau |
| 9 | Lisible sur tablette (comptoir) ; consultation possible sur smartphone | Non | Navigateur en largeur réduite |
| 10 | Thème clair et sombre de Filament respectés | Non | Navigateur |

### Règle de décision

- **Tous les critères obligatoires tenus dans la journée** → `guava/calendar` retenu ; intégration en lot L12 (3 j).
- **Un critère obligatoire non tenu**, et non corrigeable en 0,5 j de plus → **repli : frise Livewire maison** (grille CSS véhicules × jours, blocs positionnés côté serveur, aucune dépendance). Charge estimée du repli : 4 j au lieu de 3 j, soit **+1 j pris sur les aléas**, signalé au compte rendu du lot L0.
- Dans les deux cas : **décision D35** écrite avec les captures et les mesures, puis **arrêt pour ta validation** avant le lot L1. C'est le seul arrêt prévu en cours de phase, hors écarts.

### Résultat du prototype (19/11/2026)

`guava/calendar` 3.2.2 (MIT ; PHP ^8.2, Filament ^5) installé. Prototype : page `Comptoir → Planning (prototype)` (`app/Filament/Pages/PlanningPrototype.php`, accessible sur le poste et en test seulement), widget `app/Filament/Prototype/PlanningPrototypeCalendar.php`, données fictives générées en mémoire (`PlanningDemoData` : 50 véhicules, 4 agences, 347 occupations sur novembre). La règle « qui voit quoi » est dans le domaine, indépendante de la bibliothèque : `app/Domain/Availability/Planning/PlanningProjection.php`, réutilisée telle quelle au lot L12.

Conditions de mesure : poste de développement, serveur intégré de PHP (un seul processus) avec OPcache et `php artisan optimize`, comme le fait `install-release.sh`. Sans OPcache ni mise en cache, la seule route `/up` prend 0,55 s sur le poste : ces conditions-là ne sont pas représentatives. Navigateur Chromium sans interface, compte d'agence de Sfax.

| N° | Critère | Résultat | Preuve |
| --- | --- | --- | --- |
| 1 | Frise par ressource, regroupée, semaine et mois | **Tenu** : `resourceTimelineWeek` (créneaux de 6 h) et `resourceTimelineMonth` (créneaux d'un jour), véhicules regroupés par agence (groupes repliables) | Captures 1 et 2 |
| 2 | 50 véhicules × 1 mois, 300 blocs : page < 2 s, données < 300 ms | **Page : tenu** (blocs affichés en 0,76 à 1,41 s à chaud ; premier octet 313 ms ; `DOMContentLoaded` 498 ms ; 347 blocs en vue mois). **Données : partiellement mesuré** : projection 30 ms côté domaine, mais requête Livewire complète entre 203 et 389 ms à chaud sur le serveur intégré de PHP (mono-processus). Rendu côté navigateur sous 150 ms | Script de mesure ; à remesurer en recette au lot L12, sur données réelles et index |
| 3 | Données construites côté serveur ; « Occupé » sans nom ni numéro pour une autre agence | **Tenu** : aucun nom, numéro, lien ni modèle dans la charge utile JSON des blocs d'une autre agence ; identifiant opaque | `tests/Feature/Planning/PlanningPrototypeTest.php` (8 tests) |
| 4 | Clic sur un bloc de son agence ; clic sur une case libre | **Tenu** : un bloc détaillé porte un lien (ouverture du document) ; une case libre déclenche l'assistant prérempli (« Assistant prérempli : Djerba, le 19/11/2026 à 12:00 ») ; un bloc « Occupé » n'est pas cliquable (`pointer-events: none`) | Navigateur |
| 5 | Délai hachuré, couleurs par type, retard en rouge | **Tenu** : fin du bloc hachurée dans la proportion exacte du délai (calculée côté serveur), bleu réservation, vert contrat, orange maintenance, gris immobilisation, gris clair « Occupé », contour rouge pour un retard | Capture 2 |
| 6 | Français, `Africa/Tunis`, 24 h, lundi | **Tenu** : « lun. 16/11 », « 18 h », « novembre 2026 », boutons « Aujourd'hui », « Semaine », « Mois » | Captures 1 et 2 |
| 7 | Glisser-déposer désactivable | **Tenu** : désactivé par défaut et laissé désactivé ; une tentative de déplacement ne bouge pas le bloc (0 px) ; test sur les deux réglages | Navigateur, test D39 |
| 8 | MIT, PHP 8.3, Filament 5, assets compilés, aucun CDN | **Tenu** : JavaScript livré avec le paquet (164 Ko, compilé), publié par `filament:upgrade` à chaque `composer install`, donc aussi dans l'archive (`build-release.sh`, étape 6) ; aucune adresse externe chargée par le code (contrôle des sources publiées : seules des URL de documentation figurent dans le texte) ; aucune requête en échec pendant les essais. `composer check` vert. Fichiers publiés ajoutés au `.gitignore` comme ceux de Filament | `composer check` ; `build-release.sh` à exécuter avant la fusion (lot L16) |
| 9 | Tablette ; smartphone en consultation | **Tenu** : tablette lisible (1024 px) ; sur smartphone, colonne des véhicules réduite à 120 px, frise défilante, aucun défilement horizontal de la page | Captures 4 et 5 |
| 10 | Thèmes clair et sombre | **Tenu** | Capture 3 |

Captures : `docs/plans/phase_4_prototype/` (données fictives).

**Défauts du paquet relevés, contournés dans le prototype** (à garder au lot L12) :

1. `CalendarResource::toCalendarObject()` ne convertit pas les ressources enfants (elles arrivent vides au navigateur : lignes sans titre, aucun bloc rattaché). Contournement : enfants transmis déjà convertis en tableaux.
2. Un clic sur un événement sans lien ni modèle signé provoque une exception côté serveur. Contournement : les blocs « Occupé » ne sont pas cliquables ; au lot L12, tout bloc détaillé porte un lien.
3. Le calendrier affiche l'heure du navigateur ; le paquet convertit les heures côté serveur à partir du décalage envoyé par le navigateur. Tunis est à UTC+1 toute l'année : un poste réglé sur un autre fuseau verrait des heures décalées. Test prévu au lot L12 avec un navigateur sur un autre fuseau. **Résultat au lot L12** : l'hypothèse était fausse, la bibliothèque convertissait bien les heures au fuseau du navigateur (−6 h à New York) ; corrigé par l'option `timeZone` d'EventCalendar (§11, ligne L12).

**D35 validée le 19/11/2026 avec conditions** (`docs/decisions.md`) : version figée à 3.2.2, tests de garde des contournements et tickets (`docs/rapports/tickets_guava_calendar.md`), heure de Tunis quel que soit le navigateur, requête sous 200 ms en local et remesure en recette ; intégrées au lot L12 (§7).

**Recommandation (D35)** : retenir **`guava/calendar`**. Les huit critères obligatoires sont tenus. Seul le temps de la requête de données reste à confirmer en recette, sur un vrai serveur. Le repli (frise Livewire maison) n'est pas nécessaire ; le jour d'aléas réservé au repli (+1 j) reste disponible.

## 4. Décisions de ce plan

| N° | Décision | Raison | Statut |
| --- | --- | --- | --- |
| D1 | `vehicle_blocks` porte toute occupation ; RG-01 en une requête sur une table | Tout le lot L9 en dépend | **Validée** (19/11/2026) |
| D35 | Choix du planning (après le prototype) | §3 | À décider à la fin de L0 |
| D36 | Dérogation à la liste noire et remise au-delà du seuil : **refus** en phase 4, mécanisme d'approbation en phase 5 | `approval_requests` est une table de la phase 5 | **Validée** (19/11/2026) |
| D37 | Acompte (C-10, P2) reporté en phase 6 avec les encaissements | Q40 ; aucun document fiscal sans règle validée | **Validée** (19/11/2026) |
| D38 | Verrous de sérialisation **en base** (ligne verrouillée par `SELECT … FOR UPDATE` dans une table `named_locks`), jamais par le cache | Le verrou de cache dépend du magasin configuré : `array` dans `phpunit.xml`, qui n'est pas partagé entre processus | **Validée** (19/11/2026) |
| D39 | Planning en **lecture seule** en phase 4 : toute modification passe par le formulaire et `ModifyReservation`, sous verrou. **Le glisser-déposer est une évolution possible, à discuter à l'atelier** (§9) : s'il est demandé, il appellera `ModifyReservation` avec les mêmes contrôles et sera chiffré avant développement | Un déplacement par glisser-déposer ne doit contourner aucun contrôle | **Validée** (19/11/2026) |
| D40 | Création **minimale** du client au comptoir ; fiche complète en phase 7. **Condition** : au moins le **numéro de pièce d'identité ou de permis** est exigé ; dès cette création, la détection des doublons par empreinte (D24, K-05) et le contrôle de la liste noire (RG-15, `CustomerRiskGate`) s'appliquent : une pièce ou un permis déjà connu renvoie à la fiche existante, avec son niveau de risque | Une réservation exige un client ; parcours P1, étape 2 bis ; empêcher le contournement de la liste noire par une nouvelle fiche | **Validée avec condition** (19/11/2026) |

Tests exigés par la condition de D40 (lot L8) :

1. **Création minimale sans pièce ni permis : refusée** (message explicite, rien n'est enregistré, aucune empreinte calculée).
2. **Client sur liste noire recréé au comptoir avec la même pièce** (saisie différente : espaces, tirets, casse, autre agence) : **retrouvé** par l'empreinte, aucune nouvelle fiche créée, et réservation **refusée** (RG-15, D36), sans motif affiché à l'agent. Variante avec le même permis.

Valeurs par défaut des questions ouvertes appliquées en phase 4, toutes paramétrables : Q3 (retour à l'agence de départ), Q28 (« Réservé » = départ dans les 24 h), Q29 (5 transitions ajoutées), Q31 (`pending` et `no_show`), Q32 (tranches de 24 h), Q33 (tarif dégressif, chaque jour au prix de sa saison), Q36 (pas de surréservation), Q37 (seule l'agence de départ crée), Q38 (« Occupé » sans nom), Q39 (non-présentation proposée après 120 min, confirmée par l'agence), Q41 (60 min), Q49 (`RES-AAAA-NNNNN`), Q90 (délais vides : alertes et expiration désactivées).

## 5. Concurrence : tests sur deux processus

Même méthode que `tests/Feature/Concurrency/SequenceConcurrencyTest.php` et `TaxRuleConcurrencyTest.php` : script de travail dans `tests/Support/`, lancé par `Symfony\Process` (pas de `pcntl` sous Windows), deux connexions réelles à `aviator_test`, `DatabaseTruncation`. Chaque test est rejoué dans `composer check`.

### 5.1 RG-02 — réservations (scénario 1)

| Test | Mise en place | Attendu |
| --- | --- | --- |
| Même véhicule, même période | Deux processus lancent `CreateReservation` en même temps, chacun avec le même véhicule | Une seule réservation et un seul `vehicle_block` ; l'autre reçoit « Ce véhicule vient d'être réservé sur cette période » ; aucun numéro `RES` consommé par l'échec |
| Verrou tenu | Le premier processus garde la transaction ouverte (point d'arrêt de test) ; le second démarre | Le second **attend**, puis voit l'occupation et échoue ; durée mesurée ≥ durée de rétention |
| Dernier véhicule d'une catégorie (Q36) | Capacité de 1 ; deux réservations « catégorie seulement » | Une seule aboutit (verrou sur la ligne `vehicle_categories`) |
| Affectation croisée | Deux réservations par catégorie affectées au même véhicule en même temps (`AssignVehicle`) | Une seule affectation |
| Modification contre création | Un processus prolonge une réservation sur la période qu'un second réserve | Une seule des deux aboutit |
| Rafale | 10 processus sur le même véhicule et la même période | Exactement une réservation |

### 5.2 RG-01 — bornes (sans concurrence)

Délai de préparation inclus (**précisé au lot L9** : véhicule rendu à 10:00 avec 60 min de préparation, prêt à 11:00 ; départ à 11:00 accepté, à 10:59 refusé) ; passage de minuit, de fin de mois et d'année ; réservation annulée ou expirée libérée (scénario 2) ; modification qui ne se bloque pas elle-même ; véhicule `retired`, `maintenance` ou `unavailable` exclu de la disponibilité.

### 5.3 Verrou des saisons (revue de la phase 3)

1. **Test d'abord**, sur l'état actuel : deux processus enregistrent en même temps deux saisons qui se chevauchent. Avec le magasin de cache `array` des tests, je m'attends à ce que les deux passent : le résultat sera cité tel quel.
2. **Correction** (D38) : `SaveSeason` sérialisé par un verrou de ligne en base (`named_locks`), comme le reste du domaine ; `Cache::lock` retiré.
3. Le même test doit alors donner : une saison enregistrée, l'autre refusée pour chevauchement. Variante « verrou tenu » comme en §5.1.

**Résultat (lot L1, 19/11/2026)** :

| Test (`tests/Feature/Concurrency/SeasonConcurrencyTest.php`) | Code de la phase 3 (`Cache::lock`) | Après D38 (`DatabaseLock`) |
| --- | --- | --- |
| Deux saisies simultanées qui se chevauchent | Passé, **par hasard** : les deux processus ne se sont pas croisés ; ce test seul ne prouve rien | Passé |
| « Verrou tenu » : le premier tient le verrou, le second doit attendre puis être refusé | **Échec** : « La seconde saisie n'a pas attendu le verrou de la première. » Le verrou de cache (`array` en test) n'est pas partagé entre processus | **Passé** (3 exécutions sur 3) : le second processus finit après la validation du premier et reçoit « elle chevauche la saison Été A » |

Correction : table `named_locks`, `App\Support\Locking\DatabaseLock` (ligne verrouillée jusqu'à la fin de la transaction), `SaveSeason` sous ce verrou avec une lecture verrouillante du chevauchement ; test d'architecture : aucun verrou de cache dans `app/`. En production, `CACHE_STORE=database` rendait le verrou de cache effectif entre processus ; la saisie n'était donc pas exposée en recette, mais la protection dépendait d'un réglage et n'était pas prouvée par les tests.

**Constat annexe, corrigé** : `DatabaseTruncation` (tests de concurrence) vidait aussi la table `settings`, que `RefreshDatabase` ne recrée pas. Tout test lancé après un test de concurrence pouvait échouer sur `MissingSettings`, selon l'ordre d'exécution (reproduit avec `SequenceConcurrencyTest` de la phase 2). La suite complète passait grâce à l'ordre des fichiers. `settings` est désormais exclue de la troncature (`tests/TestCase.php`).

## 6. Scénarios d'acceptation

| Scénario | Test | Niveau |
| --- | --- | --- |
| **1.** Deux réservations simultanées, même véhicule, même période : une seule aboutit | §5.1 | Deux processus, MariaDB |
| **2.** Réservation annulée : véhicule de nouveau disponible | `CancelReservation` (motif obligatoire) désactive le `vehicle_block` ; la même période est ensuite réservable ; le statut du véhicule revient à `available` si « Réservé » | Domaine + écran |
| **10.** Véhicule à l'assurance expirée : départ refusé | `Fleet\DepartureGuard` : refus si l'assurance ou la visite technique n'est pas valide **à la date de départ** ; `VehicleStatusService` refuse la transition vers `rented` dans ce cas ; affectation à une réservation refusée pour la même raison ; alerte si l'échéance tombe **pendant** la location. L'écran de départ (phase 5) appellera ce même garde | Domaine ; repris en phase 5 |
| **15.** Loyer de leasing compté une seule fois dans les charges et la rentabilité | `Fleet\FinancingCharges::forVehicleAndMonth()` : un loyer par mois d'échéance (`charge_month`, RG-17), unicité (`vehicle_financing_id`, `sequence`) ; régénérer l'échéancier, pointer un loyer ou lever l'option ne double jamais la charge ; l'apport compté une seule fois (Q19). La phase 9 réutilise cette requête | Domaine |
| **12.** (rejoué) Agent de Sfax modifiant un document de Sousse : refus | Réservations, véhicules, financements | Policies + actions + Livewire |
| RG-15 entre agences | §2 | Domaine + écran |

## 7. Lots et charge

| Lot | Contenu | Charge (j) |
| --- | --- | ---: |
| **L0. Prototype du planning** | §3 ; décision D35 ; **arrêt pour validation** | 1,25 |
| **L1. Verrous en base** | Table `named_locks`, `Support\Locking\NamedLock` ; test à deux processus des saisons, puis correction de `SaveSeason` (§5.3) | 0,25 |
| L2. Véhicules | Migrations `vehicles`, `vehicle_documents` ; B-01, B-02, B-04, B-05, B-10 ; ressource Filament, portée par agence (agence de rattachement), policies, journal | 4 |
| L3. Fichiers privés | Contrôleur D21 ; pièces jointes B-08 | 0,5 |
| L4. Kilométrage et historique | `vehicle_mileage_logs`, `RecordMileage`, `CorrectMileage` (baisse refusée sans motif, B-03) ; onglet d'historique B-06 | 1,25 |
| L5. Machine à états | `VehicleStatusService` (seul à écrire `vehicles.status`), `vehicle_status_logs`, transitions du §6 de la skill + Q29, fenêtre « Réservé » (Q28), `DepartureGuard` (scénario 10), commande `fleet:check-status` | 3 |
| L6. Leasing et crédit | `vehicle_financings`, `financing_installments` ; B-11 à B-15 ; génération et pointage de l'échéancier en `brick/math` ; fin de leasing (levée d'option ou restitution → sortie du parc) ; `FinancingCharges` (scénario 15) | 4,5 |
| L7. Alertes | `fleet:check-documents`, `financing:due-alerts` (planificateur, idempotentes) ; notifications `database` sans donnée sensible ; délais lus dans les réglages (vides = désactivées, Q90) | 1,75 |
| L8. Clients au comptoir | `drivers` (0,5), recherche D24 (1), création minimale avec pièce ou permis obligatoire, doublons par empreinte et liste noire dès la création (D40 et ses deux tests, 0,75), recommandations v1 et `CustomerRiskGate` (0,5) | 2,75 |
| L9. Disponibilité | `vehicle_blocks`, `AvailabilityQuery` (C-01, capacité Q36, projection sans donnée client pour les autres agences), `VehicleLock`, `BlockRegistry` ; tests §5.1 et §5.2 | 4,5 |
| L10. Devis | `BillableDurationCalculator` (Q32), `QuoteCalculator` → `PriceQuote` figé (grille, saison par jour loué, agence, options ; remise sous le seuil seulement : D36) ; HT ; TTC affiché seulement avec des règles applicables (`TaxCalculator` de la phase 3) | 3 |
| L11. Réservations | Migrations `reservations`, `reservation_options` ; numéro `RES` par `SequenceAllocator` ; actions `Create`, `Modify`, `Confirm`, `Cancel`, `AssignVehicle`, `ExpireReservations`, `MarkNoShow` ; statuts C-08 et Q31 ; assistant comptoir en 5 étapes (P1) ; liste, filtres, portée par agence | 6 |
| L12. Planning | `guava/calendar` 3.2.2 (D35) sur les vraies données ; projection serveur ; filtres agence et catégorie ; prototype et données fictives supprimés. **Conditions de D35** : (1) un test par contournement du paquet, qui échoue si une mise à jour change son comportement (sous-lignes, blocs non cliquables, fuseau) ; tickets 2 et 3 reproduits avant publication ; (2) heures toujours à l'heure de Tunis, essai avec un navigateur sur un autre fuseau, conversion côté serveur si nécessaire ; (3) requête des données sous 200 ms en local (index adaptés, fenêtre de dates stricte, aucune requête N+1, compte des requêtes testé), remesurée en recette ; au-delà de 300 ms en recette : signalement avant L15 | 3 |
| L13. Sélecteur d'agence active | §2 | 0,25 |
| L14. Droits, audit et tests transverses | Matrice par profil sur les nouveaux écrans ; scénario 12 par type de document ; recherche globale et sélecteurs (aucune fuite du carnet, D13) ; tests d'architecture (aucun SQL brut, aucune écriture de `vehicles.status` hors du service) ; **traductions françaises manquantes de Filament 5** (158 clés, `lang/vendor/filament-*/fr`) et test de parité avec l'anglais, comme pour D34 (+0,5 j sur les aléas, constat du lot L5) | 1,25 |
| L15. Préparation de l'atelier | Données de démonstration fictives (`DemoFleetSeeder`, jamais en production), déroulé, fiche de recueil (§9) | 1 |
| L16. Rapport et revue | `docs/rapports/phase_4.md`, revue de la branche | 1 |
| Aléas | 3 j au départ, dont 0,25 j déjà affectés à L1 ; couvrent le repli du planning (+1 j) s'il est choisi | 2,5 |
| **Total** | | **41,75** |

Ordre : L0 → L1 → L2 → L3 → L5 → L4 → L9 → L8 → L10 → L11 → L6 → L7 → L12 → L13 → L14 → L15 → L16. La disponibilité et la machine à états passent avant le leasing, conformément au prompt de la phase (« priorité : `Availability`, puis `VehicleStatusService` »).

À chaque fin de lot : `composer check` (nombre de tests, statut de chaque outil), commits fichier par fichier, commande de push, charge estimée reportée au §11. Arrêt sur décision, écart ou risque de dépassement.

## 8. Tables migrées

`named_locks`, `vehicles`, `vehicle_documents`, `vehicle_status_logs`, `vehicle_mileage_logs`, `vehicle_blocks`, `vehicle_financings`, `financing_installments`, `drivers`, `customer_recommendations` (v1), `reservations`, `reservation_options`. InnoDB, `utf8mb4_unicode_ci`, montants en `DECIMAL(15,3)`, aucune fonctionnalité propre à MySQL 8. `docs/modele_donnees.md` mis à jour à chaque lot.

## 9. Atelier avec les agences (fin de phase)

**Préalables** : les deux réserves du §0 levées ; archive de la phase 4 installée en recette ; un compte par agence (2FA active), un pour l'admin du siège ; données de démonstration fictives **en plus** du paramétrage réel, aucun vrai client.

**Format proposé** : une séance de 2 h par écran partagé avec les 4 agents et l'admin du siège, puis 30 min de prise en main libre ; compte rendu dans `docs/rapports/atelier_phase_4.md`.

### Ce qu'il faut montrer

| N° | Démonstration | Pour | Ce qu'on leur demande |
| --- | --- | --- | --- |
| 1 | **Planning** de leur agence, filtres, autres agences en « Occupé » sans nom | Agents | La frise est-elle lisible ? Faut-il voir le nom du client des autres agences (Q38) ? **Le glisser-déposer pour déplacer une réservation est-il utile** (D39 : évolution possible, chiffrée avant développement) ? |
| 2 | **Réservation au comptoir** de bout en bout : recherche du client (et ses limites, D24), création avec doublon détecté, période, disponibilité, catégorie ou véhicule précis, devis, enregistrement, numéro `RES` | Agents | Étapes dans le bon ordre ? Champs manquants ? Format du numéro (Q49) ? |
| 3 | **Saisie simultanée en direct** : deux agents réservent le même véhicule au même moment ; un seul réussit, l'autre reçoit le message et les véhicules restants | Tous | Le message est-il clair ? |
| 4 | **Client sur liste noire** inscrit à Sfax, refusé à Sousse, sans motif affiché | Agents, admin | Comportement attendu ? (dérogation en phase 5) |
| 5 | Modification, annulation avec motif, non-présentation, expiration | Agents | Délai de non-présentation (Q39) ; expiration au comptoir (Q90 c) |
| 6 | **Fiche véhicule** : documents, kilométrage (correction motivée), statuts et leur historique, refus d'affectation d'un véhicule à l'assurance expirée (scénario 10) | Agents, admin | Signification de « Réservé » (Q28), transitions manquantes (Q29) |
| 7 | **Alertes** dans le centre de notifications : assurance, visite, fin de leasing | Admin, agents | Délais et destinataires des alertes (Q90 d) |
| 8 | **Leasing** : contrat, échéancier généré, pointage, fin de contrat | Admin du siège, direction | Données réelles des leasings en cours (Q12) ; apports (Q13) |
| 9 | Tablette et smartphone : planning et réservation en largeur réduite | Agents | Appareils utilisés au comptoir |

### Décisions à recueillir pendant l'atelier

Q3 (retour dans une autre agence), Q28, Q29, Q31, Q32 et Q33 (décompte et tarif, sur un exemple chiffré à cheval sur deux saisons), Q36 (surréservation), Q37 (réservation pour une autre agence), Q38, Q39, Q41 (délai de préparation), Q49, Q90. Chaque réponse est reportée dans `docs/questions_ouvertes.md` ; une réponse qui ajoute un mécanisme est chiffrée avant d'être développée.

## 10. Risques

| Risque | Parade |
| --- | --- |
| **Recette toujours pas installée** (Q6) | Atelier impossible ; rappel à chaque fin de lot ; si la recette n'est pas prête à L15, la phase se clôt « avec réserve », atelier reporté |
| Prototype du planning non concluant | Repli prévu et chiffré (§3), décision écrite avant tout code d'intégration |
| Test de concurrence instable sous Windows (temps de démarrage des processus) | Point d'arrêt explicite plutôt que temporisation ; mesure de l'attente plutôt que de l'ordre d'arrivée |
| Questions Q32, Q33, Q36 tranchées autrement à l'atelier | Valeurs paramétrables ; chiffrage avant développement |
| Données de leasing réelles absentes (Q12) | Saisie manuelle ou import en phase 10 ; démonstration sur des données fictives |
| Lot L11 (6 j) plus lourd que prévu | Suivi au §11 ; signalement dès qu'un lot dépasse sa charge de plus de 25 % |

## 11. Suivi de la charge (mis à jour à chaque fin de lot)

Budget 41,75 j, seuil d'alerte 46,8 j.

| Lot | Prévu (j) | Estimé (j) | Cumul estimé (j) | Cumul prévu (j) | Commentaire |
| --- | ---: | ---: | ---: | ---: | --- |
| Plan | — | 0,25 | 0,25 | — | Ce document ; D33 et D34 validées dans `docs/decisions.md` et `docs/projet.md` (compté dans L16) |
| L0. Prototype du planning | 1,25 | 1,25 | 1,5 | 1,25 | `guava/calendar` 3.2.2, projection « qui voit quoi » dans le domaine (`PlanningProjection`, réutilisée en L12), 8 tests, mesures dans le navigateur, 3 défauts du paquet contournés. Temps passé aussi sur l'environnement de mesure : MariaDB arrêtée, hôte `aviator.test` de Laragon inopérant (HTTP 404, HTTPS en erreur), OPcache absent en ligne de commande. Recommandation D35 : `guava/calendar`. **Arrêt pour validation** |
| Conditions de D35 | — | 0,25 | 1,75 | 1,25 | D35 validée avec conditions : version figée à 3.2.2, tickets pour le mainteneur (`docs/rapports/tickets_guava_calendar.md`), conditions reportées au lot L12 et dans `docs/compatibilite_paquets.md`. Vérification d'`aviator.test` : HTTP et HTTPS répondent, certificat valide ; OPcache désactivé dans `php.ini` (ligne 833). Pris sur les aléas |
| L1. Verrous en base | 0,25 | 0,5 | 2,25 | 1,5 | Test d'abord (« verrou tenu » en échec sur `Cache::lock`), puis `named_locks`, `DatabaseLock`, `SaveSeason` corrigé, test d'architecture ; piège de troncature des réglages corrigé dans `tests/TestCase.php` (+0,25 j). Écart cumulé : +0,75 j, pris sur les aléas (reste 1,75 j) |
| L2. Véhicules | 4 | 3,25 | 5,5 | 5,5 | `vehicles`, `vehicle_documents` ; permission `fleet.manage` (super admin, admin) ; `SaveVehicle` (unicité du code, du châssis et de l'immatriculation sous forme canonique `PlateNumber`, catégorie et agence actives, statut et kilométrage non modifiables, localisation fixée à la création) ; `SaveVehicleDocument` (échéance obligatoire sauf carte grise, aucun changement de véhicule) ; `AgencyScope` paramétrable, véhicules lus par leur **localisation** ; ressource Filament (liste, fiche, documents) ; 26 tests. **Écart au plan** : la sortie du parc (B-10) passe au lot L5, car elle change le statut, que seul `VehicleStatusService` écrit. Écrans couverts par les tests Livewire ; vérification dans le navigateur groupée avec L3 à L5. Retard sur le prévu rattrapé : cumul égal au prévu |
| L3. Fichiers privés | 0,5 | 0,5 | 6 | 6 | Contrôleur D21 (`VehicleDocumentFileController`, route authentifiée du panneau, 2FA) : fichier désigné par le document, jamais par un chemin ; policy du document (agent : véhicule localisé dans ses agences), sinon 404 sans dire que le document existe. `PrivateFiles` réutilisable en phase 5 : dépôt accepté seulement depuis `uploads/`, type réel contrôlé (PDF, JPEG, PNG, WebP), 10 Mo, nom aléatoire sous le dossier du document ; envoi `nosniff`, `private, no-store`, bac à sable CSP ; PDF en téléchargement (le lecteur intégré refuse le bac à sable), images en ligne. Aucune pièce supprimée. 13 tests |
| L5. Machine à états | 3 | 2,5 | 8,5 | 9 | `vehicle_status_logs` (ajout seul) ; `VehicleStatusService` seul point d'écriture (garde dans le modèle `Vehicle`), table des transitions de l'architecture §7 avec Q29 ; `DepartureGuard` (**scénario 10** : assurance et visite valides au jour de départ, heure de Tunis ; alerte si l'échéance tombe pendant la location) ; `DocumentStatusSync` et `fleet:check-status` planifié à 06:00 (D41, proposée) ; sortie du parc `RetireVehicle` (B-10, reportée de L2) ; historique des statuts et action « Sortir du parc » sur la fiche. 25 tests. La fenêtre « Réservé » avant le départ (Q28) s'ajoute au lot L11. **Vérification dans le navigateur** (L2, L3, L5) sur `aviator.test`, compte d'agent de Sfax, données fictives supprimées ensuite : liste limitée aux véhicules localisés à Sfax, aucun bouton de création, de modification ni de sortie ; véhicule de Sousse en 404 ; statuts « En maintenance » et « Indisponible » (assurance expirée) ; documents et pièce jointe ; sections de la fiche passées en pleine largeur (libellés coupés). **Constat** : 158 clés de Filament 5 sans traduction française (lues par les lecteurs d'écran, parfois visibles) ; traduction et test de parité ajoutés au lot L14 (+0,5 j, pris sur les aléas) |
| L4. Kilométrage et historique | 1,25 | 1 | 9,5 | 10,25 | `vehicle_mileage_logs` (ajout seul, valeur précédente gardée) ; `RecordMileage` (jamais en baisse, jamais dans le futur ni avant le dernier relevé ; relevé ponctuel réservé au parc, relevé avec document source prêt pour la phase 5) ; `CorrectMileage` (motif de 10 caractères au moins, fleet.manage) ; garde du modèle : `vehicles.mileage` écrit uniquement par `MileageLedger` ; relevé initial facultatif à la création ; historique consolidé `VehicleHistory` (B-06 : statuts, kilométrage, documents ; contrats, maintenances et dépenses à leur arrivée) ; onglet « Kilométrage », actions « Relever » et « Corriger », bloc « Historique » sur la fiche. 14 tests |
| Correctif de sécurité | — | 0,1 | 9,6 | 10,25 | `league/csv` 9.29.0 → 9.29.1 (avis du 22/11/2026, `composer audit` bloquant) ; branche `fix/league-csv` préparée pour `main` |
| L9. Disponibilité | 4,5 | 3,5 | 13,1 | 14,75 | `vehicle_blocks` (D1) ; `Period` ; `VehicleLock` (véhicule, catégorie) ; `BlockRegistry` seul point d'écriture (occuper, déplacer, libérer ; jamais de suppression) ; `AvailabilityQuery` (C-01 : véhicules localisés dans l'agence de départ, catégorie, délai de préparation de la catégorie, hors service exclus, documents manquants au départ signalés, aucune donnée client, nombre de requêtes constant) ; `DepartureGuard::missingAmong`. Tests : bornes (§5.2), recherche, et **concurrence sur processus distincts** : deux saisies simultanées, verrou tenu, rafale de dix, déplacement contre création, **instantané ancien**. Ce dernier **échoue** si l'on retire la lecture verrouillante du chevauchement (essai fait, puis code rétabli) : sans elle, une transaction qui a déjà lu laisserait passer une double réservation (architecture §6.1 complétée). **Reste au lot L11**, qui crée la table `reservations` : scénario 1 rejoué avec `CreateReservation`, capacité d'une catégorie sans véhicule affecté (Q36) |
| L8. Clients au comptoir | 2,75 | 2,25 | 15,35 | 17,5 | `drivers` (avancé de la phase 5 : permis et pièce chiffrés, empreintes, couverts par la rotation des clés D23) ; **recherche D24** (`CustomerSearch` : nom complet exact dans n'importe quel ordre grâce à `customers.name_key`, pièce et permis par empreinte, téléphone `+216XXXXXXXX` (`SearchNormalizer::phone` corrigé), 10 résultats sans coordonnée, journal sans la valeur, 31e recherche de l'heure refusée et super admin prévenu une fois, sans donnée client) ; **création minimale D40** (`CreateCounterCustomer` : pièce ou permis obligatoire, doublon par empreinte avant toute écriture, sous verrou en base `crm:customers`, téléphone déjà connu en simple avertissement, particulier inscrit comme conducteur) ; **recommandations v1** en ajout seul et `CustomerRiskGate` (RG-15 dans toutes les agences, refus sans motif, D36) ; `AddRecommendation` réservée au super admin jusqu'à la phase 7. 31 tests, dont les deux exigés par D40 et « liste noire à Sfax, refusé à Sousse ». **Aucun écran** : recherche et création s'affichent dans l'assistant du comptoir (L11) |
| L10. Devis | 3 | 1,5 | 16,85 | 20,5 | `BillableDurationCalculator` (Q32 : tranches de 24 h, tolérance de retard sur la dernière ; jours calendaires si le réglage le demande) ; `QuoteCalculator` (Q33 : tranche de la durée totale pour chaque jour, saison **de chaque jour** ; grille de l'agence prioritaire ; jour sans grille : refus, jamais zéro ; options par jour ou par location, plafond, quantité ; remise au-delà du seuil refusée pour tous les profils, seuil par défaut 0 % (D36) ; taxes du référentiel validé avec remise répartie au prorata, ou mention « règle fiscale non validée ») ; `PriceQuote` figé pour `reservations.quote` (montants en chaînes, aucun float). 17 tests, dont l'exemple chiffré 180,000 HT → 215,200 net. Les valeurs par défaut de Q32 et Q33 sont à confirmer à l'atelier sur cet exemple à cheval sur deux saisons |
| L11. Réservations | 6 | 4,5 | 21,35 | 26,5 | **Domaine** : `reservations`, `reservation_options` ; `SaveReservation` (droits RG-13, liste noire RG-15, règles Q90 facultatives, devis figé, numéro `RES` dans la transaction, brouillon ou confirmée) ; `ReservationHold` (verrou de la catégorie puis du véhicule, capacité Q36, documents au départ, lectures verrouillantes) ; `AssignVehicle` (devis inchangé) ; `CloseReservation` (annulation motivée, non-présentation Q39, expiration C-09 toutes les 5 min) ; `ReservedStatusSync` (Q28, chaque heure) ; D42 proposée. **Scénario 1** rejoué sur processus distincts (une seule réservation, aucun numéro perdu), capacité sous concurrence, instantané ancien (échoue sans lecture verrouillante : essai fait, code rétabli). **Scénario 2** : annulation, période de nouveau réservable, véhicule rendu « Disponible ». **Écrans** : liste par agence, fiche (dates, affectation, annulation, non-présentation), **assistant du comptoir** en 5 étapes (recherche D24 et création D40 dans l'étape « Client »). **Vérifié dans le navigateur** sur `aviator.test` (agent de Sfax, données fictives supprimées ensuite) : client retrouvé par « 9988 7766 », véhicule à l'assurance expirée désactivé avec son motif, réservation `RES-2026-00001` créée, devis figé 340,000 HT, 405,600 net. 32 tests |
| L6. Leasing et crédit | 4,5 | 2,5 | 23,85 | 31 | `vehicle_financings`, `financing_installments` ; `SaveFinancing` (leasing B-11 : loyer HT et TTC saisis, aucun taux de TVA déduit ; crédit B-15 : mensualité d'un prêt amortissable calculée en `brick/math`, capital soldé au millime ; type conforme à la fiche ; un seul financement en cours ; régénération tant qu'aucune échéance n'est pointée) ; `MarkInstallmentPaid` (B-12) ; `SettleFinancing` (B-14 : option levée, restitution qui sort le véhicule du parc, crédit remboursé) ; **`FinancingCharges` (scénario 15)** : loyer HT ou intérêts seuls (Q59), au mois de l'échéance (Q58), une seule fois. Écran **Parc → Financements** (super admin et admin), échéancier et pointage. 12 tests. Les alertes de fin de leasing et de levée d'option (B-13) relèvent du lot L7 |
| L7. Alertes | 1,75 | 1 | 24,85 | 32,75 | `DueDateAlerts` (B-07 : fin d'assurance et visite technique, sur la dernière échéance connue ; B-13 : fin de leasing et levée d'option), planifiée à 06:05 et commande `fleet:alerts` ; réglages A-09 existants : une alerte ne part que si elle est active **et** que son délai est renseigné (vide par défaut, Q90) ; une seule fois par objet et par échéance (`sent_alerts`) ; agent alerté pour les véhicules localisés dans ses agences seulement, jamais pour un financement ; comptes inactifs exclus ; texte sans donnée client (RG-20). **Centre de notifications activé** dans le panneau (cloche, rafraîchie chaque minute) : les alertes de la limite de recherches (L8) étaient enregistrées mais invisibles. 10 tests. Délais et destinataires à fixer par le super admin ou à l'atelier (Q90 d) |
| L12. Planning | 3 | 2 | 26,85 | 35,75 | Page **Comptoir → Planning** sur les vraies données (`PlanningQuery` : véhicules non sortis groupés par agence de localisation, occupations actives de la fenêtre stricte, 8 requêtes quel que soit le volume) ; projection Q38 inchangée ; filtres agence et catégorie ; clic sur une case libre → assistant prérempli (véhicule, agence, catégorie, départ), véhicule hors de portée ignoré ; prototype et données fictives supprimés. **Conditions de D35** : (1) six tests « témoin » et « contournement », un par défaut du paquet, dans `tests/Feature/Planning/PlanningTest.php` ; tickets 2 et 3 **reproduits** (`docs/rapports/tickets_guava_calendar.md`) ; (2) **défaut trouvé à l'essai** : avec un navigateur réglé sur `America/New_York`, un bloc de 15:00 s'affichait à 09:00 (EventCalendar 5 convertit au fuseau du navigateur, contrairement à l'hypothèse du prototype) ; corrigé par l'option `timeZone` fixée au décalage de Tunis ; vérifié dans ce navigateur : 15:00 affiché, clic sur 00:00 → départ 00:00 ; (3) 48 véhicules et 288 réservations fictives (supprimées ensuite), serveur local avec OPcache : **données du mois en 97 à 151 ms** (SQL 11 ms, index `vehicle_id, active, starts_at, ends_at` utilisé) ; aller-retour Livewire complet : semaine 89 à 256 ms (médiane 116), mois 94 à 343 ms (médiane 205), sur le serveur intégré de PHP, mono-processus. Optimisation faite : dates lues brutes et URL calculée une fois (casts d'Eloquent dix fois plus coûteux). **Autre défaut corrigé** : pour un agent, les réservations d'une autre agence (non chargées, portée d'agence) étaient classées « immobilisation » ; type lu sur l'occupation, test ajouté. 17 tests. **Reste** : remesure en recette (non installée) ; au-delà de 300 ms, signalement avant L15 |
| L13. Sélecteur d'agence active | 0,25 | 0,25 | 27,1 | 36 | Page **Agence active** (`SwitchAgency`) et entrée du menu utilisateur « Agence active : … », visibles seulement pour un utilisateur d'agence qui a le choix (agence par défaut plus remplacement actif aujourd'hui, agences actives seulement) ; changement par `CurrentAgency::switchTo` (journal d'audit) ; agence falsifiée refusée par le formulaire puis par le domaine ; l'assistant de réservation reprend l'agence choisie. Super admin, admin et agent sans remplacement : entrée absente, page en 403. 7 tests. Non vérifié dans le navigateur : rendu du menu contrôlé par les tests de page |
| L14. Droits, audit et tests transverses | 1,25 | 1 | 28,1 | 37,25 | **Matrice des droits** (`tests/Feature/Rights/Phase4AccessMatrixTest.php`) : 14 écrans × 3 profils (parc, financements, réservations, planning, agence active), code HTTP attendu pour chacun. **Scénario 12** par type de document, à trois niveaux (policy, action de domaine, requête Livewire directe) : réservation de Sousse (fiche introuvable, agence de Sousse injectée dans l'assistant refusée), véhicule (modification refusée même dans sa propre agence), financement (tout refusé à l'agent). **D13** : recherche globale fermée sur les trois ressources ; l'assistant ne liste aucun client avant une recherche D24 et refuse un client injecté ; aucun filtre, colonne recherchable ni export sur les nouveaux écrans. **Architecture** : `tests/Arch/VehicleWritesTest.php` interdit toute écriture de masse des véhicules (elle contournerait la garde du statut et du kilométrage), motif contrôlé sur trois exemples. **Traductions** : 158 clés de Filament 5 traduites dans `lang/vendor/*/fr` (13 fichiers, fusionnés avec ceux du paquet) ; test de parité par le traducteur, qui échouait avant l'ajout (constaté) et échouera à toute montée de version qui ajoute des clés ; aucune surcharge sans objet. 16 tests. (Ligne omise par erreur au commit des lots L13 et L14, rétablie au lot L16) |
| L15. Préparation de l'atelier | 1 | 0,5 | 28,6 | 38,25 | **Atelier reporté** (recette non installée, paramétrage non saisi : décision du 25/11/2026). Préparé : `DemoFleetSeeder` (données fictives marquées DEMO, par les actions du domaine, sans Faker ; refusé en production ; tout ou rien en transaction, un paramétrage incomplet ne laisse rien ; second lancement sans effet ; jamais appelé par `DatabaseSeeder`), 4 tests. **Défaut corrigé pendant le lot** : sans grille tarifaire, la première version laissait une démonstration partielle ; le test le vérifie désormais. Dossier `docs/rapports/atelier_phase_4_preparation.md` : préalables, commande pour le responsable technique, déroulé de 2 h 30, fiche de recueil (Q3 à Q90, D39) avec les valeurs actuelles vérifiées dans les réglages |
| L16. Rapport et revue | 1 | 0,5 | 29,1 | 39,25 | `docs/rapports/phase_4.md` (statut proposé : validée avec réserves) ; revue de la branche sur cinq axes, aucun défaut bloquant ; `docs/projet.md` mis à jour. **Reste dû** : atelier et remesure du planning en recette (environ 1 j), quand les réserves héritées seront levées |

## 12. Ce dont j'ai besoin

1. ~~Validation de ce plan~~ : validé le 19/11/2026. Prochaine validation : D35, à la fin du lot L0.
2. Les deux réserves du §0, aux échéances validées : choix du domaine (Q6) par la direction et installation de la recette avant la fin de L9 ; saisie du paramétrage avant L15.
3. Les données réelles des leasings en cours (Q12), si possible avant l'atelier.

## Annexe — reports décidés pendant les phases précédentes

### Reports de la phase 2 (chiffrés)

Rééquilibrage validé le 17/11/2026 (`docs/plans/phase_2.md`, point budgétaire).

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| Contrôleur des fichiers privés (D21) | 0,5 | Route servant les fichiers du disque `private` après policy ; en-têtes ; tests d'accès par profil et par agence |
| Recherche de clients (D24) | 1 | Nom exact complet dans n'importe quel ordre, pièce, permis, téléphone ; `SearchNormalizer::phone` au format `+216XXXXXXXX` et recalcul de `customers.phone_search` ; 10 résultats ; journal sans la valeur ; 30 recherches par heure et notification au super admin ; tests |
| Migration `drivers` avancée de la phase 5 | 0,5 | Table, modèle, permis chiffré et empreinte `license_hash`, rechiffrement couvert (D23) ; tests |
| Sélecteur d'agence active | 0,25 | Choix de l'agence active dans le menu utilisateur (`CurrentAgency`) ; test |
| Test RG-15 entre agences | 0,5 | Première version de `customer_recommendations` (niveau, statut, ajout seul) et `CustomerRiskGate` ; « client bloqué à Sfax, refusé à Sousse » |
| **Total des reports** | **2,75** | |

Le scénario 12 rejoué sur les réservations et le prototype du planning (D5) font partie du périmètre déjà prévu de la phase 4 : pas de charge supplémentaire.

### Effets sur les phases suivantes

| Phase | Effet | Charge (j) | Nouveau total | Écart avec `docs/projet.md` §5 |
| --- | --- | ---: | ---: | --- |
| 5. Contrats | La migration `drivers` en sort (faite en phase 4) | −0,5 | 29,5 | −0,5 j (−1,7 %) |
| 7. CRM | La création de la table `customer_recommendations` en sort (faite en phase 4) ; l'écran, les propositions de liste noire et les approbations restent en phase 7 | −0,25 | 20,75 | −0,25 j (−1,2 %) |
