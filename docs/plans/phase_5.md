# Plan de la phase 5 — Contrats et états des lieux

| Élément | Valeur |
| --- | --- |
| Phase | 5. Contrats et états des lieux (lot 1) |
| Branche | `phase/05-contrats-etats-des-lieux`, créée le 26/11/2026 depuis `main` (`8fb17aa`) |
| Budget | **30 j** : référence 30 j, `drivers` avancé en phase 4 (−0,5 j), dossier de dommage (+0,5 j, référentiel du cabinet). **Seuil d'alerte : 36 j** (référence + 20 %) |
| Statut | **Plan validé le 26/11/2026** par Hafedh, avec D43 à D51 (`docs/decisions.md`) et Q91 à Q93 (`docs/questions_ouvertes.md`) |
| Critère d'acceptation | Scénarios **3** (prolongation avec contrôle de disponibilité) et **4** (retard, frais selon les paramètres) verts ; PDF ; saisie sur tablette (`references/phases.md` §12). En plus : **scénario 12** sur les contrats, scénario 10 rejoué au départ d'un contrat |
| Sources | Cahier, module D (D-01 à D-11), RG-03, RG-04, RG-09, RG-10, RG-11, RG-13, RG-15, §5.1 ; parcours P3, P4, P5 (`docs/maquettes/parcours.md`) ; `docs/architecture.md` §3.3, §3.6, §5 ; `docs/modele_donnees.md` §5 et §7 ; D36, D41, D42 ; analyse du cabinet N2 ; Q4, Q14, Q15, Q42 à Q48, Q52, Q68, Q72, Q73, Q75 |

## 0. Réserves héritées

Elles ne bloquent pas le développement de la phase 5, mais restent prioritaires de ton côté :

| Réserve | État | Effet sur la phase 5 |
| --- | --- | --- |
| Installation de la recette (phase 2, n° 1) | Non levée | La vérification « saisie sur tablette » sera faite sur le poste (Chrome en mode tablette, 10 pouces portrait, Q72). Un essai sur une vraie tablette en recette reste à faire, **noté comme non vérifié** dans le rapport s'il n'a pas eu lieu |
| Saisie du paramétrage (phase 3, n° 1) | Non levée | Les frais de km et de carburant restent à zéro tant que leurs paramètres sont vides (Q43, Q44) : c'est la valeur par défaut, testée |
| Atelier avec les agences et remesure du planning (phase 4, n° 1 et 2) | Non levées, environ 1 j dû à la phase 4 | Le déroulé de l'atelier sera complété des parcours P3 et P4, pour ne tenir qu'un seul atelier |

## 1. Périmètre

### Dans la phase

| Exigence | Contenu |
| --- | --- |
| D-01, RG-03 | Contrat créé depuis une réservation confirmée (réservation « convertie », occupation transférée au contrat) ou au comptoir (« Louer maintenant », contrôle immédiat de disponibilité) |
| D-02, D-03, Q42, Q47, Q48 | Conducteurs du contrat : un conducteur principal obligatoire, conducteurs additionnels ; âge minimal et ancienneté du permis (réglage général, surcharge par catégorie, déjà en base) ; permis valide jusqu'à la fin prévue ; permis étranger signalé ; contrôle de la liste noire sur la pièce et le permis de chaque conducteur |
| D-04, RG-10, D36 | Tarif et options repris du devis figé ; remise au-delà du seuil soumise à l'approbation du super admin, **mécanisme générique `approval_requests`** ; dérogation à la liste noire (RG-15) par le même mécanisme |
| D-05 | Conditions de kilométrage (illimité ou forfait par jour, prix du km supplémentaire) et de carburant, figées sur le contrat depuis la catégorie |
| D-06 | États des lieux de départ et de retour sur tablette : kilométrage, carburant en huitièmes, dommages placés sur un schéma, photos compressées, signature du client |
| D-07, **scénario 3** | Prolongation après contrôle de disponibilité sur la nouvelle période, sous verrou |
| D-08 (P2) | Restitution anticipée : règle `contracts.early_return_refund = none` (Q14), date réelle enregistrée, **aucun remboursement calculé** |
| D-09, RG-09, **scénario 4** | Frais au retour : retard au-delà de la tolérance, km supplémentaires, carburant manquant, dommages (montant saisi et justificatif, plafonné par la franchise) ; détail de chaque calcul avec le paramètre utilisé |
| D-10, Q15, Q46 | PDF du contrat, de l'état des lieux et des conditions générales (dompdf), signature intégrée ; mention « Modèle non validé » tant que `contracts.template_validated = false` |
| D-11, RG-11 | Contrat clôturé non modifiable ; avenant tracé ; contrat numéroté jamais supprimé, annulé avec motif avant le départ |
| N2 (cabinet) | Dossier de dommage : états des lieux, photos, constat, devis ou facture de réparation, référence du contrat ; justificatif des lignes hors champ en phase 6 |
| A-09 | Alerte « Retard de restitution » (déjà au catalogue), canal `database` |
| Q4 | Téléphone du client visible sur un contrat **en cours de son agence**, consultation journalisée |
| §5.1 | Véhicule « Loué » au départ, « Disponible » ou « En maintenance » au retour, par `VehicleStatusService` |

### Hors phase (rappel)

| Élément | Phase |
| --- | --- |
| Caution au départ et à la restitution (P3 étape 4, P4 étape 7) | 6. Le départ ne l'exige pas en phase 5 ; la phase 6 ajoutera cette condition au départ et à la clôture |
| Facture, facture complémentaire, encaissement (P4 étapes 5 et 6) ; code fiscal des frais | 6. La phase 5 fige des **frais par nature** (retard, km, carburant, dommage) sans code fiscal : le choix du code se fait à la facture (N2), aucune règle fiscale n'est appliquée ici |
| Notification push de la demande d'approbation (scénario 19) | 9. En phase 5 : canal `database` (centre de notifications) |
| Ordres de maintenance (sortie de l'état « En maintenance ») | 8. En phase 5, un retour avec dommage peut mettre le véhicule en maintenance ; voir D47 |
| Fiche client complète, carnet d'adresses | 7 |

## 2. Décisions proposées (à valider)

| N° | Décision | Raison |
| --- | --- | --- |
| **D43** | **PDF par dompdf** (LGPL-2.1, PHP pur, compatible mutualisé), documents en français, police DejaVu Sans (Q5, Q75). Paquet `barryvdh/laravel-dompdf` si sa version compatible Laravel 13 et PHP 8.3 est vérifiée au lot L11, sinon `dompdf/dompdf` directement. PDF générés à la demande, jamais stockés, sauf l'exemplaire signé de l'état des lieux (figé à la validation, disque privé, empreinte SHA-256) | Q75 (dompdf par défaut) ; un document signé ne doit pas changer si un paramètre change ensuite |
| **D44** | **Photos** : compression dans le navigateur (canevas, 1 600 px au plus, JPEG qualité 0,75) **puis** ré-encodage côté serveur par GD (extension présente), qui retire les métadonnées EXIF (position GPS) et refuse tout fichier qui n'est pas une image ; 8 Mo au plus à l'envoi, 12 photos au plus par état des lieux (`inspections.max_photos`). Aucun paquet d'image ajouté | Espace disque du mutualisé (Q68) ; un fichier du navigateur n'est jamais cru sur parole |
| **D45** | **Signature** : composant `signature_pad` (npm, licence MIT, compilé avec les assets) dans un champ Livewire ; enregistrée en PNG sur le disque privé, servie par un contrôleur comme les pièces des véhicules (D21) ; signature obligatoire pour valider un état des lieux, sauf case « Client absent (retour hors horaires) » avec motif (Q73) | Q46 (signature sur tablette intégrée au PDF, papier toujours possible) |
| **D46** | **Approbations génériques** (`approval_requests`) : types `discount` et `blacklist_override` en phase 5 (`retention` en phase 6, `blacklist` en phase 7). La demande **fige** ses données (montant, pourcentage, motif) ; seule une décision du super admin la tranche, sous verrou (pas de double décision), refus motivé ; pas de délégation (Q52) ; aucune approbation automatique. **Effet** : une réservation ou un contrat avec une remise en attente est enregistré, mais **le départ est refusé** tant que la demande n'est pas approuvée ; un refus retire la remise et recalcule le total. Une dérogation approuvée vaut pour **une** réservation ou **un** contrat, jamais pour le client en général. Remplace le refus pur de D36 | RG-10, RG-15, Q52, P5 |
| **D47** | **Retour avec dommage** : l'agent choisit à la validation du retour « Véhicule à réparer » (→ « En maintenance ») ou non (→ « Disponible ») ; un dommage nouveau ne bloque pas le véhicule de lui-même. En attendant les ordres de maintenance (phase 8), une action « Fin d'intervention » réservée à l'admin et au super admin, motif obligatoire, ramène le véhicule à « Disponible » par `VehicleStatusService` | §5.1 ; sans elle, un véhicule mis en maintenance en phase 5 resterait bloqué jusqu'à la phase 8 |
| **D48** | **Prolongation tarifée au tarif journalier figé du contrat**, sans remise automatique ; une remise sur la prolongation suit RG-10. La prolongation déplace l'occupation (`vehicle_blocks`) sous le verrou du véhicule ; refusée si une autre occupation chevauche la nouvelle période, délai de préparation compris | Même base que le retard (Q14 : « tarif journalier du contrat ») ; prix prévisible au comptoir |
| **D49** | **Statuts du contrat** : `draft` (créé, état des lieux de départ non validé) → `active` (départ validé) → `returned` (retour validé, frais figés) → `closed` ; `cancelled` seulement depuis `draft`, avec motif. En phase 5, la clôture exige le retour validé et aucune approbation en attente ; **la phase 6 y ajoutera** facture émise et caution soldée (liste de conditions extensible, testée) | D-11 ; la phase 6 ne doit pas défaire la phase 5 |
| **D50** | **Carburant** : niveau en huitièmes ; frais = huitièmes manquants × capacité du réservoir ÷ 8 × prix du litre + frais de service. Nouvelle colonne `vehicles.fuel_tank_liters` (vide par défaut) et paramètres `pricing.fuel_liter_amount`, `pricing.fuel_service_amount` (vides). Si l'un manque, **aucun frais calculé** et l'écran l'indique | Q44 ; impossible de passer des huitièmes aux litres sans la capacité |
| **D51** | **Retard à l'heure** (`pricing.late_unit = hour`, déjà au paramétrage) : aucun tarif horaire n'est défini. Proposition : nouveau paramètre `pricing.late_hour_amount` par catégorie, **vide** ; tant qu'il est vide, l'unité « heure » est refusée à l'enregistrement du paramétrage. L'unité « jour » (valeur par défaut, Q14) reste seule utilisable | Signaler plutôt que deviner (règle 4) ; nouvelle question **Q91** |

Questions ajoutées à `docs/questions_ouvertes.md` au lot L0 :

| N° | Question | Valeur par défaut |
| --- | --- | --- |
| Q91 | Retard facturé à l'heure : quel tarif horaire (montant par catégorie, fraction du tarif journalier) ? Plafond à une journée ? | Unité « jour » seule utilisable ; `late_hour_amount` vide (D51) |
| Q92 | Capacité des réservoirs : par véhicule (saisie) ou par catégorie ? | Par véhicule, vide (D50) |
| Q93 | Une remise sur un contrat déjà en cours (prolongation, geste commercial) passe-t-elle aussi par le super admin ? | Oui, même seuil (RG-10, D48) |

## 3. Modèle de données

Conforme à `docs/modele_donnees.md` §5 et §7, avec les écarts suivants, reportés dans ce document au fil des lots.

| Table | Lot | Contenu, écarts au modèle |
| --- | --- | --- |
| `approval_requests` | L1 | Conforme au §7 ; clés étrangères ajoutées sur `reservations.discount_approval_id`, `reservations.override_approval_id`, `customer_recommendations.approval_request_id` |
| `contracts` | L2 | Conforme au §5, plus `override_approval_id` FK N, `cancellation_reason` N, `cancelled_at` N, `fuel_eighths_required` TINYINT (niveau attendu au retour), `daily_rate_amount` DECIMAL(15,3) (tarif figé, D48), `quote` JSON (devis figé), `deposit_expected_amount` DECIMAL(15,3) N (repris de la catégorie, pour la phase 6) ; trait `HasSequenceNumber` (jamais supprimé) |
| `contract_drivers`, `contract_options` | L2, L3 | Conformes |
| `contract_extensions` | L7 | Plus `added_amount` DECIMAL(15,3), `discount_approval_id` FK N |
| `contract_charges` **[ajout]** | L8 | Frais figés au retour : `contract_id` FK, `nature` (`late_return`, `extra_km`, `fuel`, `damage`), `quantity` DECIMAL(10,3), `unit_amount`, `amount` DECIMAL(15,3), `calculation` JSON (paramètres utilisés), `damage_file_id` FK N ; **aucun code fiscal** (choisi à la facture, phase 6) ; ajout seul après le retour |
| `contract_amendments` | L10 | Conforme |
| `inspections`, `inspection_damages`, `inspection_photos` | L5 | Conformes, plus `inspections.signed_pdf_path` N et `signed_pdf_sha256` N (D43), `customer_absent_reason` N (D45), `returned_at_declared` DATETIME N (Q73) |
| `damage_files` **[ajout]**, `damage_file_documents` **[ajout]** | L9 | Dossier : `contract_id` FK, `inspection_damage_id` FK N, `status` (`open`, `complete`), `estimated_amount` DECIMAL(15,3) N ; pièces : `kind` (`report`, `quote`, `repair_invoice`, `other`), `path` privé, `sha256`, `uploaded_by` |
| `vehicles.fuel_tank_liters` | L4 | SMALLINT N (D50) |
| Paramètres | L4, L8, L11 | `pricing.fuel_liter_amount`, `pricing.fuel_service_amount`, `pricing.late_hour_amount` (D51), `contracts.early_return_refund` (`none`), `contracts.template_validated` (`false`), `contracts.terms_text` (conditions générales, super admin), `inspections.max_photos` (12) |

Toutes les tables qui portent une agence passent par `AgencyScope` ; montants en `DECIMAL(15,3)` et `brick/math`.

## 4. Lots

Ordre : approbations et contrat d'abord (tout le reste en dépend), puis le départ, puis le retour.

| Lot | Contenu | Tests clés | Charge (j) |
| --- | --- | --- | ---: |
| **L0. Plan et questions** | Ce plan, D43 à D51, Q91 à Q93 | — | 0,5 |
| **L1. Approbations** | `RequestApproval`, `DecideApproval`, `ApprovalInbox` (`app/Domain/Approvals`) ; page « Approbations » du super admin (plus anciennes en tête, contexte, approuver ou refuser) ; notification `database` au super admin puis au demandeur, **sans montant ni nom** (RG-20) ; branchement sur les réservations : remise au-delà du seuil et dérogation de liste noire deviennent des demandes au lieu d'un refus (D46) | Double décision refusée (deux processus) ; admin et agent ne peuvent pas décider, y compris par requête Livewire falsifiée ; refus sans motif refusé ; texte de notification sans donnée sensible ; dérogation à usage unique | 3 |
| **L2. Contrats** | `OpenContract` depuis une réservation confirmée (réservation `converted`, occupation transférée sans trou) ou au comptoir (contrôle immédiat, RG-03) ; numéro `CTR-AAAA-NNNNN` dans la transaction ; options et devis figés ; `CancelContract` (brouillon seulement, motif) ; ressource Filament « Contrats » | Réservation non confirmée refusée ; contrat au comptoir sur un véhicule occupé refusé ; conversion concurrente de la même réservation (deux processus) : un seul contrat ; aucun numéro perdu sur un refus ; contrat numéroté non supprimable | 3,5 |
| **L3. Conducteurs** | `ValidateDriver` (âge, ancienneté et validité du permis jusqu'à la fin prévue, surcharge de la catégorie, permis étranger signalé) ; conducteur principal obligatoire (le client particulier ou une personne désignée pour une entreprise) ; conducteurs additionnels contrôlés contre la liste noire (Q48) | Limites exactes (veille et jour d'anniversaire, ancienneté pile) ; permis qui expire pendant la location refusé ; conducteur additionnel sur liste noire bloqué sans dérogation | 1,5 |
| **L4. Conditions km et carburant** | Conditions figées sur le contrat depuis la catégorie (D-05) ; `vehicles.fuel_tank_liters` ; paramètres D50 et D51 au paramétrage | Paramètres vides : aucun frais ; unité « heure » refusée sans tarif | 0,75 |
| **L5. États des lieux (domaine)** | `RecordInspection`, `StartRental` (départ : documents valides à la date de départ, scénario 10 ; km ≥ dernier relevé ; véhicule → « Loué » ; contrat `active`) ; dommages du dernier retour repris automatiquement ; un état des lieux validé n'est plus modifiable ; photos et signature sur le disque privé, servies par contrôleur (D21, D44, D45) | RG-04 au retour (égal accepté, inférieur refusé) ; départ refusé avec une assurance expirée ; photo falsifiée (PHP renommé en `.jpg`) refusée ; EXIF retiré ; fichier d'une autre agence refusé | 2,5 |
| **L6. Écrans tablette** | « Départs et retours du jour » (badges d'alerte) ; assistant d'état des lieux en étapes (km, carburant 8 crans, schéma du véhicule en SVG avec zones cliquables, photos par l'appareil photo, signature) ; comparaison départ et retour côte à côte ; mise en page 10 pouces portrait (Q72) | Tests de page Livewire de chaque étape ; **vérification dans le navigateur** en mode tablette (Chrome, 800 × 1 280), photo et signature comprises | 4,5 |
| **L7. Prolongation** | `ExtendContract` (D48) sous le verrou du véhicule ; trace dans `contract_extensions` ; remise éventuelle soumise à RG-10 | **Scénario 3** : prolongation acceptée sur période libre, refusée si une réservation chevauche la nouvelle période ou son délai de préparation ; deux prolongations ou une prolongation et une réservation simultanées (deux processus) : pas de chevauchement | 1,5 |
| **L8. Retour et frais** | `RegisterReturn` : `LateFeeCalculator` (tolérance, unité, Q14), `MileageFeeCalculator`, `FuelFeeCalculator` (D50), dommage saisi avec justificatif et plafonné par la franchise de la catégorie (Q45) ; restitution anticipée sans remboursement (D-08) ; frais figés dans `contract_charges` avec leur calcul ; heure déclarée hors horaires (Q73) ; véhicule → « Disponible » ou « En maintenance » (D47), occupation libérée ; action « Fin d'intervention » | **Scénario 4** : retour à la tolérance pile, sans frais ; une minute après, une journée au tarif du contrat ; plusieurs jours de retard ; km supplémentaires exacts au millime ; carburant ; franchise plafonnante ; paramètre modifié après le retour sans effet sur les frais figés | 3 |
| **L9. Dossier de dommage** | Ouverture depuis un dommage nouveau au retour ; pièces (constat, devis, facture de réparation) ; vue qui rassemble états des lieux, photos et contrat ; dossier « complet » exigé en phase 6 pour une ligne hors champ (N2) | Pièce d'un autre contrat refusée ; dossier incomplet signalé | 0,5 |
| **L10. Clôture et avenants** | `CloseContract` (D49, conditions extensibles) ; `AmendContract` sur contrat clôturé (motif, changements tracés en JSON, conducteurs additionnels et mentions seulement ; un écart financier relève d'une facture complémentaire en phase 6) | Modification directe d'un contrat clôturé refusée (domaine, Filament, Livewire falsifié) ; avenant journalisé | 1 |
| **L11. PDF** | Contrat, état des lieux (exemplaire signé figé, D43), conditions générales (texte paramétré par le super admin) ; mention « Modèle non validé » (Q15) ; impression depuis la tablette | Contenu attendu (numéro, conducteurs, conditions, signature) ; mention présente tant que le modèle n'est pas validé ; PDF d'un contrat d'une autre agence refusé ; exemplaire signé inchangé après modification d'un paramètre | 2,5 |
| **L12. Alerte de retard et téléphone (Q4)** | Tâche planifiée : contrat actif dont la fin prévue + tolérance est dépassée → notification `database` aux utilisateurs de l'agence, une seule fois ; téléphone visible sur un contrat actif de son agence, consultation journalisée | Une seule alerte par contrat ; texte sans nom ni immatriculation ; téléphone masqué sur un contrat clôturé ou d'une autre agence | 0,75 |
| **L13. Droits et tests transverses** | Matrice des écrans × 3 profils ; **scénario 12** (Sfax contre un contrat de Sousse : lecture, modification, état des lieux, prolongation, PDF, fichiers) ; tests d'architecture (écritures des contrats et états des lieux seulement depuis `app/Domain`, aucun SQL brut, aucun `float`) ; traductions | Codes HTTP attendus pour chaque profil ; requêtes Livewire falsifiées | 1,25 |
| **L14. Rapport et revue** | Mise à jour du déroulé de l'atelier (P3, P4) ; `docs/rapports/phase_5.md` ; revue de la branche | `composer check` | 1,5 |
| **Total** | | | **28,25** |

Marge : 1,75 j sous le budget de 30 j ; seuil d'alerte à 36 j. Je signale tout lot qui dépasse sa charge de plus de 20 %.

## 5. Risques

| Risque | Parade |
| --- | --- |
| Tablettes non choisies (Q72) : appareil photo et signature peuvent se comporter autrement sur Safari iPadOS | Essai sur Chrome en mode tablette au lot L6 ; essai sur une vraie tablette en recette, noté « non vérifié » tant qu'il n'a pas eu lieu |
| Mémoire de PHP en mutualisé pour le ré-encodage GD et dompdf | Images réduites dans le navigateur avant l'envoi (D44) ; photos insérées réduites dans le PDF ; mesure de la mémoire maximale dans un test |
| Compatibilité de `barryvdh/laravel-dompdf` avec Laravel 13 | Vérifiée au lot L11 ; repli sur `dompdf/dompdf` (D43) ; inscrite dans `docs/compatibilite_paquets.md` |
| Super admin injoignable au comptoir (Q52) | Aucune approbation automatique ; la demande reste en attente et l'agent peut retirer la remise pour laisser partir le client |
| Valeurs par défaut non confirmées par les agences (Q14, Q42 à Q48) | Toutes paramétrables ; reprises dans la fiche de recueil de l'atelier |

## 6. À valider

1. Ce plan et son découpage en lots (28,25 j pour 30 j de budget).
2. Les décisions **D43 à D51**, en particulier D46 (effet d'une remise en attente : départ refusé), D47 (sortie de maintenance provisoire avant la phase 8) et D49 (clôture sans facture en phase 5).
3. Les questions **Q91 à Q93** et leurs valeurs par défaut.
4. L'ajout de deux paquets gratuits : `barryvdh/laravel-dompdf` (ou `dompdf/dompdf`), LGPL-2.1 ou MIT, et `signature_pad` (npm, MIT).

## 7. Suivi de la charge

| Lot | Prévu (j) | Estimé (j) | Cumul (j) | Remarque |
| --- | ---: | ---: | ---: | --- |
| Jeu de données de développement (`DevSampleDataSeeder`), hors lots | — | 0,5 | 0,5 | Demandé le 26/11/2026 pour tester sans saisie ; poste local uniquement |
| L0. Plan et questions | 0,5 | 0,5 | 1 | |
| L1. Approbations | 3 | 2,5 | 3,5 | Domaine `Approvals`, écran du super admin, branchement sur les réservations (remise, dérogation), action « Confirmer » d'un brouillon (manquait en phase 4) ; double décision testée sur deux processus (échoue sans le verrou : essai fait) |
| L2. Contrats | 3,5 | 3 | 6,5 | Contrat depuis une réservation ou au comptoir (« Louer maintenant » dans l'assistant), conditions figées, annulation du brouillon, effets des refus d'approbation sur le contrat, véhicule « Réservé » jusqu'au départ ; conversion simultanée testée sur deux processus. Correctif de `SequenceAllocator` : `prepare()` crée un compteur avant la transaction (sinon délai de verrou dépassé quand une transaction tient déjà le verrou d'un autre compteur) |
| L3. Conducteurs | 1,5 | 1,25 | 7,75 | `DriverEligibility` (âge et ancienneté du permis au départ, permis valide jusqu'au retour, permis étranger et date de fin non renseignée signalés), `SaveContractDriver` et `RemoveContractDriver` (brouillon seulement, un seul conducteur principal), liste noire contrôlée par l'empreinte du permis et de la pièce (Q48), `ContractDrivers::assertReadyForDeparture` pour le lot L5 ; écran du contrat |
| L4. Conditions km et carburant | 0,75 | 0,5 | 8,25 | `vehicles.fuel_tank_liters`, `vehicle_categories.late_hour_amount`, `pricing.fuel_liter_amount` et `pricing.fuel_service_amount` (vides par défaut) ; retard à l'heure refusé tant qu'une catégorie active n'a pas de tarif horaire (D51) ; conditions figées sur le contrat, y compris la franchise de la catégorie (utile au plafond des dommages, lot L8) |
| L5. États des lieux (domaine) | 2,5 | 2,25 | 10,5 | `inspections`, `inspection_damages`, `inspection_photos` ; `RecordInspection` (dommages du dernier retour repris, kilométrage contrôlé, RG-04 au retour, photos ré-encodées par GD sans métadonnées, signature PNG, client absent au retour, Q73) ; `StartRental` (état des lieux complet, remise et dérogation approuvées, conducteurs, documents valides, kilométrage, véhicule « Loué », état des lieux figé) ; `InspectionFileController` ; réglage `inspections.max_photos` et sa page. L'exemplaire signé en PDF (colonnes prévues) arrive au lot L11 |
| L6. Écrans tablette | 4,5 | 3,5 | 14 | Page « Départs et retours du jour » ; page d'état des lieux (relevés, carburant en 8 crans, schéma SVG cliquable, photos compressées dans le navigateur, signature `signature_pad` 5.1.7 MIT, validation du départ ; comparaison avec le départ au retour) ; `resources/js/inspection.js` compilé par Vite. **Vérifié dans le navigateur** (Chromium, 800 × 1 280 portrait, agent de Djerba, base de développement) : parcours complet jusqu'au contrat « En cours », photo réelle de 3 000 px reçue en 1 600 × 1 067, aucune erreur de console ni requête en échec. **Défaut trouvé et corrigé** : un départ en avance n'avançait pas l'occupation du véhicule ; `StartRental` l'avance désormais sous verrou et refuse une avance qui chevauche une autre occupation (test). **Non vérifié** : vraie tablette (Q72), appareil photo réel, signature au doigt |
| L7. Prolongation | 1,5 | 1,25 | 15,25 | `contract_extensions` (ajout seul), `ExtendContract` (occupation déplacée sous le verrou du véhicule, jours ajoutés au tarif journalier figé, options par jour prolongées dans la limite du plafond : Q94 ouverte avec cette valeur par défaut, remise au-delà du seuil soumise au super admin et retirée en cas de refus, Q93) ; bouton « Prolonger » et liste des prolongations sur la fiche du contrat. **Scénario 3** vert, bornes du délai de préparation testées ; prolongation et réservation simultanées sur deux processus : une seule aboutit |
| L8. Retour et frais | 3 | 2,5 | 17,75 | `ReturnCharges` (retard : jours facturés réels moins prévus, même décompte que le devis, ou heures entamées au tarif horaire figé ; km au-delà du forfait ; carburant au millilitre puis au millime, frais de service ; dommages saisis, total plafonné par la franchise figée), `RegisterReturn` (état des lieux complet, RG-04, heure déclarée hors horaires Q73, frais figés dans `contract_charges` avec leur calcul et sans code fiscal, kilométrage, véhicule disponible ou en maintenance, occupation libérée), `EndMaintenance` (D47, admin et super admin), réglage `contracts.early_return_refund` (« none », D-08) ; écran de retour avec aperçu des frais. **Scénario 4** vert : tolérance pile sans frais, une minute après une journée, plusieurs journées, à l'heure |
| L9. Dossier de dommage | 0,5 | 0,5 | 18,25 | `damage_files` (un par dommage nouveau du retour validé), `damage_file_documents` (constat, devis, facture de réparation, autre ; PDF ou image contrôlés, disque privé, SHA-256, ajout seul), frais du dommage rattachés ; complet seulement avec un constat et un devis ou une facture (exigé par la facture en phase 6, N2) ; contrôleur des pièces par contrat ; action et section sur la fiche du contrat |
| Correctif de verrouillage (lot L7) | — | 0,25 | 18,5 | **Défaut trouvé au contrôle du lot L9** : interblocage MariaDB (1213) entre une prolongation et une réservation simultanées, environ une exécution sur trois du test de concurrence. Cause : `ExtendContract` verrouillait le véhicule sans verrouiller d'abord la catégorie (ordre de D38). Corrigé dans `ExtendContract`, `StartRental`, `RegisterReturn` et `CancelContract` ; test relancé 10 fois sur 10 sans échec ; le test affiche désormais la sortie brute d'un processus illisible |
| L10. Clôture et avenants | 1 | 0,75 | 19,25 | `CloseContract` et `ContractClosingConditions` (retour validé, aucune approbation en attente ; la phase 6 y ajoutera facture et caution), contrat clôturé refusé par le modèle ; `contract_amendments` et `AmendContract` (mention corrective ou conducteur additionnel contrôlé, motif obligatoire, ajout seul ; écart financier refusé) ; boutons « Clôturer » et « Avenant », section des avenants |
| L11. PDF | 2,5 | 1,75 | 21 | `barryvdh/laravel-dompdf` 3.1.2 et `dompdf/dompdf` 3.1.6 (D43, aucune ressource distante, police en sous-ensemble) ; `ContractDocuments` : contrat (société, client et pièce, conducteurs et permis, véhicule, conditions et montants figés, prolongations, frais, signature ; ni téléphone ni courriel ni adresse, D13, à confirmer par le conseil juridique Q15), état des lieux (relevés, dommages, photos réduites, signature), conditions générales saisies au réglage `contracts.terms_text` ; « Modèle non validé » tant que `contracts.template_validated` est faux (Q15) ; **exemplaire signé figé** à la validation du départ et du retour (disque privé, SHA-256) ; page de réglages « Contrats » ; menu « Imprimer » de la fiche du contrat. **PDF vérifiés à l'œil** sur la base de développement : contrat 37 Ko en 0,8 s, état des lieux 45 Ko en 1,7 s, 96 Mo de mémoire au plus (870 Ko avant l'activation du sous-ensemble de police) |
| L12. Alerte de retard et téléphone | 0,75 | 0,5 | 21,5 | `LateReturnAlerts` (contrat en cours dont le retour prévu, tolérance comprise, est dépassé ; une alerte par échéance, aux profils du paramétrage qui agissent sur l'agence ; texte sans nom, immatriculation ni montant ; toutes les 15 minutes) ; `ContractCustomerPhone` (Q4 : téléphone affiché seulement sur un contrat en cours de l'agence, réglage `contracts.show_phone_on_active_contract`, consultation journalisée dans `customer_access` sans le numéro) |
| Correctif de la CI | — | 0,25 | 21,75 | **CI en échec depuis l'envoi du lot L7**, signalé par Hafedh le 27/11/2026 : la page d'état des lieux appelle `@vite`, `composer check` lance les tests avant `npm run build` ; le poste gardait le manifeste d'une construction précédente, pas la CI. `withoutVite()` dans `TestCase` ; échec reproduit sur le poste sans `public/build` puis corrigé ; CI verte sur `9e06f78`. Leçon : vérifier la CI après chaque envoi |
| L13. Droits et tests transverses | 1,25 | 0,75 | 22,5 | `Phase5AccessMatrixTest` : 12 écrans × 3 profils ; **scénario 12** sur le contrat (policy, 10 actions du domaine, pages Livewire appelées directement) ; essai de mutation fait (contrôle des droits retiré de `CloseContract` : le test échoue ; code rétabli). `RentalWritesTest` : documents de location et approbations écrits seulement depuis `App\Domain`, motifs eux-mêmes testés |
| L14. Rapport et revue | 1,5 | 1,5 | 24 | Rapport `docs/rapports/phase_5.md`, revue de la branche, complément de l'atelier pour P3 et P4 |

Budget 30 j ; seuil d'alerte 36 j. **Total estimé : 24 j** (−6 j). Reste dû : essai sur une vraie tablette et partie phase 5 de l'atelier, environ 0,5 j, en recette.
