# Plan de la phase 3 — Paramétrage et référentiels

| Élément | Valeur |
| --- | --- |
| Phase | 3. Paramétrage et référentiels (lot 1) |
| Branche | `phase/03-parametrage`, créée depuis `main` après la fusion de `phase/02-architecture-socle` |
| Budget | **19 j** : référence 16 j, reports de la phase 2 (+0,25 j), demande d'évolution du cabinet, part indispensable (+2,75 j). **Seuil d'alerte : 19,2 j** (+20 %), soit 0,2 j de marge |
| Statut | **Plan validé le 18/11/2026** (rapport de la phase 2 validé avec réserves le même jour). Démarrage après la fusion de la phase 2 dans `main` |
| Critère d'acceptation (cahier §7.1) | Paramétrage complet saisi en recette, validé par l'admin du siège. Il suppose la recette installée (réserve n° 1 de la phase 2, à lever avant la fin de cette phase) |
| Sources | Cahier, module A (A-01 à A-09), G-05 ; **référentiel fiscal v1.0 validé** (`docs/reference/regles_fiscales_validees/`) ; analyse d'écarts, version 2 (`docs/analyse_ecarts_cabinet.md`) ; `docs/modele_donnees.md` §3 ; décisions D13 à D27 |

## 1. Périmètre

### Dans la phase

1. **A-01** : fiche société (raison sociale, matricule fiscal, RNE, adresse, devise TND fixe (D26), logo, mentions) et **route publique du logo** sans `storage:link` (D21, report de la phase 2).
2. **A-02** : agences (créées en phase 2) et leurs lieux de prise en charge et de restitution, avec horaires.
3. **A-03** et règles des conducteurs : catégories de véhicules, avec âge minimum et ancienneté du permis (Q42), caution (Q14), kilométrage inclus (Q43), franchise (Q45), délai de préparation (Q41). La table `drivers` reste en phase 4 (D24).
4. **A-04** : saisons et grilles tarifaires par catégorie, durée et saison, commune ou par agence (Q10), prix **HT** (Q34). Le calcul du prix d'une réservation (Q32, Q33) relève de la phase 4 ; la phase 3 stocke et contrôle les grilles.
5. **A-05** : options facturables, chacune liée à un **code fiscal** du référentiel, jamais déduit de son libellé.
6. **A-06** : règles de réservation (réglages).
7. **A-07** : conditions et modes de paiement. La numérotation des factures (D27) relève de la phase 6.
8. **A-08** : **paramètres fiscaux** selon le référentiel validé (§3 ci-dessous).
9. **A-09** : réglages des notifications (événements, destinataires, délais), sans envoi : le push arrive en phase 9.
10. Réglages de sécurité, dont la 2FA des utilisateurs d'agence (Q76), et des approbations (seuil de remise, Q10, Q52).
11. **G-05** : fournisseurs (fiche simple).

### Hors phase

- Calcul du prix d'une réservation (phase 4).
- Factures, avoirs et numérotation D27 (phase 6).
- Écrans clients (phase 7).
- Envoi des notifications (phase 9).
- Exports comptables (phase 8).

## 2. Droits

| Paramétrage | Super admin | Admin du siège | Utilisateur d'agence |
| --- | --- | --- | --- |
| Société, agences et lieux, catégories, saisons, grilles, options, modes de paiement, fournisseurs | Écriture | Écriture | Lecture de ce qui sert au comptoir (catégories, options, lieux) |
| Codes fiscaux, règles de TVA, règles de timbre, statuts de validation | Écriture | **Lecture** | — |
| Rapport de configuration fiscale | Oui | Oui (lecture) | — |
| Réglages (réservation, tarification, facturation, approbations, notifications, sécurité) | Écriture | — | — |

Chaque création ou modification est journalisée (journal `audit`, avant et après). Les droits sont contrôlés par des policies, testées par profil.

## 3. Paramètres fiscaux (référentiel v1.0 validé)

### 3.1 Tables

Elles remplacent la table `taxes` de `docs/modele_donnees.md`, qui sera mis à jour en conséquence.

| Table | Colonnes principales | Règles |
| --- | --- | --- |
| `fiscal_references` | `version` (`1.0`), `title`, `file_name`, `sha256`, `validation_source` (« Confirmation du cabinet conservée par Aviator, attestée par Faouzi Bannour, directeur général, le 18/11/2026 »), `validated_at`, `attestation_file_name` | Une ligne par version validée ; jamais modifiée (une nouvelle version s'ajoute, `CLAUDE.md` règle 3). Elle est nécessaire parce que `docs/` n'est pas livré sur le serveur (D25) : l'empreinte y est enregistrée |
| `tax_codes` | `code` (unique : les 15 codes), `label`, `nature` (`taxable`, `exempt`, `out_of_scope`), `requires_justification`, `legal_reference`, `description` | Liste fermée, créée par un seeder ; pas de création libre dans l'interface |
| `tax_rules` | `tax_code_id`, `rate` DECIMAL(7,4) (nul si non taxable), `valid_from`, `valid_to`, `validation_status` (`CONFIRMED`, `CONDITIONAL`, `PENDING_DOCUMENTATION`), `fiscal_reference_id`, `is_active`, `created_by` | **Jamais modifiée sur place** : un changement crée une nouvelle version datée, et la précédente est close à la veille. Aucun chevauchement pour un même code, contrôlé sous verrou |
| `stamp_duty_rules` | `rule_code`, `description`, `amount` DECIMAL(15,3), `applicability`, `exemption_condition`, `legal_reference`, `effective_from`, `effective_to`, `validation_status`, `fiscal_reference_id`, `is_active` | Référentiel §3.4. Règle de base : 1,000 DT par facture ordinaire concernée (CONFIRMED). Exonérations de l'article 118 : PENDING_DOCUMENTATION, inactives. Aucune exonération automatique d'une facture « impayée » ; tarif « grandes surfaces » 2026 jamais créé |
| `options.tax_code_id` | Clé étrangère vers `tax_codes` (remplace `tax_nature`) | Une option sans code fiscal ne peut pas être activée |

### 3.2 Valeurs initiales

Seeder idempotent, qui ne modifie jamais une règle existante :

- les **15 codes** du référentiel, avec leur statut (`docs/analyse_ecarts_cabinet.md`, V2.3) :
  - 6 CONFIRMED à 19 % : `RENTAL`, `CHILD_SEAT`, `GPS`, `EXTRA_DRIVER`, `EXTRA_RENTAL_PERIOD`, `FUEL_RECHARGE` ;
  - 9 CONDITIONAL : assurance, retard, dégâts, franchise ;
- le timbre de 1,000 DT ;
- la référence v1.0 (SHA-256 `43b2de817cb6590f2e90875afbff9c29c795f50599073fe3ecd7cbb98158e887`) ;
- les modes de paiement (espèces, chèque, carte, virement, traite).

Options du cahier (A-05) et code fiscal par défaut :

| Option | Code fiscal |
| --- | --- |
| Conducteur supplémentaire | `EXTRA_DRIVER` |
| Siège enfant | `CHILD_SEAT` |
| GPS | `GPS` |
| Franchise réduite | `EXCESS_REDUCTION_OPTION` |
| **Livraison** | **aucun code dans le référentiel** : option créée **inactive**, en attente de **Q85** |

### 3.3 Services de domaine (`app/Domain/Tax`)

- **`TaxRuleResolver`** : pour un code et une date, renvoie la règle en vigueur. Il refuse explicitement une règle PENDING_DOCUMENTATION ou inactive, et une date sans règle. Il ne lit jamais de libellé.
- **`TaxCalculator`** : à partir de lignes (code fiscal, montant HT), il calcule :
  1. les bases agrégées par taux ;
  2. la TVA de chaque base, arrondie à 3 décimales ;
  3. le TTC ;
  4. le timbre, ajouté après le TTC ;
  5. le net à payer.

  C'est la méthode du référentiel §5, avec `Money` (phase 2). Le **mode d'arrondi** est un réglage (`billing.vat_rounding_mode`), par défaut le demi supérieur, **à confirmer (Q86)**. Ce service sert au rapport de configuration (exemple chiffré calculé en direct) et sera réutilisé tel quel par les factures en phase 6.

### 3.4 Rapport de configuration fiscale

Écran et export XLSX (OpenSpout, déjà présent avec Filament), plus une version imprimable pour signature. Il contient :

- chaque code ;
- chaque règle de timbre, avec taux, nature, validité, statut et référence de validation ;
- le SHA-256 du référentiel ;
- l'exemple chiffré de la §5, calculé par `TaxCalculator`.

La direction le signera avant la mise en production (`docs/plans/phase_10.md`).

### 3.5 Garde-fou

`aviator:check-environment` reçoit une ligne « Règles fiscales ». En production, elle passe en **ERREUR** dans quatre cas :

- aucune référence validée n'est enregistrée ;
- une règle active est PENDING_DOCUMENTATION ;
- une règle CONFIRMED n'a pas de référence ;
- **les mentions obligatoires des factures sont vides** (fiche société absente ou `company.invoice_legal_mentions` vide). Décision du 19/11/2026, à la validation du lot L1 : les mentions sont un paramètre fiscal, vide par défaut, saisi par le super admin à partir de la règle FACTURE validée ; pas de production sans elles.

Hors production, ces cas donnent un AVERTISSEMENT. Une règle CONDITIONAL est admise, car son justificatif est exigé ligne par ligne en phase 6. Les règles en cause sont citées par leur code seulement.

## 4. Tables migrées

`company`, `agency_locations`, `vehicle_categories`, `seasons`, `rate_plans`, `rate_plan_lines`, `options`, `fiscal_references`, `tax_codes`, `tax_rules`, `stamp_duty_rules`, `payment_methods`, `suppliers` ; groupes de réglages `spatie/laravel-settings` (réservation, tarification, facturation, approbations, notifications, sécurité). InnoDB, `utf8mb4_unicode_ci`, montants en `DECIMAL(15,3)`, compatibles MariaDB 10.11.

## 5. Lots et charge

| Lot | Contenu | Charge (j) |
| --- | --- | ---: |
| L1. Société et logo | A-01 (page unique), route publique du logo (D21 : lecture seule, liste blanche, en-têtes de cache) | 1,25 |
| L2. Agences et lieux | A-02 : lieux, horaires d'ouverture, prise en charge et restitution | 1 |
| L3. Catégories et règles des conducteurs | A-03 avec Q14, Q41, Q42, Q43, Q45 | 1,5 |
| L4. Saisons et grilles tarifaires | A-04 : saisons sans chevauchement, grilles par catégorie, saison, tranches de durée, commune ou par agence (Q10), HT (Q34) ; contrôles de cohérence (tranches continues, pas de doublon) | 3 |
| L5. Options | A-05, liées à un code fiscal ; option sans code impossible à activer | 1 |
| L6. Paramètres fiscaux | §3 : tables, seeder, `TaxRuleResolver`, `TaxCalculator`, écrans (super admin en écriture, admin en lecture), transitions de statut journalisées, rapport de configuration, garde-fou. Détail : base 1,5 + demande d'évolution 2,75 (codes et natures 0,75 ; versions 0,5 ; rapport 0,5 ; garde-fou 0,25 ; timbre 0,75) | 4,25 |
| L7. Facturation et paiements | A-07 : conditions et modes de paiement (référence exigée ou non) | 0,75 |
| L8. Réglages | A-06, A-09, approbations, sécurité (Q76) : pages de réglages pour le super admin | 2,25 |
| L9. Fournisseurs | G-05 | 0,75 |
| L10. Droits, audit et tests transverses | Policies de toutes les ressources, journalisation, tests d'architecture (aucun libellé lu par le domaine fiscal) | 1,25 |
| L11. Données de référence et appui à la saisie en recette | Seeders, guide de saisie pour l'admin du siège | 0,5 |
| L12. Rapport | `docs/rapports/phase_3.md` | 0,5 |
| Aléas | | 1 |
| **Total** | | **19** |

Hypothèse de l'estimation : les valeurs par défaut des questions (§7) sont retenues. Toute réponse qui ajoute un mécanisme (grille par agence obligatoire, tarification calendaire…) sera chiffrée et signalée avant d'être développée.

## 6. Tests prévus (Pest, sur MariaDB)

| Domaine | Tests |
| --- | --- |
| **Exemple chiffré de la §5** (test de référence) | Location 100,000 + GPS 10,000 + siège enfant 5,000, tous à 19 % : total HT **115,000** ; TVA **21,850** (sur la base agrégée par taux, arrondie à 3 décimales) ; TTC **136,850** ; timbre **1,000** ajouté après le TTC ; net à payer **137,850** |
| **Libellé « assurance »** | Aucune correspondance par libellé. Une prestation ou une option dont le libellé contient « assurance » (toutes casses, avec ou sans accent) n'obtient ni `INSURANCE_PREMIUM` ni une exonération. Un test d'architecture interdit aussi au domaine fiscal de lire un libellé |
| Statuts | Une règle PENDING_DOCUMENTATION n'est jamais renvoyée comme applicable ; une règle inactive non plus ; contrôle de l'environnement en ERREUR en production, en AVERTISSEMENT ailleurs ; mentions des factures vides : ERREUR en production, AVERTISSEMENT ailleurs |
| Versions | Un changement de taux crée une nouvelle version datée et clôt la précédente ; une date antérieure retrouve l'ancienne version ; chevauchement refusé, y compris sous saisie simultanée |
| Timbre | 1,000 DT par facture ; une exonération PENDING_DOCUMENTATION ne s'applique jamais ; aucune exonération automatique d'une facture impayée |
| Arrondi (Q86) | Base de 0,150 à 19 % : TVA de 0,029 en demi supérieur (valeur par défaut) et 0,028 en demi pair ; le réglage change le résultat |
| Référence validée | Le seeder enregistre la référence v1.0 et son SHA-256 ; il est idempotent et ne modifie jamais une règle existante |
| Options | Une option sans code fiscal ne peut pas être activée (livraison, Q85) |
| Droits | Matrice du §2 par profil : l'admin lit les règles fiscales sans pouvoir les modifier ; l'utilisateur d'agence ne modifie rien |
| Audit | Création, modification et changement de statut journalisés, avec avant et après |
| Grilles | Tranches continues, sans doublon ; saisons sans chevauchement ; montants HT avec 3 décimales, jamais `float` |
| Logo (D21) | Servi par sa route, en lecture seule ; aucun autre fichier accessible ; aucun `storage:link` |

Commande de contrôle : `composer check`, avant chaque commit, avec son résultat cité.

## 7. Questions et valeurs par défaut

| Question | Valeur par défaut retenue (paramètre) |
| --- | --- |
| Q10 grilles et seuil de remise | Grille par catégorie et saison, commune aux 4 agences, surcharge par agence possible ; seuil de remise paramétrable |
| Q14 caution | Montant par catégorie, vide par défaut |
| Q32, Q33 décompte et tarif | Stockés en phase 3 ; appliqués en phase 4 (tranches de 24 h, tarif dégressif) |
| Q34 HT ou TTC | Saisie HT |
| Q41 délai de préparation | 60 min, surchargeable par catégorie |
| Q42 conducteurs | 21 ans, permis de 2 ans, surchargeables par catégorie |
| Q76 2FA des utilisateurs d'agence | Réglage modifiable par le super admin (recommandé : actif) |
| **Q85** (nouvelle) codes fiscaux manquants | Option « livraison » inactive ; kilomètres supplémentaires sans code jusqu'à la réponse (utile en phase 5 ou 6) |
| **Q86** (nouvelle) mode d'arrondi | Demi supérieur, réglage `billing.vat_rounding_mode`, à confirmer par le cabinet |

## 8. Risques

| Risque | Parade |
| --- | --- |
| **Budget sans marge** (19 j pour un seuil de 19,2 j) | Aucun ajout sans accord ; dépassement signalé dès qu'il apparaît, sans supprimer de test ni abaisser un contrôle |
| Recette non installée (réserve n° 1) | Le critère d'acceptation (saisie en recette) en dépend : installation à faire au plus tard avant la fin de la phase |
| Réponse à Q85 ou Q86 tardive | Valeurs par défaut paramétrables ; option « livraison » inactive en attendant |
| Grilles tarifaires plus complexes que prévu (Q10, Q33) | Chiffrage et signalement avant développement |

## 9. Ce dont j'ai besoin

1. **Validation du rapport de la phase 2 et de ce plan.**
2. Après validation : fusion de `phase/02-architecture-socle` dans `main`, puis création de `phase/03-parametrage`.
3. Q85 et Q86 posées au cabinet.
4. La recette installée avant la fin de la phase (réserve n° 1), pour la saisie du paramétrage par l'admin du siège.

## 10. Suivi de la charge (mis à jour à chaque fin de lot)

Charge estimée en jours-équivalents d'un développeur Laravel expert, comme en phase 2. Budget 19 j, seuil d'alerte 19,2 j.

| Lot | Prévu (j) | Estimé (j) | Cumul estimé (j) | Cumul prévu (j) | Commentaire |
| --- | ---: | ---: | ---: | ---: | --- |
| L1. Société et logo | 1,25 | 1 | 1 | 1,25 | Validé le 19/11/2026 ; la route du logo a réutilisé le disque privé et les en-têtes de la phase 2 |
| L2. Agences et lieux | 1 | 1 | 2 | 2,25 | Ressource des agences (création sans développement, code fixé à la création, siège non désactivable, pas de désactivation avec des utilisateurs actifs), lieux et horaires (`OpeningHours`), portée par agence |
| L3. Catégories et règles des conducteurs | 1,5 | 1,25 | 3,25 | 3,75 | Catégories, valeurs générales `reservations` (Q41, Q42) et `CategoryRules` (surcharges), saisie des montants à la française (`AmountInput`, `AmountField`, réutilisés en L4, L5 et L7) |
| L4. Saisons et grilles tarifaires | 3 | 2,5 | 5,75 | 6,75 | Saisons sans chevauchement, grilles communes ou par agence (Q10), tranches continues (`RateLines`), validité au jour loué (Q88, nouvelle), journal des tranches, `RateResolver` (prix d'un jour) prêt pour la phase 4 |
| L6. Paramètres fiscaux (avancé avant L5, dont les options dépendent de `tax_codes`) | 4,25 | 4 | 9,75 | 11 | Tables et seeder du référentiel v1.0, `TaxRuleResolver`, `TaxCalculator` (exemple §5 exact, Q86), versions datées sous verrou (test à deux processus), activation, écrans (super admin en écriture, admin en lecture), rapport (écran, XLSX, imprimable), garde-fou à quatre cas, test d'architecture (aucun libellé) |
| L5. Options | 1 | 0,75 | 10,5 | 12 | Options liées à un code fiscal choisi ; activation refusée sans code applicable ni prix ; options du cahier créées inactives et sans prix, « livraison » sans code (Q85) ; test « assurance » sur le libellé |
| L7. Facturation et paiements | 0,75 | 0,5 | 11 | 12,75 | Modes de paiement (référence exigée ou non, code fixé), conditions de paiement en réglage `billing.payment_terms_days` (Q89, nouvelle) |
| L9. Fournisseurs (avancé avant L8) | 0,75 | 0,5 | 11,5 | 13,5 | Fiche simple (G-05), super admin et admin |
| L8. Réglages | 2,25 | 1,75 | 13,25 | 15,75 | Six pages pour le super admin (réservation, tarification, facturation, approbations, notifications A-09, sécurité Q76) ; `UpdateSettings` (catalogue des clés, validation, journal avant/après) ; valeurs vides pour A-06 et A-09 non décidées (Q90, nouvelle). **Constat** : aucun fichier `lang/fr`, les messages de validation de Laravel s'affichent en clés brutes (`validation.required`) depuis la phase 2 ; correction prévue en L10 |
| L10. Droits, audit et tests transverses | 1,25 | 1 | 14,25 | 17 | Matrice du §2 sur les 12 écrans et les 3 profils, policies sans suppression, journal sur tous les modèles modifiables, recherche globale fermée ; traductions `lang/fr` (validation, authentification, mots de passe, pagination), parité testée avec le framework |
| L11. Données de référence et guide de saisie | 0,5 | 0,25 | 14,5 | 17,5 | Seeders (agences, référentiel fiscal, options, modes de paiement) appelés par `DatabaseSeeder` ; `docs/guide_saisie_parametrage.md`. L'appui à la saisie en recette attend l'installation de la recette (réserve n° 1) |
| L12. Rapport | 0,5 | 0,5 | 15 | 18 | `docs/rapports/phase_3.md`, au statut « en attente de la saisie en recette » ; décisions D28 à D34 |

## Annexe — décisions reprises

| Élément | Origine |
| --- | --- |
| Route publique du logo (0,25 j) | Rééquilibrage de la phase 2, validé le 17/11/2026 |
| Réglages de sécurité (Q76) | Phase 2, compris dans L8 sans charge supplémentaire |
| Paramètres fiscaux et garde-fou (2,75 j) | Demande d'évolution du cabinet, intégrée au lot 1 le 18/11/2026, révisée selon le référentiel v1.0 validé |
| Statuts CONFIRMED, CONDITIONAL, PENDING_DOCUMENTATION | Référentiel v1.0, §14 |
