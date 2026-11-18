# Fiche projet — Aviator (Laravel)

> Lue par Claude Code au début de chaque session. Mettez-la à jour à chaque validation de phase.
> Les valeurs `[À COMPLÉTER]` sont demandées au moment de la phase qui en a besoin.

Dernière mise à jour : 18/11/2026

## 1. Projet

| Élément | Valeur |
| --- | --- |
| Client | Aviator, location de voitures |
| Agences | Djerba (siège), Sfax, Sousse, Tunis |
| Utilisateurs | 1 super admin, 1 admin (siège), 4 utilisateurs d'agence |
| Clients existants | ~1 000, à reprendre dans le CRM |
| Lots | Lot 1 : application de gestion (phases 1 à 10) ; lot 2 : espace client (phase 11) |
| Hors périmètre | Comptabilité générale, paiement en ligne, SMS, application native |
| Cahier des charges | `docs/reference/cahier_des_charges_aviator.md` |
| Chiffrage | `docs/reference/chiffrage_aviator.xlsx` |

## 2. Technique

| Élément | Valeur |
| --- | --- |
| Framework | Laravel 13, Filament v5 |
| PHP | 8.3 (maximum disponible chez Oxahost) |
| Base de données | MariaDB 10.11.19 en production (confirmé par Oxahost le 17/11/2026) ; MariaDB 10.11 en local |
| Poste de développement | Windows 11 + Laragon (PHP 8.3.16, MariaDB 10.11) ; dépôt dans `D:\aviator`, jonction `C:\laragon\www\aviator` → `D:\aviator` pour Laragon ; `https://aviator.test` — `docs/installation_poste_dev.md` |
| Dépôt Git | [À COMPLÉTER] |
| Hébergement | Oxahost Ultimate (mutualisé cPanel), Tunis — `docs/installation_hebergement_oxahost.md` |
| Domaine | [À COMPLÉTER — question Q6] |
| Recette | [À COMPLÉTER — par exemple `recette.<domaine>`], protégée par mot de passe |
| Production | [À COMPLÉTER — par exemple `gestion.<domaine>`] ; espace client au lot 2 |
| Accès de Claude Code | Poste local uniquement ; **aucun** accès à la recette ni à la production |

## 3. Personnes

| Rôle | Nom | Valide |
| --- | --- | --- |
| Responsable technique et développeur | [À COMPLÉTER] | Toutes les phases ; déploiements |
| Super admin (direction d'Aviator) | [À COMPLÉTER — Q2] | Phases 1, 7, 9, 10, 11 ; approbations |
| Admin du siège (Djerba) | [À COMPLÉTER] | Phases 3, 4, 6 |
| Expert-comptable | [À COMPLÉTER] | Taxes des factures (phase 6), format des exports (phase 8) |
| Conseil juridique | [À COMPLÉTER] | Contrat, recommandations, conditions en ligne |

## 4. Budget de référence (scénario médian, HT)

| Élément | Valeur |
| --- | --- |
| Lot 1 | 245 j de réalisation ; 123 329 DT avec aléas, hébergement et validations externes |
| Lot 2 | 45 j de réalisation ; 22 390 DT |
| Exploitation annuelle | 13 019 DT |
| Seuil d'alerte | Phase dépassant sa charge de plus de 20 % |

## 5. Suivi des phases

| Phase | Lot | Charge prévue (j) | Charge réelle (j) | Statut | Validée par | Date | Réserves |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1. Analyse et maquettes | 1 | 12 | | Validée avec réserves | [À COMPLÉTER] | 17/11/2026 | 1) Ateliers avec les 4 agences, à tenir pendant la phase 2 et avant la phase 4 ; 2) Q27 (carnet d'adresses) appliquée par défaut, à confirmer par la direction ; 3) Q70 : bascule du poste et des tests en MariaDB 10.11, à vérifier en phase 2. Rapport : `docs/rapports/phase_1.md` |
| 2. Architecture et socle | 1 | 19 | | En cours | | | Projeté **20,25 j** : 18,5 j après rééquilibrage, plus 1,25 j pour D25 (déploiement par archive, contrainte de l'hébergeur), rapport compris ; **plafond relevé de 19,5 à 20,25 j** le 18/11/2026 (`docs/plans/phase_2.md`, avenant 2) |
| 3. Paramétrage et référentiels | 1 | 16 | | | | | Projeté **19 j** (+18,75 %, proche du seuil) : reports de la phase 2 (0,25 j) et demande d'évolution du cabinet, part indispensable (2,75 j) (`docs/plans/phase_3.md`) |
| 4. Parc, leasing et réservations | 1 | 39 | | | | | Projeté 41,75 j : reports de la phase 2 (`docs/plans/phase_4.md`) |
| 5. Contrats et états des lieux | 1 | 30 | | | | | Projeté 30 j : `drivers` avancé en phase 4 (−0,5 j) ; dossier de dommage comme justificatif fiscal (+0,5 j, référentiel du cabinet) (`docs/plans/phase_5.md`) |
| 6. Facturation, encaissements, cautions | 1 | 31 | | | | | Projeté **37,75 j (+21,8 %, au-delà du seuil d'alerte)** : demande d'évolution du cabinet, part indispensable (5 j) et numérotation D27 (1,75 j) (`docs/plans/phase_6.md`) ; **décision attendue** : accepter le dépassement ou déplacer l'écran de numérotation (1 j) en phase 9 (36,75 j, +18,5 %) |
| 7. CRM et recommandations | 1 | 21 | | | | | Projeté 20,75 j : table des recommandations créée en phase 4 |
| 8. Maintenance, dépenses, exports | 1 | 20 | | | | | Projeté **22,75 j** (+13,75 %) : demande d'évolution du cabinet, part indispensable (2,75 j) (`docs/plans/phase_8.md`) |
| 9. Statistiques, mobile et sécurité | 1 | 38 | | | | | Projeté **42 j** (+10,5 %) : purge des journaux (Q77, 0,5 j) et clôture mensuelle, exigée par le référentiel du cabinet (3,5 j) (`docs/plans/phase_9.md`) |
| 10. Recette et mise en production du lot 1 | 1 | 19 | | | | | |
| 11. Espace client | 2 | 45 | | | | | |
| **Total** | | **290** | | | | | |
| **Écart cumulé du lot 1** (au 18/11/2026) | 1 | **245** prévus | **265,25** projetés | **+20,25 j (+8,3 %)** | | | Phases 3 à 10 : référence corrigée des reports chiffrés ; phase 1 comptée à sa référence (12 j, charge réelle à renseigner) ; phase 2 projetée à 20,25 j (D25) ; demande d'évolution du cabinet, part indispensable selon le référentiel v1.0, clôture mensuelle comprise : +14,5 j ; numérotation D27 : +1,75 j. Sans multidevise (D26). Facturation électronique TTN : avenant séparé, hors lot 1. Mise à jour à chaque fin de phase |

Statuts : À démarrer · En cours · En attente de validation · Validée · Validée avec réserves.

## 6. Décisions

| Date | Décision | Prise par |
| --- | --- | --- |
| 17/11/2026 | Laravel plutôt qu'ERPNext (expertise du développeur, hébergement mutualisé, interface mobile, espace client) | Responsable technique |
| 17/11/2026 | Comptabilité tenue par l'expert-comptable à partir des exports | [À confirmer par la direction] |
| 17/11/2026 | Hébergement Oxahost Ultimate (mutualisé), recette en sous-domaine | Responsable technique |
| 17/11/2026 | Espace client en lot 2 ; pas de paiement en ligne ni de SMS ; notifications push uniquement | Direction |
| 17/11/2026 | Base MariaDB 10.11 en local, en test et en production ; connexion Laravel `mariadb` (production Oxahost en MariaDB 10.11.19, confirmé par Oxahost) | Responsable technique |
| 17/11/2026 | Validation de la phase 1 avec réserves ; décisions techniques D1 à D13 (`docs/decisions.md`) | [À COMPLÉTER] |
| 17/11/2026 | D6 : `config.platform.php = 8.3.16` dans `composer.json` ; versions majeures compatibles PHP 8.3 (Pest 4, laravel-activitylog 4, laravel-backup `~10.3.0`) | Responsable technique |
| 17/11/2026 | D5 : planning sur `guava/calendar`, prototype obligatoire au début de la phase 4 ; repli : frise Livewire maison | Responsable technique |
| 17/11/2026 | D13 (RG-14, Q27) : le carnet d'adresses = coordonnées des clients en liste et tout export de fichier clients, réservés au super admin ; l'admin voit les noms dans les listes métier ; exports comptables limités au nom et au matricule fiscal ; envois groupés aux clients : super admin | Responsable technique ; **à confirmer par la direction** |
| 17/11/2026 | Dépôt dans `D:\aviator`, jonction `C:\laragon\www\aviator` pour Laragon | Responsable technique |
| 17/11/2026 | Q11, Q30 et Q16 envoyées à l'expert-comptable ; la phase 3 reste bloquée sur les taxes, mentions et numérotation | Responsable technique |
| 17/11/2026 | Ateliers avec les agences pendant la phase 2, avant la phase 4 ; maquettes publiées sur une page privée pour ces ateliers | Responsable technique |
| 18/11/2026 | D25 : déploiement par archive construite sur le poste (`build-release.sh`), envoyée par FTPS ou par le gestionnaire de fichiers et installée dans le terminal web de cPanel (`install-release.sh`, `rollback-release.sh`) ; racine web `~/apps/<env>/current/public` ; 2FA sur le compte cPanel ; remplace `scripts/deploy.sh` et D22. Cahier des charges §6.5 et §2.2 mis à jour en conséquence le 18/11/2026 | Responsable technique |
| 18/11/2026 | Plafond de la phase 2 relevé de 19,5 à 20,25 j pour D25 (contrainte de l'hébergeur), **confirmé** ; écart cumulé du lot 1 projeté à +4 j (+1,6 %). Lot 10 validé | Responsable technique |
| 18/11/2026 | Spécifications du cabinet comptable reçues (`docs/reference/cabinet/`), **sans valeur de validation** : chaque règle fiscale porte un statut CONFIRMED, PROPOSED ou PENDING_ACCOUNTANT_VALIDATION (§8) ; seules les règles CONFIRMED servent en production (garde-fou dans `aviator:check-environment` en phase 3 et dans la liste de mise en production en phase 10). Phase 3 non bloquée sur le principe ; elle démarre après la validation du rapport de la phase 2 et de l'analyse d'écarts (`docs/analyse_ecarts_cabinet.md`) | Responsable technique |
| 18/11/2026 | **Demande d'évolution du cabinet** (`docs/analyse_ecarts_cabinet.md`, validée avec décisions provisoires) : part indispensable (8,75 j) intégrée au lot 1, phases 3 (+2,25 j), 6 (+3,75 j) et 8 (+2,75 j) ; écart cumulé du lot 1 : +12,75 j (+5,2 %). En attente de la direction : clôture mensuelle déplacée en phase 9 (+3,5 j) ; multidevise minimale, option chiffrée à part (+1,75 j) selon Q78 ; exports quotidiens automatiques reportés (export manuel par période dans le lot 1). Facturation électronique TEIF / TTN : avenant séparé, hors lot 1 ; interface `ElectronicInvoicingProvider` (0,75 j) dans le lot 1. Synthèse pour la direction : `docs/demande_evolution_cabinet.md` | Responsable technique ; **à confirmer par la direction** |
| 18/11/2026 | **Référentiel fiscal v1.0 du cabinet** : remplace les spécifications du 18/11 pour la conception ; statuts CONFIRMED / CONDITIONAL / PENDING_DOCUMENTATION (§8) ; 15 codes fiscaux, qualification selon la nature réelle avec justificatif pour l'exonéré et le hors champ, table `stamp_duty_rules`, numérotation `AVI-000001` sans remise à zéro, clôture mensuelle devenue exigence du cabinet. Part indispensable portée à 14,5 j ; écart cumulé du lot 1 : +18,5 j (+7,6 %). Confirmation PDF du 18/11 **non retenue** (modèle non rempli, sans nom ni signature) : aucune règle applicable en production en attendant la confirmation nominative | Responsable technique ; **à confirmer par la direction** |
| 18/11/2026 | **Référentiel fiscal v1.0 validé** : confirmation du cabinet conservée par Aviator, attestée par Faouzi Bannour, directeur général, le 18/11/2026 (`docs/reference/regles_fiscales_validees/`). Référentiel copié sans modification dans `regles_fiscales_validees/`, avec un README (source, fichiers, SHA-256). Règles du référentiel notées CONFIRMED : applicables ; CONDITIONAL et PENDING_DOCUMENTATION inchangées. Règle 3 de `CLAUDE.md` : validation par confirmation écrite du cabinet ou attestation écrite de la direction. Rapport de configuration fiscale à signer par la direction avant la mise en production (phase 10) | Direction ; responsable technique |
| 18/11/2026 | **D27 : numérotation des factures et des avoirs** (Q30) : séries distinctes `AVI-{YEAR}-{SEQUENCE}` et `AV-{YEAR}-{SEQUENCE}`, compteurs continus et indépendants, remise à zéro verrouillée, format modifiable par le super admin. Phase 6 : +1,75 j, soit +21,8 % (au-delà du seuil) ; lot 1 : 265,25 j (+8,3 %). **À arbitrer** : (1) le format avec l'année diffère du format recommandé par le référentiel validé (`AVI-000001`, §4.1), et le référentiel (§4.2) comme le courriel exigent la confirmation du cabinet pour les modalités de séquencement avant la mise en production ; (2) le courriel est signé « La Direction », sans nom ni fonction | Direction ; responsable technique |
| 18/11/2026 | **D26 : dinar tunisien uniquement** (Q78) : multidevise retirée ; colonne « Devise » des exports toujours à TND ; toute autre devise refusée (test en phase 6) ; contrôle « opérations en devises » de la clôture sans objet | Direction |
| 18/11/2026 | **TTN, situation B** (Q84) : demande d'adhésion déposée ; factures papier conformes autorisées pendant la finalisation, sous réserve de conserver la preuve du dépôt. Interface `ElectronicInvoicingProvider` dans le lot 1 ; connecteur TTN en avenant séparé, **pas préalable** à la mise en production du lot 1, à livrer dès la finalisation de l'adhésion. Écart cumulé du lot 1 sans devises : 263,5 j (+18,5 j, +7,6 %) | Direction |

## 7. Points à confirmer avec Oxahost avant commande

| Point | Statut |
| --- | --- |
| Version de PHP | Confirmé (17/11/2026) : PHP 8.3 avec PHP Selector (CloudLinux) ; certaines extensions peuvent être restreintes |
| Calendrier de disponibilité de PHP 8.4 | « Prochainement », sans date : le projet reste en PHP 8.3 |
| Ligne de commande | Confirmé (18/11/2026) : **uniquement le terminal web de cPanel**, sans SSH depuis le poste ni clé ; `mysqldump` et `tar` disponibles. Déploiement par archive (D25), `scripts/deploy.sh` et D22 supprimés (Q8) |
| Cron toutes les minutes | Confirmé (18/11/2026) : autorisé ; D20 (toutes les 5 minutes) reste en mode de secours |
| Fonctions `exec`, `proc_open`, `shell_exec`, `system`, `passthru` | Confirmé (18/11/2026) : **désactivées** ; D14 et D15 confirmées ; aucun `composer install` sur le serveur (Q71) |
| Fonction `symlink` | Confirmé (18/11/2026) : activée ; D21 maintenue par choix de sécurité (pas de `storage:link`) |
| Extensions PHP | Confirmé (18/11/2026) : toutes disponibles ; contrôlées par `aviator:check-environment` |
| Lieu de stockage des sauvegardes (Tunisie) | Confirmé (17/11/2026) : JetBackup stocké en Tunisie |
| Sous-domaines avec racine web hors de `public_html`, SSL gratuit | Confirmé (17/11/2026) |
| Prix annuel exact (358,80 ou 418,68 DT HT) | À confirmer |
| Version exacte de la base de données | Confirmé : MariaDB 10.11.19 |

Seul le prix annuel reste à confirmer. Les réponses d'Oxahost sont vérifiées sur le serveur par le responsable technique pendant la garantie de 30 jours, avec les premiers essais du terminal web et `aviator:check-environment` (`docs/installation_recette.md`, étapes 2 et 12).

## 8. Règles fiscales

**Référentiel en vigueur pour la conception** : référentiel fiscal et comptable v1.0 du cabinet (`docs/reference/cabinet/referentiel_fiscal_aviator_v1.0_2026-11.md`, SHA-256 `43b2de817cb6590f2e90875afbff9c29c795f50599073fe3ecd7cbb98158e887`). Il remplace les spécifications du 18/11 (`docs/reference/cabinet/specifications_facturation_2026-11-18.md`). Analyse : `docs/analyse_ecarts_cabinet.md`, version 2.

Statuts (référentiel, §14) : **CONFIRMED** (règle validée et documentée) ; **CONDITIONAL** (applicable selon la nature réelle de l'opération, avec ses justificatifs) ; **PENDING_DOCUMENTATION** (pièce ou confirmation nécessaire avant activation).

**Référentiel v1.0 validé** : confirmation du cabinet conservée par Aviator, attestée par Faouzi Bannour, directeur général, le 18/11/2026 (`docs/reference/regles_fiscales_validees/`). Le référentiel y est copié sans modification (SHA-256 `43b2de81…e887`), avec l'attestation `attestation_direction_2026-11-18.pdf`. Validation donnée « sous réserve de la conformité des opérations effectivement réalisées, des contrats applicables et des justificatifs » : les règles CONDITIONAL et PENDING_DOCUMENTATION gardent leur statut. La lettre sans noms reçue plus tôt a été supprimée par le responsable technique ; elle n'a jamais été versée.

| Règle | Valeur ou traitement | Référentiel | Statut |
| --- | --- | --- | --- |
| TVA : `RENTAL`, `CHILD_SEAT`, `GPS`, `EXTRA_DRIVER`, `EXTRA_RENTAL_PERIOD`, `FUEL_RECHARGE` | 19 % | §2.1, §2.3, §2.4 A | CONFIRMED |
| Assurance : `INSURANCE_PREMIUM` (exonérée, prime véritable), `ADDITIONAL_PROTECTION_SERVICE` et `EXCESS_REDUCTION_OPTION` (19 % en principe) | Selon la nature réelle ; jamais d'exonération déduite du libellé | §2.2 | CONDITIONAL |
| Retard : `LATE_RETURN_PENALTY` | Hors champ si la nature indemnitaire est démontrée | §2.4 B | CONDITIONAL |
| Dégâts : `VEHICLE_DAMAGE_COMPENSATION` (hors champ si indemnitaire), `REPAIR_SERVICE` (normalement 19 %), `DAMAGE_ADMIN_FEE` (19 % en principe) | Selon la nature réelle, justificatifs conservés | §2.5 | CONDITIONAL |
| Franchise : `INSURANCE_EXCESS_COMPENSATION` (hors champ si indemnitaire), `EXCESS_REDUCTION_OPTION`, `CLAIM_ADMIN_FEE` (19 % en principe) | Selon la nature réelle, justificatifs du sinistre | §2.6 | CONDITIONAL |
| Timbre fiscal | 1,000 DT par facture ordinaire concernée, ligne distincte, ajouté au TTC (art. 117 du Code des droits d'enregistrement et de timbre) | §3.1, §3.2 | CONFIRMED |
| Numérotation des factures | Série centralisée et **continue** pour les 4 agences, **sans remise à zéro annuelle** ; année et agence dans des champs distincts | §4 | CONFIRMED |
| Format des numéros (D27) | Factures `AVI-{YEAR}-{SEQUENCE}`, avoirs `AV-{YEAR}-{SEQUENCE}` (6 chiffres, année d'émission à l'heure de Tunis) ; modifiables par le super admin pour les prochains numéros | courriel de la direction du 18/11/2026 (`docs/reference/regles_fiscales_validees/reponse_q30_numerotation.pdf`) | **À arbitrer** : (1) le format avec l'année diffère du format recommandé par le référentiel validé (`AVI-000001`, §4.1), et le référentiel (§4.2) comme le courriel exigent la confirmation du cabinet pour les modalités de séquencement avant la mise en production ; (2) le courriel est signé « La Direction », sans nom ni fonction |
| Méthode d'arrondi | 3 décimales ; TVA sur la base agrégée par taux, arrondie à 3 décimales ; timbre après le TTC ; exemple : 115,000 HT, 21,850 TVA, 136,850 TTC, 137,850 net à payer | §5 | CONFIRMED |
| Annulation et avoirs | Aucune suppression ; avoir avec son propre numéro, lié obligatoirement à la facture initiale ; avoirs partiels et totaux ; total des avoirs ≤ montant de la facture ; facture corrigée éventuelle | §6 | CONFIRMED |
| Série des avoirs (Q30, D27) | **Série distincte** des factures, compteur indépendant | courriel de la direction du 18/11/2026 (`docs/reference/regles_fiscales_validees/reponse_q30_numerotation.pdf`) | Décision de la direction ; courriel sans signataire nommé (à arbitrer, D27) |
| Format des exports (Q1) | XLSX et CSV UTF-8 ; journaux §9.1 à §9.5 (dont le code fiscal) ; identifiants stables | §9 | Référentiel (logiciel du cabinet, séparateur et codes comptables non fournis) |
| Devise (Q78, D26) | **Dinar tunisien uniquement** ; colonne « Devise » des exports toujours à `TND` ; toute autre devise refusée | §9.2, §11 | Décision de la direction (D26, 18/11/2026) |
| 1. Nature juridique des options d'assurance, contrats avec les assureurs | Pièces à fournir | §14 | PENDING_DOCUMENTATION |
| 2. Clauses contractuelles relatives aux retards | Pièces à fournir | §14 | PENDING_DOCUMENTATION |
| 3. Conditions de retenue des franchises | Pièces à fournir | §14 | PENDING_DOCUMENTATION |
| 4. Justification des indemnités pour dégâts | Pièces à fournir | §14 | PENDING_DOCUMENTATION |
| 5. Situation exacte de l'adhésion TTN (Q84) | **Situation B déclarée** (demande d'adhésion déposée) ; **preuve du dépôt à fournir** (date et copie) ; factures papier conformes autorisées pendant la finalisation | §7.2, §7.3, §14 | PENDING_DOCUMENTATION |
| 6. Identité juridique et matricule fiscal communs aux 4 agences | Pièces à fournir | §10, §14 | PENDING_DOCUMENTATION |
| 7. Conditions d'application des exonérations de timbre (art. 118) | Pièces à fournir ; jamais d'exonération automatique d'une facture « impayée » | §3.3, §14 | PENDING_DOCUMENTATION |

**Au 18/11/2026** : les règles CONFIRMED sont applicables ; les règles CONDITIONAL s'appliquent opération par opération, avec leurs justificatifs ; les sept points PENDING_DOCUMENTATION restent inactifs tant que leur pièce manque.
