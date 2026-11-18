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
| 3. Paramétrage et référentiels | 1 | 16 | | | | | Projeté 16,25 j : reports de la phase 2 (`docs/plans/phase_3.md`) |
| 4. Parc, leasing et réservations | 1 | 39 | | | | | Projeté 41,75 j : reports de la phase 2 (`docs/plans/phase_4.md`) |
| 5. Contrats et états des lieux | 1 | 30 | | | | | Projeté 29,5 j : `drivers` avancé en phase 4 (`docs/plans/phase_5.md`) |
| 6. Facturation, encaissements, cautions | 1 | 31 | | | | | |
| 7. CRM et recommandations | 1 | 21 | | | | | Projeté 20,75 j : table des recommandations créée en phase 4 |
| 8. Maintenance, dépenses, exports | 1 | 20 | | | | | |
| 9. Statistiques, mobile et sécurité | 1 | 38 | | | | | Projeté 38,5 j : purge des journaux (Q77, `docs/plans/phase_9.md`) |
| 10. Recette et mise en production du lot 1 | 1 | 19 | | | | | |
| 11. Espace client | 2 | 45 | | | | | |
| **Total** | | **290** | | | | | |
| **Écart cumulé du lot 1** (au 18/11/2026) | 1 | **245** prévus | **249** projetés | **+4 j (+1,6 %)** | | | Phases 3 à 10 : référence corrigée des reports chiffrés ; phase 1 comptée à sa référence (12 j, charge réelle à renseigner) ; phase 2 projetée à 20,25 j (D25). Mise à jour à chaque fin de phase |

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

Statuts (spécifications du cabinet, §16) : **CONFIRMED** (validée par écrit, document déposé dans `docs/reference/regles_fiscales_validees/` avec valideur, date et exemple chiffré) ; **PROPOSED** (valeur proposée par le cabinet comme paramétrage initial) ; **PENDING_ACCOUNTANT_VALIDATION** (le cabinet a indiqué qu'elle exige sa validation). Seule une règle CONFIRMED peut servir en production.

Source des valeurs : spécifications du cabinet du 18/11/2026 (`docs/reference/cabinet/specifications_facturation_2026-11-18.md`), qui ne valent **pas** validation. Analyse : `docs/analyse_ecarts_cabinet.md`.

| Règle | Valeur ou traitement | Source | Statut |
| --- | --- | --- | --- |
| TVA sur la location de véhicules | 19 % | §2 | PROPOSED |
| TVA sur le siège enfant, le GPS, le conducteur supplémentaire | 19 % | §2 | PROPOSED |
| TVA sur l'assurance complémentaire | Selon la nature juridique et contractuelle | §2 | PENDING_ACCOUNTANT_VALIDATION |
| TVA sur le carburant refacturé | 19 % « en principe » | §2 | PENDING_ACCOUNTANT_VALIDATION |
| TVA sur le retard de restitution | Selon la nature de la somme facturée | §2 | PENDING_ACCOUNTANT_VALIDATION |
| Dégâts sur véhicule | Hors champ possible en cas d'indemnisation réelle | §2 | PENDING_ACCOUNTANT_VALIDATION |
| Franchise d'assurance | Selon la nature de la retenue | §2 | PENDING_ACCOUNTANT_VALIDATION |
| Timbre fiscal : montant | 1,000 DT par facture concernée, ligne distincte, inclus dans le net à payer | §3 | PROPOSED |
| Timbre fiscal : conditions et exonérations | À préciser (« sous réserve de confirmation des dispositions applicables ») | §3 | PENDING_ACCOUNTANT_VALIDATION |
| Mentions obligatoires des factures et des avoirs | Liste du §4 (société, document, client particulier ou professionnel, lignes, récapitulatif) | §4, §6 | PROPOSED |
| Numérotation des factures | Séquence centralisée pour les 4 agences, format `AVI-AAAA-NNNNNN`, agence dans un champ distinct | §5 | PROPOSED |
| Remise à zéro annuelle de la numérotation | Non tranchée (« doit être confirmée par le cabinet ») | §5 | PENDING_ACCOUNTANT_VALIDATION |
| Numérotation des avoirs et des factures complémentaires (Q30) | Numéro propre ; séquence commune ou distincte non précisée | §5, §6 | PENDING_ACCOUNTANT_VALIDATION |
| Statuts de facture | `DRAFT`, `VALIDATED`, `ISSUED`, `PARTIALLY_PAID`, `PAID`, `CREDITED`, `CANCELLED` | §6 | PROPOSED |
| Arrondis | 3 décimales ; TVA calculée sur la base groupée par taux puis arrondie ; timbre ajouté séparément | §7 | PENDING_ACCOUNTANT_VALIDATION (« la méthode définitive doit être validée ») |
| Qualification fiscale des retenues sur caution (Q51) | À qualifier selon la nature de la retenue | §9.3, §10 | PENDING_ACCOUNTANT_VALIDATION |
| Format des exports pour le cabinet (Q1) | XLSX et CSV UTF-8 ; colonnes des journaux §9.1 à §9.5 ; fréquences §9.6 ; identifiants stables | §9 | PROPOSED (logiciel, séparateur et codes comptables non fournis) |
| Multidevise | Devise de référence TND ; devise d'origine, taux et contre-valeur conservés | §11 | PROPOSED (besoin réel à confirmer, Q78) |
| Facturation électronique (TEIF / TTN, Q16) | Obligation étendue aux services selon le cabinet ; situation d'Aviator à confirmer | §8 | PENDING_ACCOUNTANT_VALIDATION |

**Aucune règle n'est CONFIRMED au 18/11/2026.** Aucun document n'a été versé dans `docs/reference/regles_fiscales_validees/`.
