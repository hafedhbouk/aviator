# Questions ouvertes — Aviator

Dernière mise à jour : 17/11/2026 (validation de la phase 1).

Chaque question doit être tranchée avant la phase indiquée. Tant qu'elle ne l'est pas, la fonctionnalité concernée reste **désactivée** ou utilise la **valeur par défaut** indiquée. Cette valeur est un paramètre modifiable par le super admin (table `settings`), jamais une constante dans le code.

**Règle fiscale** : les questions marquées **BLOQUANTE** n'ont aucune valeur par défaut. Seul un document déposé dans `docs/reference/regles_fiscales_validees/` permet de les paramétrer. Tant qu'il manque, l'émission de facture affiche « Règle fiscale non validée » et reste refusée en production.

Statuts : **Ouverte** · **Répondue** (réponse à reporter dans `docs/decisions.md`) · **Close**.

## Sommaire

0. [Tri par blocage](#tri-par-blocage-validation-de-la-phase-1-17112026)
1. [Questions du cahier des charges (Q1 à Q23)](#1-questions-du-cahier-des-charges-q1-à-q23)
2. [Incohérences relevées dans le cahier des charges (Q24 à Q31)](#2-incohérences-relevées-dans-le-cahier-des-charges-q24-à-q31)
3. [Réservations et tarifs (Q32 à Q41)](#3-réservations-et-tarifs-q32-à-q41)
4. [Contrats et états des lieux (Q42 à Q49)](#4-contrats-et-états-des-lieux-q42-à-q49)
5. [Facturation, encaissements et cautions (Q50 à Q56)](#5-facturation-encaissements-et-cautions-q50-à-q56)
6. [Charges et statistiques (Q57 à Q62)](#6-charges-et-statistiques-q57-à-q62)
7. [CRM et données personnelles (Q63 à Q68)](#7-crm-et-données-personnelles-q63-à-q68)
8. [Organisation, hébergement et technique (Q69 à Q76)](#8-organisation-hébergement-et-technique-q69-à-q76)
9. [Récapitulatif par échéance](#9-récapitulatif-par-échéance)

---

## Tri par blocage (validation de la phase 1, 17/11/2026)

La colonne **Bloque** de chaque tableau indique la première phase qui ne peut pas être **acceptée** sans la réponse : la valeur par défaut ne suffit pas, ou elle entraînerait une reprise importante.

| Catégorie | Questions |
| --- | --- |
| **Bloquent la phase 2** | Q2, Q6 (nom de domaine), Q8, Q27 (confirmation), Q71 |
| **Bloquent les phases 3 à 5** | Q5 et Q75 (langue et moteur des PDF), Q10, **Q11**, **Q30** (fiscal, phase 3), Q12, Q28, Q29, Q32, Q33, Q34, Q72 |
| **Autres** | Toutes les autres : elles ont une valeur par défaut paramétrable, ou concernent les phases 6 et suivantes. Q16 (TEIF) est envoyée à l'expert-comptable : elle peut changer le périmètre de la phase 6 |
| Closes | Q70 (MariaDB 10.11.19), Q74 (ateliers) |

---

## 1. Questions du cahier des charges (Q1 à Q23)

Reprises de la section 9 du cahier des charges, complétées d'une valeur par défaut.

| N° | Bloque | Question | Destinataire | Avant la phase | Valeur par défaut (paramètre) | Statut |
| --- | --- | --- | --- | --- | --- | --- |
| Q1 | — | Quel logiciel l'expert-comptable utilise-t-il, et sous quel format veut-il recevoir les exports ? *Note : le cahier demande une réponse avant la phase 1 (§9), mais fixe le format « avant la phase 8 » (§4.8). Proposition : le nom du logiciel en phase 1, pour anticiper H-07 et H-08 ; le format complet avant la phase 8.* | Expert-comptable | 1 (logiciel), 8 (format) | **BLOQUANTE pour l'acceptation de la phase 8.** En attendant, exports génériques Excel et CSV (UTF-8, séparateur `;`) avec les colonnes listées en H-01 à H-04, sans code comptable. | Ouverte |
| Q2 | **Phase 2** | Qui détient le compte super admin ? Faut-il un second compte de secours ? | Direction | 2 | Un seul compte super admin. Recommandation : un compte de secours désactivé, réactivable par le responsable technique en ligne de commande, avec journalisation. | Ouverte |
| Q3 | — | Restitution possible dans une autre agence que celle du départ ? Transferts de véhicules entre agences ? | Direction | 2 | Non : retour à l'agence de départ (`reservations.allow_one_way = false`). Le modèle de données prévoit quand même `return_agency_id` et la localisation courante du véhicule. Voir aussi Q26. | Ouverte |
| Q4 | — | Un utilisateur d'agence doit-il voir le téléphone d'un client pendant une location en cours (pour le joindre en cas de retard) ? | Direction | 2 | Oui, uniquement sur un contrat **en cours de son agence** (`crm.show_phone_on_active_contract = true`), consultation journalisée. | Ouverte |
| Q5 | Phases 3 à 5 | Langues : back-office en français ; espace client et documents clients aussi en anglais et en arabe ? | Direction | 1 (documents), 11 (espace client) | Français uniquement. Libellés dans `lang/fr` pour permettre d'ajouter des langues plus tard. Documents PDF en français, avec dompdf (voir Q75 pour l'arabe). | Ouverte |
| Q6 | **Phase 2 (domaine)** | Nom de domaine, logo et charte graphique d'Aviator | Direction | 1 | Maquettes en fils de fer neutres (gris), sans logo. Couleurs et logo paramétrables dans la fiche société (A-01). | Ouverte |
| Q7 | — | Budget, calendrier cible et date de mise en service du lot 1 ; horizon souhaité pour le lot 2 | Direction | 2 | Aucune. Budget de référence : 245 j (lot 1) et 45 j (lot 2). | Ouverte |
| Q8 | **Phase 2** | Confirmation écrite d'Oxahost : calendrier de PHP 8.4, cron toutes les minutes et Composer, lieu des sauvegardes, prix annuel exact (358,80 ou 418,68 DT HT). *À compléter : fonctions `exec` / `proc_open` et `mysqldump`, extensions PHP (voir Q71). Version de la base : réglée par Q70 (MariaDB 10.11.19).* | Responsable technique, Oxahost | 2 | **Réponse partielle d'Oxahost (17/11/2026)**. Confirmé : PHP 8.3 (PHP Selector, CloudLinux, extensions parfois restreintes) ; PHP 8.4 « prochainement », sans date, donc on reste en 8.3 ; SSH avec Git, Composer, mysqldump et tar ; JetBackup stocké en Tunisie ; sous-domaines hors de `public_html` avec SSL gratuit. **Encore ouvert** (relance envoyée) : cron toutes les minutes (l'application supporte 5 min, D20) ; SSH depuis l'extérieur avec une clé ou seulement le terminal cPanel (variante manuelle, D22) ; prix. Vérification par le responsable technique pendant la garantie de 30 jours. | Partiellement répondue |
| Q9 | — | Téléphones du super admin et des agences : Android ou iPhone ? Pour l'iPhone, iOS 16.4 ou plus est nécessaire aux notifications push | Direction | 9 | Tests sur un Android récent (Chrome) et un iPhone sous iOS 16.4 ou plus (Safari, application ajoutée à l'écran d'accueil). | Ouverte |
| Q10 | Phases 3 à 5 | Grilles tarifaires par agence et par saison, remises, et seuil de remise des utilisateurs d'agence ? | Direction | 3 | Grille par catégorie et par saison, commune aux 4 agences, avec une surcharge par agence possible. Seuil de remise à **0 %** pour tous les profils : toute remise passe par l'approbation du super admin, jusqu'à ce que la direction fixe un seuil (voir Q52). | Ouverte |
| Q11 | Phases 3 à 5 | Taux de TVA, timbre fiscal et mentions obligatoires des factures ; numérotation commune ou par agence ? | Expert-comptable | 3 | **BLOQUANTE**, aucune valeur par défaut. Voir aussi Q30 (avoirs). | Envoyée à l'expert-comptable (17/11/2026) |
| Q12 | Phases 3 à 5 | Contrats de leasing en cours : sociétés, échéanciers, apports, valeurs résiduelles, pour chaque véhicule | Direction | 4 | Aucune. Saisie manuelle par l'admin en phase 4, ou import Excel en phase 10. | Ouverte |
| Q13 | — | Par « apports », entend-on bien l'apport initial versé à l'achat ou au leasing ? Faut-il aussi suivre des apports d'associés ? | Direction | 4 | Apport initial à l'acquisition ou au leasing uniquement. Pas d'apports d'associés : ils relèvent de la comptabilité générale, hors périmètre. | Ouverte |
| Q14 | — | Règles de retard, de restitution anticipée, montants et modes de caution par catégorie | Direction | 5 | Retard : tolérance de 60 min (`pricing.late_tolerance_minutes`), puis une journée supplémentaire au tarif journalier du contrat (`pricing.late_unit = day`). Restitution anticipée : pas de remboursement, D-08 désactivé (`contracts.early_return_refund = none`). Caution : montant par catégorie, **vide** par défaut, donc saisi à chaque contrat. | Ouverte |
| Q15 | — | Modèle de contrat et conditions générales validés juridiquement ? | Conseil juridique | 5 | Modèle provisoire dont le PDF porte la mention « Modèle non validé » tant que `contracts.template_validated = false`. | Ouverte |
| Q16 | — | Facturation électronique (TEIF / TTN) obligatoire pour Aviator ? | Expert-comptable | 6 | **BLOQUANTE pour le périmètre** : si elle est obligatoire, c'est un développement non chiffré (cahier §9). | Envoyée à l'expert-comptable (17/11/2026) |
| Q17 | — | Niveaux de recommandation, motifs autorisés, durée de conservation d'une inscription sur liste noire | Direction, conseil juridique | 7 | Les 4 niveaux du cahier (recommandé, standard, vigilance, liste noire). Motif choisi dans une liste fermée, plus un commentaire factuel. Durée de conservation **vide** : aucune purge automatique avant décision. | Ouverte |
| Q18 | — | Format actuel du fichier des ~1 000 clients et qualité des données (doublons, pièces manquantes) | Direction | 7 | Aucune. Un échantillon anonymisé est nécessaire avant la phase 7 pour écrire l'import. | Ouverte |
| Q19 | — | Méthode de rentabilité : sur quelle durée répartir l'apport et le prix d'achat d'un véhicule ? | Direction, expert-comptable | 9 | Apport réparti linéairement sur la durée du leasing ; prix d'achat comptant réparti sur une durée de détention prévue, saisie par véhicule (`fleet.default_holding_months = 48`). Voir aussi Q59 (crédit). | Ouverte |
| Q20 | — | Durée de conservation des pièces d'identité et des permis | Conseil juridique | 9 | **Vide** : purge automatique désactivée tant que la durée n'est pas fixée. | Ouverte |
| Q21 | — | Données à reprendre (véhicules, contrats en cours, impayés, cautions) et leur format | Direction | 10 | Aucune. Voir aussi Q60 (historique pour les comparaisons N-1). | Ouverte |
| Q22 | — | Lot 2 : réservation en ligne confirmée immédiatement ou après validation de l'agence ? Conditions d'annulation et de modification en ligne ? | Direction | 11 | Validation par l'agence (`online.auto_confirm = false`), expiration au bout de 24 h (RG-21). Modification et annulation en ligne jusqu'à 48 h avant le départ. | Ouverte |
| Q23 | — | Lot 2 : conditions de réservation en ligne et politique de confidentialité validées juridiquement ? | Conseil juridique | 11 | Aucune : pas de mise en ligne de l'espace client sans validation. | Ouverte |

---

## 2. Incohérences relevées dans le cahier des charges (Q24 à Q31)

| N° | Bloque | Question | Origine | Destinataire | Avant la phase | Valeur par défaut (paramètre) | Statut |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q24 | — | Le cahier dit que le client peut « réserver, **payer** et suivre » ses locations, alors que le paiement en ligne est exclu (L-10). Corriger le texte en « réserver et suivre, sans paiement en ligne » ? | §1.2 objectif 5 et §3 (profil Client) contre §1.3, L-10 et la décision du 17/11/2026 | Direction | 1 | Aucun paiement en ligne. Le cahier est à corriger. | Ouverte |
| Q25 | — | E-10 prévoit l'« envoi des factures par e-mail en PDF », alors que le §4.10 (et le `CLAUDE.md`) réserve l'e-mail à la sécurité des comptes. E-10 est-il maintenu ? | E-10 contre §4.10 | Direction | 6 | E-10 désactivé (`billing.email_invoices = false`). La facture est remise en PDF au comptoir, puis disponible dans l'espace client au lot 2. | Ouverte |
| Q26 | — | Si la restitution dans une autre agence est autorisée (Q3), l'agence de retour doit faire l'état des lieux, facturer les frais et encaisser sur le contrat d'une autre agence, ce que RG-13 interdit. Quelle agence porte ces opérations ? | Q3 contre RG-13 | Direction | 2 | Si Q3 = oui : l'agence de retour reçoit un droit limité « retour » sur le contrat (état des lieux de retour, facture complémentaire, encaissement dans **sa** caisse). Le contrat reste rattaché à l'agence de départ. | Ouverte |
| Q27 | **Phase 2 (confirmation)** | Plusieurs fonctions donnent à l'admin une liste de clients, ce qui contredit RG-14 : journal des ventes (nom, matricule fiscal, H-01), impayés et relances (E-07, E-08), « meilleurs clients » exportable (I-19), segments (K-16). Que peut voir l'admin ? | RG-14 et K-03 contre H-01, E-07, E-08, I-19, K-16 | Direction | 2 | **H-01** : nom et matricule fiscal (nécessaires au comptable), sans coordonnées. **Impayés et relances** : nom et téléphone des seuls clients en impayé, consultation journalisée. **I-19, I-18 et segments** : super admin uniquement, y compris l'export. | **Répondue** (D13, appliquée par défaut) ; **à confirmer par la direction** |
| Q28 | Phases 3 à 5 | Le statut véhicule « Réservé » est unique, alors que les réservations sont datées : un véhicule peut être loué aujourd'hui et réservé le mois prochain. Que signifie « Réservé » ? | §5.1 et prompt §6 | Direction, responsable technique | 4 | Le statut décrit l'**état physique courant**. « Réservé » = une réservation confirmée affecte ce véhicule et son départ a lieu dans les X heures (`fleet.reserved_status_window_hours = 24`). La disponibilité se calcule **toujours sur les périodes**, jamais sur le statut. | Ouverte |
| Q29 | Phases 3 à 5 | La machine à états n'a pas de transition pour plusieurs cas réels : sinistre ou vol pendant une location (Loué → Indisponible), panne d'un véhicule réservé (Réservé → Maintenance ou Indisponible), immobilisation longue en atelier (Maintenance → Indisponible), épave (Maintenance → Sorti), assurance expirée pendant une location. Peut-on les ajouter ? | §5.1 et prompt §6 | Direction | 4 | Ajout de ces 5 transitions, chacune tracée. La réservation concernée est désaffectée, puis signalée à l'agence pour être réaffectée. | Ouverte |
| Q30 | Phases 3 à 5 | Les avoirs suivent-ils la même séquence que les factures ou une séquence distincte ? Comment numéroter les factures complémentaires ? (A-07 parle d'une numérotation « par année », Q11 de « commune ou par agence ».) | A-07, RG-07, Q11 | Expert-comptable | 3 | **BLOQUANTE**, aucune valeur par défaut. | Envoyée à l'expert-comptable (17/11/2026) |
| Q31 | — | C-08 ne contient pas le statut « en attente de validation de l'agence », pourtant utilisé par L-09 et RG-21, ni de statut pour le client qui ne se présente pas (voir Q39). Peut-on les ajouter dès le lot 1 ? | C-08 contre L-09, RG-21 | Responsable technique | 4 | Ajout de `pending` (inutilisé au lot 1) et de `no_show` à la liste des statuts. | Ouverte |

---

## 3. Réservations et tarifs (Q32 à Q41)

| N° | Bloque | Question | Origine | Destinataire | Avant la phase | Valeur par défaut (paramètre) | Statut |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q32 | Phases 3 à 5 | Comment compte-t-on les jours facturés : par tranches de 24 h à partir de l'heure de départ, ou par jours calendaires ? | A-04, C-06, RG-09 | Direction | 3 | Tranches de 24 h glissantes (`pricing.day_mode = rolling_24h`), avec la tolérance de retard de Q14 sur la dernière tranche. | Ouverte |
| Q33 | Phases 3 à 5 | Comment tarifer une location de 10 jours : tarif de la tranche « 8 à 14 jours » appliqué à chaque jour, ou une semaine plus 3 jours ? Et une location à cheval sur deux saisons ? | A-04 | Direction | 3 | Tarif dégressif : le prix journalier de la tranche de durée s'applique à tous les jours. Chaque jour est valorisé selon **sa** saison. | Ouverte |
| Q34 | Phases 3 à 5 | Les prix des grilles et des options sont-ils saisis HT ou TTC ? | A-04, A-05, A-08 | Expert-comptable, direction | 3 | Saisie HT. Le TTC n'est calculé qu'une fois les taxes validées (Q11). | Ouverte |
| Q35 | — | Uniquement en dinars ? Des clients touristes paient-ils en euros ? | A-01, E-06 | Direction | 3 | TND uniquement. Un encaissement en devise est hors périmètre et passe par une conversion manuelle avant saisie. | Ouverte |
| Q36 | — | Pour une réservation par catégorie (sans véhicule précis), la surréservation est-elle tolérée ? | C-05, RG-01 | Direction | 4 | Non. Capacité disponible = véhicules louables de la catégorie à l'agence de départ − réservations et contrats de la catégorie qui chevauchent la période. | Ouverte |
| Q37 | — | Une agence peut-elle créer une réservation au départ d'une **autre** agence (client qui appelle Sfax pour louer à Tunis) ? | C-04, RG-13 | Direction | 2 | Non : seule l'agence de départ crée la réservation. Les autres agences voient la disponibilité en lecture. | Ouverte |
| Q38 | — | Sur le planning des autres agences (consultable en lecture), le nom du client est-il affiché ? | C-02, RG-14 | Direction | 4 | Non : « Occupé », sans nom ni numéro de réservation. | Ouverte |
| Q39 | — | Client absent au départ : délai avant de libérer le véhicule, sort de l'acompte ? | C-08, C-10 | Direction | 4 | Statut « non présenté » proposé après 120 min (`reservations.no_show_after_minutes`), confirmé par l'agence. Le sort de l'acompte est décidé au cas par cas et tracé. | Ouverte |
| Q40 | — | Acompte reçu à la réservation (C-10) : quel document remettre (reçu, facture d'acompte) ? Remboursable en cas d'annulation ? | C-10, E-06 | Expert-comptable, direction | 4 (saisie), 6 (document) | Encaissement non imputé, rattaché à la réservation puis imputé à la facture finale. **Aucun document fiscal** n'est émis tant que la règle n'est pas validée. | Ouverte |
| Q41 | — | Délai de préparation (A-06) : valeur unique ou par catégorie ? S'applique-t-il aussi à un transfert entre agences ? | A-06, RG-01 | Direction | 3 | Valeur unique de 60 min (`reservations.preparation_buffer_minutes`), surchargeable par catégorie. | Ouverte |

---

## 4. Contrats et états des lieux (Q42 à Q49)

| N° | Bloque | Question | Origine | Destinataire | Avant la phase | Valeur par défaut (paramètre) | Statut |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q42 | — | Âge minimum du conducteur et ancienneté minimale du permis, par catégorie ? Permis étrangers ou internationaux acceptés ? | D-03 (âge non prévu) | Direction | 3 | Âge minimum de 21 ans et permis de 2 ans, surchargeables par catégorie. Permis étranger accepté et signalé à l'agent. | Ouverte |
| Q43 | — | Forfait kilométrique par jour ou par contrat ? Prix du kilomètre supplémentaire par catégorie ? | D-05 | Direction | 5 | Kilométrage illimité par défaut. Le forfait par jour et le prix du km sont paramétrables par catégorie, **vides** tant qu'ils ne sont pas saisis. | Ouverte |
| Q44 | — | Carburant : quelle règle (même niveau au retour) ? Prix du litre manquant, frais de service ? | D-05, D-09 | Direction | 5 | Niveau relevé en huitièmes. Prix du litre et frais de service **vides** : pas de frais de carburant calculés tant qu'ils ne sont pas saisis. | Ouverte |
| Q45 | — | Dommages : comment les chiffrer (devis, barème par élément) ? Quelle franchise par catégorie, et comment la « franchise réduite » (A-05) la modifie-t-elle ? | D-09, A-05 | Direction, conseil juridique | 5 | Montant saisi à la main, avec justificatif, plafonné par la franchise de la catégorie (vide = pas de plafond). | Ouverte |
| Q46 | — | La signature du client sur tablette suffit-elle juridiquement (loi 2000-83 sur les échanges électroniques), ou faut-il aussi un exemplaire papier signé ? | D-06, J-10 | Conseil juridique | 5 | Signature sur tablette intégrée au PDF, impression papier toujours possible. | Ouverte |
| Q47 | — | Le client particulier est-il automatiquement le conducteur principal ? Pour une entreprise, qui sont les conducteurs ? | D-02, K-01 | Direction | 5 | Un conducteur principal est obligatoire. Il peut être le client particulier lui-même ; pour une entreprise, c'est une personne désignée. | Ouverte |
| Q48 | — | Les conducteurs additionnels sont-ils contrôlés comme le client (liste noire, vigilance) ? | K-10, RG-15 | Direction | 5 | Oui, contrôle sur la pièce d'identité et le permis. | Ouverte |
| Q49 | — | Format de numérotation des réservations et des contrats (non fiscal) ? | C-03, D-01 | Direction | 4 | `RES-AAAA-NNNNN` et `CTR-AAAA-NNNNN`, séquence commune aux agences, format paramétrable. | Ouverte |

---

## 5. Facturation, encaissements et cautions (Q50 à Q56)

| N° | Bloque | Question | Origine | Destinataire | Avant la phase | Valeur par défaut (paramètre) | Statut |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q50 | — | Quand la facture est-elle émise : au départ (location payée d'avance) ou au retour ? Faut-il une facture par mois pour les longues durées ? | E-01, E-02, RG-05 | Direction, expert-comptable | 6 | Facture au retour, plus une facture complémentaire si besoin. Au-delà de 30 jours, une facture par période de 30 jours (`billing.long_rental_period_days`). | Ouverte |
| Q51 | — | Une retenue sur caution règle-t-elle la facture de frais (encaissement imputé), ce qui la fait entrer dans le chiffre d'affaires par la facture ? | F-04, F-07 | Expert-comptable | 6 | La retenue règle la facture de frais. La caution elle-même reste hors chiffre d'affaires. | Ouverte |
| Q52 | — | Approbations du super admin (remise RG-10, retenue F-05, dérogation de liste noire K-11) : toutes les retenues ou seulement au-delà d'un seuil ? Que faire si le super admin ne répond pas alors que le client attend au comptoir ? Un suppléant est-il possible ? Seuil de remise en % ou en montant, par profil ou par utilisateur ? | RG-10, F-05, K-11, Q10 | Direction | 5 | Toutes les retenues passent en approbation. Seuil de remise en %, par profil, fixé à 0 %. Pas de délégation (`approvals.delegate_to_admin = false`). Une demande sans réponse reste en attente, sans approbation automatique. | Ouverte |
| Q53 | — | Relances d'impayés (E-08) : par quel canal, puisqu'il n'y a ni SMS ni e-mail ? | E-08, J-12 | Direction | 6 | Relance manuelle (appel, courrier) tracée dans l'historique ; push au client à partir du lot 2. | Ouverte |
| Q54 | — | Caution par empreinte de carte (F-01) : sans terminal intégré, se contente-t-on d'enregistrer la référence d'autorisation ? | F-01 | Direction | 6 | Saisie manuelle de la référence d'autorisation, **aucune** donnée de carte (ni numéro ni date). | Ouverte |
| Q55 | — | Journal de caisse (E-09) : fond de caisse, qui clôture, comment traiter les écarts ? | E-09 | Direction | 6 | Clôture quotidienne par l'utilisateur d'agence ; écart saisi avec un motif, visible par l'admin. | Ouverte |
| Q56 | — | Faut-il un reçu numéroté pour les cautions et leur restitution (F-03) ? Ce document n'est pas fiscal. | F-03 | Direction | 6 | Reçu numéroté `CAU-AAAA-NNNNN`, séquence distincte de celle des factures. | Ouverte |

---

## 6. Charges et statistiques (Q57 à Q62)

| N° | Bloque | Question | Origine | Destinataire | Avant la phase | Valeur par défaut (paramètre) | Statut |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q57 | — | Les charges des statistiques (loyers de leasing, entretien) sont-elles comptées HT ou TTC ? La TVA est-elle récupérable ? | B-11, I-01, I-07 | Expert-comptable | 8 | Les montants HT et TTC sont saisis. Les statistiques utilisent le HT, sous réserve de la réponse. | Ouverte |
| Q58 | — | Mois d'imputation (RG-17) : un loyer de leasing est-il imputé au mois de son échéance ou de son paiement ? La prime d'assurance annuelle est-elle répartie sur 12 mois ? | RG-17, I-01 | Direction, expert-comptable | 8 | Loyer imputé au mois de l'échéance. Assurance et vignette réparties sur leur période de couverture (`stats.spread_annual_costs = true`). | Ouverte |
| Q59 | — | Crédit bancaire (B-15) : les mensualités contiennent capital et intérêts. Si le prix d'achat est aussi réparti (Q19), ne compter que les intérêts pour éviter le double comptage ? | B-15, I-01, I-08, Q19 | Direction, expert-comptable | 9 | Prix d'achat réparti (Q19) **plus** intérêts seulement. Le capital des mensualités n'est pas compté comme charge. | Ouverte |
| Q60 | — | La comparaison avec l'année précédente (§4.9) suppose un historique. Reprend-on les données des années passées (CA, contrats) ? | §4.9, Q21 | Direction | 10 | Pas de reprise d'historique : comparaison N-1 disponible un an après la mise en service. | Ouverte |
| Q61 | — | Les statistiques de l'utilisateur d'agence (« activité de son agence ») incluent-elles le chiffre d'affaires ? | §3.1, I-14 | Direction | 9 | Activité seulement (départs, retours, retards, nombre de contrats), sans CA ni marge. | Ouverte |
| Q62 | — | Seuils d'alerte : âge et kilométrage de renouvellement (I-16), réparations sur 12 mois (I-10), entretien préventif (G-01) | I-10, I-16, G-01 | Direction | 8 | **Vides** : les alertes correspondantes restent désactivées tant qu'ils ne sont pas saisis. | Ouverte |

---

## 7. CRM et données personnelles (Q63 à Q68)

| N° | Bloque | Question | Origine | Destinataire | Avant la phase | Valeur par défaut (paramètre) | Statut |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q63 | — | Recherche exacte (K-04) : la recherche par « nom exact » renvoie des homonymes. Combien de résultats afficher ? Faut-il exiger un second critère ? | K-04, RG-14 | Direction | 7 | Nom et prénom exacts (insensibles aux accents et à la casse), 5 résultats au maximum (`crm.search_max_results`), sinon demande d'un second critère. Recherches journalisées et limitées à 30 par heure et par utilisateur (anti-énumération). | **Répondue (17/11/2026)** : D24 (nom exact complet ou pièce, permis, téléphone ; 10 résultats ; 30 recherches par heure ; journal sans la valeur recherchée). Codée en phase 4. |
| Q64 | — | Après une recherche, combien de temps la fiche reste-t-elle accessible à un admin ou un utilisateur d'agence ? (Sans limite, il suffirait de changer le numéro dans l'adresse de la page pour parcourir tout le carnet.) | K-04, RG-14 | Direction, responsable technique | 7 | Fiche accessible si le client a été trouvé par une recherche de l'utilisateur dans les 8 dernières heures (`crm.search_grant_hours`), ou s'il a un document actif dans son agence. | Ouverte |
| Q65 | — | Pendant qu'une proposition de liste noire attend la validation du super admin, le client est-il bloqué ? | K-11 | Direction | 7 | Non bloqué : il est affiché en « vigilance » jusqu'à la décision. | Ouverte |
| Q66 | — | Qui peut sortir un client de la liste noire ? | K-11, RG-16 | Direction | 7 | Le super admin seul, par une nouvelle recommandation (ajout seul, RG-16). | Ouverte |
| Q67 | — | La déclaration du traitement à l'INPDP (loi 2004-63, art. 7) est-elle faite, et qui s'en charge ? Le partage des recommandations entre agences et la liste noire demandent-ils une autorisation particulière ? | §6.3, K-08 à K-11 | Direction, conseil juridique | 10 | Pas de mise en production sans récépissé de déclaration. | Ouverte |
| Q68 | — | Combien de temps conserver les photos d'états des lieux et les signatures (données personnelles et espace disque) ? | D-06, §6.5 | Conseil juridique | 9 | Conservation illimitée en attendant la réponse, purge désactivée ; alerte quand l'espace disque dépasse 80 %. | Ouverte |

---

## 8. Organisation, hébergement et technique (Q69 à Q76)

| N° | Bloque | Question | Origine | Destinataire | Avant la phase | Valeur par défaut (paramètre) | Statut |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Q69 | — | Chaque agence n'a qu'un utilisateur. Qui le remplace en cas d'absence ? Un utilisateur peut-il être rattaché à plusieurs agences ? | §3, RG-13 | Direction | 2 | Le super admin peut rattacher un utilisateur à plusieurs agences. L'utilisateur choisit alors son agence active et chaque document porte cette agence. Les comptes partagés sont interdits. | **Répondue (17/11/2026)** : remplacements à durée limitée (table `agency_assignments`), dates de début et de fin incluses (Africa/Tunis), créés et révoqués par le super admin seul, journalisés. |
| Q70 | — | Version exacte de la base de données chez Oxahost ? | §2.2, Q8 | Responsable technique, Oxahost | 2 | **Réponse d'Oxahost (17/11/2026) : MariaDB 10.11.19** (`10.11.19-MariaDB-cll-lve`), pas MySQL 8. Décision : MariaDB 10.11 en local, en test et en production, connexion Laravel `mariadb` (D12). | **Close** |
| Q71 | **Phase 2** | Sur Oxahost, les fonctions `exec` / `proc_open` et le binaire `mysqldump` (ou `mariadb-dump`) sont-ils disponibles (nécessaires à `spatie/laravel-backup` pour J-08) ? Les extensions `iconv`, `simplexml`, `xmlreader`, `xmlwriter`, `exif` et `sodium`, absentes de la liste de `docs/installation_hebergement_oxahost.md`, sont-elles activables ? | J-08, compatibilité des paquets | Responsable technique, Oxahost | 2 | **Réponse partielle d'Oxahost (17/11/2026)** : `mysqldump` disponible en SSH ; extensions « parfois restreintes ». **Encore ouvert** (relance envoyée) : `exec`, `proc_open`, `symlink` et la liste des extensions restreintes. Sans attendre : l'application ne dépend ni de `exec` ni de `proc_open` (D14, D15), ni de `symlink` (D21) ; sauvegardes par `deploy.sh` et JetBackup ; `aviator:check-environment` liste les extensions chargées et `disable_functions`. | Partiellement répondue |
| Q72 | Phases 3 à 5 | Tablettes des agences pour les états des lieux et la signature : modèle, système, navigateur, appareil photo ? Faut-il les acheter (non chiffré) ? | J-10, D-06 | Direction | 5 | Maquettes conçues pour une tablette de 10 pouces en mode portrait, sous Chrome Android ou Safari iPadOS. | Ouverte |
| Q73 | — | Retours en dehors des heures d'ouverture (boîte à clés) : quelle heure de retour retenir ? | A-02, RG-09 | Direction | 5 | Heure réelle déclarée par le client et saisie à l'ouverture suivante, avec une mention dans l'état des lieux. Fuseau `Africa/Tunis`. | Ouverte |
| Q74 | — | Phase 1 : quand ont lieu les ateliers avec les 4 agences prévus au chiffrage, et qui valide les maquettes ? | §7.1, phase 1 | Direction | Validation de la phase 1 | Maquettes de ce dépôt présentées en atelier. Validation par le super admin. | **Close** : ateliers pendant la phase 2, avant la phase 4 ; maquettes publiées sur une page privée |
| Q75 | Phases 3 à 5 | Si les documents clients doivent être en arabe (Q5), il faut mPDF (licence GPL-2.0) au lieu de dompdf (LGPL), car dompdf gère mal l'écriture de droite à gauche. Accord sur cette licence ? | Q5, J-06 | Responsable technique | 5 | dompdf, documents en français uniquement. | Ouverte |
| Q76 | — | La 2FA doit-elle être obligatoire aussi pour les **utilisateurs d'agence** ? Elle l'est déjà pour le super admin et l'admin (J-03). **Recommandation du responsable technique : l'activer**, car un compte d'agence ouvre les contrats, les encaissements et la recherche de clients ; une application d'authentification gratuite sur le téléphone de l'agent suffit. | J-03, sécurité | Direction | 9 | Réglage `security.require_2fa_for_agency_users`, **désactivé par défaut** ; le super admin pourra l'activer sans développement (écran en phase 3). | Ouverte |

---

## 9. Récapitulatif par échéance

| Avant la phase | Questions | Bloquantes |
| --- | --- | --- |
| Validation de la phase 1 (validée avec réserves le 17/11/2026) | Restent ouvertes : Q1 (logiciel), Q5 (documents), Q6, Q24 ; Q74 close | — |
| 2 — Socle | Q2, Q3, Q4, Q7, Q8, Q26, Q27, Q37, Q69, Q71 (Q70 close) | **Q2, Q6 (domaine), Q8, Q27 (confirmation), Q71** ; Q8 et Q71 sont à vérifier dans les 30 jours de garantie |
| 3 — Paramétrage | Q10, Q11, Q30, Q32, Q33, Q34, Q35, Q41, Q42 | **Q11, Q30** (fiscal, envoyées à l'expert-comptable le 17/11/2026) : phase 3 bloquée |
| 4 — Parc, réservations | Q12, Q13, Q28, Q29, Q31, Q36, Q38, Q39, Q40 (saisie), Q49 | — |
| 5 — Contrats | Q14, Q15, Q43, Q44, Q45, Q46, Q47, Q48, Q52, Q72, Q73, Q75 | — |
| 6 — Facturation | Q16, Q25, Q40 (document), Q50, Q51, Q53, Q54, Q55, Q56 | **Q16** (périmètre, envoyée à l'expert-comptable le 17/11/2026), **Q40** (document fiscal) |
| 7 — CRM | Q17, Q18, Q63, Q64, Q65, Q66 | — |
| 8 — Exports | Q1 (format), Q57, Q58, Q62 | **Q1** (acceptation de la phase 8) |
| 9 — Statistiques | Q9, Q19, Q20, Q59, Q61, Q68, Q76 | — |
| 10 — Mise en production | Q21, Q60, Q67 | **Q67** (INPDP) |
| 11 — Espace client | Q5 (espace client), Q22, Q23 | Q23 |
