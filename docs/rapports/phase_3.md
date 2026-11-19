# Rapport de fin de phase 3 — Paramétrage et référentiels

| Élément | Valeur |
| --- | --- |
| Phase | 3. Paramétrage et référentiels (lot 1) |
| Branche | `phase/03-parametrage`, créée depuis `main` (`fc206c7`, après la fusion de `fix/02-build-ci`) |
| Dates | 19/11/2026 |
| Statut proposé | **Validée avec réserves** (option (a) du 19/11/2026). **Réserve n° 1 de la phase 3** : saisie du paramétrage complet par l'admin du siège en recette, selon `docs/guide_saisie_parametrage.md`. Rappel : la **réserve n° 1 de la phase 2** (installation de la recette) n'est pas levée et devient **prioritaire**. Les deux réserves sont à lever **avant les ateliers avec les agences (fin de la phase 4)** |
| Critère d'acceptation (cahier §7.1) | Paramétrage complet saisi en recette, validé par l'admin du siège : **non atteint** à ce jour, la recette n'étant pas installée (Q6) ; objet de la réserve n° 1 de la phase 3 |
| Valideur | Admin du siège (saisie), responsable technique (rapport) |
| Plan | `docs/plans/phase_3.md` (validé le 18/11/2026 ; suivi de la charge au §10) |

**En bref** : tout le paramétrage du module A est livré et testé : société, agences et lieux, catégories, saisons et grilles, options, paramètres fiscaux du référentiel v1.0 validé, modes de paiement, fournisseurs, réglages. Au total, 663 tests, `composer check` au vert. Le lot L1 a été validé le 19/11/2026. Charge : **15 j** pour un budget de 19 j (seuil d'alerte 19,2 j). Écrans vérifiés dans le navigateur par le responsable technique le 19/11/2026. Revue de la branche : aucun défaut bloquant (§10). Statut proposé : validée avec réserves, la saisie en recette restant à faire (§8).

---

## 1. Exigences traitées

| Lot | Exigences | Statut | Où |
| --- | --- | --- | --- |
| L1. Société et logo | **A-01** : fiche unique (raison sociale, matricule fiscal, RNE, adresse, contact, logo, mentions des factures), devise TND seule (D26) ; route publique du logo sans lien symbolique (D21) | **Fait**, validé le 19/11/2026 (page et aperçu du logo vérifiés dans le navigateur) | `app/Domain/Setup/Actions/SaveCompanyProfile.php`, `app/Filament/Pages/CompanyProfile.php`, `app/Http/Controllers/PublicFileController.php` |
| L2. Agences et lieux | **A-02** : ajout d'agences sans développement ; lieux de prise en charge et de restitution, horaires par jour (plages, journée coupée, fermé), portée par agence | **Fait** | `app/Filament/Resources/Agencies/`, `app/Domain/Setup/OpeningHours.php` |
| L3. Catégories | **A-03** et règles du conducteur : caution (Q14), âge et permis (Q42), kilométrage et prix du km (Q43), franchise (Q45), délai de préparation (Q41) ; valeurs générales et surcharges | **Fait** | `app/Filament/Resources/VehicleCategories/`, `app/Domain/Setup/CategoryRules.php` |
| L4. Saisons et grilles | **A-04** : saisons sans chevauchement ; grilles par catégorie et saison, communes ou par agence (Q10), tranches de durée continues, prix HT (Q34) ; prix d'un jour (`RateResolver`) prêt pour la phase 4 | **Fait** (le calcul du prix d'une réservation relève de la phase 4) | `app/Filament/Resources/Seasons/`, `app/Filament/Resources/RatePlans/`, `app/Domain/Setup/RateLines.php` |
| L5. Options | **A-05** : options liées à un code fiscal choisi ; activation refusée sans code applicable ni prix ; « livraison » sans code (Q85) | **Fait** | `app/Filament/Resources/Options/`, `app/Domain/Setup/Actions/SaveOption.php` |
| L6. Paramètres fiscaux | **A-08** selon le référentiel v1.0 validé : 15 codes, versions datées, timbre, `TaxRuleResolver`, `TaxCalculator` (§5), écrans, rapport de configuration (écran, XLSX, version imprimable), garde-fou de `aviator:check-environment` | **Fait** | `app/Domain/Tax/`, `app/Domain/TaxReporting/`, `app/Filament/Resources/TaxCodes/`, `app/Filament/Resources/StampDutyRules/` |
| L7. Facturation et paiements | **A-07** : modes de paiement (référence exigée ou non) ; conditions de paiement (`billing.payment_terms_days`, Q89) | **Fait** (numérotation D27 en phases 6 et 9) | `app/Filament/Resources/PaymentMethods/` |
| L8. Réglages | **A-06**, **A-09** (événements, délais, destinataires ; envoi en phase 9), approbations (Q10, Q52), sécurité (**Q76**), facturation (Q86, Q89) | **Fait** | `app/Filament/Pages/Settings/`, `app/Domain/Setup/Actions/UpdateSettings.php` |
| L9. Fournisseurs | **G-05** : fiche simple | **Fait** | `app/Filament/Resources/Suppliers/` |
| L10. Droits, audit, tests transverses | Matrice du plan (§2) par profil ; journal de toutes les écritures ; traductions françaises | **Fait** | `tests/Feature/Setup/SetupAccessMatrixTest.php`, `lang/fr/` |
| L11. Données de référence | Seeders (agences, référentiel fiscal, options, modes de paiement) ; guide de saisie pour l'admin du siège | **Fait** ; appui à la saisie en recette **non fait** (recette non installée) | `database/seeders/`, `docs/guide_saisie_parametrage.md` |
| L12. Rapport | Ce document, revue de la branche (§10) | **Fait** | `docs/rapports/phase_3.md` |

## 2. Fichiers créés ou modifiés

170 fichiers modifiés depuis `main` (10 600 lignes ajoutées).

| Domaine | Principaux fichiers |
| --- | --- |
| Domaine | `app/Domain/Setup/` (actions d'écriture, `OpeningHours`, `CategoryRules`, `RateLines`, `RateResolver`, `SettingsCatalog`), `app/Domain/Tax/` (`TaxRuleResolver`, `TaxCalculator`, `StampDutyResolver`, `FiscalGuard`, actions de versions), `app/Domain/TaxReporting/` |
| Modèles et policies | 16 modèles (`Company`, `AgencyLocation`, `VehicleCategory`, `Season`, `RatePlan`, `RatePlanLine`, `Option`, `FiscalReference`, `TaxCode`, `TaxRule`, `StampDutyRule`, `PaymentMethod`, `Supplier`…) et leurs policies ; permissions `reference_data.manage`, `fiscal.manage`, `settings.manage` |
| Interface | 10 ressources Filament (groupes « Paramétrage » et « Paramètres fiscaux »), page société, rapport fiscal, 6 pages de réglages ; vues Blade du rapport ; route publique du logo (`routes/public.php`) ; route imprimable du rapport (2FA exigée) |
| Base | 7 migrations, 4 migrations de réglages (`reservations`, `pricing`, `billing`, `approvals`, `notifications`), seeders `FiscalReferenceSeeder`, `OptionSeeder`, `PaymentMethodSeeder` |
| Divers | `app/Support/Files/PublicFiles.php`, `app/Support/Money/AmountInput.php`, `app/Filament/Forms/AmountField.php`, `lang/fr/` (validation, authentification, mots de passe, pagination), ligne « Règles fiscales » de `EnvironmentChecker` |
| Documentation | `docs/modele_donnees.md`, `docs/architecture.md` §8.1, `docs/plans/phase_3.md` (§3.5, §10), `docs/plans/phase_9.md`, `docs/plans/phase_10.md`, `docs/questions_ouvertes.md` (Q88 à Q90), `docs/decisions.md` (D28 à D34), `docs/guide_saisie_parametrage.md`, `docs/projet.md` |

## 3. Tests

| Commande | Résultat |
| --- | --- |
| `composer check` (poste, dernier lancement sur `ced1f72`, avant chaque commit) | Pint : passé ; Larastan : 0 erreur ; Pest : **663 tests, 661 réussis, 2 ignorés** (2 083 assertions) ; `composer audit` : aucune vulnérabilité ; `npm run build` : OK |
| Fichiers de tests créés en phase 3 (`scripts/clean-env.sh php artisan test tests/Feature/Setup tests/Feature/Tax tests/Unit/Setup tests/Unit/Support/AmountInputTest.php tests/Arch/TaxDomainTest.php tests/Feature/Concurrency/TaxRuleConcurrencyTest.php`) | **267 tests, 267 réussis** (872 assertions) ; s'y ajoutent les tests existants complétés (schéma, permissions, contrôle de l'environnement, architecture) |

| CI GitHub (`composer check`, Ubuntu, MariaDB 10.11), run `44522479320` sur `c66537e` | **663 tests réussis, 0 ignoré** (2 120 assertions) ; les commits suivants ne touchent que la documentation |

**Tests ignorés sur le poste** : les 2 mêmes qu'en phase 2 (`tests/Feature/Release/ReleaseScriptsTest.php`, exécutés et réussis en CI). Aucun test de la phase 3 n'est ignoré.

Tests de référence du plan (§6) :

| Domaine | Test | Fichier |
| --- | --- | --- |
| Exemple chiffré de la §5 | 115,000 HT ; 21,850 TVA ; 136,850 TTC ; 1,000 timbre ; 137,850 net, calculé par `TaxCalculator` et affiché par le rapport | `tests/Feature/Tax/FiscalSetupTest.php` |
| Libellé « assurance » | Aucune résolution par libellé (5 variantes) ; option « assurance » sans code non activable ; test d'architecture : aucun libellé lu par les services fiscaux | `FiscalSetupTest.php`, `tests/Feature/Setup/OptionTest.php`, `tests/Arch/TaxDomainTest.php` |
| Statuts | PENDING_DOCUMENTATION et règle inactive jamais renvoyées ; activation d'une règle en attente de pièce refusée ; garde-fou en ERREUR en production, en AVERTISSEMENT ailleurs | `FiscalSetupTest.php` |
| Versions | Nouvelle version datée, précédente close à la veille, ancienne retrouvée à une date antérieure ; **saisie simultanée par deux processus PHP** : une seule version passe | `FiscalSetupTest.php`, `tests/Feature/Concurrency/TaxRuleConcurrencyTest.php` |
| Timbre | 1,000 DT ; exonérations de l'article 118 inactives ; aucun tarif « grandes surfaces » ; nouvelle version appliquée à sa date | `FiscalSetupTest.php` |
| Arrondi (Q86) | 0,150 à 19 % : 0,029 en demi supérieur, 0,028 en demi pair ; TVA sur la base agrégée, pas ligne par ligne | `FiscalSetupTest.php` |
| Référence validée | SHA-256 enregistré égal à l'empreinte réelle du fichier validé ; seeder idempotent qui ne modifie aucune règle | `FiscalSetupTest.php` |
| Droits | Matrice du §2 sur 12 écrans et 3 profils ; l'admin lit la fiscalité sans la modifier | `SetupAccessMatrixTest.php` et tests de chaque lot |
| Audit | Création et modification journalisées avec l'avant et l'après, tranches de grille comprises, réglages (`settings_updated`) | tests de chaque lot |
| Grilles | Tranches continues sans doublon, saisons sans chevauchement, montants au millime sans float | `tests/Unit/Setup/RateLinesTest.php`, `tests/Feature/Setup/SeasonAndRatePlanTest.php` |
| Logo (D21) | Servi par sa route, en lecture seule, liste blanche, type réel contrôlé, 304, aucun `/storage` | `tests/Feature/Setup/PublicFileRouteTest.php` |

**Vérification dans le navigateur** : écrans des lots L1 à L10 vérifiés par le responsable technique le 19/11/2026, sur le poste (`https://aviator.test`) : OK.

## 4. Écarts au cahier des charges et décisions

| Point | Traitement | Décision |
| --- | --- | --- |
| Mentions des factures (A-01) | Paramètre fiscal, vide par défaut, saisi par le super admin à partir de la règle FACTURE validée ; pas de production sans elles | D28, **validée** le 19/11/2026 |
| Matricule fiscal | Aucun format imposé ; avertissement sur le format en option (phase 9) | D28, **validée** le 19/11/2026 |
| Table `taxes` du modèle | Remplacée par `fiscal_references`, `tax_codes`, `tax_rules`, `stamp_duty_rules` (plan §3.1) ; tailles de colonnes un peu plus larges que prévu (`docs/modele_donnees.md`) | Plan validé |
| Validité des grilles | Appliquée au jour loué ; dernière tranche sans limite | D30 : valeurs par défaut **acceptées** (19/11/2026), à confirmer par la direction au prochain point hebdomadaire (Q88) |
| Règles A-06 et alertes A-09 sans valeur dans le cahier | Laissées vides : contrainte ou alerte désactivée | D31 : **acceptées**, à confirmer par la direction (Q90) |
| Conditions de paiement (A-07) | Réglage `billing.payment_terms_days`, comptant par défaut | D31 : **acceptées**, à confirmer par la direction (Q89) |
| Codes fixés à la création | Agence, mode de paiement ; agence non désactivable avec des utilisateurs actifs ; siège non désactivable | D32, **validée** (19/11/2026) |
| Valeurs fiscales initiales | Règles en vigueur à la date de validation du référentiel (18/11/2026) ; exonérations de l'article 118 créées inactives | D33, proposée |
| Ordre des lots | L6 avant L5 (les options exigent `tax_codes`), L9 avant L8 | Sans effet sur la charge |
| Traductions | Aucun fichier `lang/fr` depuis la phase 2 : messages de validation affichés en clés brutes. Corrigé en L10, avec un test de parité | D34 |

Détail : `docs/decisions.md`, D28 à D34.

## 5. Charge consommée

Estimation en jours-équivalents d'un développeur Laravel expert (`docs/plans/phase_3.md` §10).

| Lot | Prévu (j) | Estimé (j) |
| --- | ---: | ---: |
| L1. Société et logo | 1,25 | 1 |
| L2. Agences et lieux | 1 | 1 |
| L3. Catégories | 1,5 | 1,25 |
| L4. Saisons et grilles | 3 | 2,5 |
| L5. Options | 1 | 0,75 |
| L6. Paramètres fiscaux | 4,25 | 4 |
| L7. Facturation et paiements | 0,75 | 0,5 |
| L8. Réglages | 2,25 | 1,75 |
| L9. Fournisseurs | 0,75 | 0,5 |
| L10. Droits, audit, tests transverses | 1,25 | 1 |
| L11. Données de référence | 0,5 | 0,25 |
| L12. Rapport | 0,5 | 0,5 |
| Aléas | 1 | 0 |
| **Total** | **19** | **15** |

**15 j pour 19 j de budget** (référence 16 j, −21 % sur le budget, −6,25 % sur la référence). Reste prévu : l'appui à la saisie en recette (dans L11, environ 0,25 j) et la levée de la réserve n° 1 de la phase 2 (0,25 j, imputés à la phase 2). Charge réelle retenue : **15 j** (revue comprise dans L12, aléas non consommés) ; avec l'appui à la saisie : **15,25 j**. Lot 1 : la phase 3 était projetée à 19 j dans l'écart cumulé ; il baisse de 3,75 j, à **265,2 j** (+20,2 j, +8,2 %), reporté dans `docs/projet.md` §5.

## 6. Risques nouveaux

| Risque | Effet | Parade |
| --- | --- | --- |
| Recette toujours pas installée (Q6) | Le critère d'acceptation de la phase 3 ne peut pas être atteint ; les surprises de l'hébergement arrivent tard | Réserve n° 1 : à lever avant la clôture de la phase 3 ; procédure prête (`docs/installation_recette.md`) |
| Données de référence chargées à la première installation seulement (`db:seed` dans `install-release.sh`) | Une instance installée avant cette version n'aurait ni codes fiscaux ni options | Aucune instance installée à ce jour ; sinon `php artisan db:seed --class=FiscalReferenceSeeder --force` (puis `OptionSeeder`, `PaymentMethodSeeder`) ; à intégrer à `install-release.sh` si une mise à jour en a besoin |
| Mentions obligatoires des factures non fournies par le cabinet (règle FACTURE) | Garde-fou fiscal en ERREUR en production | Demande au cabinet avant la phase 6 ; décision D28 |
| Saisons non couvertes | Un jour hors saison n'a pas de prix : réservation refusée en phase 4 | Guide de saisie (étape 4) ; contrôle de couverture possible en phase 4 |

## 7. Questions pour la direction et l'expert-comptable

| N° | Pour | Question | Valeur par défaut |
| --- | --- | --- | --- |
| Q85 | Cabinet | Code fiscal de la livraison et des kilomètres supplémentaires | « Livraison » inactive, sans code |
| Q86 | Cabinet | Mode d'arrondi de la TVA | Demi supérieur |
| Q88 (nouvelle) | Direction | Validité d'une grille : jour loué ou date de réservation ? Durée maximale de location ? | Jour loué ; dernière tranche sans limite |
| Q89 (nouvelle) | Direction | Conditions de paiement : comptant ? délai pour les entreprises ? | Comptant (0 jour) |
| Q90 (nouvelle) | Direction | Durée minimale, préavis, expiration au comptoir, délais et destinataires des alertes | Vides : désactivés |
| FACTURE | Cabinet | Liste des mentions obligatoires des factures (règle FACTURE, écart E8) | Aucune : saisie par le super admin à réception |
| Q76 | Direction | 2FA des utilisateurs d'agence (activable depuis Réglages → Sécurité) | Désactivée ; activation recommandée |

## 8. Saisie en recette : réserve n° 1 de la phase 3

**Raison** : la recette n'est pas installée (réserve n° 1 de la phase 2, domaine en attente : Q6). Cette installation devient **prioritaire**. Les deux réserves sont à lever **avant les ateliers avec les agences, à la fin de la phase 4**.

**Critère de levée de la réserve n° 1 de la phase 3** : paramétrage complet saisi en recette par l'admin du siège selon `docs/guide_saisie_parametrage.md`, et validé par lui. Les mentions des factures, qui dépendent du cabinet (D28), n'en font pas partie : elles bloquent la mise en production, pas la levée de la réserve.

**Reste à faire**, dans l'ordre :

1. Choix du domaine par la direction (Q6).
2. Installation de la recette par le responsable technique : `docs/installation_recette.md`, avec une archive construite depuis cette branche (workflow « Release » ou `scripts/build-release.sh`). Les données de référence (agences, référentiel fiscal, options, modes de paiement) sont chargées à cette première installation.
3. Résultat de `aviator:check-environment` joint à ce rapport. La ligne « Règles fiscales » restera en ERREUR tant que les mentions des factures n'auront pas été saisies : c'est attendu (D28).
4. Saisie du paramétrage par l'admin du siège selon `docs/guide_saisie_parametrage.md` (société, lieux et horaires, catégories, saisons, grilles, prix des options, fournisseurs), puis par le super admin (réglages, mentions des factures quand le cabinet les aura fournies).
5. Validation de la saisie par l'admin du siège ; correction des anomalies relevées.

Commandes, dans le terminal web de cPanel, après l'envoi de l'archive :

```bash
cd ~/deploiement
tar -xzf aviator-<version>.tar.gz ./scripts/install-release.sh ./scripts/rollback-release.sh
bash scripts/install-release.sh recette ~/deploiement/aviator-<version>.tar.gz
php ~/apps/recette/current/artisan aviator:check-environment
```

## 9. À valider avant la phase 4

1. **Ce rapport**, au statut proposé « Validée avec réserves » (réserve n° 1 de la phase 3 ; réserve n° 1 de la phase 2 prioritaire).
2. Les décisions D33 et D34 (proposées) ; D30 et D31 à confirmer par la direction au prochain point hebdomadaire (Q88, Q89, Q90).
3. La conclusion de la revue (§10).
4. Fusion de `phase/03-parametrage` dans `main` (pull request, commit de fusion), puis création de la branche de la phase 4.
5. **Levée des réserves** avant les ateliers avec les agences (fin de la phase 4) : installation de la recette, puis saisie du paramétrage (§8).

## 10. Revue de la branche (19/11/2026)

Revue de `phase/03-parametrage` (`fc206c7..HEAD`) par Claude Code, à la demande du responsable technique, sur cinq axes.

| Axe | Constat | Preuve |
| --- | --- | --- |
| Sécurité | Toutes les écritures passent par les actions de `app/Domain` (aucune création, modification ou suppression directe dans les ressources ; actions de création et de modification Filament redirigées vers le domaine). Les champs désactivés ou falsifiés côté Livewire sont refusés par le domaine (mentions, chemin du logo, code d'agence). Le logo n'expose aucun autre fichier : liste blanche, dossier imposé, type réel contrôlé, chemins détournés refusés. Vues Blade échappées (aucun `{!!`). Aucun SQL brut. Version imprimable derrière l'authentification et la 2FA. Message de réinitialisation neutre | `PublicFileRouteTest`, `CompanyProfileTest`, `AgencyManagementTest`, `FiscalSetupTest` (2FA), `DatabaseAccessTest` |
| Droits par profil | Matrice du plan (§2) appliquée sur les 12 écrans et les 3 profils ; écriture fiscale réservée au super admin, lecture par l'admin ; réglages réservés au super admin ; aucune suppression sauf une saison inutilisée ; recherche globale fermée | `SetupAccessMatrixTest` et tests de chaque lot |
| Portée par agence | `agency_locations` soumise à `AgencyScope` et à `BelongsToAgency` (écriture hors de ses agences refusée même sans policy) ; l'utilisateur d'agence ne voit que ses agences (404 sur une autre) | `AgencyManagementTest` |
| Règles fiscales | Conformes au référentiel v1.0 validé : 15 codes, natures et statuts de V2.3 ; 19 % sur les 6 codes CONFIRMED ; timbre de 1,000 DT après le TTC ; exonérations de l'article 118 inactives et non activables ; aucun tarif « grandes surfaces » ; méthode d'arrondi §5 (exemple 137,850 exact) ; aucune lecture de libellé ; SHA-256 enregistré égal à celui du fichier validé | `FiscalSetupTest`, `TaxDomainTest`, `OptionTest` |
| Documentation et tests | Chaque affirmation « fait » du rapport renvoie à un test ; `docs/modele_donnees.md` décrit les tables migrées (écarts de taille signalés) ; aucune affirmation « testé » sans test | §3 de ce rapport |

**Observations non bloquantes** :

1. `SaveSeason` sérialise les saisies par un verrou de cache, sans test à deux processus, contrairement aux règles fiscales. Le texte dit « sous verrou » et ne prétend pas que c'est testé. Si le verrou n'est pas obtenu en 5 s, l'utilisateur voit une erreur générique : cas très improbable avec 6 utilisateurs.
2. L'export XLSX ne contient que des données du référentiel ou des montants ; aucune saisie libre ne peut y devenir une formule.
3. Les données de référence ne sont chargées qu'à la première installation (risque du §6).

**Conclusion** : aucun défaut bloquant. La branche peut être fusionnée dans `main`.
