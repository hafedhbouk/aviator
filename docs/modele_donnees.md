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

### `agency_assignments` — P2 (remplace `agency_user`, lot 5)

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
| `birth_date` | DATE | N | |
| `nationality` | CHAR(2) | N | Code ISO 3166-1 |
| `phone` | VARCHAR(32) | N | **Coordonnée** (RG-14) |
| `phone_search` | VARCHAR(32) | N, I | Téléphone normalisé (chiffres seuls, indicatif compris) |
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

`id` ; `legal_name` VARCHAR(255) NN ; `trade_name` VARCHAR(255) N ; `tax_id` VARCHAR(32) N ; `trade_register` VARCHAR(64) N ; `address` VARCHAR(255) N ; `city` VARCHAR(100) N ; `phone`, `email` N ; `currency` CHAR(3) NN défaut `TND` ; `logo_path` VARCHAR(255) N (disque `private`) ; `invoice_legal_mentions` TEXT N (**rempli seulement à partir d'une règle validée**) ; horodatages. Une seule ligne.

### `agency_locations`

`id` ; `agency_id` FK RESTRICT, I ; `name` VARCHAR(100) NN ; `address` VARCHAR(255) N ; `is_pickup` BOOLEAN NN défaut 1 ; `is_return` BOOLEAN NN défaut 1 ; `opening_hours` JSON N (par jour : ouverture, fermeture ; pas de recherche dans le JSON) ; `is_active` BOOLEAN NN défaut 1 ; horodatages.

### `vehicle_categories`

`id` ; `code` VARCHAR(16) NN U ; `name` VARCHAR(100) NN ; `description` TEXT N ; `deposit_amount` DECIMAL(15,3) N (Q14) ; `min_driver_age` TINYINT UNSIGNED N (Q42) ; `min_license_years` TINYINT UNSIGNED N (Q42) ; `included_km_per_day` INT UNSIGNED N (Q43) ; `extra_km_amount` DECIMAL(15,3) N ; `insurance_excess_amount` DECIMAL(15,3) N (Q45) ; `preparation_buffer_minutes` SMALLINT UNSIGNED N (Q41) ; `sort_order` SMALLINT NN défaut 0 ; `is_active` BOOLEAN NN défaut 1 ; horodatages.

### `seasons`

`id` ; `name` VARCHAR(100) NN ; `starts_on` DATE NN ; `ends_on` DATE NN ; I (`starts_on`, `ends_on`) ; horodatages. Pas de chevauchement (contrôle applicatif).

### `rate_plans` et `rate_plan_lines`

- `rate_plans` : `id` ; `vehicle_category_id` FK RESTRICT ; `season_id` FK RESTRICT ; `agency_id` FK RESTRICT **N** (grille commune si nulle, Q10) ; `valid_from` DATE NN ; `valid_to` DATE N ; `is_active` BOOLEAN NN ; horodatages ; I (`vehicle_category_id`, `season_id`, `agency_id`, `valid_from`).
- `rate_plan_lines` : `id` ; `rate_plan_id` FK CASCADE ; `min_days` SMALLINT UNSIGNED NN ; `max_days` SMALLINT UNSIGNED N ; `daily_amount` DECIMAL(15,3) NN (HT, Q34) ; horodatages ; U (`rate_plan_id`, `min_days`).

### `options`

`id` ; `code` VARCHAR(32) NN U ; `name` VARCHAR(100) NN ; `pricing_unit` VARCHAR(32) NN (`per_day`, `per_rental`) ; `amount` DECIMAL(15,3) NN (HT) ; `max_amount_per_rental` DECIMAL(15,3) N ; `tax_nature` VARCHAR(32) N (nature fiscale, rattachée à une règle validée) ; `is_active` BOOLEAN NN ; horodatages.

### `taxes`

`id` ; `code` VARCHAR(32) NN ; `kind` VARCHAR(32) NN (`rate`, `fixed_amount`) ; `rate` DECIMAL(7,4) N ; `amount` DECIMAL(15,3) N ; `applies_to` VARCHAR(32) NN (nature : location, option, frais…) ; `valid_from` DATE NN ; `valid_to` DATE N ; `source_document` VARCHAR(255) NN (fichier de `regles_fiscales_validees/`) ; `validated_by` VARCHAR(255) NN ; `validated_at` DATE NN ; horodatages ; I (`code`, `valid_from`). ~~Table vide tant qu'aucune règle n'est déposée~~ **Révisée le 18/11/2026** : les valeurs du cabinet y sont saisies en `PROPOSED` ou `PENDING_ACCOUNTANT_VALIDATION`, avec nature fiscale, versions et statut de validation ; seules les règles `CONFIRMED` servent en production. Conception à arrêter dans le plan de la phase 3 (`docs/plans/phase_3.md`, « Paramètres fiscaux »).

### `payment_methods`

`id` ; `code` VARCHAR(32) NN U (`cash`, `cheque`, `card`, `transfer`, `bill_of_exchange`) ; `name` VARCHAR(100) NN ; `requires_reference` BOOLEAN NN ; `is_active` BOOLEAN NN ; horodatages.

### `suppliers`

`id` ; `type` VARCHAR(32) NN (`garage`, `insurer`, `leasing_company`, `bank`, `other`) ; `name` VARCHAR(255) NN ; `tax_id` VARCHAR(32) N ; `phone`, `email`, `address` N ; `is_active` BOOLEAN NN ; horodatages ; I (`type`, `name`).

---

## 4. Parc, financement et maintenance (phases 4 et 8)

### `vehicles` (phase 4)

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
| `vehicle_status_logs` | `vehicle_id` FK, `from_status`, `to_status` VARCHAR(32), `cause_type`/`cause_id` (polymorphe), `user_id` FK N, `occurred_at` DATETIME | I (`vehicle_id`, `occurred_at`) ; ajout seul |
| `vehicle_mileage_logs` | `vehicle_id` FK, `mileage` INT UNSIGNED, `source_type`/`source_id`, `is_correction` BOOLEAN, `reason` VARCHAR(255) N, `user_id` FK, `recorded_at` DATETIME | Correction : motif obligatoire (contrôle applicatif) |
| `vehicle_documents` | `vehicle_id` FK, `type` (`insurance`, `technical_inspection`, `road_tax`, `registration`), `supplier_id` FK N, `reference` VARCHAR(64) N, `valid_from` DATE N, `valid_until` DATE N, `amount` DECIMAL(15,3) N, `file_path` N (privé) | I (`vehicle_id`, `type`, `valid_until`) |
| `vehicle_blocks` | `vehicle_id` FK, `starts_at`, `ends_at` DATETIME (délai de préparation inclus), `source_type`/`source_id`, `active` BOOLEAN | **I (`vehicle_id`, `active`, `starts_at`, `ends_at`)** ; contrôle RG-01 sous verrou |
| `vehicle_financings` | `vehicle_id` FK, `type` (`leasing`, `credit`), `supplier_id` FK, `reference` VARCHAR(64), `starts_on`, `ends_on` DATE, `initial_payment_amount`, `monthly_amount_excl_tax`, `monthly_amount_incl_tax`, `residual_value_amount`, `principal_amount` (crédit), `interest_rate` DECIMAL(7,4) N, `purchase_option_on` DATE N, `outcome` (`option_exercised`, `returned`) N | I (`vehicle_id`) |
| `financing_installments` | `vehicle_financing_id` FK CASCADE, `sequence` SMALLINT, `due_date` DATE, `amount_excl_tax`, `amount_incl_tax`, `interest_amount` N, `charge_month` DATE (1er du mois, RG-17), `paid_at` DATETIME N | **U (`vehicle_financing_id`, `sequence`)** ; I (`charge_month`) |

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

### `reservations` (phase 4)

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

### `contracts` (phase 5)

`id` ; `number` VARCHAR(32) NN U (`CTR-AAAA-NNNNN`) ; `agency_id` FK NN I ; `return_agency_id` FK NN ; `reservation_id` FK N U ; `vehicle_id` FK NN I ; `customer_id` FK NN I ; `starts_at`, `ends_at` DATETIME NN (prévu) ; `departed_at`, `returned_at` DATETIME N (réel) ; `status` VARCHAR(32) NN I (`draft`, `active`, `returned`, `closed`, `cancelled`) ; `mileage_policy` VARCHAR(32) NN (`unlimited`, `daily_allowance`) ; `included_km_per_day` INT N ; `extra_km_amount` DECIMAL(15,3) N ; `fuel_policy` VARCHAR(32) NN ; `rental_amount`, `options_amount`, `discount_amount` DECIMAL(15,3) NN ; `discount_approval_id` FK N ; `closed_at` DATETIME N ; `created_by` FK ; horodatages.

| Table | Colonnes principales | Contraintes clés |
| --- | --- | --- |
| `drivers` | `customer_id` FK, `first_name`, `last_name`, `birth_date` DATE, `license_number` TEXT (chiffré), `license_hash` CHAR(64) I, `license_issued_on`, `license_expires_on` DATE, `license_country` CHAR(2), `id_document_number` TEXT (chiffré) N, `id_document_hash` CHAR(64) N I | Recherche exacte par permis (K-04) |
| `contract_drivers` | `contract_id` FK CASCADE, `driver_id` FK, `is_primary` BOOLEAN | PK (`contract_id`, `driver_id`) ; un seul conducteur principal |
| `contract_options` | `contract_id` FK CASCADE, `option_id` FK, `quantity`, `unit_amount`, `total_amount` | |
| `contract_extensions` | `contract_id` FK, `previous_ends_at`, `new_ends_at` DATETIME, `created_by` FK | Contrôle de disponibilité tracé (D-07) |
| `contract_amendments` | `contract_id` FK, `reason` TEXT, `changes` JSON, `created_by` FK | D-11 |
| `inspections` | `contract_id` FK, `type` (`departure`, `return`), `mileage` INT UNSIGNED, `fuel_eighths` TINYINT UNSIGNED (0 à 8), `signature_path` N (privé), `notes` TEXT N, `validated_at` DATETIME N, `validated_by` FK N | **U (`contract_id`, `type`)** ; RG-04 au retour |
| `inspection_damages` | `inspection_id` FK CASCADE, `zone` VARCHAR(32), `x`, `y` DECIMAL(5,2), `description`, `severity`, `is_new` BOOLEAN | |
| `inspection_photos` | `inspection_id` FK CASCADE, `path`, `size_bytes` INT UNSIGNED, `sha256` CHAR(64), `taken_at` DATETIME N | Disque `private` |

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
| `invoice_taxes` | `invoice_id` FK CASCADE, `tax_id` FK, `code`, `kind`, `rate`, `base_amount`, `tax_amount` (copie figée) | |
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
| `customer_recommendations` | 7 | `customer_id` FK, `author_id` FK, `contract_id` FK N, `level` (`recommended`, `standard`, `watch`, `blacklist`), `reason_code` VARCHAR(32), `comment` TEXT N, `status` (`effective`, `proposed`, `rejected`), `approval_request_id` FK N, `created_at` | **Ajout seul** (RG-16) : ni `updated_at` modifiable, ni suppression |
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

## 8. Lot 2 (phase 11)

`customer_accounts` (1 pour 1 avec `customers`, garde `customer`), `customer_documents` (pièces envoyées par le client, statut de vérification, disque `private`), `reviews` (avis, distincts des recommandations). Définies en phase 11.
