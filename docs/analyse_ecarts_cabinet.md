# Analyse d'écarts — spécifications du cabinet comptable du 18/11/2026

Version du 18/11/2026. Document analysé : `docs/reference/cabinet/specifications_facturation_2026-11-18.md` (désigné ci-dessous « le document »). Référence : cahier des charges (`docs/reference/cahier_des_charges_aviator.md`), modèle de données (`docs/modele_donnees.md`), décisions (`docs/decisions.md`).

**Statut : à valider par le responsable technique avant tout code.** Aucune règle du document n'est validée : il propose des valeurs et en renvoie plusieurs à une validation écrite du cabinet. Rien n'est versé dans `docs/reference/regles_fiscales_validees/`. La section 15 du document (instructions à Claude Code) n'est pas exécutée : la méthode par phases du projet s'applique (plan, validation, lots, tests). Elle couvre déjà l'audit, l'analyse d'écarts, la proposition d'architecture et la validation demandés par cette section.

## 1. Synthèse

- **Compatible avec le cahier des charges sur l'essentiel** : facturation centralisée multi-agences, numérotation atomique sans trou, pas de suppression, avoirs liés, montants en `DECIMAL(15,3)` sans `float`, cautions hors chiffre d'affaires, exports Excel et CSV. Plusieurs briques existent déjà (phase 2) : `SequenceAllocator` (attribution atomique, testée en concurrence), `Money` (3 décimales, arrondi explicite), `HasSequenceNumber` (documents numérotés jamais supprimés), `AuditsChanges`, `AgencyScope`.
- **Écarts réels** : statut de validation de chaque règle fiscale et rapport de configuration (§16), natures taxable / exonérée / hors champ (§2), conditions du timbre (§3), statuts de facture plus fins (§6), suivi du remboursement ou de l'imputation des avoirs (§9.5), TVA sur les dépenses (§9.4), identifiants stables et exports idempotents (§9.6), clôture mensuelle avec contrôles (§13), multidevise (§11), facturation électronique (§8).
- **Charges ajoutées au lot 1** : **8,75 j indispensables**, **5,75 j recommandés**, **7,5 j optionnels**. La facturation électronique TEIF / TTN est chiffrée à part : **18 à 27 j** (22 j en estimation centrale), seulement si l'obligation s'applique à Aviator.
- **Écart cumulé du lot 1** (aujourd'hui 249 j projetés, +4 j) : **257,75 j (+12,75 j, +5,2 %)** avec les seuls points indispensables (détail au §5).
- **Phase 8 et seuil d'alerte** : avec les points recommandés, la phase 8 dépasserait de plus de 20 % (§5.3). Il faudra un rééquilibrage ou votre accord explicite.

## 2. Tableau des écarts

Écart : **aucun** (déjà prévu), **précision** (prévu, à détailler), **ajout** (non prévu). Charge : jours ajoutés à la phase indiquée, hors facturation électronique. Classement : **I** indispensable, **R** recommandé, **O** optionnel.

| N° | § | Exigence | Cahier des charges | Écart | Phase | Charge (j) | Risque si non traité | Recommandation |
| --- | --- | --- | --- | --- | --- | ---: | --- | --- |
| E1 | 2 (1, 7) | Aucun taux codé en dur ; paramètres modifiables sans toucher au code | A-08 ; « toute règle susceptible d'évoluer est un paramètre » (§4.1) ; table `taxes` | Aucun | 3 | 0 | — | Lot 1 (déjà prévu) |
| E2 | 2 (2, 3, 4, 6) | Table fiscale : plusieurs taux, natures **taxable / exonérée / hors champ**, chaque catégorie de prestation liée à un traitement | `taxes` (`kind` taux ou montant fixe, `applies_to`) ; options avec `tax_nature` | Précision : la nature fiscale (taxable, exonérée, hors champ) n'existe pas ; le lien prestation → traitement est partiel | 3 | 0,5 | TVA appliquée à une indemnité hors champ (dégâts) ou oubliée sur un frais taxable : redressement | Lot 1, **I** |
| E3 | 2 (5, 8), 14.10 | Dates de validité, trace et versions de toute modification | `valid_from` / `valid_to` ; journal d'audit (`AuditsChanges`) | Précision : une règle n'est jamais modifiée sur place ; un changement crée une nouvelle version datée, l'ancienne reste lisible | 3 | 0,5 | Facture ancienne recalculée avec un taux nouveau | Lot 1, **I** |
| E4 | 2 (fin), 16 | Statut par règle : `CONFIRMED`, `PROPOSED`, `PENDING_ACCOUNTANT_VALIDATION` ; règle non confirmée jamais considérée comme applicable | « uniquement selon les règles validées » (A-08) ; table `taxes` **vide** tant qu'aucune règle n'est déposée | Ajout : statut, transitions journalisées ; `CONFIRMED` exige un document dans `regles_fiscales_validees/` (valideur, date, exemple chiffré). Les valeurs du document sont saisies en `PROPOSED` ou `PENDING_…` (décision du 18/11/2026) | 3 | (inclus dans E2, E3) | Mise en production avec des règles non validées | Lot 1, **I** |
| E5 | 16 | Rapport de configuration fiscale à faire valider par le cabinet avant la production | Non prévu | Ajout : écran et export (XLSX et PDF) de toutes les règles : valeur, nature, validité, statut, source, exemple chiffré | 3 | 0,5 | Validation par e-mails dispersés, sans preuve | Lot 1, **I** |
| E6 | 16 | Garde-fou : aucune règle non `CONFIRMED` en production | Non prévu | Ajout : `aviator:check-environment` en ERREUR si une règle active n'est pas `CONFIRMED` en production (0,25 j, phase 3) ; refus d'émettre une facture en production avec une telle règle, filigrane « Règles fiscales non confirmées » sur les PDF hors production (0,5 j, phase 6) ; contrôle dans la liste de mise en production (phase 10) | 3, 6, 10 | 0,75 | Factures réelles émises avec des taux proposés | Lot 1, **I** |
| E7 | 3 | Timbre paramétrable (1,000 DT proposé), ligne distincte, hors TVA et hors chiffre d'affaires, conditions et exonérations, historique, inclus dans le net à payer | A-08 (timbre avec date d'effet) ; `taxes` `kind = fixed_amount` | Précision : **conditions d'application et exonérations** paramétrables (type de document, type de client, montant…), à confirmer par le cabinet | 3 (paramètre), 6 (calcul) | 0,5 | Timbre appliqué à tort sur un avoir ou une facture exonérée | Lot 1, **I** |
| E8 | 4 | Mentions : société (RNE, agence émettrice), document (type, contrat, statut), client particulier ou professionnel (RNE si disponible, pays de résidence), lignes (véhicule, immatriculation, dates, jours), totaux par taux, devise, mode de règlement | A-01 (fiche société, `trade_register` = RNE) ; E-01 ; `customers.type`, `country` ; `customer_snapshot` | Précision : RNE du client entreprise (colonne à ajouter), mentions adaptées au type de client, type de document et agence émettrice sur le modèle. Liste à confirmer par le cabinet (règle FACTURE) | 6 | 0,5 | Facture refusée par un client professionnel ou non déductible pour lui | Lot 1, **I** |
| E9 | 5 | Numérotation centralisée `AVI-2026-000001`, agence dans un champ distinct, unique, sans trou, atomique, historique, non modifiable, numéros annulés conservés | A-07, E-03, RG-07 ; `SequenceAllocator` (lot 8 de la phase 2) ; `HasSequenceNumber` | Aucun sur le mécanisme. **Format et remise à zéro annuelle à confirmer** : le document recommande « une séquence centralisée et continue, avec l'année intégrée dans le numéro », ce qui n'indique pas si le compteur repart à 1 en janvier (A-07 dit « par année ») | 6 | 0 | Numérotation contestée lors d'un contrôle | Lot 1 ; question au cabinet (Q11) |
| E10 | 6 | Pas de suppression ; correction par avoir lié ; facture corrigée | E-03, E-04, RG-06 | Aucun | 6 | 0 | — | Lot 1 (déjà prévu) |
| E11 | 6 | Statuts `DRAFT`, `VALIDATED`, `ISSUED`, `PARTIALLY_PAID`, `PAID`, `CREDITED`, `CANCELLED` | `invoices.status` : `draft`, `issued`, `cancelled_by_credit` | Précision : ajouter `validated` (numéro attribué, pas encore émise) et `credited`. **Recommandation** : deux champs au lieu d'un, le **statut du document** (brouillon, validée, émise, avoir total, annulée) et le **statut de paiement** calculé à partir des imputations (non payée, partiellement payée, payée), pour qu'ils ne se contredisent jamais. `CANCELLED` sans avoir : à réserver à une facture validée **jamais émise** (numéro conservé, motif obligatoire), à confirmer par le cabinet (Q79) | 6 | 0,5 | Facture « payée » et « avoir » à la fois ; annulation qui contourne l'avoir | Lot 1, **I** |
| E12 | 7 | 3 décimales, décimaux, TVA calculée sur la base groupée par taux, arrondie à 3 décimales, timbre ajouté séparément | `DECIMAL(15,3)`, `brick/math`, `Money` avec arrondi explicite (phase 2) | Précision : TVA sur la **base par taux** (pas ligne par ligne). Méthode d'arrondi à valider (`PENDING`) ; elle reste un paramètre | 6 | 0 | Écart d'un millime entre la facture et la déclaration | Lot 1 |
| E13 | 8 | Architecture découplée `ElectronicInvoicingProvider`, sans intégration TTN fictive | Q16 : « non chiffré » (cahier §9) | Ajout : interface, fournisseur « désactivé » par défaut (aucune transmission, aucune simulation), champs de suivi sur la facture (statut électronique, référence TTN, fichier signé, accusé) et table des envois, **structure seulement** | 6 | 0,75 | Reprise lourde du modèle de facture si l'obligation tombe après la phase 6 | Lot 1, **I** |
| E14 | 8 | Facturation électronique TEIF / TTN complète | Non chiffrée (Q16) | Ajout majeur, **conditionnel** : voir §3 | — | 18 à 27 | Voir §3 | **Lot ultérieur** (avenant), selon la réponse du cabinet |
| E15 | 9 | Exports XLSX et CSV UTF-8, par période et par agence, consolidés | H-05 ; Q1 (format) | Aucun sur le principe ; le format devient connu (§9.1 à §9.5) | 8 | 0 | — | Lot 1 |
| E16 | 9.1, 9.3 | Colonnes du journal des ventes (code client, type, contrat, immatriculation, nature, timbre, net à payer, statut) et des cautions (justificatif, statut) | H-01, H-03 | Précision : colonnes supplémentaires. Une ligne par facture **et par taux** (le document n'a qu'une colonne « Taux TVA ») : à confirmer (Q82). Les exports restent limités au nom et au matricule fiscal du client (D13) : le code client est l'identifiant interne, sans coordonnées | 8 | 0,5 | Export inutilisable par le cabinet | Lot 1, **I** |
| E17 | 9.2 | Encaissements : caisse, banque, devise ; n factures ↔ n encaissements | E-06, H-02 ; `payment_allocations` (une facture, plusieurs encaissements ; un encaissement, plusieurs factures) | Précision : le modèle couvre déjà le n ↔ n. À ajouter : **banque** (chèque, virement) sur l'encaissement ; export **une ligne par imputation**, plus une ligne pour la part non imputée (acompte). Règle de rapprochement : imputation manuelle avec proposition « la plus ancienne d'abord », à confirmer (Q81) | 6, 8 | 0,5 | Encaissement compté deux fois ou acompte perdu à l'export | Lot 1, **I** |
| E18 | 9.4 | Dépenses : n° de pièce, matricule fiscal du fournisseur, HT, taux, TVA, TTC, mode de paiement, véhicule | G-02, G-04, G-05, H-04 (montant unique) | Ajout : **TVA des dépenses** (HT, taux, TVA, TTC), n° de pièce, mode de paiement | 8 | 1 | TVA déductible non transmise au cabinet | Lot 1, **I** |
| E19 | 9.5 | Avoirs : statut de remboursement ou d'imputation | E-04 (avoir lié) | Ajout : un avoir est **remboursé** (décaissement tracé, en caisse ou par virement) ou **imputé** sur une autre facture, totalement ou partiellement ; statut et export | 6 | 1 | Avoir remboursé deux fois, ou jamais | Lot 1, **I** |
| E20 | 9.6, 14.9 | **Identifiants uniques et stables**, réimport sans doublon ; exports idempotents | H-06 (période exportée close) ; `export_files` (empreinte) | Ajout : identifiant stable par ligne d'export (référence immuable de l'opération, jamais l'ordre de la ligne), exports par plage de dates rejouables à l'identique, versions conservées | 8 | 1 | Doublons dans la comptabilité du cabinet | Lot 1, **I** |
| E21 | 9.6 | Fréquences : quotidienne (ventes, encaissements, cautions, avoirs), hebdomadaire (dépenses), mensuelle (synthèse) | Exports par période, à la demande (H-01 à H-05) | Ajout : génération automatique par le planificateur (fichiers privés, notification push à l'admin, sans donnée client dans le texte). **Remise au cabinet** : le cabinet n'est pas utilisateur (cahier §3.1) et l'e-mail est réservé à la sécurité des comptes ; à décider (Q80) | 8 | 0,5 | Exports quotidiens à télécharger à la main | Lot 1, **R** |
| E22 | 9.6 | Accès du cabinet aux exports | Non prévu : le cabinet « reçoit les exports » | Ajout possible : profil « cabinet » limité aux exports comptables (nom et matricule fiscal seulement, D13), 2FA, journalisé | 8 | 1,5 | — | **O**, décision de la direction (Q80) |
| E23 | 10 | Cautions dans un module distinct, jamais en chiffre d'affaires ; retenue motivée, justifiée, **qualifiée fiscalement** | F-01 à F-08 ; Q51 | Précision : la retenue porte une **nature fiscale** (par exemple dégâts hors champ ou frais taxable), via la facture de frais ou la retenue elle-même, à valider (Q51) | 6 | 0,25 | Retenue entrée en chiffre d'affaires sans TVA, ou l'inverse | Lot 1, **I** |
| E24 | 11 | **Multidevise** : devise du contrat, montant d'origine, taux de change, contre-valeur TND, écarts de change, références du paiement | A-01 : devise TND seulement ; rien sur les devises | Ajout. Deux niveaux : **(a) minimal** : factures toujours en TND, encaissement possible en devise (devise, montant d'origine, taux, contre-valeur TND figée), caisse par devise, colonne devise dans les exports ; **(b) complet** : contrats et factures en devise, table des cours, écarts de change entre facture et paiement | (a) 6, 8 ; (b) 3 à 8 | (a) 1,75 ; (b) 6 | Encaissements en devises non tracés, écarts de caisse inexpliqués | (a) Lot 1, **R**, **si** Aviator reçoit des devises (Q78) ; (b) **O**, lot ultérieur |
| E25 | 12 | Une entité, un matricule, facturation et numérotation uniques, agence sur chaque opération, caisses par agence, vision consolidée | Cahier §3, RG-13 ; `AgencyScope` ; `cash_sessions` par agence | Aucun. « Rapprochements par agence » : compris comme le rapprochement de caisse (E-09) ; le **rapprochement bancaire reste hors périmètre** (cahier §1.3) | — | 0 | — | Lot 1 (déjà prévu) |
| E26 | 13 | **Clôture mensuelle** : écran de préparation avec 12 contrôles (factures, avoirs, encaissements, impayés, cautions non restituées, dépenses, justificatifs manquants, écarts de caisse, TVA collectée, devises, régularisations, cohérence contrats / factures / paiements) | H-06, RG-12 : période exportée close ; E-07, E-09, F-08, G-08 : rapports séparés | Ajout : écran de préparation qui réunit les contrôles avec un état (OK, à vérifier) et un lien vers chaque anomalie (2 j) ; contrôle de cohérence contrats / factures / encaissements (1 j) | 8 | 3 | Clôture d'un mois incomplet | Lot 1, **R** |
| E27 | 13 | Correction d'une période close « autorisée et historisée » | H-06 : plus aucune modification | Précision : la correction normale reste un avoir dans la période ouverte. Réouverture **exceptionnelle** par le super admin, avec motif, journalisée, et nouvelle version des exports de la période (0,5 j). À confirmer par le cabinet (Q83) | 8 | 0,5 | Exports déjà importés par le cabinet modifiés sans trace | Lot 1, **R** |
| E28 | 14 | Principes : règles fiscales séparées, modules, unicité, intégrité, pas de suppression, historique, droits par agence, idempotence, versions, séparation des cautions, tests des calculs | Cahier §6.1 ; `app/Domain` ; tests d'architecture ; Pest sur MariaDB | Aucun ; l'idempotence (E20) et les versions (E3) sont traitées ci-dessus. Chaque calcul fiscal aura des tests, avec les exemples chiffrés du cabinet une fois validés | — | 0 | — | Lot 1 (déjà prévu) |
| E29 | 15 | Instructions à Claude Code | Méthode par phases (prompt maître) | Non exécutée, couverte par la méthode du projet | — | 0 | — | — |

## 3. Facturation électronique TEIF / TTN (estimation séparée)

### 3.1 Ce qu'il faudrait si l'obligation s'applique à Aviator

Selon le document, la loi de finances 2026 et la note commune n° 2/2026 étendent l'obligation aux prestations de services. **La situation d'Aviator, son adhésion à TTN, la date d'application et les mesures transitoires restent à confirmer par le cabinet** (Q16). Les éléments techniques ci-dessous sont des hypothèses de travail, à vérifier sur la documentation officielle de TTN avant tout engagement.

| Lot | Contenu | Charge (j) |
| --- | --- | ---: |
| T1. Génération TEIF | Correspondance facture et avoir → XML TEIF, validation contre le schéma officiel, tests sur des factures types (TVA par taux, timbre, avoir) | 4 à 6 |
| T2. Signature électronique | Signature du XML (signature avancée XML) avec le certificat d'Aviator ; gestion du certificat (stockage chiffré, date d'expiration, alerte) | 3 à 5 |
| T3. Transmission à TTN | Connecteur au service de TTN selon le mode autorisé, environnement de test de TTN, réception de la référence unique | 4 à 6 |
| T4. Rejets et erreurs | Statuts d'envoi, file `database` avec reprises, motifs de rejet lisibles, correction puis renvoi, alerte push à l'admin | 2 à 3 |
| T5. Archivage | XML signé, accusés de réception et PDF conservés sur le disque privé, intégrité (empreinte), inclus dans les sauvegardes ; durée de conservation à confirmer | 1 à 2 |
| T6. PDF pour le client | Version lisible portant la référence TTN (et le code éventuellement exigé) | 1 |
| T7. Recette | Essais avec l'environnement de test de TTN, coordination avec le cabinet et TTN | 3 à 4 |
| **Total** | | **18 à 27 (22 en estimation centrale)** |

Hors charge de développement : adhésion à TTN, certificat de signature, éventuels frais par facture, à chiffrer par Aviator.

### 3.2 Faisabilité sur Oxahost mutualisé (sans `exec` ni `proc_open`)

| Besoin | Faisable ? | Condition |
| --- | --- | --- |
| Générer et valider le XML TEIF | Oui | Extensions PHP `dom`, `libxml` (présentes) |
| Signer le XML | **Oui, si le certificat est un fichier logiciel** (PKCS#12) | Signature en PHP pur avec l'extension `openssl`, sans programme externe. **Non, si le certificat est sur une clé matérielle (jeton USB)** : impossible sur un serveur mutualisé ; il faudrait signer sur un poste d'Aviator ou passer par un prestataire |
| Transmettre à TTN | Oui, en principe | Appel HTTPS sortant par l'extension `curl`. **À vérifier** : TTN peut exiger une adresse IP déclarée, alors que l'IP d'un mutualisé est partagée ; un certificat client TLS est possible avec `curl` |
| Kits fournis par TTN | **Non** s'ils exigent Java ou un programme à lancer | Aucun processus externe sur le mutualisé (D14) |
| Envois asynchrones, reprises | Oui | File `database` et cron chaque minute (D15) |
| Archivage | Oui | Disque privé, sauvegardes JetBackup en Tunisie |
| Saisie manuelle sur le portail TTN | Oui, en secours | Dépôt manuel des XML générés : charge réduite (T3 et T4 allégés, environ −5 j), mais travail manuel à chaque facture |

**Conclusion** : faisable sur le mutualisé **si** le certificat est logiciel et si TTN accepte un appel depuis une IP partagée. Sinon : signature sur un poste, prestataire tunisien agréé, ou hébergement dédié. Ces trois points sont à lever avant de chiffrer définitivement (Q16).

### 3.3 Recommandation

- **Lot 1** : interface `ElectronicInvoicingProvider` et structure de suivi (E13, 0,75 j), pour ne pas reprendre le modèle de facture plus tard.
- **TEIF complet** : avenant séparé, après la réponse du cabinet. Il ne tient pas dans la phase 6 : 22 j de plus sur 31 j représenteraient +71 %, très au-delà du seuil d'alerte de 20 %.
- **Si l'obligation s'applique avant la mise en production du lot 1**, le calendrier de la phase 6 et de la phase 10 est à revoir dès maintenant.

## 4. Points traités en particulier

| Point | Synthèse | Réf. |
| --- | --- | --- |
| Multidevise (§11) | Absent du cahier. Niveau minimal recommandé seulement si Aviator reçoit vraiment des devises ; niveau complet en lot ultérieur | E24, Q78 |
| Clôture mensuelle (§13) | Le cahier prévoit la clôture d'une période exportée, pas l'écran de préparation à 12 contrôles ; réouverture exceptionnelle encadrée | E26, E27, Q83 |
| Rapprochement n factures ↔ n encaissements (§9.2) | Déjà couvert par `payment_allocations` ; ajouter la banque et l'export par imputation ; règle d'imputation à confirmer | E17, Q81 |
| Statuts de validation et rapport de configuration (§16) | Statut par règle, versions, rapport pour le cabinet, garde-fou en production (contrôle de l'environnement, refus d'émission, phase 10) | E4, E5, E6 |
| Fréquence des exports et identifiants stables (§9.6) | Identifiants stables et exports rejouables indispensables ; génération quotidienne automatique recommandée ; mode de remise au cabinet à décider | E20, E21, E22, Q80 |
| Statuts de facture (§6) | Séparer statut du document et statut de paiement ; `CANCELLED` réservé à une facture validée jamais émise | E11, Q79 |
| Interface `ElectronicInvoicingProvider` (§8) | Structure en lot 1, aucune transmission simulée | E13 |

## 5. Charges et écart cumulé du lot 1

### 5.1 Par classement

| Classement | Points | Charge (j) |
| --- | --- | ---: |
| **Indispensable** | E2, E3, E5, E6, E7, E8, E11, E13, E16, E17, E18, E19, E20, E23 (E4 inclus dans E2, E3) | **8,75** |
| **Recommandé** | E21 (0,5), E24 (a) (1,75), E26 (3), E27 (0,5) | **5,75** |
| **Optionnel** | E22 (1,5), E24 (b) (6) | **7,5** |
| Facturation électronique complète (conditionnelle) | E14 | 18 à 27 (22) |

### 5.2 Écart cumulé du lot 1 (référence 245 j ; projection actuelle 249 j, +4 j)

| Scénario | Lot 1 projeté | Écart | Écart (%) |
| --- | ---: | ---: | ---: |
| Actuel (avant cette analyse) | 249 | +4 | +1,6 % |
| + indispensable | **257,75** | **+12,75** | **+5,2 %** |
| + indispensable et recommandé | 263,5 | +18,5 | +7,6 % |
| + indispensable, recommandé et optionnel | 271 | +26 | +10,6 % |
| + indispensable et TEIF complet (22 j) | 279,75 | +34,75 | +14,2 % |

### 5.3 Par phase (seuil d'alerte : +20 % de la charge de référence)

| Phase | Référence (j) | Projection actuelle (j) | + indispensable | + recommandé | Écart avec indispensable et recommandé |
| --- | ---: | ---: | ---: | ---: | --- |
| 3. Paramétrage | 16 | 16,25 | +2,25 (E2, E3, E5, E6 pour 0,25, E7) | — | 18,5 j : **+15,6 %** |
| 6. Facturation | 31 | 31 | +3,75 (E6 pour 0,5, E8, E11, E13, E17 pour 0,25, E19, E23) | +1,5 (E24 a) | 36,25 j : **+16,9 %** |
| 8. Exports | 20 | 20 | +2,75 (E16, E17 pour 0,25, E18, E20) | +4,25 (E21, E24 a pour 0,25, E26, E27) | 27 j : **+35 %, au-delà du seuil** |
| 10. Mise en production | 19 | 19 | 0 (contrôle ajouté à la liste existante) | — | 19 j |

**Alerte phase 8** : avec les points recommandés, elle dépasse le seuil de 20 % (27 j pour 20 j). Avec les seuls points indispensables, elle reste à 22,75 j (+13,75 %). Options : avancer une partie de la clôture (contrôles de caisse et d'impayés) en phase 6, reporter l'écran de préparation à la clôture en phase 9, ou accepter le dépassement.

## 6. Questions nouvelles ou complétées

Reportées dans `docs/questions_ouvertes.md` : Q11, Q16, Q1 et Q30 complétées ; Q78 à Q83 créées (multidevise, statut `CANCELLED`, remise des exports au cabinet, règle d'imputation, forme du journal des ventes, réouverture d'une période close).

## 7. Décisions attendues

1. Valider ou ajuster ce tableau, en particulier le classement indispensable, recommandé ou optionnel.
2. Choisir la réponse à l'alerte de la phase 8 (§5.3).
3. Multidevise : niveau (a) en lot 1 seulement si Aviator reçoit des devises (Q78).
4. TEIF : confirmer le traitement en avenant séparé, et la structure (E13) en lot 1.
5. Après validation : reporter les charges dans `docs/projet.md` §5 et dans les plans des phases 3, 6, 8 et 10.

## 8. Décisions du 18/11/2026 (provisoires, à confirmer par la direction)

Analyse validée par le responsable technique, avec ces décisions :

| Point | Décision | Charge |
| --- | --- | ---: |
| Indispensable (E2 à E23) | Intégré au lot 1 comme **demande d'évolution du client** : phases 3 (+2,25 j), 6 (+3,75 j) et 8 (+2,75 j) | 8,75 j |
| Clôture mensuelle (E26, E27) | Recommandée, **déplacée en phase 9** pour que la phase 8 reste sous +20 % ; en attente de la direction | 3,5 j |
| Multidevise (E24) | Selon Q78 ; version minimale (devise, taux, montant converti) **chiffrée à part** comme option : phase 6 (1,5 j), phase 8 (0,25 j) | 1,75 j |
| Exports quotidiens automatiques (E21) | **Reportés** ; export manuel par période dans le lot 1 | 0 |
| Accès du cabinet (E22), multidevise complète (E24 b) | Non retenus pour le lot 1 | 0 |
| TEIF / TTN (E14) | **Avenant séparé, hors lot 1** ; interface `ElectronicInvoicingProvider` (E13) gardée dans le lot 1 ; questions pour TTN et le cabinet ajoutées à Q16 | (18 à 27 j hors lot 1) |

### Deux scénarios

| Phase | Référence (j) | Indispensable seul | Indispensable + clôture |
| --- | ---: | ---: | ---: |
| 3. Paramétrage | 16 | 18,5 (+15,6 %) | 18,5 (+15,6 %) |
| 6. Facturation | 31 | 34,75 (+12,1 %) | 34,75 (+12,1 %) |
| 8. Exports | 20 | **22,75 (+13,75 %)** | **22,75 (+13,75 %)** |
| 9. Statistiques, mobile, sécurité | 38 | **38,5 (+1,3 %)** | **42 (+10,5 %)** |
| **Lot 1** | **245** | **257,75 (+12,75 j, +5,2 %)** | **261,25 (+16,25 j, +6,6 %)** |
| Lot 1 avec l'option multidevise (+1,75 j) | 245 | 259,5 (+14,5 j, +5,9 %) | 263 (+18 j, +7,3 %) |

Aucune phase ne dépasse le seuil d'alerte de 20 % dans ces scénarios. Avec l'option multidevise, la phase 6 passe à 36,25 j (+16,9 %) et la phase 8 à 23 j (+15 %).

Report dans `docs/projet.md` (§5, §6) et dans les plans des phases 3, 6, 8, 9 et 10. Synthèse pour la direction : `docs/demande_evolution_cabinet.md`.
