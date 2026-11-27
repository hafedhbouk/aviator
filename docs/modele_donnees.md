# Modèle de données — Aviator

Version de la phase 2 (lot 2), 18/11/2026. Il complète `docs/architecture.md` §3 et §4 (rôle des tables et schémas entité-relation) avec les colonnes, les types, les index et les contraintes.

**Migrées en phase 2 (D18)** : les tables marquées **P2**. Les autres sont migrées dans la phase indiquée ; leur définition peut encore être ajustée selon les réponses aux questions ouvertes, et tout écart sera signalé dans le rapport de la phase concernée.

## 1. Conventions

| Sujet | Règle |
| --- | --- |
| Moteur et interclassement | InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, imposés par la connexion `mariadb` (D12) |
| Noms | Tables et colonnes en anglais, au pluriel et en `snake_case` ; pivots par ordre alphabétique (`customer_segment_members`…) |
| Clé primaire | `id` `BIGINT UNSIGNED AUTO_INCREMENT` |
| Clés étrangères | `BIGINT UNSIGNED`, toujours avec une contrainte. Par défaut `ON DELETE RESTRICT` : un document métier n'est jamais supprimé en cascade (RG-11). `CASCADE` seulement pour les lignes qui n'existent pas sans leur parent (lignes de facture, pivots) |
| Montants | `DECIMAL(15,3)` (dinar à 3 décimales), suffixe `_amount` ; jamais de `FLOAT` ni de `DOUBLE` ; calculs avec `brick/math` (cast `MoneyCast`) |
| Taux | `DECIMAL(7,4)` (exemple : 19,0000 %), uniquement à partir de règles validées (règle 9 du prompt maître) |
| Statuts et types | `VARCHAR(32)`, adossés à une énumération PHP (pas de type `ENUM` MariaDB, coûteux à faire évoluer) |
| Dates métier | `DATETIME` (heure locale `Africa/Tunis`) ou `DATE` ; `created_at` et `updated_at` en `TIMESTAMP` (convention Laravel) |
| Kilométrage | `INT UNSIGNED` |
| Données chiffrées | `TEXT` (cast `encrypted` de Laravel, clé `APP_KEY`) |
| Empreintes de recherche | `CHAR(64)` (HMAC-SHA256 en hexadécimal, clé `AVIATOR_BLIND_INDEX_KEY`), indexées (D8) |
| Suppression | Pas de `SoftDeletes` sur les documents validés : une annulation est un statut (RG-11). Clients : anonymisation (`anonymized_at`), pas de suppression |
| Agence | `agency_id` sur toute donnée qui appartient à une agence (✔ dans `docs/architecture.md`), avec `AgencyScope` |
| Auteur | `created_by` (FK `users`, nullable) sur les documents métier |
| Fonctionnalités interdites (MySQL 8) | Index fonctionnels, index sur JSON, `joinLateral`, interclassements `utf8mb4_0900_*` |

Abréviations des tableaux : **NN** = non nul ; **N** = nullable ; **U** = unique ; **I** = index ; **FK** = clé étrangère.

---

## 2. Socle (phase 2)

### `agencies` — P2

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK | |
| `code` | VARCHAR(8) | NN, U | `DJE`, `SFX`, `SOU`, `TUN` |
| `name` | VARCHAR(100) | NN | « Djerba », « Sfax »… |
| `is_head_office` | BOOLEAN | NN, défaut 0 | Djerba = 1 ; une seule agence siège (contrôle applicatif) |
| `city` | VARCHAR(100) | NN | |
| `address` | VARCHAR(255) | N | |
| `phone` | VARCHAR(32) | N | Téléphone de l'agence |
| `email` | VARCHAR(255) | N | |
| `is_active` | BOOLEAN | NN, défaut 1 | Ajout d'agences sans développement (A-02) |
| `created_at`, `updated_at` | TIMESTAMP | N | |

### `users` — P2 (table du squelette, complétée)

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK | |
| `name` | VARCHAR(255) | NN | |
| `email` | VARCHAR(255) | NN, U | |
| `email_verified_at` | TIMESTAMP | N | |
| `password` | VARCHAR(255) | NN | Hachage bcrypt (D19) |
| `agency_id` | BIGINT UNSIGNED | N, FK `agencies` RESTRICT, I | Agence par défaut ; nulle pour le super admin et l'admin |
| `is_active` | BOOLEAN | NN, défaut 1 | Un compte désactivé ne peut plus se connecter (J-01) |
| `last_login_at` | DATETIME | N | |
| `app_authentication_secret` | TEXT | N | Secret TOTP de la 2FA, chiffré (Filament) |
| `app_authentication_recovery_codes` | TEXT | N | Codes de récupération, chiffrés (Filament) |
| `remember_token` | VARCHAR(100) | N | |
| `created_at`, `updated_at` | TIMESTAMP | N | |

### `agency_assignments` — P2 (lot 5 ; remplace `agency_user`, dont la migration a été fusionnée avant tout déploiement)

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK | |
| `user_id` | BIGINT UNSIGNED | NN, FK `users` CASCADE | Utilisateur d'agence remplaçant |
| `agency_id` | BIGINT UNSIGNED | NN, FK `agencies` RESTRICT | Agence où il remplace |
| `starts_on` | DATE | NN | Premier jour inclus |
| `ends_on` | DATE | NN | Dernier jour inclus (≥ `starts_on`, contrôle applicatif) |
| `created_by` | BIGINT UNSIGNED | N, FK `users` RESTRICT | Super admin qui a créé l'affectation |
| `created_at`, `updated_at` | TIMESTAMP | N | |

Index (`user_id`, `starts_on`, `ends_on`). **Remplacements à durée limitée (Q69)** : l'accès à l'agence n'est actif qu'entre `starts_on` et `ends_on` inclus, appréciés dans le fuseau `Africa/Tunis`. Plusieurs périodes par agence sont possibles, sans chevauchement. Seul le super admin crée ou révoque une affectation, et chaque opération est journalisée. L'agence par défaut reste `users.agency_id`.

### `sequences` — P2

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK | |
| `key` | VARCHAR(64) | NN, U | Exemples : `reservation:2026`, `contract:2026`. La clé des factures sera fixée par la règle fiscale (Q11, Q30) |
| `last_value` | BIGINT UNSIGNED | NN, défaut 0 | Incrémenté sous `SELECT … FOR UPDATE` (RG-02, RG-07) |
| `created_at`, `updated_at` | TIMESTAMP | N | |

### `named_locks` — P4 (lot L1, D38)

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `name` | VARCHAR(64) | PK | Nom du verrou, par exemple `setup:seasons` ; ligne créée à la première utilisation |
| `created_at` | TIMESTAMP | N | |

Verrous de sérialisation en base : `App\Support\Locking\DatabaseLock` verrouille la ligne par `SELECT … FOR UPDATE` jusqu'à la fin de la transaction. Aucune donnée métier. Les verrous de cache sont interdits dans `app/` (test d'architecture `tests/Arch/LockingTest.php`).

### Tables des paquets — P2

| Table | Origine | Remarque |
| --- | --- | --- |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | `spatie/laravel-permission` 8 | Sans équipes (`teams = false`) ; garde `web` |
| `activity_log` | `spatie/laravel-activitylog` 4 | Colonnes `log_name` (`audit`, `security`, `customer_access`), `subject_*`, `causer_*`, `properties` (JSON : avant / après), `event`, `batch_uuid` |
| `settings` | `spatie/laravel-settings` 3 | `group`, `name`, `locked`, `payload` (JSON) ; groupes créés à partir de la phase 3 |
| `notifications` | Laravel | Centre de notifications (J-13) |
| `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Laravel | Sessions, cache et file en base (mutualisé) |

### `customers` — P2 (structure seulement, écrans en phase 7)

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK | |
| `type` | VARCHAR(32) | NN | `individual` ou `company` |
| `first_name`, `last_name` | VARCHAR(100) | N | Obligatoires pour un particulier (contrôle applicatif) |
| `company_name` | VARCHAR(255) | N | Obligatoire pour une entreprise |
| `tax_id` | VARCHAR(32) | N | Matricule fiscal (entreprise) ; seule donnée client, avec le nom, des exports comptables (D13) |
| `search_name` | VARCHAR(255) | NN, I | Nom normalisé (minuscules, sans accents ni espaces multiples) pour la recherche exacte (K-04) |
| `name_key` | VARCHAR(255) | NN, I, défaut `''` | Nom complet aux mots triés (D24 : recherche exacte du nom complet dans n'importe quel ordre) ; ajouté au lot L8 de la phase 4 |
| `birth_date` | DATE | N | |
| `nationality` | CHAR(2) | N | Code ISO 3166-1 |
| `phone` | VARCHAR(32) | N | **Coordonnée** (RG-14) |
| `phone_search` | VARCHAR(32) | N, I | Téléphone au format international `+216XXXXXXXX` (D24, lot L8 de la phase 4) |
| `email` | VARCHAR(255) | N | **Coordonnée** |
| `address`, `city` | VARCHAR(255), VARCHAR(100) | N | **Coordonnées** |
| `country` | CHAR(2) | N | |
| `id_document_type` | VARCHAR(32) | N | `cin`, `passport`, `residence_permit` |
| `id_document_number` | TEXT | N | **Chiffré** (D8) |
| `id_document_hash` | CHAR(64) | N, I | Empreinte HMAC du numéro normalisé (recherche exacte, doublons K-05) |
| `id_document_expires_at` | DATE | N | |
| `origin_agency_id` | BIGINT UNSIGNED | NN, FK `agencies` RESTRICT, I | Agence d'origine (K-01) ; **sans** `AgencyScope` (voir `docs/architecture.md` §8.3) |
| `acquisition_source` | VARCHAR(32) | N | Source d'acquisition (K-01) |
| `referrer_customer_id` | BIGINT UNSIGNED | N, FK `customers` RESTRICT | Parrain (K-13) |
| `created_by` | BIGINT UNSIGNED | N, FK `users` RESTRICT | |
| `anonymized_at` | DATETIME | N | Anonymisation (L-05, Q20) |
| `created_at`, `updated_at` | TIMESTAMP | N | |

Le permis et les dates d'obtention vont dans `drivers` (phase 5) : le client particulier y figure comme conducteur (Q47). La recherche par permis passe par `drivers.license_hash`.

---

## 3. Paramétrage (phase 3)

### `company`

`id` ; `legal_name` VARCHAR(255) NN ; `trade_name` VARCHAR(255) N ; `tax_id` VARCHAR(32) N ; `trade_register` VARCHAR(64) N ; `address` VARCHAR(255) N ; `city` VARCHAR(100) N ; `phone`, `email` N ; `currency` CHAR(3) NN défaut `TND` ; `logo_path` VARCHAR(255) N (disque `private`, dossier `public-files/company`, PNG, JPEG ou WebP ; servi par la route `/fichiers/public/{chemin}`, D21) ; `invoice_legal_mentions` TEXT N (**rempli seulement à partir d'une règle validée** ; modifiable par le super admin seulement) ; horodatages. Une seule ligne, d'identifiant 1 (`Company::SINGLETON_ID`), créée au premier enregistrement. Réalisé en phase 3, lot L1.

### `agency_locations`

`id` ; `agency_id` FK RESTRICT, I ; `name` VARCHAR(100) NN ; `address` VARCHAR(255) N ; `is_pickup` BOOLEAN NN défaut 1 ; `is_return` BOOLEAN NN défaut 1 ; `opening_hours` JSON N (clés `monday` à `sunday`, chacune une liste de 0 à 3 plages `{open, close}` en `HH:MM`, triées, sans chevauchement ; jour sans plage = fermé ; null = horaires non renseignés ; heure de Tunis ; validé par `App\Domain\Setup\OpeningHours` ; pas de recherche dans le JSON) ; `is_active` BOOLEAN NN défaut 1 ; horodatages ; **U (`agency_id`, `name`)**. Soumise à `AgencyScope` ; aucun lieu supprimé (désactivation). Réalisé en phase 3, lot L2.

### `vehicle_categories`

`id` ; `code` VARCHAR(16) NN U ; `name` VARCHAR(100) NN ; `description` TEXT N ; `deposit_amount` DECIMAL(15,3) N (Q14) ; `min_driver_age` TINYINT UNSIGNED N (Q42) ; `min_license_years` TINYINT UNSIGNED N (Q42) ; `included_km_per_day` INT UNSIGNED N (Q43) ; `extra_km_amount` DECIMAL(15,3) N ; `insurance_excess_amount` DECIMAL(15,3) N (Q45) ; `preparation_buffer_minutes` SMALLINT UNSIGNED N (Q41) ; `sort_order` SMALLINT NN défaut 0 ; `is_active` BOOLEAN NN défaut 1 ; horodatages. Vide = valeur générale (`reservations.min_driver_age` 21, `reservations.min_license_years` 2, `reservations.preparation_buffer_minutes` 60 : `App\Settings\ReservationSettings`) ou « non fixé » (caution, kilométrage illimité, prix du km, franchise sans plafond) ; valeurs effectives : `App\Domain\Setup\CategoryRules`. Aucune catégorie supprimée (désactivation). Réalisé en phase 3, lot L3.

### `seasons`

`id` ; `name` VARCHAR(100) NN ; `starts_on` DATE NN ; `ends_on` DATE NN ; I (`starts_on`, `ends_on`) ; horodatages. Bornes incluses ; pas de chevauchement (contrôle applicatif sous verrou, `SaveSeason`). Supprimable seulement si aucune grille ne l'utilise. Réalisé en phase 3, lot L4.

### `rate_plans` et `rate_plan_lines`

- `rate_plans` : `id` ; `vehicle_category_id` FK RESTRICT ; `season_id` FK RESTRICT ; `agency_id` FK RESTRICT **N** (grille commune si nulle, Q10) ; `valid_from` DATE NN ; `valid_to` DATE N ; `is_active` BOOLEAN NN ; horodatages ; I (`vehicle_category_id`, `season_id`, `agency_id`, `valid_from`).
- `rate_plan_lines` : `id` ; `rate_plan_id` FK CASCADE ; `min_days` SMALLINT UNSIGNED NN ; `max_days` SMALLINT UNSIGNED N ; `daily_amount` DECIMAL(15,3) NN (HT, Q34) ; horodatages ; U (`rate_plan_id`, `min_days`).
- Réalisé en phase 3, lot L4 (index de recherche nommé `rate_plans_lookup_index`, nom par défaut trop long pour MariaDB). Règles (`SaveRatePlan`, `RateLines`) : une seule grille **active** par catégorie, saison et agence sur une même période de validité (contrôle sous verrou de la catégorie) ; validité appliquée au **jour loué** (Q88) ; tranches continues depuis 1 jour, dernière sans limite, prix > 0 ; tranches remplacées ensemble, journalisées sur la grille (`rate_plan_lines_updated`, avant et après) ; aucune grille supprimée. Prix d'un jour : `App\Domain\Setup\RateResolver` (grille de l'agence, sinon grille commune ; aucun prix plutôt que zéro).

### `options`

`id` ; `code` VARCHAR(32) NN U ; `name` VARCHAR(100) NN ; `pricing_unit` VARCHAR(32) NN (`per_day`, `per_rental`) ; `amount` DECIMAL(15,3) NN (HT) ; `max_amount_per_rental` DECIMAL(15,3) N ; `tax_code_id` FK `tax_codes` N (code fiscal ; une option sans code ne peut pas être activée) ; `is_active` BOOLEAN NN ; horodatages. Réalisé en phase 3, lot L5 : `amount` **N** (prix saisi par l'admin) et `is_active` défaut 0 ; une option ne s'active qu'avec un code fiscal dont la règle est applicable (ni inactive ni PENDING_DOCUMENTATION) et un prix HT > 0 (`SaveOption`) ; `OptionSeeder` crée les 5 options du cahier inactives et sans prix, « livraison » sans code (Q85) ; aucune option supprimée.

### Paramètres fiscaux (référentiel fiscal v1.0 validé ; conception : `docs/plans/phase_3.md` §3)

Ils remplacent la table `taxes` prévue en phase 1. Statuts de validation (référentiel, §14) : **`CONFIRMED`** (règle validée et documentée), **`CONDITIONAL`** (applicable selon la nature réelle de l'opération, avec ses justificatifs), **`PENDING_DOCUMENTATION`** (pièce ou confirmation nécessaire avant activation ; jamais applicable en production).

- `fiscal_references` : `id` ; `version` VARCHAR(16) NN U ; `title` VARCHAR(255) NN ; `file_name` VARCHAR(255) NN ; `sha256` CHAR(64) NN ; `validation_source` VARCHAR(255) NN ; `validated_at` DATE NN ; `attestation_file_name` VARCHAR(255) N ; horodatages. Une ligne par version validée, jamais modifiée.
- `tax_codes` : `id` ; `code` VARCHAR(48) NN U (les 15 codes du référentiel) ; `label` VARCHAR(100) NN ; `nature` VARCHAR(16) NN (`taxable`, `exempt`, `out_of_scope`) ; `requires_justification` BOOLEAN NN ; `legal_reference` VARCHAR(255) N ; `description` TEXT N ; horodatages. Liste fermée, créée par un seeder.
- `tax_rules` : `id` ; `tax_code_id` FK RESTRICT ; `rate` DECIMAL(7,4) N (nul si non taxable) ; `valid_from` DATE NN ; `valid_to` DATE N ; `validation_status` VARCHAR(32) NN ; `fiscal_reference_id` FK N ; `is_active` BOOLEAN NN ; `created_by` FK N ; horodatages ; I (`tax_code_id`, `valid_from`). Jamais modifiée sur place : un changement crée une nouvelle version ; aucun chevauchement pour un même code.
- `stamp_duty_rules` : `id` ; `rule_code` VARCHAR(48) NN ; `description` VARCHAR(255) NN ; `amount` DECIMAL(15,3) NN ; `applicability` VARCHAR(255) NN ; `exemption_condition` VARCHAR(255) N ; `legal_reference` VARCHAR(255) N ; `effective_from` DATE NN ; `effective_to` DATE N ; `validation_status` VARCHAR(32) NN ; `fiscal_reference_id` FK N ; `is_active` BOOLEAN NN ; horodatages. Règle de base : 1,000 DT par facture (CONFIRMED) ; exonérations de l'article 118 en `PENDING_DOCUMENTATION`, inactives.
- **Réalisé en phase 3, lot L6.** Écarts de taille : `tax_codes.code` et `stamp_duty_rules.rule_code` VARCHAR(64), `tax_codes.label` et `legal_reference` VARCHAR(255) NN, `validation_source`, `description`, `applicability` et `exemption_condition` VARCHAR(500) ; `stamp_duty_rules.created_by` FK N ajouté. Valeurs initiales : `FiscalReferenceSeeder` (référence v1.0 et SHA-256, 15 codes, 15 règles prenant effet le 18/11/2026, timbre `STANDARD` 1,000 DT, exonérations `EXEMPT_ART118_NOT_ACCEPTED`, `EXEMPT_ART118_NOT_PAID`, `EXEMPT_ART118_EXPORT` inactives) ; idempotent, ne modifie jamais l'existant ; lancé par `db:seed` à la première installation. Les modèles interdisent la modification sur place (seuls `valid_to`/`effective_to` et `is_active` changent) et la suppression ; référence et codes immuables. Services : `App\Domain\Tax\TaxRuleResolver` (code exact, refuse PENDING_DOCUMENTATION, inactive, date sans règle), `TaxCalculator` (§5, réglage `billing.vat_rounding_mode`, Q86), `StampDutyResolver`, `FiscalGuard` (ligne « Règles fiscales » de `aviator:check-environment`) ; actions `AddTaxRuleVersion`, `AddStampDutyRuleVersion`, `SetFiscalRuleActive` (super admin, journalisées). Rapport de configuration fiscale : `App\Domain\TaxReporting` (écran, XLSX, version imprimable avec zone de signature).

### `payment_methods`

`id` ; `code` VARCHAR(32) NN U (`cash`, `cheque`, `card`, `transfer`, `bill_of_exchange`) ; `name` VARCHAR(100) NN ; `requires_reference` BOOLEAN NN ; `is_active` BOOLEAN NN ; horodatages. Réalisé en phase 3, lot L7 : code fixé à la création, aucune suppression ; `PaymentMethodSeeder` (référence exigée sauf pour les espèces). Conditions de paiement : réglage `billing.payment_terms_days` (0 = comptant, Q89).

### `suppliers`

`id` ; `type` VARCHAR(32) NN (`garage`, `insurer`, `leasing_company`, `bank`, `other`) ; `name` VARCHAR(255) NN ; `tax_id` VARCHAR(32) N ; `phone`, `email`, `address` N ; `is_active` BOOLEAN NN ; horodatages ; I (`type`, `name`). Réalisé en phase 3, lot L9 : super admin et admin seulement, aucune suppression, matricule fiscal sans format imposé.

---

## 4. Parc, financement et maintenance (phases 4 et 8)

### `vehicles` (phase 4) — réalisé au lot L2

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK | |
| `internal_code` | VARCHAR(16) | NN, U | Identifiant interne (B-01) |
| `plate_number` | VARCHAR(16) | NN, U | Immatriculation (B-01) |
| `vin` | VARCHAR(32) | NN, U | Numéro de châssis |
| `vehicle_category_id` | BIGINT UNSIGNED | NN, FK RESTRICT, I | |
| `brand`, `model` | VARCHAR(64) | NN | |
| `year` | SMALLINT UNSIGNED | NN | |
| `color` | VARCHAR(32) | N | |
| `fuel` | VARCHAR(32) | NN | `petrol`, `diesel`, `hybrid`, `electric` |
| `transmission` | VARCHAR(32) | NN | `manual`, `automatic` |
| `agency_id` | BIGINT UNSIGNED | NN, FK RESTRICT, I | Agence de rattachement (B-04) |
| `current_agency_id` | BIGINT UNSIGNED | NN, FK RESTRICT, I | Localisation actuelle |
| `status` | VARCHAR(32) | NN, I | Dérivé par `VehicleStatusService` (§7 de l'architecture) |
| `mileage` | INT UNSIGNED | NN, défaut 0 | Dernier relevé ; jamais en baisse sans correction tracée (B-03) |
| `acquired_on` | DATE | N | B-02 |
| `acquisition_supplier_id` | BIGINT UNSIGNED | N, FK `suppliers` | |
| `purchase_amount` | DECIMAL(15,3) | N | |
| `financing_mode` | VARCHAR(32) | NN | `cash`, `credit`, `leasing` |
| `initial_contribution_amount` | DECIMAL(15,3) | N | Apport (Q13) |
| `planned_holding_months` | SMALLINT UNSIGNED | N | Rentabilité (Q19) |
| `retired_on` | DATE | N | Sortie du parc (B-10) |
| `retirement_reason` | VARCHAR(32) | N | `sale`, `scrap`, `lease_return` |
| `disposal_amount` | DECIMAL(15,3) | N | Prix de cession, pour information |
| `created_at`, `updated_at` | TIMESTAMP | N | |

### Autres tables du parc (phase 4)

| Table | Colonnes principales | Contraintes clés |
| --- | --- | --- |
| `vehicle_status_logs` | `vehicle_id` FK, `from_status`, `to_status` VARCHAR(32), `cause_type`/`cause_id` (polymorphe), `user_id` FK N, `occurred_at` DATETIME | I (`vehicle_id`, `occurred_at`) ; ajout seul (modification et suppression refusées par le modèle) ; colonne `reason` VARCHAR(255) N ajoutée ; réalisé au lot L5 |
| `vehicle_mileage_logs` | `vehicle_id` FK, `previous_mileage`, `mileage` INT UNSIGNED, `source_type`/`source_id` N, `is_correction` BOOLEAN, `reason` VARCHAR(255) N, `user_id` FK N, `recorded_at` DATETIME | I (`vehicle_id`, `recorded_at`) ; ajout seul ; baisse seulement par correction motivée (10 caractères au moins), relevés chronologiques ; `vehicles.mileage` écrit uniquement par `MileageLedger` ; réalisé au lot L4 |
| `vehicle_documents` | `vehicle_id` FK, `type` (`insurance`, `technical_inspection`, `road_tax`, `registration`), `supplier_id` FK N, `reference` VARCHAR(64) N, `valid_from` DATE N, `valid_until` DATE N, `amount` DECIMAL(15,3) N, `file_path` N (privé), `created_by` FK `users` N | I (`vehicle_id`, `type`, `valid_until`) ; réalisé au lot L2 ; échéance obligatoire sauf carte grise ; aucune suppression (historique des échéances) |
| `vehicle_blocks` | `vehicle_id` FK, `agency_id` FK (agence du document), `starts_at`, `ends_at` DATETIME (délai de préparation inclus), `buffer_minutes` SMALLINT, `source_type`/`source_id`, `active` BOOLEAN, `released_at` DATETIME N | **I (`vehicle_id`, `active`, `starts_at`, `ends_at`)** ; **U (`source_type`, `source_id`)** (une occupation par source, déplacée ou libérée, jamais supprimée) ; écrite uniquement par `BlockRegistry` sous verrou du véhicule ; réalisé au lot L9 |
| `vehicle_financings` | `vehicle_id` FK, `type` (`leasing`, `credit`), `supplier_id` FK, `reference` VARCHAR(64), `starts_on`, `ends_on` DATE, `initial_payment_amount`, `monthly_amount_excl_tax`, `monthly_amount_incl_tax`, `residual_value_amount`, `principal_amount` (crédit), `interest_rate` DECIMAL(7,4) N, `purchase_option_on` DATE N, `outcome` (`option_exercised`, `returned`) N | I (`vehicle_id`) ; **réalisé au lot L6** : `first_due_on` DATE, `installments_count` SMALLINT, `outcome_on` DATE N, `created_by` FK N ajoutés ; `outcome` : `option_exercised`, `returned`, `repaid` ; un seul financement en cours par véhicule (contrôle sous verrou) |
| `financing_installments` | `vehicle_financing_id` FK CASCADE, `sequence` SMALLINT, `due_date` DATE, `amount_excl_tax`, `amount_incl_tax`, `interest_amount` N, `charge_month` DATE (1er du mois, RG-17), `paid_at` DATETIME N | **U (`vehicle_financing_id`, `sequence`)** ; I (`charge_month`) ; **réalisé au lot L6** : `paid_at` remplacé par `paid_on` DATE ; charge = loyer HT (leasing) ou intérêts (crédit, Q59), au mois de l'échéance |

### Maintenance et dépenses (phase 8)

| Table | Colonnes principales | Contraintes clés |
| --- | --- | --- |
| `maintenance_plans` | `vehicle_id` FK, `label`, `interval_km` INT N, `interval_months` SMALLINT N, `last_done_at` DATE N, `last_done_mileage` INT N | |
| `maintenance_orders` | `vehicle_id` FK, `agency_id` FK, `type` (`preventive`, `repair`, `inspection`), `supplier_id` FK N, `planned_start_at`, `planned_end_at`, `started_at`, `ended_at` DATETIME, `amount` DECIMAL(15,3) N, `status`, `notes` TEXT N | I (`vehicle_id`, `status`) |
| `incidents` | `vehicle_id` FK, `contract_id` FK N, `occurred_at` DATETIME, `description` TEXT, `third_party` TEXT N, `insurer_supplier_id` FK N, `report_reference` N, `estimated_amount`, `indemnified_amount` DECIMAL(15,3) N, `status` | I (`vehicle_id`, `occurred_at`) |
| `vehicle_unavailabilities` | `vehicle_id` FK, `reason` VARCHAR(32), `starts_at`, `ends_at` DATETIME N, `notes` | Crée un `vehicle_block` |
| `expenses` | `vehicle_id` FK, `agency_id` FK, `supplier_id` FK N, `nature` (liste fermée **sans** loyer de leasing), `amount_excl_tax`, `amount_incl_tax` DECIMAL(15,3), `charge_month` DATE NN (RG-17), `spent_on` DATE, `reference` N, `file_path` N, `created_by` FK | I (`vehicle_id`, `charge_month`) ; contrôle des doublons (fournisseur, référence, montant) |

---

## 5. Réservations, contrats et états des lieux (phases 4 et 5)

### `reservations` (phase 4) — réalisé au lot L11

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK | |
| `number` | VARCHAR(32) | NN, U | `RES-AAAA-NNNNN` (Q49), via `sequences` |
| `agency_id` | BIGINT UNSIGNED | NN, FK, I | Agence de départ (RG-13) |
| `return_agency_id` | BIGINT UNSIGNED | NN, FK | Égale à l'agence de départ tant que Q3 = non |
| `pickup_location_id`, `return_location_id` | BIGINT UNSIGNED | N, FK `agency_locations` | |
| `customer_id` | BIGINT UNSIGNED | NN, FK, I | |
| `vehicle_category_id` | BIGINT UNSIGNED | NN, FK | |
| `vehicle_id` | BIGINT UNSIGNED | N, FK, I | Affectation au plus tard au départ (C-05) |
| `starts_at`, `ends_at` | DATETIME | NN | I (`vehicle_category_id`, `starts_at`, `ends_at`) |
| `status` | VARCHAR(32) | NN, I | `draft`, `pending`, `confirmed`, `cancelled`, `expired`, `no_show`, `converted` (Q31) |
| `channel` | VARCHAR(16) | NN, défaut `counter` | `online` au lot 2 |
| `quote` | JSON | NN | Devis figé (`PriceQuote`) ; pas de recherche dans le JSON |
| `estimated_total_amount` | DECIMAL(15,3) | NN | HT |
| `discount_amount` | DECIMAL(15,3) | NN, défaut 0 | |
| `discount_approval_id` | BIGINT UNSIGNED | N, FK `approval_requests` | RG-10 |
| `expires_at` | DATETIME | N | C-09, RG-21 |
| `cancellation_reason` | VARCHAR(255) | N | Obligatoire à l'annulation (C-03) |
| `override_approval_id` | BIGINT UNSIGNED | N, FK `approval_requests` | Dérogation de liste noire (RG-15) |
| `created_by` | BIGINT UNSIGNED | N, FK `users` | |
| `created_at`, `updated_at` | TIMESTAMP | N | |

`reservation_options` : `reservation_id` FK CASCADE, `option_id` FK, `quantity` SMALLINT, `unit_amount` DECIMAL(15,3), `total_amount` DECIMAL(15,3) ; U (`reservation_id`, `option_id`).

**Réalisé au lot L11 de la phase 4.** Écarts au tableau ci-dessus : `cancelled_at` DATETIME N ajouté ; `discount_approval_id` et `override_approval_id` sans clé étrangère jusqu'à `approval_requests` (phase 5, D36) ; index (`agency_id`, `status`, `starts_at`) ajouté. Numéro `RES-AAAA-NNNNN` attribué dans la transaction de création (aucun numéro consommé par un refus). Toute écriture passe par `SaveReservation`, `AssignVehicle` et `CloseReservation`, sous verrou de la catégorie puis du véhicule (`ReservationHold`). Le réglage `reservations.reserved_status_window_hours` (Q28, 24 h) est ajouté.

### `contracts` (phase 5)

`id` ; `number` VARCHAR(32) NN U (`CTR-AAAA-NNNNN`) ; `agency_id` FK NN I ; `return_agency_id` FK NN ; `reservation_id` FK N U ; `vehicle_id` FK NN I ; `customer_id` FK NN I ; `starts_at`, `ends_at` DATETIME NN (prévu) ; `departed_at`, `returned_at` DATETIME N (réel) ; `status` VARCHAR(32) NN I (`draft`, `active`, `returned`, `closed`, `cancelled`) ; `mileage_policy` VARCHAR(32) NN (`unlimited`, `daily_allowance`) ; `included_km_per_day` INT N ; `extra_km_amount` DECIMAL(15,3) N ; `fuel_policy` VARCHAR(32) NN ; `rental_amount`, `options_amount`, `discount_amount` DECIMAL(15,3) NN ; `discount_approval_id` FK N ; `closed_at` DATETIME N ; `created_by` FK ; horodatages.

**`contracts` réalisé au lot L2 de la phase 5.** Écarts : `vehicle_category_id` FK NN ajouté (catégorie du devis) ; `total_amount` DECIMAL(15,3) NN (total HT du devis figé) ; `quote` JSON NN (devis figé repris de la réservation ou calculé au comptoir) ; `daily_rate_amount` DECIMAL(15,3) NN (tarif du dernier jour du devis, base de la prolongation et du retard, D48) ; `fuel_eighths_required` TINYINT N (niveau attendu, posé au départ) ; `deposit_expected_amount` DECIMAL(15,3) N (caution prévue de la catégorie, encaissée en phase 6) ; `discount_approval_id` et `override_approval_id` FK `approval_requests` ; `cancellation_reason`, `cancelled_at` N ; index (`agency_id`, `status`, `starts_at`), (`vehicle_id`, `status`), (`customer_id`, `starts_at`). Numéro `CTR-AAAA-NNNNN` attribué dans la transaction de création ; jamais supprimé (`HasSequenceNumber`). `contract_drivers` : plus `checks` JSON N (résultat des contrôles du permis, lot L3) et horodatages. `contract_options` : identifiant propre, U (`contract_id`, `option_id`). Clé étrangère posée sur `customer_recommendations.contract_id`. **Lot L4** : `contracts.fuel_tank_liters` SMALLINT N, `fuel_liter_amount`, `fuel_service_amount`, `late_hour_amount`, `insurance_excess_amount` DECIMAL(15,3) N, figés à la création ; `vehicles.fuel_tank_liters` SMALLINT N (Q92) ; `vehicle_categories.late_hour_amount` DECIMAL(15,3) N (Q91) ; réglages `pricing.fuel_liter_amount` et `pricing.fuel_service_amount` (Q44), vides par défaut. Écriture uniquement par `App\Domain\Rental` (`OpenContract`, `CancelContract`, `RemoveContractDiscount`).

| Table | Colonnes principales | Contraintes clés |
| --- | --- | --- |
| `drivers` | `customer_id` FK, `first_name`, `last_name`, `birth_date` DATE, `license_number` TEXT (chiffré), `license_hash` CHAR(64) I, `license_issued_on`, `license_expires_on` DATE, `license_country` CHAR(2), `id_document_number` TEXT (chiffré) N, `id_document_hash` CHAR(64) N I | Recherche exacte par permis (K-04) ; `created_by` FK N ajouté ; permis et pièce chiffrés, couverts par `aviator:reencrypt-secrets` et `aviator:rehash-blind-index` ; **réalisé au lot L8 de la phase 4** (avancé de la phase 5) |
| `contract_drivers` | `contract_id` FK CASCADE, `driver_id` FK, `is_primary` BOOLEAN | PK (`contract_id`, `driver_id`) ; un seul conducteur principal |
| `contract_options` | `contract_id` FK CASCADE, `option_id` FK, `quantity`, `unit_amount`, `total_amount` | |
| `contract_charges` **[ajout]** | **Réalisé au lot L8** : `contract_id` FK, `nature` (`late_return`, `extra_km`, `fuel`, `damage`), `label`, `quantity` DECIMAL(10,3), `unit_amount`, `amount` DECIMAL(15,3), `calculation` JSON (paramètres utilisés), `inspection_damage_id` FK N, `damage_file_id` N (clé étrangère au lot L9), `created_by` FK N | Ajout seul, aucun code fiscal (choisi à la facture, phase 6, N2) |
| `damage_files` **[ajout]**, `damage_file_documents` **[ajout]** | **Réalisé au lot L9** : dossier `contract_id` FK, `inspection_damage_id` FK **U**, `status` (`open`, `complete`), `notes` N, `completed_at`, `completed_by` N ; pièces `damage_file_id` FK, `kind` (`report`, `quote`, `repair_invoice`, `other`), `path` privé, `sha256`, `size_bytes`, `uploaded_by` | Jamais supprimés ; pièces en ajout seul ; clé étrangère posée sur `contract_charges.damage_file_id` |
| `contract_extensions` | `contract_id` FK, `previous_ends_at`, `new_ends_at` DATETIME, `created_by` FK | Contrôle de disponibilité tracé (D-07) |
| `contract_amendments` | `contract_id` FK, `type` (`note`, `additional_driver`), `reason` TEXT, `changes` JSON, `created_by` FK | D-11 ; **réalisé au lot L10**, ajout seul ; un contrat clôturé est refusé par le modèle |
| `inspections` | **Réalisé au lot L5** ; écarts : `signature_sha256` CHAR(64) N, `customer_absent_reason` N, `returned_at_declared` DATETIME N (Q73), `signed_pdf_path` et `signed_pdf_sha256` N (D43, lot L11), `created_by` FK N ; validé, il n'est plus modifiable et ne se supprime jamais (modèle). `contract_id` FK, `type` (`departure`, `return`), `mileage` INT UNSIGNED, `fuel_eighths` TINYINT UNSIGNED (0 à 8), `signature_path` N (privé), `notes` TEXT N, `validated_at` DATETIME N, `validated_by` FK N | **U (`contract_id`, `type`)** ; RG-04 au retour |
| `inspection_damages` | **Réalisé au lot L5** ; `zone` parmi les 13 zones du schéma (`VehicleZone`), `severity` (`minor`, `moderate`, `major`), `carried_from_id` FK N (dommage repris du dernier retour). `inspection_id` FK CASCADE, `zone` VARCHAR(32), `x`, `y` DECIMAL(5,2), `description`, `severity`, `is_new` BOOLEAN | |
| `inspection_photos` | **Réalisé au lot L5** ; `width`, `height` SMALLINT, `created_by` FK N ; JPEG ré-encodé (D44). `inspection_id` FK CASCADE, `path`, `size_bytes` INT UNSIGNED, `sha256` CHAR(64), `taken_at` DATETIME N | Disque `private` |

---

## 6. Facturation, encaissements, cautions et exports (phases 6 et 8)

### `invoices` (phase 6)

| Colonne | Type | Contraintes | Remarque |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK | |
| `agency_id` | BIGINT UNSIGNED | NN, FK, I | |
| `type` | VARCHAR(32) | NN | `invoice`, `credit_note`, `supplementary` |
| `number` | VARCHAR(32) | N, **U** | Nul au brouillon, attribué à l'émission (RG-07) ; format fixé par une règle validée (Q11, Q30) |
| `sequence_key` | VARCHAR(64) | N | Clé de `sequences` utilisée |
| `status` | VARCHAR(32) | NN, I | `draft`, `issued`, `cancelled_by_credit` |
| `issued_at` | DATETIME | N, I | Chronologie et clôture (RG-12) |
| `contract_id` | BIGINT UNSIGNED | N, FK, I | |
| `customer_id` | BIGINT UNSIGNED | NN, FK, I | |
| `credited_invoice_id` | BIGINT UNSIGNED | N, FK `invoices` | Avoir lié à la facture d'origine (E-04) |
| `period_start`, `period_end` | DATETIME | N | Période facturée ; pas de recouvrement par contrat (RG-05, contrôle applicatif sous verrou) |
| `customer_snapshot` | JSON | NN à l'émission | Nom, matricule fiscal et adresse figés |
| `total_excl_tax_amount`, `total_tax_amount`, `total_incl_tax_amount` | DECIMAL(15,3) | NN | |
| `due_on` | DATE | N | Conditions de paiement |
| `created_by`, `issued_by` | BIGINT UNSIGNED | N, FK `users` | |
| `created_at`, `updated_at` | TIMESTAMP | N | |

| Table | Colonnes principales | Contraintes clés |
| --- | --- | --- |
| `invoice_lines` | `invoice_id` FK CASCADE, `nature` VARCHAR(32), `label`, `quantity` DECIMAL(10,3), `unit_amount_excl_tax`, `discount_amount`, `total_excl_tax_amount` | Verrouillées à l'émission (RG-06) |
| `invoice_taxes` | `invoice_id` FK CASCADE, `tax_rule_id` FK (`tax_rules`), `code`, `kind`, `rate`, `base_amount`, `tax_amount` (copie figée) | |
| `payments` | `agency_id` FK, `customer_id` FK, `reservation_id` FK N (acompte), `payment_method_id` FK, `reference` N, `amount` DECIMAL(15,3), `received_at` DATETIME I, `cash_session_id` FK N, `status` (`recorded`, `cancelled`), `created_by` FK | Jamais supprimé (RG-11) |
| `payment_allocations` | `payment_id` FK, `invoice_id` FK, `amount` DECIMAL(15,3) | Σ imputations ≤ montant encaissé et ≤ reste dû (contrôle sous verrou) |
| `payment_reminders` | `invoice_id` FK, `channel` VARCHAR(32) (Q53), `reminded_at` DATETIME, `outcome` TEXT N, `created_by` FK | |
| `cash_sessions` | `agency_id` FK, `business_date` DATE, `opened_at`, `closed_at` DATETIME, `opening_amount`, `expected_amount`, `counted_amount`, `difference_amount` DECIMAL(15,3), `difference_reason` N, `closed_by` FK N | **U (`agency_id`, `business_date`)** |
| `deposits` | `agency_id` FK, `contract_id` FK **U**, `amount_received` DECIMAL(15,3), `method` VARCHAR(32), `reference` N (jamais de numéro de carte, Q54), `received_at` DATETIME, `status` (dérivé) | RG-08 sous verrou |
| `deposit_movements` | `deposit_id` FK, `type` (`refund`, `retention`), `amount` DECIMAL(15,3), `reason` N, `file_path` N, `invoice_id` FK N (facture de frais), `approval_request_id` FK N, `receipt_number` N U (Q56), `created_by` FK | Ajout seul |
| `export_periods` | `month` DATE **U** (1er du mois), `status` (`open`, `exported`, `closed`), `closed_at` DATETIME N, `closed_by` FK N | RG-12 |
| `export_files` | `export_period_id` FK, `kind` VARCHAR(32), `format` (`xlsx`, `csv`), `path` (privé), `sha256` CHAR(64), `generated_by` FK, `generated_at` DATETIME | |

---

## 7. CRM, approbations, notifications et statistiques (phases 5 à 9)

| Table | Phase | Colonnes principales | Contraintes clés |
| --- | --- | --- | --- |
| `approval_requests` | 5 | `agency_id` FK, `type` (`discount`, `retention`, `blacklist`, `blacklist_override`), `subject_type`/`subject_id`, `payload` JSON (données figées), `requested_by` FK, `status` (`pending`, `approved`, `rejected`, `cancelled`), `decided_by` FK N, `decided_at` DATETIME N, `comment` TEXT N | I (`status`, `created_at`) ; décision sous verrou (pas de double décision) |
| `customer_recommendations` | **4 (v1, lot L8)**, écran en 7 | `customer_id` FK, `author_id` FK, `contract_id` FK N, `level` (`recommended`, `standard`, `watch`, `blacklist`), `reason_code` VARCHAR(32), `comment` TEXT N, `status` (`effective`, `proposed`, `rejected`), `approval_request_id` FK N, `created_at` | **Ajout seul** (RG-16) : ni modification ni suppression (refusées par le modèle) ; `agency_id` FK N ajouté ; `contract_id` et `approval_request_id` sans clé étrangère jusqu'à leurs tables (phase 5) |
| `customer_interactions` | 7 | `customer_id` FK, `agency_id` FK, `user_id` FK, `type` (`call`, `email`, `visit`, `complaint`, `dispute`), `subject`, `body` TEXT, `follow_up` TEXT N, `occurred_at` DATETIME | I (`customer_id`, `occurred_at`) |
| `complaints` | 7 | `customer_id` FK, `agency_id` FK, `assigned_to` FK N, `subject`, `body`, `status`, `due_on` DATE N, `resolved_at` N | |
| `customer_consents` | 7 | `customer_id` FK, `channel` (`push`), `granted` BOOLEAN, `recorded_at` DATETIME, `source` | Ajout seul (K-07) |
| `customer_segments`, `customer_segment_members` | 7 | Segment : `name`, `rules` JSON N (dynamique), `is_static` ; membres : `customer_segment_id` FK CASCADE, `customer_id` FK | PK composite des membres |
| `customer_search_grants` | 7 | `user_id` FK CASCADE, `customer_id` FK CASCADE, `expires_at` DATETIME | I (`user_id`, `customer_id`, `expires_at`) ; D11 |
| `customer_follow_ups` | 7 | `customer_id` FK, `assigned_to` FK, `due_on` DATE, `done_at` N, `note` | |
| `imports` | 7 | `type`, `file_path` (privé), `status`, `total_rows`, `imported_rows`, `duplicate_rows`, `error_rows` INT, `report_path` N, `created_by` FK | Super admin |
| `push_subscriptions` | 9 | Table du paquet webpush : `subscribable_type`/`subscribable_id`, `endpoint` U, `public_key`, `auth_token`, `content_encoding` | Polymorphe (`User`, puis `Customer`) |
| `notification_preferences` | 9 | `user_id` FK CASCADE, `type` VARCHAR(64), `enabled` BOOLEAN | U (`user_id`, `type`) |
| `kpi_monthly_vehicle` | 9 | `vehicle_id` FK, `agency_id` FK, `month` DATE, charges par nature et CA HT net (DECIMAL(15,3)), `rented_days`, `available_days` SMALLINT, `km_driven` INT, `is_dirty` BOOLEAN, `computed_at` DATETIME | **U (`vehicle_id`, `month`)** ; RG-17 |

**`approval_requests` réalisé au lot L1 de la phase 5** (D46). Écarts au tableau : `reason` TEXT NN (motif du demandeur, obligatoire) ; types `discount` et `blacklist_override` seulement en phase 5 (`retention` en phase 6, `blacklist` en phase 7) ; index (`subject_type`, `subject_id`, `type`) ; colonnes figées à la création (agence, type, objet, `payload`, motif, demandeur : modification refusée par le modèle) et suppression refusée. Clés étrangères posées vers `approval_requests` sur `reservations.discount_approval_id`, `reservations.override_approval_id` et `customer_recommendations.approval_request_id`. Écriture uniquement par `App\Domain\Approvals` (`RequestApproval`, `DecideApproval` sous verrou de la ligne, `CancelPendingApprovals`) ; permission `approvals.decide`, super admin seul.

## 8. Lot 2 (phase 11)

`customer_accounts` (1 pour 1 avec `customers`, garde `customer`), `customer_documents` (pièces envoyées par le client, statut de vérification, disque `private`), `reviews` (avis, distincts des recommandations). Définies en phase 11.
