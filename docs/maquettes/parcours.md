# Parcours utilisateurs prioritaires — Aviator

Phase 1, version du 17/11/2026, à valider en atelier avec les agences et le super admin [Q74].

Les écrans cités (E1 à E8) sont dessinés en fils de fer dans [`wireframes.html`](wireframes.html), à ouvrir dans un navigateur. Toutes les données affichées sont **fictives**. Aucun montant de taxe n'y figure : les règles fiscales ne sont pas encore validées [Q11].

| Parcours | Acteur | Appareil | Écrans | Exigences |
| --- | --- | --- | --- | --- |
| P1. Réservation au comptoir | Utilisateur d'agence | Ordinateur ou tablette | E1, E2 | C-01 à C-07, C-10, D-04, K-04, K-05, K-10, RG-01, RG-02, RG-10, RG-15 |
| P2. Planning | Tous | Ordinateur | E3 | C-02, RG-13, Q38 |
| P3. Départ | Utilisateur d'agence | Tablette | E4 | D-01 à D-06, D-10, F-01, RG-03, B-05, scénario 10 |
| P4. Retour | Utilisateur d'agence | Tablette | E5 | D-06, D-09, E-01, E-05, E-06, F-03 à F-06, RG-04, RG-09 |
| P5. Approbation du super admin | Super admin | Smartphone (application installée) | E6 | RG-10, F-05, K-11, J-09, J-12, J-14, scénario 19 |
| P6. Statistiques sur mobile | Super admin | Smartphone | E7 | I-01 à I-22, J-09, J-11, scénario 21 |
| P7. Recherche client et carnet d'adresses | Tous / super admin | Ordinateur ou smartphone | E8 | K-02 à K-05, K-08 à K-11, RG-14, scénario 13 |

---

## P1. Réservation au comptoir (écrans E1 et E2)

**Contexte** : un client se présente à l'agence de Sfax, ou téléphone, pour louer une citadine du 3 au 7 du mois.

| Étape | Action de l'utilisateur | Réponse de l'application | Règle |
| --- | --- | --- | --- |
| 1 | Ouvre **Comptoir → Nouvelle location** | Assistant en 5 étapes : client, période, véhicule, options et devis, validation | — |
| 2 | Recherche le client par pièce, permis, téléphone ou nom exact | Au plus 5 résultats, limités aux informations utiles au contrat. Si le client est en vigilance ou sur liste noire, un bandeau d'alerte apparaît, **dans toutes les agences** | K-04, K-10 |
| 2 bis | Client inconnu : **Créer le client** | Contrôle des doublons sur la pièce, le permis et le téléphone **avant** l'enregistrement. Si un doublon est trouvé, l'agent est invité à utiliser la fiche existante | K-05 |
| 3 | Saisit les dates et heures, l'agence de retour et la catégorie | Liste des véhicules disponibles (délai de préparation compris) et capacité de la catégorie | C-01, C-04, RG-01 |
| 4 | Choisit un véhicule précis, ou « catégorie seulement » | Le véhicule peut être affecté plus tard, au plus tard au départ | C-05 |
| 5 | Ajoute des options et, éventuellement, une remise | Devis calculé côté serveur (grille, saison, options). Si la remise dépasse le seuil, mention « Approbation requise » | C-06, D-04, RG-10 |
| 6 | **Enregistre la réservation**, avec un acompte facultatif | Contrôle final sous verrou. Réservation confirmée (ou en attente d'approbation de remise), numéro `RES-…`, acompte enregistré | C-07, C-10, RG-02 |
| 6 bis | Ou **Louer maintenant** | Le contrat est créé directement (saisie comptoir) et l'agent passe au départ (P3) | D-01, RG-03 |

**Cas d'erreur (écran E2)**

- **Saisie simultanée** : une autre agence a pris le véhicule entre le devis et l'enregistrement. Message « Ce véhicule vient d'être réservé sur cette période », avec proposition des véhicules encore disponibles. Rien n'est enregistré (scénario 1).
- **Client sur liste noire** : le bouton « Enregistrer » est remplacé par « Demander une dérogation au super admin ». Le motif n'est visible que par les profils autorisés (RG-15, scénario 14).
- **Remise au-delà du seuil** : la réservation est enregistrée et la remise reste « en attente ». Le super admin reçoit une notification push (P5).
- **Taxes** : tant que les règles fiscales ne sont pas validées, le devis affiche « Taxes : règle fiscale non validée » à la place des montants TTC.

---

## P2. Planning (écran E3)

| Étape | Action | Réponse | Règle |
| --- | --- | --- | --- |
| 1 | Ouvre **Comptoir → Planning** | Frise : une ligne par véhicule, une colonne par jour (vue semaine ou mois), agence de l'utilisateur affichée par défaut | C-02 |
| 2 | Filtre par agence ou par catégorie | Les véhicules des autres agences restent visibles, avec des blocs « Occupé » **sans nom de client** | RG-13, Q38 |
| 3 | Clique sur un bloc de son agence | Ouvre la réservation ou le contrat | — |
| 4 | Clique sur une case libre | Ouvre l'assistant P1, avec le véhicule et la date déjà remplis | — |

Légende des blocs : réservation confirmée, contrat en cours, maintenance, immobilisation, délai de préparation (hachuré), retard (bordure rouge).

---

## P3. Départ (écran E4, tablette)

| Étape | Action | Réponse | Règle |
| --- | --- | --- | --- |
| 1 | Ouvre **Départs et retours du jour** | Liste des départs prévus, avec un badge d'alerte (client signalé, véhicule non affecté, document de véhicule à échéance) | J-12 |
| 2 | Sélectionne la réservation, puis **Créer le contrat** | Reprise du devis et des options. Contrôle de chaque conducteur : âge, permis non expiré et ancienneté suffisante | D-01, D-03 |
| 3 | Affecte ou confirme le véhicule | Refus si l'assurance ou la visite technique n'est pas valide à la date de départ (message explicite) | B-05, scénario 10 |
| 4 | Enregistre la caution (montant, mode, référence) | Reçu de caution imprimable | F-01, F-03 |
| 5 | **État des lieux de départ** : kilométrage, carburant (8 crans), dommages placés sur le schéma, photos | Les dommages du dernier retour sont repris automatiquement. Le kilométrage ne peut pas être inférieur au dernier relevé | D-06, B-03 |
| 6 | Le client signe sur la tablette | Signature enregistrée dans un fichier privé | D-06, J-10 |
| 7 | **Valider le départ** | Contrat actif, véhicule « Loué », PDF du contrat, de l'état des lieux et des conditions générales | D-10, §7 |

---

## P4. Retour (écran E5, tablette)

| Étape | Action | Réponse | Règle |
| --- | --- | --- | --- |
| 1 | Ouvre le contrat depuis **Départs et retours du jour** | Rappel des conditions : km, carburant, heure prévue de retour | — |
| 2 | **État des lieux de retour** : kilométrage, carburant, nouveaux dommages, photos | Refus si le kilométrage de retour est inférieur à celui du départ. Comparaison avec le départ affichée côte à côte | RG-04 |
| 3 | Signature du client | — | D-06 |
| 4 | Contrôle des frais calculés : retard au-delà de la tolérance, km supplémentaires, carburant manquant, dommages (montant saisi + justificatif) | Détail de chaque calcul, avec le paramètre utilisé | D-09, RG-09 |
| 5 | **Émettre la facture**, ou la facture complémentaire | Numéro attribué à l'émission, ou blocage « Règle fiscale non validée » | E-01, E-05, RG-07 |
| 6 | Encaisse, avec un ou plusieurs modes de paiement | Reste dû mis à jour | E-06 |
| 7 | Caution : **restituer** ou **demander une retenue** | Restitution immédiate avec reçu. Une retenue passe en approbation (P5), et la caution reste « à restituer » | F-03 à F-06 |
| 8 | **Clôturer** | Contrat clôturé, non modifiable. Véhicule « Disponible », ou « Maintenance » si un dommage a été signalé | D-11, §7 |

---

## P5. Approbation par le super admin sur smartphone (écran E6)

| Étape | Action | Réponse | Règle |
| --- | --- | --- | --- |
| 1 | Reçoit une notification push : « Nouvelle demande d'approbation à traiter » | **Aucun montant, nom ni motif** dans le texte | RG-20, J-14 |
| 2 | Touche la notification | L'application installée s'ouvre sur **Approbations**. Si la session a expiré : connexion, puis code 2FA | J-03, J-11 |
| 3 | Consulte la liste : type, agence, demandeur, ancienneté de la demande | Les demandes les plus anciennes sont en tête | J-09 |
| 4 | Ouvre une demande | Contexte : contrat, client (niveau de recommandation), montant ou pourcentage, motif saisi, historique du client | — |
| 5 | **Approuver** ou **Refuser**, avec un commentaire facultatif (obligatoire pour un refus) | Contrôle serveur du rôle et de l'état de la demande (une demande déjà traitée est refusée). Le demandeur reçoit une notification | RG-10, F-05, K-11 |

Scénario 19 : une demande de remise déclenche la notification ; le super admin l'approuve depuis son téléphone.

---

## P6. Statistiques sur mobile (écran E7)

| Étape | Action | Réponse | Règle |
| --- | --- | --- | --- |
| 1 | Ouvre l'application depuis l'écran d'accueil | Tableau de bord du mois : CA HT, marge, taux d'utilisation, impayés, avec l'écart par rapport au même mois de l'année précédente (si l'historique existe, Q60) | I-06, I-07, I-11, I-20 |
| 2 | Change le mois, l'agence ou la catégorie | Filtres repliés dans un panneau en bas de l'écran | §4.9 |
| 3 | Ouvre **Rentabilité** | Classement des véhicules du plus au moins rentable, avec un badge « à revoir » | I-08, I-10 |
| 4 | Touche un véhicule | Revenus, charges par nature, marge, point mort (en jours), coût au km, sur le mois et cumulés sur l'année | I-01, I-05, I-09 |
| 5 | Touche **Méthode** | Formule, source et limites de chaque indicateur (« estimation de gestion, pas un résultat comptable ») | §4.9 |

Scénario 21 : consultation sur Android et sur iPhone, application installée.

---

## P7. Recherche client et carnet d'adresses (écran E8)

| Étape | Profil | Action | Réponse | Règle |
| --- | --- | --- | --- | --- |
| 1 | Agence ou admin | **Clients → Rechercher** : un seul champ, avec le type de critère | Correspondance exacte seulement, 5 résultats au maximum, jamais de liste complète | K-04 |
| 2 | Agence ou admin | Ouvre un résultat | Fiche « contrat » : identité, pièce, permis, niveau de recommandation, historique de ses locations dans le réseau. Coordonnées selon Q4 et Q27. Accès valable 8 h | K-02, Q64 |
| 3 | Agence ou admin | **Ajouter une recommandation**, ou **Proposer la liste noire** | Ajout seul. Une proposition de liste noire crée une demande d'approbation pour le super admin | K-08, K-09, K-11, RG-16 |
| 4 | Super admin | **Clients → Carnet d'adresses** | Liste complète, filtres, export Excel. Chaque consultation et chaque export sont journalisés | K-03, RG-14 |
| 5 | Agence ou admin | Tente d'ouvrir le carnet, ou de changer le numéro de fiche dans l'adresse de la page | Refus serveur, tentative journalisée | Scénario 13 |
