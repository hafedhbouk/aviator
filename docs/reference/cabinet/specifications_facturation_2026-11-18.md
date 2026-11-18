# Spécifications fonctionnelles et comptables – Module de facturation Aviator
## Référentiel fiscal et règles de gestion – Tunisie

### 1. Contexte du projet

Nous développons une nouvelle application de gestion pour la société Aviator, spécialisée dans la location de véhicules en Tunisie.

L'application couvre quatre agences :
- Djerba
- Sfax
- Sousse
- Tunis

La comptabilité générale reste tenue par un cabinet comptable externe. L'application Aviator doit donc assurer la gestion commerciale, la facturation, les encaissements, les cautions et les dépenses, puis produire des exports structurés permettant au cabinet d'effectuer les traitements comptables et fiscaux.

**Principe fondamental :** l'application ne doit pas remplacer le logiciel comptable du cabinet. Elle doit fournir des données fiables, exhaustives, traçables et conformes aux règles fiscales tunisiennes.

Les présentes spécifications constituent le référentiel de conception initial. Toute règle fiscale non formellement validée par le cabinet doit rester configurable et ne doit pas être considérée comme définitivement approuvée.

---

### 2. Gestion de la TVA

Le taux normal de TVA en Tunisie est de 19 %, sous réserve des dispositions particulières applicables.

Prévoir le paramétrage suivant :

| Nature de l'opération | Traitement proposé |
|---|---|
| Location de véhicules | TVA 19 % |
| Siège enfant | TVA 19 % |
| GPS | TVA 19 % |
| Conducteur supplémentaire | TVA 19 % |
| Assurance complémentaire | Selon la nature juridique et contractuelle |
| Carburant refacturé | TVA 19 % en principe |
| Retard de restitution | Selon la nature de la somme facturée |
| Dégâts sur véhicule | Hors champ possible en cas d'indemnisation réelle |
| Franchise d'assurance | Selon la nature de la retenue |

**Exigences de développement :**

1. Ne jamais coder les taux de TVA en dur dans les fonctions métier.
2. Créer une table de paramétrage fiscal.
3. Permettre plusieurs taux de TVA.
4. Distinguer les opérations taxables, exonérées et hors champ.
5. Conserver les dates de validité des règles fiscales.
6. Associer chaque catégorie de prestation à un traitement fiscal configurable.
7. Permettre la modification des paramètres sans modifier le code source.
8. Conserver une trace de toute modification du paramétrage fiscal.

Les traitements des assurances, franchises, dommages, retards et carburants doivent être identifiés comme nécessitant une validation du cabinet comptable.

---

### 3. Gestion du timbre fiscal

Prévoir un timbre fiscal paramétrable, avec une valeur initiale proposée de 1,000 DT par facture concernée, sous réserve de confirmation des dispositions applicables et des éventuelles exceptions.

Exigences :

- Afficher le timbre sur une ligne distincte.
- Le distinguer de la TVA et du chiffre d'affaires.
- Prévoir les éventuelles exonérations.
- Paramétrer les conditions d'application.
- Conserver l'historique des modifications du montant.
- Intégrer le timbre dans le calcul du net à payer.

Ne pas considérer automatiquement que toutes les factures sont soumises au même traitement sans validation réglementaire.

---

### 4. Mentions obligatoires des factures

Le modèle de facture doit prévoir les informations suivantes.

**Identification de la société :**
- Raison sociale.
- Adresse du siège social.
- Matricule fiscal.
- Référence RNE.
- Coordonnées.
- Agence émettrice.

**Identification du document :**
- Numéro unique de facture.
- Date d'émission.
- Type de document.
- Référence du contrat de location.
- Statut du document.

**Identification du client particulier :**
- Nom et prénom.
- Adresse.
- Coordonnées.
- Pays de résidence, si pertinent.

**Identification du client professionnel :**
- Raison sociale.
- Adresse.
- Matricule fiscal.
- Référence RNE, si disponible.

**Détail des prestations :**
- Désignation.
- Véhicule concerné.
- Immatriculation.
- Date de début et de fin de location.
- Nombre de jours.
- Quantité.
- Prix unitaire HT.
- Montant HT.
- Taux de TVA.
- Montant TVA.

**Récapitulatif :**
- Total HT.
- Total TVA par taux.
- Total TTC.
- Timbre fiscal.
- Net à payer.
- Devise.
- Mode de règlement, si disponible.

Le système doit pouvoir distinguer les particuliers des entreprises et adapter les informations obligatoires selon la réglementation applicable.

---

### 5. Numérotation des factures

Prévoir une numérotation centralisée pour les quatre agences.

Format recommandé :

AVI-2026-000001

Exemples :
- AVI-2026-000001 – Djerba
- AVI-2026-000002 – Sfax
- AVI-2026-000003 – Tunis
- AVI-2026-000004 – Sousse

L'agence doit être enregistrée dans un champ distinct du numéro de facture.

Exigences :
- Numérotation unique au niveau de la société.
- Aucune duplication entre agences.
- Séquence ininterrompue.
- Attribution atomique du numéro pour éviter les doublons en cas de facturation simultanée.
- Historique des numéros attribués.
- Interdiction de modifier manuellement le numéro d'une facture validée.
- Conservation des numéros des documents annulés.

La politique de remise à zéro annuelle doit rester configurable et être confirmée par le cabinet. La recommandation initiale est de conserver une séquence centralisée et continue, avec l'année intégrée dans le numéro.

---

### 6. Annulation et correction des factures

Une facture validée et émise ne doit jamais être supprimée physiquement.

Mettre en place le workflow suivant :

1. Création de la facture en brouillon.
2. Validation de la facture.
3. Attribution du numéro définitif.
4. Émission de la facture.
5. En cas d'erreur, création d'un avoir lié à la facture initiale.
6. Émission éventuelle d'une facture corrigée.

L'avoir doit comporter :
- Son propre numéro.
- Sa date.
- La référence de la facture initiale.
- Le client.
- L'agence.
- Le motif.
- Les montants HT.
- La TVA.
- Le timbre fiscal, selon le traitement applicable.
- Le montant total.

Prévoir les statuts suivants :

- DRAFT
- VALIDATED
- ISSUED
- PARTIALLY_PAID
- PAID
- CREDITED
- CANCELLED

Le statut CANCELLED ne doit jamais entraîner la suppression de la facture originale.

Toutes les opérations doivent être historisées dans un journal d'audit.

---

### 7. Gestion des arrondis

Utiliser trois décimales pour les montants en dinars tunisiens.

Règles proposées :
- Stocker les montants avec trois décimales.
- Utiliser des types décimaux, jamais des nombres flottants binaires.
- Calculer les montants HT.
- Regrouper les bases imposables par taux de TVA.
- Calculer la TVA sur les bases correspondantes.
- Arrondir la TVA à trois décimales.
- Calculer le TTC.
- Ajouter le timbre fiscal séparément.

Utiliser notamment le type DECIMAL(15,3) en base de données, avec une précision suffisante pour les calculs intermédiaires.

La méthode d'arrondi définitive doit être validée par le cabinet comptable.

---

### 8. Facturation électronique – TEIF / Tunisie TradeNet

L'application doit être conçue pour pouvoir intégrer la facturation électronique tunisienne.

La location de véhicules constitue une prestation de services. Il faut donc tenir compte de l'extension de l'obligation de facturation électronique prévue par la loi de finances 2026 et des modalités précisées par la Note commune n° 2/2026.

Ne pas supposer que la génération d'un PDF constitue une facture électronique réglementaire.

Prévoir une architecture évolutive permettant :

- La génération des données nécessaires au format TEIF.
- La transmission à Tunisie TradeNet (TTN), selon les modalités autorisées.
- La réception de la référence unique.
- La gestion des signatures électroniques.
- La gestion des rejets et des erreurs.
- L'archivage des fichiers électroniques.
- La conservation des accusés de réception.
- La génération d'une version PDF lisible par le client.

**Important :** avant d'activer définitivement le processus, le cabinet doit confirmer la situation d'Aviator, son adhésion éventuelle à TTN, la date d'entrée en application de son obligation et les éventuelles mesures transitoires.

Ne pas développer une intégration TTN fictive ou simuler une transmission réglementaire.

En attendant, préparer une architecture découplée avec une interface de type `ElectronicInvoicingProvider`, qui permettra d'intégrer ultérieurement un connecteur conforme.

---

### 9. Exports comptables destinés au cabinet

Prévoir deux formats d'export :
- Excel XLSX.
- CSV UTF-8.

Les exports doivent être disponibles par période et par agence, avec possibilité de consolidation globale.

#### 9.1 Journal des ventes

Colonnes minimales :

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
- Montant HT.
- Taux TVA.
- Montant TVA.
- Total TTC.
- Timbre.
- Net à payer.
- Statut.

#### 9.2 Journal des encaissements

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

Une facture peut être réglée par plusieurs encaissements.

Un encaissement peut également être affecté à plusieurs factures selon les règles de rapprochement retenues.

#### 9.3 Journal des cautions

Colonnes :

- Date opération.
- Référence contrat.
- Client.
- Agence.
- Montant initial.
- Mode de versement.
- Montant restitué.
- Montant retenu.
- Motif de retenue.
- Justificatif.
- Statut.

Les cautions remboursables doivent être isolées du chiffre d'affaires.

Une retenue doit être justifiée et faire l'objet d'une qualification fiscale et comptable adaptée.

#### 9.4 Journal des dépenses

Colonnes :

- Date dépense.
- Numéro pièce.
- Fournisseur.
- Matricule fiscal fournisseur.
- Agence.
- Catégorie dépense.
- Montant HT.
- Taux TVA.
- Montant TVA.
- Montant TTC.
- Mode de paiement.
- Référence véhicule.

Les justificatifs doivent être attachés ou référencés.

#### 9.5 Journal des avoirs

Colonnes :

- Numéro avoir.
- Date avoir.
- Numéro facture initiale.
- Client.
- Agence.
- Motif.
- Montant HT.
- TVA.
- Timbre.
- Total.
- Statut de remboursement ou d'imputation.

#### 9.6 Fréquence des exports

| Export | Fréquence |
|---|---|
| Ventes | Quotidienne |
| Encaissements | Quotidienne |
| Cautions | Quotidienne |
| Dépenses | Hebdomadaire |
| Avoirs | Quotidienne |
| Synthèse comptable | Mensuelle |

Prévoir des identifiants uniques et stables pour chaque opération afin de permettre les réimportations sans doublons.

---

### 10. Gestion comptable des cautions

Les cautions doivent être gérées dans un module distinct de la facturation.

Cycle de vie :

1. Réception de la caution.
2. Enregistrement du dépôt de garantie.
3. Association au contrat de location.
4. Restitution totale ou partielle.
5. En cas de retenue, enregistrement du motif et du justificatif.
6. Qualification comptable et fiscale de la retenue.

Ne jamais considérer automatiquement une caution comme un produit ou un encaissement de chiffre d'affaires.

---

### 11. Gestion multidevise

Pour les clients étrangers :

- Conserver la devise du contrat.
- Enregistrer le montant dans la devise d'origine.
- Enregistrer le taux de change.
- Conserver le montant converti en TND.
- Identifier les écarts de change.
- Conserver les références du paiement.

La devise de référence comptable reste le dinar tunisien.

---

### 12. Architecture mult agence

Sous réserve que les quatre agences appartiennent à la même personne morale :

- Une seule entité juridique.
- Un seul matricule fiscal.
- Une facturation centralisée.
- Une numérotation unique.
- Une identification obligatoire de l'agence sur chaque opération.
- Des caisses distinctes par agence.
- Des rapprochements par agence.
- Des exports consolidés pour le cabinet.

Ne pas créer quatre sociétés comptables distinctes uniquement parce qu'il existe quatre agences.

Prévoir une gestion des autorisations permettant de limiter les utilisateurs à leur agence, tout en donnant au responsable autorisé une vision consolidée.

---

### 13. Clôture comptable mensuelle

Créer une fonctionnalité de préparation à la clôture mensuelle.

Contrôles obligatoires :

1. Factures émises.
2. Avoirs.
3. Encaissements.
4. Impayés.
5. Cautions non restituées.
6. Dépenses.
7. Justificatifs manquants.
8. Écarts de caisse.
9. TVA collectée.
10. Opérations en devises.
11. Opérations en attente de régularisation.
12. Cohérence entre les contrats, les factures et les paiements.

Une période clôturée ne doit pas pouvoir être modifiée librement. Toute correction ultérieure doit être autorisée et historisée.

---

### 14. Principes techniques non négociables

1. Séparer les règles fiscales de la logique métier.
2. Utiliser une architecture modulaire.
3. Garantir l'unicité des numéros de facture.
4. Assurer l'intégrité référentielle entre factures, contrats, paiements, cautions et avoirs.
5. Interdire la suppression physique des documents comptables émis.
6. Historiser toutes les opérations sensibles.
7. Gérer les droits d'accès par agence et par fonction.
8. Assurer la traçabilité des modifications.
9. Garantir l'idempotence des exports.
10. Conserver les versions des paramètres fiscaux.
11. Séparer les encaissements des factures et les cautions du chiffre d'affaires.
12. Prévoir des tests unitaires et fonctionnels sur tous les calculs fiscaux.

---

### 15. Instructions à Claude Code

Avant toute implémentation :

**Étape 1 – Audit de l'existant**

- Examiner l'architecture actuelle du projet.
- Identifier les modules de facturation, contrats, paiements, cautions, dépenses et agences.
- Examiner le schéma de la base de données.
- Identifier les règles fiscales déjà implémentées.
- Identifier les éventuelles incohérences avec les présentes spécifications.
- Ne pas modifier le code avant d'avoir présenté les résultats de l'audit.

**Étape 2 – Analyse des écarts**

Produire un tableau contenant :
- Fonctionnalité.
- État actuel.
- Règle attendue.
- Écart constaté.
- Risque.
- Modification recommandée.
- Dépendances.

**Étape 3 – Proposition d'architecture**

Présenter :
- Les modifications de base de données.
- Les entités et relations.
- Les services métier.
- Les workflows.
- Les interfaces d'export.
- Les mécanismes de traçabilité.
- Les tests nécessaires.

**Étape 4 – Validation**

Soumettre les choix d'architecture avant toute modification majeure.

**Étape 5 – Implémentation progressive**

Développer par lots indépendants, avec migrations, tests et documentation.

Ne pas casser les fonctionnalités existantes.

**Étape 6 – Vérification**

Tester notamment :
- Calcul TVA 19 %.
- Gestion des différentes catégories fiscales.
- Timbre fiscal.
- Facturation mult agence.
- Numérotation simultanée.
- Avoirs et annulations.
- Arrondis à trois décimales.
- Encaissements partiels.
- Restitution des cautions.
- Exports Excel et CSV.
- Clôture mensuelle.
- Traçabilité.

### 16. Gestion des règles fiscales non confirmées

Il est impératif de distinguer :

- Les règles confirmées par les textes réglementaires.
- Les règles proposées à titre de paramétrage initial.
- Les règles en attente de validation écrite du cabinet comptable.

Créer un statut de validation pour chaque règle fiscale :

- `CONFIRMED`
- `PROPOSED`
- `PENDING_ACCOUNTANT_VALIDATION`

Une règle `PENDING_ACCOUNTANT_VALIDATION` ne doit pas être considérée comme définitivement applicable.

Prévoir un rapport de configuration fiscale permettant au cabinet de valider les règles avant la mise en production.

**Livrable attendu :** une application de gestion Aviator disposant d'un module de facturation fiable, paramétrable, mult agence, compatible avec les obligations fiscales tunisiennes et capable de produire des exports comptables exploitables directement par le cabinet.