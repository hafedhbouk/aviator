# Plan de la phase 6 — Facturation, encaissements, cautions

| Élément | Valeur |
| --- | --- |
| Phase | 6. Facturation, encaissements, cautions (lot 1) |
| Branche | `phase/06-facturation-cautions`, créée le 27/11/2026 depuis `main` (`7262eeb`, fusion de la pull request n° 7) |
| Budget | **37 j** : référence 31 j, demande d'évolution du cabinet (part indispensable, 5 j), numérotation D27 (1 j). **Seuil d'alerte : 37,2 j** (référence + 20 %). Le plan prévoit 32 j (§4) |
| Statut | **Plan validé** par Hafedh le 27/11/2026 (lots, D52 à D59, application par défaut des questions ouvertes) ; lots en cours (§7) |
| Critère d'acceptation | Scénarios **5** (facture réglée en deux paiements partiels), **6** (caution partiellement restituée après retenue approuvée), **7** (avoir partiel, numérotation sans trou) et **8** (modification d'une facture validée refusée ; période clôturée : voir D53) verts ; tests de référence du référentiel (annexe B) verts |
| Règles fiscales disponibles | Liste **confirmée par Hafedh le 27/11/2026** : les règles **CONFIRMED** du référentiel v1.0 validé (TVA 19 % sur `RENTAL`, `CHILD_SEAT`, `GPS`, `EXTRA_DRIVER`, `EXTRA_RENTAL_PERIOD`, `FUEL_RECHARGE` ; timbre de 1,000 DT ; numérotation continue `AVI-{SEQUENCE}` et `AV-{SEQUENCE}` ; méthode d'arrondi ; avoirs) ; les règles **CONDITIONAL** opération par opération, avec leur justificatif (assurances, retard, dégâts, franchise) ; les **sept points PENDING_DOCUMENTATION inutilisables** tant que leur pièce manque (dont les exonérations de timbre et la preuve du dépôt TTN). Rien d'autre n'est appliqué |
| Sources | Cahier, modules E (E-01 à E-10) et F (F-01 à F-08), RG-05 à RG-08, RG-11, RG-12 ; référentiel v1.0 §2 à §10 ; `docs/analyse_ecarts_cabinet.md` (E6, E8, E11, E13, E17, E19, E23, N2 à N4) ; D26, D27, D37, D46, D49 ; parcours P4 étapes 5 à 8, P5 ; Q25, Q50 à Q56, Q79, Q81, Q87, Q89 ; annexes A et B de ce document |

## 0. Réserves héritées

Elles ne bloquent pas le développement, mais restent prioritaires de ton côté : installation de la recette (phase 2), saisie du paramétrage (phase 3), atelier avec les agences et remesure du planning (phase 4), essai sur une vraie tablette (phase 5). **En plus, pour cette phase** : les mentions obligatoires des factures (`company.invoice_legal_mentions`, super admin) sont vides ; tant qu'elles le sont, `aviator:check-environment` reste en erreur et aucune facture ne part en production (décision du 19/11/2026). Les factures émises sur le poste et en recette portent la mention « Règles fiscales non confirmées » (E6).

## 1. Périmètre

### Dans la phase

| Exigence | Contenu |
| --- | --- |
| E-01, E-02, RG-05, Q50 | Facture de location établie depuis un contrat restitué : location, options, prolongations, frais figés au retour ; aucune période facturée deux fois ; location de plus de 30 jours facturable par période (`billing.long_rental_period_days`, Q50) |
| N2, E6 | Code fiscal **choisi** pour chaque ligne, jamais déduit d'un libellé ; code proposé pour la location et les options (déjà configurées), **aucun code proposé pour les frais** du retour ; ligne exonérée ou hors champ refusée sans justificatif rattaché ; règle PENDING_DOCUMENTATION refusée en production |
| E-03, RG-06, RG-07, D27, N4 | Numéro attribué **à la validation**, jamais au brouillon, dans la transaction, série `AVI-` continue ; facture validée verrouillée (modèle, base, interface) ; numérotation par modèle et compteur par série, verrous, commande Artisan (annexe B, D27-1 à D27-3) |
| E11, Q79 | Deux statuts : **document** (brouillon, validée, émise, avoir total, annulée) et **paiement** (calculé : non payée, partiellement payée, payée) ; « annulée » sans avoir seulement pour une facture validée jamais émise, motif obligatoire, numéro conservé |
| E8, D26 | Mentions : société (RNE, matricule), agence émettrice, type de document, contrat, client figé (`customer_snapshot` : nom, matricule fiscal, RNE du client entreprise, pays), totaux par taux, devise `TND` seule (toute autre refusée) |
| §5 du référentiel | Calcul par `TaxCalculator` (bases agrégées par taux, TVA arrondie, timbre après le TTC) : exemple chiffré 115,000 → 137,850 identique à l'écran, au PDF et dans les montants enregistrés |
| N3 | Exonération du timbre appliquée **à la main**, par une règle d'exonération active, avec motif et justificatif ; jamais automatique ; toutes les exonérations sont PENDING_DOCUMENTATION aujourd'hui, donc refusées en production |
| E-05, Q87 c | Facture complémentaire (retard, dommages, km constatés après coup), série `AVI-` |
| E-04, D27-4, E19 | Avoir total ou partiel, série `AV-`, lié à la facture d'origine, total plafonné sous verrou ; avoir **remboursé** (décaissement tracé) ou **imputé** sur une autre facture |
| E-06, E17, Q81, D37, C-10 | Encaissements multi-modes (espèces, chèque, carte, virement, traite) avec banque pour le chèque et le virement ; paiements partiels ; imputation n ↔ n proposée « la plus ancienne d'abord », confirmée ; part non imputée = acompte ; **acompte à la réservation** (reporté de la phase 4, D37) |
| E-07 | Liste des impayés et de leur ancienneté |
| E-08 (P2), Q53 | Relances manuelles tracées (appel, courrier, visite) |
| E-09 (P2), Q55 | Journal de caisse par agence et par jour, ouverture, clôture avec montant compté et écart motivé |
| F-01 à F-08, RG-08, Q51, Q52, Q54, Q56, E23 | Caution liée au contrat (montant, mode, référence, aucune donnée de carte) ; états ; restitution totale ou partielle avec reçu numéroté `CAU-AAAA-NNNNN` ; retenue motivée, justifiée, **approuvée par le super admin** (D46, type `retention`), qualifiée (elle règle la facture de frais) ; restitué + retenu ≤ reçu sous verrou ; jamais en chiffre d'affaires ; liste des cautions en cours |
| D49, P3 étape 4 | Conditions ajoutées : caution enregistrée au départ quand une caution est prévue ; facture émise et caution soldée à la clôture du contrat (`ContractClosingConditions`) |
| E-10, Q25 | PDF de la facture, de l'avoir et des reçus (caution, encaissement) ; **pas d'envoi par e-mail** (`billing.email_invoices = false`, Q25) |
| E13 | Interface `ElectronicInvoicingProvider`, fournisseur « désactivé » par défaut, champs de suivi et table des envois : **structure seulement**, aucune transmission simulée |
| Scénario 12, RG-13 | Factures, encaissements, cautions, caisse : portée par agence, testée par type de document |

### Hors phase

| Élément | Phase |
| --- | --- |
| Exports comptables (H-01 à H-08), clôture des périodes exportées (RG-12) | 8 (voir D53 pour le scénario 8) |
| Clôture mensuelle exigée par le cabinet | 9 |
| Écran de réglage de la numérotation (D27) | 9 (commande Artisan en phase 6) |
| Connecteur TEIF / TTN | Avenant séparé (Q16, Q84) |
| Notifications push (retenue à approuver, impayés) | 9 ; canal `database` ici |

## 2. Décisions proposées (à valider)

| N° | Décision | Raison |
| --- | --- | --- |
| **D52** | **Moment de la facture** (Q50, valeur par défaut) : une facture de location par contrat, établie au retour (contrat « Restitué ») ; pour un contrat de plus de `billing.long_rental_period_days` (30) jours, factures intermédiaires par période, jamais deux fois la même (RG-05, contrôle sous verrou du contrat). La facture se prépare en brouillon depuis le contrat ; l'agent choisit les codes fiscaux, puis la valide | Q50 ; P4 étape 5 |
| **D53** | **Scénario 8 en deux temps** : en phase 6, la modification d'une facture validée est refusée (modèle, base, interface, requête falsifiée) ; un **garde de période** (`ClosedPeriodGuard`) est appelé par l'émission, les avoirs et les encaissements, mais il ne bloque rien tant que la table des périodes exportées n'existe pas (phase 8). La moitié « période clôturée » du scénario 8 est testée en phase 8 | RG-12 relève de la phase 8 ; le garde est posé dès maintenant pour ne rien reprendre |
| **D54** | **Codes fiscaux des lignes** (N2) : location `RENTAL` et options (code de l'option) proposés, modifiables ; prolongation proposée en `EXTRA_RENTAL_PERIOD` ; frais du retour **sans proposition** (l'agent choisit, par exemple `LATE_RETURN_PENALTY` ou `EXTRA_RENTAL_PERIOD` pour un retard, `VEHICLE_DAMAGE_COMPENSATION` ou `REPAIR_SERVICE` pour un dommage, `FUEL_RECHARGE` pour le carburant) ; une ligne de libellé libre reçoit aussi son code. Aucun code n'est déduit d'un libellé | Référentiel §2.7, N2 ; tests de référence (annexe B) |
| **D55** | **Justificatifs** d'une ligne exonérée ou hors champ : un dossier de dommage **complet** (phase 5) ou une pièce jointe (contrat d'assurance, clause, constat) ; sans justificatif, la validation est refusée. En production, une règle PENDING_DOCUMENTATION est refusée même avec un justificatif (FiscalGuard) | Référentiel §14, N2, N5 |
| **D56** | **Statuts et cycle** (E11, Q79) : brouillon → validée (numéro) → émise (remise au client, PDF figé) ; annulation sans avoir seulement d'une facture validée jamais émise ; statut de paiement toujours calculé depuis les imputations, jamais saisi | Référentiel §6 ; Q79 (valeur par défaut) |
| **D57** | **Encaissements** (Q81) : imputation proposée « la plus ancienne d'abord », confirmée par l'agent ; part non imputée = acompte du client, imputable plus tard ; un encaissement en espèces exige une caisse ouverte de l'agence (Q55) ; un encaissement ne se supprime pas, il s'annule avec motif (RG-11) | Q81, Q55 ; annexe A |
| **D58** | **Cautions** (Q51, Q52, Q54, Q56) : caution exigée au départ quand le contrat en prévoit une (`contracts.deposit_expected_amount`, repris de `vehicle_categories.deposit_amount`), montant reçu différent accepté avec motif ; empreinte de carte = référence d'autorisation seulement ; **toute retenue** passe par le super admin (D46, type `retention`) ; une retenue approuvée crée un encaissement « retenue sur caution » imputé sur la facture de frais, qui porte la qualification fiscale (la caution elle-même n'entre jamais en chiffre d'affaires) ; reçu `CAU-AAAA-NNNNN` à la réception et à chaque restitution | Q51, Q52, Q54, Q56 (valeurs par défaut) ; E23 |
| **D59** | **Mentions et RNE du client entreprise** (E8) : colonne `customers.trade_register` (vide par défaut) ; le client est figé sur la facture à la validation ; les mentions légales du super admin s'impriment en pied de facture | E8 ; décision du 19/11/2026 |

Questions déjà ouvertes que ce plan applique par défaut : Q50, Q51, Q52, Q53, Q54, Q55, Q56, Q79, Q81, Q87, Q89. Aucune nouvelle question à ce stade.

## 3. Modèle de données

Conforme à `docs/modele_donnees.md` §6, avec les écarts suivants.

| Table | Lot | Contenu, écarts au modèle |
| --- | --- | --- |
| `sequences` | L1 | Série nommée (`invoice`, `credit_note`, `deposit_receipt`) et modèle de format (D27-1), compteur attaché à la série ; reprise des compteurs existants sans changement de numéro |
| `invoices` | L2 | Conformes au §6, plus `document_status` et `payment_status` (D56), `period_start`, `period_end`, `currency` (`TND` seule), `issued_pdf_path` et `issued_pdf_sha256`, `cancellation_reason`, `stamp_exemption_rule_id` N, champs de suivi électronique (E13) |
| `invoice_lines` | L3 | Plus `tax_code_id` FK (code choisi), `source_type` et `source_id` (contrat, prolongation, frais, ligne libre) |
| `invoice_line_justifications` **[ajout]** | L4 | `invoice_line_id`, `damage_file_id` N ou pièce jointe (chemin privé, SHA-256), `kind` |
| `invoice_taxes` | L4 | Conformes ; copie figée de la règle appliquée |
| `payments`, `payment_allocations` | L7 | Conformes, plus `bank_name` N (E17), `currency` ; `reservation_id` N pour l'acompte (D37) |
| `payment_reminders` | L9 | Conformes (Q53) |
| `cash_sessions` | L8 | Conformes |
| `deposits`, `deposit_movements` | L10 | Conformes, plus `currency` ; mouvement `refund` ou `retention`, retenue liée à l'approbation et à l'encaissement qui règle la facture de frais |
| `credit_note_settlements` **[ajout]** | L6 | Remboursement (décaissement) ou imputation d'un avoir (E19) |
| `electronic_invoice_submissions` **[ajout]** | L12 | Structure seulement (E13) |
| `customers.trade_register` | L2 | VARCHAR(64) N (D59) |
| Réglages | L1, L3 | `billing.long_rental_period_days` (30), `billing.email_invoices` (`false`), formats de numérotation (D27) |

## 4. Lots

Ordre : numérotation et facture d'abord (tout le reste s'y impute), puis avoirs, encaissements, caisse, cautions, et le retour sur tablette.

| Lot | Contenu | Tests clés | Charge (j) |
| --- | --- | --- | ---: |
| **L0. Plan** | Ce plan, D52 à D59 | — | 0,5 |
| **L1. Numérotation (D27)** | Modèle de format et compteur par série, reprise des compteurs, verrous (format avec l'année, remise à zéro), commande Artisan de réglage | Tests de référence D27 (annexe B) : passage d'année, séries indépendantes, verrous, commande | 1 |
| **L2. Factures (modèle)** | `invoices`, lignes, taxes, statuts (D56), client figé, RNE du client (D59), dinar seul (D26) ; facture validée verrouillée par le modèle et refusée à la suppression | Modification et suppression d'une facture validée refusées (scénario 8, partie 1) ; devise autre que TND refusée | 2,5 |
| **L3. Brouillon depuis le contrat** | Lignes reprises du contrat (location, options, prolongations, frais), codes proposés ou à choisir (D54), lignes libres, période facturée (RG-05, Q50) | Aucune période facturée deux fois (sous verrou, deux processus) ; aucun code proposé pour les frais ; facture de plus de 30 jours par période | 3 |
| **L4. Validation et émission** | Garde fiscal (E6, N2, D55), calcul (§5), numéro dans la transaction (RG-07), timbre, exonération manuelle (N3), émission et PDF figé ; `ClosedPeriodGuard` (D53) | Exemple chiffré 115,000 → 137,850 ; libellé « assurance » ; hors champ sans justificatif refusé ; règle PENDING_DOCUMENTATION refusée en production ; numéros sans trou sous émission simultanée (deux processus) ; aucun numéro consommé par un refus | 3 |
| **L5. Facture complémentaire et annulation** | E-05 ; annulation d'une facture validée jamais émise (Q79) | Annulation d'une facture émise refusée | 1 |
| **L6. Avoirs** | Série `AV-`, total ou partiel, plafond sous verrou, remboursement ou imputation (E19) | **Scénario 7** ; avoirs plafonnés (annexe B) ; deux avoirs simultanés : un seul passe | 2,5 |
| **L7. Encaissements** | Modes, banque, partiels, imputation proposée et confirmée (Q81), acompte de réservation (D37), statut de paiement, annulation motivée, impayés et ancienneté (E-07) | **Scénario 5** ; Σ imputations ≤ encaissé et ≤ reste dû sous verrou ; acompte imputé à la facture du contrat | 3,5 |
| **L8. Journal de caisse** | Ouverture, encaissements en espèces rattachés, clôture, écart motivé (Q55) | Espèces refusées sans caisse ouverte ; une seule caisse par agence et par jour | 2 |
| **L9. Relances** | Relances manuelles tracées (Q53) | Historique par facture | 0,5 |
| **L10. Cautions** | Réception (mode, référence sans donnée de carte), reçus `CAU`, restitution totale ou partielle, retenue soumise au super admin (D46), retenue appliquée en encaissement de la facture de frais (Q51), états, liste des cautions en cours (F-08) | **Scénario 6** ; RG-08 sous verrou (deux processus) ; double restitution refusée ; caution jamais en chiffre d'affaires | 4 |
| **L11. Conditions du contrat** | Caution au départ (D58), facture émise et caution soldée à la clôture (D49) | Départ refusé sans caution prévue ; clôture refusée sans facture | 0,5 |
| **L12. Facturation électronique (E13)** | Interface, fournisseur désactivé, champs de suivi, table des envois | Aucune transmission ; fournisseur désactivé par défaut | 0,75 |
| **L13. PDF** | Facture, avoir, reçus ; mentions (E8) ; « Règles fiscales non confirmées » hors production (E6) | Contenu et totaux identiques à l'écran ; mention présente hors production | 1,5 |
| **L14. Écrans** | Factures, avoirs, encaissements, caisse, cautions ; retour sur tablette (P4 étapes 5 à 8 : facture, encaissement, caution, clôture) | Tests de page ; **vérification dans le navigateur** | 3 |
| **L15. Droits et tests transverses** | Matrice par profil, scénario 12 par type de document, architecture (écritures dans le domaine, aucun `float`) | Codes HTTP attendus ; requêtes Livewire falsifiées | 1,25 |
| **L16. Rapport et revue** | `docs/rapports/phase_6.md`, revue de la branche | `composer check`, CI | 1,5 |
| **Total** | | | **32** |

Marge : 5 j sous le budget de 37 j ; seuil d'alerte à 37,2 j. Je signale tout lot qui dépasse sa charge de plus de 20 %. Après chaque envoi, je vérifie la CI avant de poursuivre.

## 5. Risques

| Risque | Parade |
| --- | --- |
| Mentions légales des factures vides | Garde de `aviator:check-environment` ; mention « Règles fiscales non confirmées » hors production ; rappel au rapport |
| Points PENDING_DOCUMENTATION (timbre, retard, franchise, assurances) qui bloquent certaines lignes en production | Codes taxables proposés à la place quand la nature le permet ; liste des pièces manquantes dans le rapport |
| Concurrence sur la numérotation, les avoirs, les imputations et les cautions | Verrous en base (D38) ; quatre tests sur processus distincts |
| Budget à 0,2 j du seuil d'alerte | Plan à 32 j ; relances (P2) et journal de caisse (P2) en fin d'ordre si une marge doit être reprise |

## 6. À valider

1. Ce plan et son découpage en lots (32 j pour 37 j de budget).
2. Les décisions **D52 à D59**, en particulier D53 (scénario 8 en deux temps), D54 (aucun code proposé pour les frais) et D58 (caution exigée au départ, toute retenue approuvée).
3. L'application par défaut des questions ouvertes Q50 à Q56, Q79, Q81, Q87 et Q89.

**Validé par Hafedh le 27/11/2026** : plan, découpage et décisions D52 à D59.

## 7. Suivi de la charge

| Lot | Prévu (j) | Estimé (j) | Cumul (j) | Remarque |
| --- | ---: | ---: | ---: | --- |
| L0. Plan | 0,5 | 0,5 | 0,5 | |
| L1. Numérotation (D27) | 1 | 1 | 1,5 | Séries `invoice`, `credit_note`, `deposit_receipt` (`sequence_series`), modèle `{PREFIX}`, `{YEAR}`, `{SEQUENCE}`, compteur attaché à la série (clé `series:<code>`), journal `sequence_series_changes` et journal d'audit ; commande `aviator:numbering` ; verrous D27-2 (voir ci-dessous) ; test sur trois processus (factures et avoirs simultanés) |
| L2. Factures (modèle) | 2,5 | 2 | 3,5 | `invoices` (factures, factures complémentaires et avoirs), `invoice_lines`, `invoice_taxes`, `customers.trade_register` (D59) ; statuts du document et de paiement (D56) ; dinar seul (D26) ; client et émetteur figés (`InvoiceSnapshots`) ; facture validée verrouillée par le modèle et par le constructeur de requêtes (modifications et suppressions en masse refusées), lignes et taxes comprises ; aucun déclencheur en base (`docs/architecture.md`) ; `InvoicePolicy` |
| L3. Brouillon depuis le contrat | 3 | 2,5 | 6 | `DraftContractInvoice` sous verrou du contrat : prochaine période non facturée, lignes reprises du contrat (location, options, prolongation et ses options, frais du retour sur la dernière période), code proposé (`RENTAL`, code de l'option, `EXTRA_RENTAL_PERIOD`), **aucun pour les frais** ; `ContractBillingPlan` (périodes de `billing.long_rental_period_days`, remise répartie, reste sur la période où l'élément se termine : Q95) ; `EditInvoiceDraft` (code choisi, libellé, lignes libres, abandon du brouillon) ; `invoice_lines.source_key` ; test sur deux processus |
| L4. Validation et émission | 3 | 3 | 9 | `ValidateInvoice` : contrôles avant le numéro (brouillon, `ClosedPeriodGuard`, garde-fou fiscal en production, code choisi sur chaque ligne, règle applicable, justificatif pour l'exonéré, le hors champ et toute règle CONDITIONAL : Q96), calcul `TaxCalculator`, une taxe par code avec la copie figée de sa règle, numéro dans la transaction, client et émetteur figés, échéance ; exonération du timbre à la main (règle active, motif, justificatif) ; `invoice_line_justifications` et `ManageLineJustifications` ; `IssueInvoice` : PDF figé (SHA-256), mention « Règles fiscales non confirmées » hors production ; une règle PENDING_DOCUMENTATION est refusée partout, pas seulement en production (`TaxRuleResolver`) ; test sur trois processus |
| L5. Facture complémentaire et annulation | 1 | 1 | 10 | `DraftSupplementaryInvoice` : contrat restitué ou clôturé dont toute la location est facturée, frais non encore facturés repris sans code (D54), lignes libres, série `AVI-`, timbre ; un frais n'est jamais facturé deux fois, même si la facture de location est refaite après annulation. `CancelInvoice` (Q79) : facture validée jamais émise, motif, numéro conservé, période libérée ; facture émise refusée (avoir). Correctif de la numérotation (L1) : un compteur créé après l'instantané de la transaction en cours n'est plus recréé (attente de son propre verrou) |
| L6. Avoirs | 2,5 | 2,5 | 12,5 | `CreateCreditNote` : facture émise seulement (validée jamais émise : annulation, Q79), motif obligatoire, série `AV-` numérotée dans la transaction, sous verrou de la facture d'origine (reste par ligne, total ≤ facture), taxes aux taux figés de la facture (copie de ses règles, arrondi par `TaxCalculator::fromBases`), avoir qui solde : reste exact de la TVA et timbre (Q97), facture au statut « avoir total » ; `SettleCreditNote` (E19) : remboursement (mode, référence) ou imputation sur une facture du même client, sous verrou, jamais au-delà ; `InvoiceTaxWriter` extrait de la validation ; PDF de l'avoir (facture initiale, motif) ; test sur deux processus |
| L7. Encaissements | 3,5 | 3,5 | 16 | `RecordPayment` (modes, référence, banque pour le chèque et le virement : E17, dinar seul, acompte de réservation : D37), `PaymentAllocator` (proposition « la plus ancienne d'abord » puis imputation confirmée : Q81 ; acomptes de la réservation proposés sur la facture du contrat ; part non imputée = acompte), `CancelPayment` (motif, jamais supprimé : RG-11), `InvoiceBalance` (reste dû, statut de paiement calculé, avoirs imputés compris), `UnpaidInvoices` (impayés et ancienneté : E-07) ; facture qui a reçu un règlement : plus d'annulation sans avoir. **Plafonds contrôlés sur des cumuls tenus sous verrou** (`invoices.paid_amount`, `invoices.settled_amount`, `payments.allocated_amount`) : le test sur deux processus a montré que la somme des imputations lue dans l'instantané de la transaction pouvait ignorer celles d'un processus concurrent |

**Verrous D27-2, mise en œuvre (L1)** : le format avec l'année et la remise à zéro annuelle d'une série fiscale s'ouvrent seulement avec `--confirmation=<version>`, une version du référentiel enregistrée dans `fiscal_references` et **postérieure à la v1.0** (une nouvelle version exige la confirmation signée du cabinet, `CLAUDE.md` règle 3) ; la version qui a ouvert le verrou reste attachée à la série. Un format qui peut reproduire un numéro d'une autre série, actuelle ou ancienne, est refusé (intersection exacte des formats) ; dans la même série, seul un ancien format de même squelette (longueur seule changée) est accepté. Quand la remise à zéro s'active, le compteur de l'année en cours reprend la valeur du compteur continu : la remise à zéro joue au 1er janvier suivant.

Exemples :

```bash
php artisan aviator:numbering invoice                                  # réglage, prochain numéro, verrous
php artisan aviator:numbering invoice --prefix=FAC --by="Nom, fonction"   # en production : ajouter --force
php artisan aviator:numbering invoice --template='{PREFIX}-{YEAR}-{SEQUENCE}' --confirmation=1.1 --by="Nom, fonction"
```


---


## Annexe A. Décidé pendant la phase 2

| Élément | Charge en phase 6 | Détail |
| --- | ---: | --- |
| **Documents numérotés jamais supprimés** | Incluse (brique livrée en phase 2) | Trait `HasSequenceNumber` (refus de suppression par le modèle et par suppression en masse), concern de policy `ForbidsDeletingNumberedDocuments` (aucune suppression dans l'interface, super admin compris), test qui exige la protection de toute table portant une colonne `number`. À poser sur les factures, avoirs, factures complémentaires et reçus de caution |
| **Mode d'annulation à confirmer avec l'expert-comptable** | 0 (question) | Facture émise : annulation par un **avoir** total ou partiel (E-04, RG-06) ; autres documents numérotés (reçus de caution, encaissements) : par un **statut** d'annulation avec motif, ou par un document d'annulation. À valider avec Q11, Q30 et Q16 avant d'écrire les écrans |
| **Format des numéros** | Selon la règle fiscale | `SequenceDefinition` des factures et avoirs (préfixe, période, séquence commune ou par agence) fixée par la règle validée ; aucun format n'est écrit avant |

## Annexe B. Demande d'évolution du cabinet (18/11/2026)

Source : `docs/analyse_ecarts_cabinet.md`, validée le 18/11/2026 avec décisions provisoires, à confirmer par la direction.

### Indispensable, intégré au lot 1

| Élément (analyse) | Charge (j) | Contenu |
| --- | ---: | --- |
| E6. Garde-fou fiscal à l'émission | 0,5 | Refus d'émettre en production avec une règle non `CONFIRMED` ; filigrane « Règles fiscales non confirmées » sur les PDF hors production |
| E8. Mentions | 0,5 | RNE du client entreprise, mentions adaptées au type de client, type de document et agence émettrice |
| E11. Statuts | 0,5 | Statut du document (brouillon, validée, émise, avoir total, annulée) et statut de paiement calculé (non payée, partiellement payée, payée) ; « annulée » selon Q79 |
| E13. Interface `ElectronicInvoicingProvider` | 0,75 | Interface, fournisseur « désactivé » par défaut, champs de suivi et table des envois (structure seulement), **aucune transmission simulée** ; le connecteur TEIF / TTN relève d'un avenant séparé, pas préalable à la mise en production (situation B, Q84), à livrer dès la finalisation de l'adhésion |
| E17. Banque sur l'encaissement | 0,25 | Banque pour les chèques et virements ; imputation selon Q81 |
| E19. Avoirs remboursés ou imputés | 1 | Remboursement (décaissement tracé) ou imputation sur une autre facture, totale ou partielle |
| E23. Qualification des retenues sur caution | 0,25 | Nature fiscale de la retenue (Q51) |
| N2. Qualification selon la nature réelle (référentiel v1.0) | 1 | Code fiscal choisi pour chaque ligne, jamais déduit du libellé ; ligne exonérée ou hors champ : justificatif rattaché obligatoire (contrat d'assurance, clause de retard, dossier de dommage de la phase 5, justificatifs du sinistre), sinon validation refusée ; règle `PENDING_DOCUMENTATION` jamais applicable en production |
| N3. Exonération du timbre | 0,25 | Appliquée à la main sur une facture, par une règle active de `stamp_duty_rules`, avec motif et justificatif ; jamais automatique pour une facture « impayée » |
| N4. Numérotation continue | 0 | Compteur sans remise à zéro ; année et agence dans des champs distincts (référentiel §4.1) |
| **Total** | **5** | |

### Numérotation des factures et des avoirs (D27, Q30)

Source : courriel de la direction du 18/11/2026 (`docs/reference/regles_fiscales_validees/reponse_q30_numerotation.pdf`), arbitré le 18/11/2026. Le courriel reste **à confirmer** jusqu'au dépôt de la confirmation nominative de Faouzi Bannour ; les règles sur les avoirs ne contredisent pas le référentiel et sont planifiées.

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| D27-1. Format par modèle et compteur par série | 0,5 | `SequenceDefinition` actuelle : l'année n'apparaît dans le numéro que si le compteur repart à zéro chaque année, et la clé du compteur est le préfixe. D27 exige un **modèle** (`{PREFIX}`, `{YEAR}`, `{SEQUENCE}`) indépendant de la période du compteur, et un compteur attaché à la **série** (factures, avoirs), pour qu'un changement de préfixe ne remette pas le compteur à zéro. **Réglage initial : `AVI-{SEQUENCE}` et `AV-{SEQUENCE}`** (format du référentiel validé, 6 chiffres) |
| D27-2. Verrous | 0,25 | Format avec l'année (`AVI-{YEAR}-{SEQUENCE}`, `{YEAR}` = année d'émission à l'heure de Tunis) **prévu mais désactivé**, activable seulement quand une confirmation écrite du cabinet est enregistrée ; remise à zéro annuelle présente mais refusée tant qu'elle n'est pas CONFIRMED dans une version ultérieure du référentiel |
| D27-3. Commande Artisan de réglage du format | 0,25 | Réservée au terminal (aucun écran en phase 6) ; changement journalisé (avant, après, qui, quand) ; **refusée en production sans `--force`** ; appliquée aux prochains numéros seulement ; refus d'un format sans `{SEQUENCE}` ou pouvant produire un numéro déjà attribué, dans sa série ou dans l'autre ; refus du format avec l'année tant que le verrou D27-2 est fermé |
| D27-4. Avoirs liés, plafonnés | 0 | Déjà prévu (E-04, RG-06) : avoir lié obligatoirement à sa facture ; avoirs partiels et totaux dans la série `AV-` ; total des avoirs d'une facture ≤ son montant, contrôlé sous verrou |
| D27-5. Factures complémentaires | 0 | Série `AVI-` par défaut, paramétrable (Q87) |
| **Total D27 en phase 6** | **1** | L'écran de numérotation (1 j) est en phase 9 |

### Dinar uniquement (D26)

Aucune devise étrangère (réponse de la direction à Q78, 18/11/2026) : multidevise retirée du plan. Toute devise autre que `TND` est refusée sur les factures, avoirs, encaissements et cautions. Sans charge supplémentaire (contrôle de saisie et test).

| Phase 6 | Charge (j) |
| --- | ---: |
| Référence | 31 |
| Demande d'évolution (indispensable, référentiel v1.0) | +5 |
| Numérotation D27 (écran en phase 9) | +1 |
| **Nouveau total** | **37** |
| **Écart** | **+6 j (+19,4 %)**, sous le seuil d'alerte (37,2 j), avec 0,2 j de marge |

**Arbitrage du 18/11/2026** : l'écran de numérotation passe en phase 9 ; en phase 6, une commande Artisan le remplace (+0,25 j). La phase 6 reste sous le seuil d'alerte, sans marge réelle.

### Tests de référence (référentiel v1.0)

| Test | Attendu |
| --- | --- |
| **Exemple chiffré de la §5** sur une facture complète | Facture de location 100,000 + GPS 10,000 + siège enfant 5,000 : total HT **115,000**, TVA 19 % **21,850**, TTC **136,850**, timbre **1,000**, net à payer **137,850**, identiques à l'écran, dans le PDF et dans le journal des ventes |
| **Libellé « assurance »** | Une ligne dont le libellé contient « assurance » (« Assurance tous risques », « ASSURANCE », « assurance complémentaire »…) n'est **jamais** exonérée automatiquement : sans code fiscal choisi, la validation est refusée ; avec `INSURANCE_PREMIUM` sans contrat ni justificatif de l'assureur, la validation est refusée ; avec `ADDITIONAL_PROTECTION_SERVICE`, TVA 19 % |
| Hors champ sans justificatif | `LATE_RETURN_PENALTY`, `VEHICLE_DAMAGE_COMPENSATION` ou `INSURANCE_EXCESS_COMPENSATION` sans justificatif : validation refusée |
| Timbre d'une facture impayée | Le timbre reste dû ; aucune exonération automatique |
| **Passage d'année (D27)** | Format initial : dernière facture de 2026 `AVI-000125`, première de 2027 `AVI-000126` (aucune remise à zéro) ; même chose pour les avoirs. Format avec l'année, une fois déverrouillé : `AVI-2026-000125` (31/12/2026 à 23:59, heure de Tunis) puis `AVI-2027-000126` ; une facture émise le 31/12/2026 à 23:30 UTC (00:30 à Tunis le 01/01/2027) porte l'année 2027 |
| **Format avec l'année verrouillé (D27)** | Activer `AVI-{YEAR}-{SEQUENCE}` est refusé tant que la confirmation écrite du cabinet n'est pas enregistrée |
| **Deux séries indépendantes (D27)** | Factures et avoirs ont chacun leur compteur : émettre un avoir n'avance pas le compteur des factures ; numéros uniques sous émission simultanée, dans chaque série |
| **Remise à zéro verrouillée (D27)** | Activer la remise à zéro annuelle est refusé tant que la règle n'est pas CONFIRMED |
| **Avoirs plafonnés (D27)** | Facture de 137,850 : avoir partiel de 50,000 accepté, second avoir de 87,850 accepté, troisième avoir de 0,001 refusé ; un avoir sans facture d'origine est refusé ; deux avoirs simultanés qui dépasseraient ensemble le montant : un seul passe |
| **Commande de réglage du format (D27)** | Changement journalisé ; refusé en production sans `--force` ; le numéro suivant prend le nouveau format, les numéros existants ne changent pas ; format sans `{SEQUENCE}` refusé ; format pouvant reproduire un numéro existant refusé (par exemple préfixe de facture remplacé par `AV-`) ; changer le préfixe ne remet pas le compteur à zéro |
| **Dinar uniquement (D26)** | Une facture, un avoir, un encaissement ou une caution dans une devise autre que `TND` (par exemple `EUR`) est refusé avec un message clair ; rien n'est enregistré |
