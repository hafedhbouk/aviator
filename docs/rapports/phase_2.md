# Rapport de fin de phase 2 — Architecture et socle

| Élément | Valeur |
| --- | --- |
| Phase | 2. Architecture et socle (lot 1) |
| Branche | `phase/02-architecture-socle` (créée depuis `main` après la fusion de `phase/01-analyse`) ; 31 commits, dernier commit `8cd8872` |
| Dates | 17 au 18/11/2026 |
| Statut proposé | **Validée avec réserves** — réserve n° 1 : installation en recette (§8) |
| Critère d'acceptation (cahier §7.1) | Socle déployé en recette ; résultat de `aviator:check-environment` joint au rapport, dans les 30 jours de garantie |
| Valideur | Responsable technique |
| Plan | `docs/plans/phase_2.md` (plan, avenants 1 et 2, points budgétaires) |

**En bref** : le socle est livré et testé (355 tests, CI au vert). L'installation en recette est **reportée**, faute de nom de domaine (Q6) : c'est la réserve n° 1. Charge : **20,25 j** pour 19 j de référence et un plafond de 20,25 j. Trois points restent à arbitrer avant la phase 6 (D27), et deux autres doivent être tranchés avant la validation (§5 et §9).

---

## 1. Exigences traitées

| Périmètre du plan (lot) | Exigences | Statut | Où |
| --- | --- | --- | --- |
| Installation et outillage (L1) | Laravel 13, Filament 5.11, PHP 8.3 (`config.platform.php = 8.3.16`, D6), MariaDB 10.11, Pest, Pint, Larastan niveau 6 ; `composer check`, contrôles identiques sur le poste et en CI | **Fait** | `composer.json`, `.github/workflows/` |
| Modèle de données (L2) | Modèle détaillé d'environ 60 tables ; migrations du socle seulement (D18) | **Fait** | `docs/modele_donnees.md`, `database/migrations/` |
| Authentification et utilisateurs (L3) | J-01, J-02, **J-03** (2FA obligatoire pour le super admin et l'admin, codes de récupération) ; `aviator:create-super-admin` (Q2, aucun compte dans les seeders) ; mots de passe (D19) ; `aviator:reset-2fa` ; désactivation qui ferme les sessions | **Fait** | `app/Filament/Resources/`, `app/Console/Commands/` |
| Agences et portée (L4) | **RG-13** : `AgencyScope`, `CurrentAgency`, remplacements datés entre agences (Q69, `agency_assignments`) | **Fait** | `app/Support/Agency/` |
| Données clients (L5) | **RG-14 / D13** : carnet d'adresses et exports de clients réservés au super admin, assouplissables par permission (D17), journal `customer_access` ; empreintes de recherche (D8) | **Fait** (structure ; écrans en phase 7, recherche D24 en phase 4) | `app/Support/Privacy/` |
| Audit (L6) | **J-04, J-05** : journaux `audit`, `security`, `customer_access` ; attributs sensibles signalés, jamais copiés | **Fait** | `app/Support/Audit/` |
| Mutualisé et sécurité (L7) | Pas d'`exec` ni de `proc_open` (D14) ; planificateur dans le processus (D15) ; cron toutes les 5 minutes toléré (D20) ; pas de `storage:link` (D21) ; disque privé ; en-têtes de sécurité ; HTTPS | **Fait** | `app/Support/Scheduling/`, `bootstrap/app.php`, tests `Arch` |
| Briques transverses (L8) | `Money` (3 décimales, arrondi explicite), `SequenceAllocator` avec test de concurrence sur deux connexions (**RG-02** préparée, **RG-07** : brique), documents numérotés jamais supprimés (**RG-06** : brique) | **Fait** | `app/Support/Money/`, `app/Support/Sequences/`, `app/Support/Documents/` |
| Contrôle de l'environnement (L9) | `aviator:check-environment` : PHP, extensions, fonctions désactivées (attendues chez Oxahost), base, connexions, cadence du cron, clés (D23, valeurs jamais affichées), HTTPS, débogage, droits d'écriture, e-mail | **Fait** | `app/Support/Environment/` |
| Intégration continue et installation (L10) | CI GitHub (PHP 8.3, service MariaDB 10.11) ; **déploiement par archive (D25)** : `build-release.sh`, `install-release.sh`, `rollback-release.sh` ; guides d'installation | **Fait** ; installation sur le serveur **non faite** (réserve n° 1) | `scripts/`, `docs/installation_recette.md`, `docs/installation_hebergement_oxahost.md` |
| Gestion des clés | D23 : une clé par environnement, copie en Tunisie, `aviator:reencrypt-secrets`, `aviator:rehash-blind-index` | **Fait** | `docs/gestion_des_cles.md` |
| Sauvegarde (J-08, P2) | Sauvegarde de la base avant chaque installation (`install-release.sh`), JetBackup ; restauration documentée | **Partiel** : procédure documentée, sans bouton dans l'application (D14) | `docs/installation_hebergement_oxahost.md` §4 |
| Rapport (L11) | Ce document | **Fait** | `docs/rapports/phase_2.md` |

Hors lots, à la demande du responsable technique : **analyse des exigences du cabinet comptable** (spécifications, puis référentiel fiscal v1.0 validé), décisions D26 et D27, synthèse pour la direction. Elle prépare les phases 3, 6, 8, 9 et 10 (§5).

## 2. Fichiers créés ou modifiés

235 fichiers modifiés depuis `main` (31 557 lignes ajoutées).

| Domaine | Principaux fichiers |
| --- | --- |
| Application | `app/Support/` (Agency, Audit, Auth, Authorization, Documents, Environment, Money, Privacy, Scheduling, Sequences), `app/Domain/Administration/`, `app/Policies/`, `app/Http/Middleware/`, `app/Filament/Resources/` (utilisateurs, journal d'audit) |
| Commandes Artisan | `aviator:create-super-admin`, `aviator:sync-permissions`, `aviator:permission`, `aviator:reset-2fa`, `aviator:check-environment`, `aviator:reencrypt-secrets`, `aviator:rehash-blind-index` |
| Base | 15 migrations du socle, seeders (4 agences, aucun compte), réglages `spatie/laravel-settings` (sécurité, notifications) |
| Configuration | `config/` (14 fichiers : connexion `mariadb` et `mariadb_autocommit`, disques, sessions, file, journaux) |
| Scripts | `scripts/build-release.sh`, `scripts/install-release.sh`, `scripts/rollback-release.sh`, `scripts/lib/release-files.sh` ; `scripts/deploy.sh` supprimé (D25) |
| Tests | `tests/Arch`, `tests/Feature/*`, `tests/Unit`, fixtures (§3) |
| Documentation | `docs/modele_donnees.md`, `docs/decisions.md` (D14 à D27), `docs/gestion_des_cles.md`, `docs/installation_recette.md`, `docs/installation_hebergement_oxahost.md`, `docs/installation_poste_dev.md`, `docs/analyse_ecarts_cabinet.md`, `docs/demande_evolution_cabinet.md`, `docs/plans/phase_2.md` à `phase_10.md`, `docs/projet.md`, `docs/questions_ouvertes.md`, `CLAUDE.md` |
| Références | `docs/reference/cabinet/` (spécifications et référentiel), `docs/reference/regles_fiscales_validees/` (référentiel v1.0 validé, attestation de la direction, réponse Q30, README avec empreintes), cahier des charges aligné (§2.2, §6.5, §7) |

## 3. Tests

| Commande | Résultat |
| --- | --- |
| `composer check` (poste, avant chaque commit) | Pint : passé ; Larastan : 0 erreur ; Pest : **355 tests, 353 réussis, 2 ignorés** ; `composer audit` : aucune vulnérabilité ; `npm run build` : OK |
| CI GitHub (`composer check`, Ubuntu, MariaDB 10.11) | **355 tests réussis, 0 ignoré** (1 016 assertions) ; dernier run vert sur `d050794`, puis sur `8cd8872` |

**Tests ignorés sur le poste** (exécutés et réussis en CI) :

1. `shellcheck` des scripts de déploiement : outil absent du poste Windows.
2. Essai complet d'installation, de conservation de 3 versions et de retour arrière : il exige des liens symboliques Linux. Il a aussi été rejoué à la main dans WSL avec un faux `php`.

| Domaine | Cas (avant jeux de données) | Exemples |
| --- | ---: | --- |
| Environnement | 33 | Chaque contrôle en OK, AVERTISSEMENT et ERREUR ; fonctions désactivées attendues ; clés jamais affichées |
| Authentification | 29 | 2FA imposée par rôle, codes de récupération, comptes désactivés, création du super admin |
| Administration | 27 | Gestion des utilisateurs par le super admin seulement, journal d'audit |
| Portée par agence | 19 | Remplacements avant, pendant, après ; aucune fuite entre agences |
| Données clients (D13) | 15 | Refus et journalisation, assouplissement par permission |
| Briques | 15 + 30 unitaires | `Money`, séquences, empreintes harmonisées |
| Infrastructure, audit, base, modèles | 50 | Planificateur sans sous-processus, verrou de la file, MariaDB 10.11 exigée, interclassement |
| Architecture | 14 | Pas d'`exec`, pas de `float`, pas de `DB::table` hors `App\Domain\Statistics`, pas de `storage:link`, `app/Domain` sans Filament |
| Concurrence | 3 | Deux processus réels sur deux connexions MariaDB : aucun doublon, aucun trou (l'interblocage 1213 trouvé ici a été corrigé) |
| Déploiement | 8 | Contenu de l'archive, `bash -n`, shellcheck, aucun Composer, npm ou Git côté serveur, essai Linux complet |
| Documents numérotés | 8 | Jamais supprimés, ni par le modèle, ni en masse, ni par l'interface |

## 4. Écarts au cahier des charges et décisions

Décisions D14 à D27 dans `docs/decisions.md` :

| Décision | Objet |
| --- | --- |
| D14, D15 | Ni `exec` ni sous-processus ; planificateur dans le processus (confirmées par Oxahost le 18/11) |
| D16, D17 | Permissions déclarées dans le code ; assouplissement de D13 par commande journalisée |
| D18, D19 | Migrations du socle seulement ; règles de mot de passe locales |
| D20, D21 | Cron toutes les 5 minutes en secours ; pas de `storage:link` |
| D22 → D25 | Déploiement par archive, terminal web de cPanel (D22 remplacée) |
| D23, D24 | Gestion des clés ; recherche de clients (phase 4) |
| D26, D27 | Dinar uniquement ; numérotation des factures et des avoirs |

| Écart | Traitement |
| --- | --- |
| Cahier §6.5 : « déploiement automatisé depuis Git par SSH » | Impossible chez Oxahost ; cahier mis à jour par la direction (D25) |
| Cahier §2.2 : points Oxahost « à confirmer » | Confirmés par Oxahost ; cahier mis à jour |
| **A-07 et RG-07 : numérotation « par année »** | **À trancher** : le référentiel fiscal validé (§4.1) impose un compteur **sans remise à zéro annuelle** (D27). Le cahier n'est pas modifié ; je propose d'aligner A-07 et RG-07 sur le référentiel |
| Critère d'acceptation : « socle déployé en recette » | Non atteint : installation reportée (réserve n° 1, §8) |
| J-08 « déclenchable par l'administrateur » | Sauvegarde déclenchée par le script d'installation et JetBackup, sans bouton dans l'application (D14 : `exec` désactivé) |

## 5. Charge consommée

| Élément | Charge (j) |
| --- | ---: |
| Référence de la phase | 19 |
| Plafond accepté (avenant 2, D25) | 20,25 |
| Réalisé, lots 1 à 10 (dont D25) | 19,75 |
| L11, rapport | 0,5 |
| **Total de la phase 2** | **20,25** (+6,6 % sur la référence ; plafond atteint, sans marge) |
| Levée de la réserve n° 1 : analyse du résultat de `check-environment`, mise à jour du rapport | 0,25 (prévu) : **léger dépassement du plafond**, signalé |
| Hors lots : analyse des exigences du cabinet, D26, D27, synthèse pour la direction | **environ 1,25** : **imputation à décider** |

**Décision attendue** sur ces 1,25 j :

- **(a) Les imputer à la demande d'évolution du cabinet** (proposé). L'écart cumulé du lot 1 passe de 265,25 j à 266,5 j (+8,8 %).
- **(b) Les compter dans la phase 2.** Elle passerait à 21,5 j (+13,2 %, au-delà du plafond de 20,25 j mais sous le seuil d'alerte de 20 %).

Écart cumulé du lot 1 au 18/11/2026 (`docs/projet.md` §5) : **265,25 j projetés pour 245 j (+20,25 j, +8,3 %)**, avant les deux points ci-dessus.

## 6. Risques nouveaux

| Risque | Effet | Parade |
| --- | --- | --- |
| Installation en recette repoussée (Q6) | Les surprises de l'hébergement apparaissent tard ; le critère de la phase 3 (« paramétrage complet saisi en recette ») en dépend | Réserve n° 1 avec date limite (§8) ; scripts et contrôle testés en CI et dans WSL |
| Phase 3 à **+18,75 %** (19 j pour 16 j) | Aucune marge avant le seuil d'alerte (19,2 j) | Plan de la phase 3 serré, sans aléa supplémentaire ; dépassement signalé immédiatement |
| Phase 6 à **+21,8 %** avec D27 | Au-delà du seuil d'alerte | Décision attendue : accepter, ou déplacer l'écran de numérotation en phase 9 (`docs/plans/phase_6.md`) |
| Filament et OpenSpout passeront à PHP 8.4 | Blocage des mises à jour tant qu'Oxahost reste en PHP 8.3 | Versions épinglées compatibles PHP 8.3 (D6) |
| Avenant TTN | Doit être prêt dès la finalisation de l'adhésion (situation B, Q84) | Délai de TTN à obtenir (Q16) pour planifier l'avenant |
| Durée de la suite de tests sur le poste | De 1 à 6 minutes selon la charge de la machine | Sans effet sur la CI (environ 1 min 30) |

## 7. Questions pour la direction et l'expert-comptable

| N° | Question | Pour |
| --- | --- | --- |
| Q6 | **Nom de domaine** (aviator.tn probablement) : condition de l'installation en recette | Direction |
| Q27 | Confirmation de D13 (carnet d'adresses et exports réservés au super admin) | Direction |
| Q76 | 2FA obligatoire aussi pour les utilisateurs d'agence ? (recommandé) | Direction |
| Q77 | Durées de conservation des journaux | Direction, conseil juridique |
| D27 | (1) Le format avec l'année, qui s'écarte du référentiel §4.1, exige la confirmation du cabinet (§4.2) ; (2) le courriel du 18/11 n'a pas de signataire nommé | Direction, cabinet |
| Q16 | TTN : date de dépôt et copie de la preuve, délai annoncé, type de certificat | Direction |
| Q30 (reste) | Numérotation des factures complémentaires | Cabinet |
| **Q85** (nouvelle) | Code fiscal de l'option « livraison » (A-05) et des kilomètres supplémentaires (E-05), absents des 15 codes du référentiel | Cabinet |
| **Q86** (nouvelle) | Mode d'arrondi de la TVA à 3 décimales : demi supérieur ou autre ? Le référentiel (§5) ne le précise pas | Cabinet |
| Q1, Q79 à Q83 | Logiciel du cabinet, annulation sans avoir, remise des exports, imputation, forme du journal des ventes, réouverture d'une période close | Cabinet, direction |
| — | Pièces PENDING_DOCUMENTATION (référentiel §14) : contrats d'assurance, clauses de retard, franchises, indemnités, matricule fiscal commun, exonérations de timbre | Direction |

## 8. Installation en recette : reportée

**Raison** : la direction choisit le nom de domaine (Q6, aviator.tn probablement). Le sous-domaine de recette, son certificat et `APP_URL` en dépendent.

**Prêt** :

- archive construite et contrôlée sur le poste (`scripts/build-release.sh`) ;
- scripts d'installation et de retour arrière, testés en CI et dans WSL ;
- procédure pas à pas : `docs/installation_recette.md` ;
- mise à jour : `docs/installation_hebergement_oxahost.md`, annexe A.

**Reste à faire**, par le responsable technique (Claude Code n'agit sur aucun serveur) :

1. Choix du domaine par la direction (Q6).
2. `docs/installation_recette.md`, étapes 0 à 13 :
   - construction de l'archive ;
   - réglages cPanel (2FA, PHP 8.3, base, e-mail, FTP) ;
   - premiers essais dans le terminal web ;
   - envoi de l'archive ;
   - premier lancement, puis `shared/.env` et ses clés ;
   - `~/.my.recette.cnf` ;
   - deuxième lancement ;
   - sous-domaine et certificat ;
   - cron ;
   - protection par mot de passe ;
   - premier super admin et sa 2FA ;
   - contrôle de l'environnement ;
   - vérifications dans le navigateur.
3. Envoi à Claude Code des résultats de l'étape 2 (`php -v`, `proc_open`, `composer -V`, `mysqldump`) et de `~/check-environment-recette.txt`.

**Critère de levée de la réserve n° 1** : installation faite **et** résultat d'`aviator:check-environment` joint à ce rapport, sans ligne ERREUR non expliquée, **au plus tard avant la fin de la phase 3**. Son critère d'acceptation (« paramétrage complet saisi en recette ») suppose d'ailleurs une recette en service.

Commandes à exécuter, une fois le domaine choisi (dans le terminal web de cPanel, après l'envoi de l'archive) :

```bash
cd ~/deploiement
tar -xzf aviator-<version>.tar.gz ./scripts/install-release.sh ./scripts/rollback-release.sh
bash scripts/install-release.sh recette ~/deploiement/aviator-<version>.tar.gz
```

Puis la suite de `docs/installation_recette.md`.

## 9. À valider avant la phase 3

1. **Ce rapport**, avec le statut proposé « Validée avec réserves » (réserve n° 1 : installation en recette, à lever avant la fin de la phase 3).
2. **Imputation des 1,25 j hors lots** (§5) : demande d'évolution du cabinet (proposé) ou phase 2.
3. **A-07 et RG-07** : aligner le cahier sur le compteur continu du référentiel validé (§4).
4. **Plan de la phase 3** : `docs/plans/phase_3.md`.
5. **Arbitrages D27** (avant la phase 6) : format avec l'année et confirmation du cabinet ; signataire du courriel ; dépassement de la phase 6.
6. Après validation : fusion de `phase/02-architecture-socle` dans `main`, puis création de la branche `phase/03-parametrage`.
