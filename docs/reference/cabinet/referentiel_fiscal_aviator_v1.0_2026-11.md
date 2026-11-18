# AVIATOR – RÉFÉRENTIEL FISCAL ET COMPTABLE FINAL
## Spécifications de facturation – Réglementation tunisienne
**Version : 1.0 – Novembre 2026**  
**Périmètre : Djerba, Sfax, Sousse et Tunis**

---

# 1. OBJET ET PÉRIMÈTRE

Le présent document définit les règles fiscales, comptables et fonctionnelles à appliquer dans le module de facturation de l'application Aviator.

Aviator exerce une activité de location de véhicules en Tunisie et dispose de quatre agences :
- Djerba ;
- Sfax ;
- Sousse ;
- Tunis.

La comptabilité générale et les déclarations fiscales restent assurées par un cabinet comptable externe. L'application doit produire les documents commerciaux et les exports nécessaires à la tenue de la comptabilité.

Le système doit garantir :
- La conformité à la réglementation fiscale tunisienne.
- La traçabilité des opérations.
- La cohérence des factures, encaissements et avoirs.
- La séparation des cautions et du chiffre d'affaires.
- La centralisation des opérations des quatre agences.
- La production d'exports comptables fiables.
- La compatibilité avec la facturation électronique tunisienne.

**Principe fondamental :** les règles fiscales doivent être indépendantes du code métier, paramétrables, historisées et évolutives.

# 2. RÉFÉRENTIEL DE TVA

Le taux normal de TVA applicable aux prestations ordinaires de location de véhicules est de 19 %.

Les règles suivantes doivent être intégrées.

## 2.1 Location et options

| Code | Désignation | Traitement fiscal |
|---|---|---|
| RENTAL | Location de véhicule | TVA 19 % |
| CHILD_SEAT | Siège enfant | TVA 19 % |
| GPS | GPS | TVA 19 % |
| EXTRA_DRIVER | Conducteur supplémentaire | TVA 19 % |
| EXTRA_RENTAL_PERIOD | Prolongation de location | TVA 19 % |

Ces opérations constituent des prestations commerciales taxables au taux normal.

## 2.2 Assurance complémentaire

Distinguer impérativement deux situations :

**A. Prime d'assurance véritable**

Lorsqu'il s'agit d'une véritable opération d'assurance relevant du régime de la taxe unique sur les assurances et exonérée de TVA conformément aux dispositions applicables :

- Traitement exonéré de TVA.
- Application éventuelle de la taxe spécifique sur les assurances selon le régime légal.
- Conservation du contrat et du justificatif de l'assureur.
- Identification distincte dans la facture et les exports.

**B. Protection commerciale proposée par Aviator**

Lorsqu'il s'agit d'une option commerciale de réduction de franchise, de protection complémentaire ou d'un service fourni par Aviator sans constituer une véritable opération d'assurance :

- TVA 19 % en principe.
- Comptabilisation comme prestation commerciale.

Codes à créer :

- `INSURANCE_PREMIUM`
- `ADDITIONAL_PROTECTION_SERVICE`
- `EXCESS_REDUCTION_OPTION`

Ne jamais appliquer automatiquement l'exonération à toute ligne comportant le mot « assurance ».

## 2.3 Carburant refacturé

Code : `FUEL_RECHARGE`

Lorsque le carburant est refacturé au client dans le cadre du contrat de location :

- TVA 19 % en principe.
- Intégration dans la base imposable.
- Identification distincte sur la facture.

Le simple remboursement d'une dépense supportée par Aviator ne constitue pas automatiquement un débours exclu de la base imposable.

## 2.4 Retard de restitution

Distinguer :

**A. Prolongation de location**

Lorsque le client conserve le véhicule au-delà de la période contractuelle et que le supplément rémunère la durée supplémentaire :

- Code : `EXTRA_RENTAL_PERIOD`
- TVA : 19 %.

**B. Véritable indemnité de retard**

Lorsque la somme constitue une indemnité réparant un préjudice et ne rémunère aucune prestation supplémentaire :

- Code : `LATE_RETURN_PENALTY`
- Traitement : hors champ de TVA si sa nature indemnitaire est démontrée.
- Conservation de la clause contractuelle et des justificatifs.

La qualification dépend de la réalité économique et contractuelle, et non uniquement du libellé choisi.

## 2.5 Dégâts sur les véhicules

Distinguer :

| Code | Nature | Traitement |
|---|---|---|
| VEHICLE_DAMAGE_COMPENSATION | Indemnisation d'un dommage réel | Hors champ de TVA si indemnitaire |
| REPAIR_SERVICE | Prestation de réparation facturée | TVA selon régime applicable, normalement 19 % |
| DAMAGE_ADMIN_FEE | Frais administratifs de gestion | TVA 19 % en principe |

Une indemnisation réparatrice n'est pas une prestation taxable lorsqu'elle ne constitue pas la contrepartie d'un service.

Justificatifs à conserver :
- État des lieux de départ et de retour.
- Photographies.
- Constat de dommage.
- Devis ou facture de réparation.
- Référence du contrat.
- Éventuel dossier d'assurance.

## 2.6 Franchise d'assurance

Distinguer :

**A. Franchise indemnitaire**

Lorsqu'elle correspond à la participation du client à la réparation d'un préjudice effectivement subi :

- Code : `INSURANCE_EXCESS_COMPENSATION`
- Traitement : hors champ de TVA si indemnitaire.
- Justificatifs du sinistre obligatoires.

**B. Option commerciale de réduction de franchise**

Lorsqu'Aviator facture une option commerciale de protection ou de réduction de franchise :

- Code : `EXCESS_REDUCTION_OPTION`
- TVA : 19 % en principe.

**C. Frais de gestion du sinistre**

- Code : `CLAIM_ADMIN_FEE`
- TVA : 19 % en principe.

Ne pas confondre la franchise indemnisant un dommage avec le prix d'une option commerciale.

## 2.7 Règle technique générale

Chaque catégorie de prestation doit être associée à une configuration fiscale :

- Code fiscal.
- Nature de l'opération.
- Taux de TVA.
- Statut : taxable, exonérée ou hors champ.
- Référence réglementaire.
- Justificatif éventuel.
- Date de début de validité.
- Date de fin de validité.
- Statut de validation.

Les opérations exonérées ou hors champ doivent pouvoir être justifiées et contrôlées.

# 3. TIMBRE FISCAL

## 3.1 Montant

Pour les factures ordinaires concernées d'Aviator, appliquer le timbre fiscal de :

**1,000 DT par facture.**

Référence : article 117 du Code des droits d'enregistrement et de timbre, sous réserve des dispositions particulières applicables.

Le timbre doit être :
- Affiché sur une ligne distincte.
- Séparé de la TVA.
- Séparé du chiffre d'affaires.
- Ajouté au total TTC pour déterminer le net à payer.

## 3.2 Champ d'application

Le timbre concerne en principe :
- Les factures de location aux particuliers.
- Les factures de location aux entreprises.
- Les factures comportant des options.
- Les factures comportant des prestations annexes.

Le mode de paiement ne constitue pas à lui seul une exonération.

## 3.3 Exonérations

Appliquer uniquement les exonérations expressément prévues par l'article 118 du Code des droits d'enregistrement et de timbre.

Prévoir notamment le traitement particulier :
- Des factures non acceptées par les débiteurs, dans les conditions légales.
- Des factures non acquittées, dans les conditions légales.
- Des factures relatives à certaines opérations d'exportation.
- Des autres exceptions expressément prévues par le Code.

Attention : une facture simplement marquée « impayée » dans l'application ne doit pas entraîner automatiquement une exonération du timbre. Les conditions légales et les justificatifs doivent être vérifiés.

Le tarif particulier introduit pour certaines factures de grandes surfaces commerciales en 2026 ne doit pas être appliqué automatiquement à Aviator, dont l'activité est la location de véhicules.

## 3.4 Paramétrage

Créer une table `stamp_duty_rules` avec :

- `rule_code`
- `description`
- `amount`
- `applicability`
- `exemption_condition`
- `legal_reference`
- `effective_from`
- `effective_to`

Paramétrage initial : 1,000 DT par facture ordinaire concernée.

# 4. NUMÉROTATION DES FACTURES

## 4.1 Règle retenue

Mettre en place une série de numérotation centralisée et continue pour les quatre agences.

Ne pas réinitialiser le compteur chaque année.

Format recommandé :

`AVI-000001`

Exemple :

| Numéro | Agence |
|---|---|
| AVI-000125 | Djerba |
| AVI-000126 | Sfax |
| AVI-000127 | Tunis |
| AVI-000128 | Sousse |

L'année et l'agence doivent être conservées dans des champs distincts.

Cette organisation permet :
- D'éviter les doublons.
- De maintenir une séquence documentaire continue.
- De faciliter les contrôles fiscaux.
- De centraliser les factures.

## 4.2 Contraintes techniques

- Attribution atomique du numéro.
- Garantie d'unicité en base de données.
- Gestion des accès concurrents.
- Interdiction de modifier le numéro après émission.
- Interdiction de réutiliser un numéro.
- Conservation des numéros des factures annulées.
- Journalisation des attributions.

Toute modification future de la politique de numérotation devra être validée par le cabinet comptable et respecter l'article 18 du Code de la TVA.

# 5. MÉTHODE D'ARRONDI

Adopter trois décimales pour tous les montants en dinars tunisiens.

La méthode retenue pour Aviator est la suivante :

1. Calcul du montant HT de chaque ligne.
2. Conservation de trois décimales.
3. Regroupement des lignes par taux de TVA.
4. Addition des bases imposables de chaque taux.
5. Calcul de la TVA sur chaque base agrégée.
6. Arrondi de la TVA à trois décimales.
7. Calcul du total TTC.
8. Ajout du timbre fiscal après le TTC.

Exemple :

| Désignation | Montant |
|---|---:|
| Location | 100,000 DT |
| GPS | 10,000 DT |
| Siège enfant | 5,000 DT |
| Total HT | 115,000 DT |
| TVA 19 % | 21,850 DT |
| Total TTC | 136,850 DT |
| Timbre fiscal | 1,000 DT |
| Net à payer | 137,850 DT |

Exigences techniques :

- Utiliser `DECIMAL(15,3)`.
- Ne jamais utiliser `FLOAT` ou `DOUBLE` pour les montants monétaires.
- Utiliser une bibliothèque de calcul décimal.
- Centraliser les règles d'arrondi dans un service dédié.
- Garantir la cohérence des calculs entre factures, avoirs et exports.

Cette méthode constitue la convention de calcul retenue pour Aviator. Elle ne doit pas être présentée comme une méthode d'arrondi expressément imposée par le Code de la TVA.

# 6. ANNULATION ET AVOIRS

Une facture validée et émise ne doit jamais être supprimée physiquement.

Workflow :

1. Création en brouillon.
2. Validation.
3. Attribution du numéro définitif.
4. Émission.
5. Création d'un avoir en cas d'annulation ou de correction.
6. Émission éventuelle d'une facture corrigée.

Chaque avoir doit contenir :
- Son propre numéro.
- Sa date.
- La référence de la facture initiale.
- Le client.
- L'agence.
- Le motif.
- Les montants HT.
- Les montants TVA.
- Le timbre applicable.
- Le montant total.

Toute correction doit être historisée.

Aucun effacement physique d'une facture émise ne doit être possible.

# 7. FACTURATION ÉLECTRONIQUE – TEIF / TUNISIE TRADENET

## 7.1 Fondement réglementaire

La loi n° 2025-17 du 12 décembre 2025 portant loi de finances pour 2026, notamment son article 53, a étendu le champ de la facturation électronique aux opérations de prestations de services.

Cette disposition modifie l'article 18 du Code de la TVA.

La date légale d'entrée en vigueur de cette extension est :

**1er janvier 2026.**

L'activité de location de véhicules exercée par Aviator constitue une prestation de services et entre donc dans le champ de cette extension.

## 7.2 Situation des prestataires

La Note commune n° 2/2026 précise les modalités d'application de cette extension.

Il faut distinguer :

**Situation A : adhésion finalisée**

Lorsque l'entreprise a adhéré au réseau et accompli les formalités nécessaires, elle doit émettre ses factures selon le dispositif électronique applicable depuis le 1er janvier 2026.

**Situation B : demande d'adhésion déposée, formalités en cours**

La doctrine administrative prévoit la possibilité de continuer à utiliser des factures papier conformes pendant la finalisation des formalités d'intégration, sous réserve de disposer de la preuve du dépôt de la demande et de respecter les conditions applicables.

**Situation C : aucune demande déposée**

La société doit engager immédiatement les démarches d'adhésion et régulariser sa situation.

## 7.3 Vérifications obligatoires pour Aviator

Avant de déterminer la situation opérationnelle exacte, vérifier :

- L'existence d'un compte TTN.
- L'existence d'une demande d'adhésion.
- La date de dépôt de cette demande.
- L'attestation éventuelle d'adhésion.
- L'accomplissement des formalités fiscales.
- L'existence d'un certificat ou moyen de signature électronique.

La date du 1er janvier 2026 est la date légale d'extension, et non nécessairement la date d'activation technique individuelle du système pour Aviator.

Ne pas inventer une date d'adhésion ou considérer l'entreprise comme techniquement intégrée sans justificatif.

## 7.4 Architecture technique à prévoir

Prévoir une architecture découplée permettant :

- La génération des données de facture.
- La production du format électronique TEIF selon les spécifications applicables.
- La transmission par un opérateur autorisé.
- L'intégration TTN.
- La signature électronique.
- La réception d'une référence unique.
- La gestion des accusés de réception.
- La gestion des rejets.
- L'archivage des fichiers originaux.
- La génération d'une copie lisible pour le client.

Créer une interface de service :

`ElectronicInvoicingProvider`

Cette interface devra permettre de développer ultérieurement le connecteur TTN sans modifier la logique métier de facturation.

Ne jamais simuler une transmission réglementaire.

Un PDF envoyé par e-mail n'est pas, à lui seul, une facture électronique réglementaire.

Références :
- Article 18 du Code de la TVA.
- Loi n° 2025-17 du 12 décembre 2025.
- Note commune n° 2/2026.
- Décret gouvernemental n° 2016-1066 du 15 août 2016.

# 8. GESTION DES CAUTIONS

Les cautions doivent être gérées indépendamment du chiffre d'affaires.

Cycle de vie :

1. Réception.
2. Enregistrement.
3. Association au contrat.
4. Conservation.
5. Restitution totale ou partielle.
6. Retenue éventuelle avec justification.
7. Qualification fiscale et comptable de la retenue.

Les cautions remboursables ne doivent pas être automatiquement intégrées au chiffre d'affaires.

Les retenues pour dégâts ou sinistres doivent être qualifiées selon leur nature réelle.

Prévoir les champs :
- Référence contrat.
- Client.
- Agence.
- Montant initial.
- Mode de versement.
- Montant restitué.
- Montant retenu.
- Motif.
- Justificatif.
- Statut.

# 9. EXPORTS COMPTABLES

Formats obligatoires :
- Excel XLSX.
- CSV UTF-8.

Les exports doivent être disponibles par période, par agence et en consolidation globale.

## 9.1 Journal des ventes

Colonnes :
- Date facture.
- Numéro facture.
- Agence.
- Code client.
- Nom client.
- Matricule fiscal.
- Type client.
- Référence contrat.
- Immatriculation.
- Nature opération.
- Code fiscal.
- Montant HT.
- Taux TVA.
- Montant TVA.
- Total TTC.
- Timbre.
- Net à payer.
- Statut.

## 9.2 Journal des encaissements

Colonnes :
- Date encaissement.
- Numéro facture.
- Agence.
- Client.
- Mode de paiement.
- Référence paiement.
- Montant.
- Devise.
- Caisse.
- Banque.

Prévoir les paiements partiels et multiples.

## 9.3 Journal des cautions

Colonnes :
- Date opération.
- Référence contrat.
- Client.
- Agence.
- Montant initial.
- Montant restitué.
- Montant retenu.
- Mode de paiement.
- Motif.
- Justificatif.
- Statut.

## 9.4 Journal des dépenses

Colonnes :
- Date dépense.
- Numéro pièce.
- Fournisseur.
- Matricule fiscal.
- Agence.
- Catégorie.
- Montant HT.
- Taux TVA.
- Montant TVA.
- Montant TTC.
- Mode de paiement.
- Référence véhicule.

## 9.5 Journal des avoirs

Colonnes :
- Numéro avoir.
- Date.
- Facture initiale.
- Client.
- Agence.
- Motif.
- Montant HT.
- TVA.
- Timbre.
- Total.
- Statut.

## 9.6 Fréquence

| Export | Fréquence |
|---|---|
| Ventes | Quotidienne |
| Encaissements | Quotidienne |
| Cautions | Quotidienne |
| Avoirs | Quotidienne |
| Dépenses | Hebdomadaire |
| Synthèse comptable | Mensuelle |

Tous les exports doivent contenir des identifiants uniques et stables pour éviter les doublons lors des réimportations.

# 10. GESTION MULTI-AGENCES

Sous réserve que les quatre agences relèvent de la même personne morale :

- Une seule entité juridique.
- Un seul matricule fiscal.
- Une numérotation centralisée.
- Une facturation commune.
- Une identification obligatoire de l'agence.
- Des caisses distinctes.
- Des états de rapprochement par agence.
- Des exports consolidés.

Les autorisations doivent permettre :
- Aux utilisateurs d'agence de consulter les opérations de leur agence.
- Aux responsables habilités de consulter plusieurs agences.
- À la direction de disposer d'une vision consolidée.

# 11. CLÔTURE COMPTABLE

Prévoir une fonctionnalité de clôture mensuelle avec les contrôles suivants :

- Factures émises.
- Avoirs.
- Encaissements.
- Impayés.
- Cautions non restituées.
- Dépenses.
- Justificatifs manquants.
- Écarts de caisse.
- TVA collectée.
- Opérations en devises.
- Opérations en attente de régularisation.
- Cohérence contrats/factures/paiements.

Une période clôturée ne doit pas être modifiable librement.

Toute correction ultérieure doit être autorisée et historisée.

# 12. EXIGENCES TECHNIQUES NON NÉGOCIABLES

1. Séparer les règles fiscales de la logique métier.
2. Utiliser des montants décimaux.
3. Garantir l'unicité des numéros de facture.
4. Interdire la suppression physique des factures émises.
5. Historiser toutes les opérations sensibles.
6. Gérer les droits par agence.
7. Conserver les justificatifs.
8. Gérer les versions des règles fiscales.
9. Garantir l'idempotence des exports.
10. Séparer les cautions des ventes.
11. Séparer les encaissements des factures.
12. Prévoir les tests unitaires et fonctionnels.

# 13. PLAN D'EXÉCUTION DEMANDÉ À CLAUDE CODE

Avant toute modification du code source, effectuer un audit complet de l'existant.

## Phase 1 – Audit

Examiner :
- Architecture applicative.
- Base de données.
- Module de facturation.
- Gestion des contrats.
- Gestion des paiements.
- Gestion des cautions.
- Gestion des dépenses.
- Gestion des agences.
- Règles fiscales existantes.
- Exports comptables.

Ne modifier aucun code pendant cette phase.

## Phase 2 – Analyse des écarts

Produire un tableau :
- Fonctionnalité.
- État actuel.
- Règle attendue.
- Écart.
- Risque.
- Correction proposée.
- Dépendances.

## Phase 3 – Architecture

Présenter :
- Les entités.
- Les relations.
- Les migrations nécessaires.
- Les services métier.
- Les workflows.
- Les interfaces d'export.
- Les mécanismes de traçabilité.
- Les tests.

## Phase 4 – Validation

Présenter le plan d'implémentation avant toute modification majeure.

## Phase 5 – Développement

Développer progressivement, par lots indépendants.

Respecter les fonctionnalités existantes et éviter toute régression.

## Phase 6 – Tests

Tester notamment :
- TVA 19 %.
- Assurance exonérée et protection commerciale taxable.
- Carburant refacturé.
- Retard et prolongation.
- Dégâts et franchises.
- Timbre fiscal.
- Numérotation mult agence.
- Factures simultanées.
- Avoirs.
- Arrondis.
- Paiements partiels.
- Cautions.
- Exports.
- Clôtures.
- Traçabilité.

# 14. STATUT DES RÈGLES ET CONTRÔLES AVANT MISE EN PRODUCTION

Le système doit distinguer les paramètres métier des conditions de conformité non encore documentées.

Créer trois statuts de validation :

- `CONFIRMED` : règle validée et documentée.
- `CONDITIONAL` : application conditionnée à la nature réelle de l'opération et à ses justificatifs.
- `PENDING_DOCUMENTATION` : pièce ou confirmation nécessaire avant activation.

Pour Aviator, les éléments suivants doivent faire l'objet d'une vérification documentaire :

1. Nature juridique des options d'assurance et contrats avec les assureurs.
2. Clauses contractuelles relatives aux retards.
3. Conditions de retenue des franchises.
4. Justification des indemnités pour dégâts.
5. Situation exacte de l'adhésion TTN et date de dépôt de la demande.
6. Identité juridique et matricule fiscal communs aux quatre agences.
7. Conditions précises d'application des exonérations de timbre.

Ne pas inventer de justificatifs ni considérer une formalité comme accomplie sans preuve.

---

# 15. OBJECTIF FINAL

Le résultat attendu est un module de facturation Aviator :

- Conforme au référentiel fiscal tunisien.
- Centralisé pour les quatre agences.
- Fiable dans ses calculs.
- Traçable dans ses opérations.
- Paramétrable sans modification du code source.
- Compatible avec la facturation électronique tunisienne.
- Capable de produire des exports comptables complets et exploitables par le cabinet.

**Instruction finale :** ce référentiel remplace les propositions fiscales précédentes pour la conception du module. Les règles conditionnelles doivent être appliquées selon la nature économique et juridique réelle des opérations, et non sur la seule base de leur libellé commercial. Aucune fonctionnalité ne doit simuler une conformité fiscale ou électronique non effectivement obtenue.