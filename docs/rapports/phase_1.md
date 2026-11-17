# Rapport de fin de phase 1 — Analyse et maquettes

| Élément | Valeur |
| --- | --- |
| Phase | 1. Analyse et maquettes (lot 1) |
| Branche | `phase/01-analyse` (créée depuis `main`, commit `a0d20c0`) |
| Date | 17/11/2026 |
| Statut | **Validée avec réserves** le 17/11/2026 (voir §10) |
| Critère d'acceptation | Maquettes (comptoir, planning, CRM, statistiques mobile) et questions validées |
| Valideur | Super admin (direction d'Aviator) |

Aucun code applicatif n'a été créé.

---

## 1. Exigences traitées

| Livrable (annexe A) | Statut | Où |
| --- | --- | --- |
| 1. Incohérences et manques du cahier ; Q1 à Q23 reprises et complétées | **Fait** : 23 questions reprises avec une valeur par défaut, 52 ajoutées (Q24 à Q75), dont 8 incohérences | `docs/questions_ouvertes.md` |
| 2. Découpage technique : modèles, tables, services de domaine, ressources Filament, schéma entité-relation en Mermaid | **Fait** : environ 60 tables, 10 contextes de domaine, environ 35 ressources et pages Filament, 4 schémas entité-relation et la machine à états | `docs/architecture.md` |
| 3. Parcours prioritaires et maquettes en fils de fer | **Fait** : 7 parcours, 8 écrans (comptoir, cas d'erreur, planning, départ, retour, approbation mobile, statistiques mobile, CRM et carnet) | `docs/maquettes/parcours.md`, `docs/maquettes/wireframes.html` |
| 4. Compatibilité Laravel 13 / PHP 8.3 / Filament v5 des paquets | **Fait** : résolution Composer réelle ; 4 incompatibilités de version majeure, toutes contournables | `docs/compatibilite_paquets.md` |
| 5. Estimation des phases 2 à 10 comparée à la référence | **Fait** : 238 j contre 233 j (+2,1 %) | `docs/estimation_phases.md` |
| 6. Rapport de phase | **Fait** | ce document |
| Ateliers avec les 4 agences (contenu de la phase au §7.1 du cahier) | **Non fait** : relève de la direction et du responsable technique ; les maquettes servent de support (Q74) | — |

Exigences du cahier couvertes par les maquettes : C-01 à C-07, C-10, D-01 à D-06, D-09, D-10, E-01, E-05, E-06, F-01, F-03 à F-06, I-01, I-05 à I-11, I-20, J-03, J-09 à J-12, J-14, K-02 à K-05, K-08 à K-11 ; règles RG-01 à RG-04, RG-07 à RG-10, RG-13 à RG-16, RG-18, RG-20 ; scénarios 1, 4 à 6, 10, 13, 14, 19, 21.

## 2. Fichiers créés ou modifiés

| Fichier | Action |
| --- | --- |
| `docs/questions_ouvertes.md` | Créé |
| `docs/architecture.md` | Créé |
| `docs/maquettes/parcours.md` | Créé |
| `docs/maquettes/wireframes.html` | Créé (HTML statique, sans dépendance externe, clair et sombre) |
| `docs/compatibilite_paquets.md` | Créé |
| `docs/estimation_phases.md` | Créé |
| `docs/decisions.md` | Créé (11 décisions techniques proposées, D1 à D11) |
| `docs/rapports/phase_1.md` | Créé |
| `docs/projet.md` | Modifié : statut de la phase 1 |

## 3. Tests et vérifications

Pas de test applicatif : il n'y a pas encore de code. Vérifications exécutées sur les livrables :

| Vérification | Commande | Résultat |
| --- | --- | --- |
| Résolution des paquets prévus en PHP 8.3 | `composer update --dry-run` puis `--no-install`, avec `config.platform.php = 8.3.16` (dossier temporaire hors du dépôt) | Solution trouvée, 199 paquets |
| Vulnérabilités connues | `composer audit --locked` | « No security vulnerability advisories found. » |
| Syntaxe des schémas Mermaid | `mermaid.parse()` (mermaid 11.4.1 sous Node 24, avec jsdom) sur `docs/architecture.md` et le cahier | 6 blocs, 0 en échec |
| Structure HTML des maquettes | Analyse de l'équilibre des balises (Python `html.parser`) | 0 erreur, 8 écrans |
| Rendu des maquettes | Chromium sans interface, à 390 px et 1280 px, captures de E1, E3 et E6 | 0 erreur console, 0 requête en échec, pas de défilement horizontal de la page. Un débordement en 390 px a été corrigé (les tableaux larges défilent dans leur encadré), ainsi que la disposition des téléphones de E6 |
| Extensions PHP locales | `php -m` | Toutes les extensions exigées sont présentes |

Tests ignorés : sans objet.

## 4. Écarts au cahier des charges et décisions

**Écarts relevés dans le cahier**, soumis comme questions : paiement mentionné malgré l'exclusion (Q24), envoi des factures par e-mail contraire à la règle « e-mail = sécurité des comptes » (Q25), RG-13 contre la restitution dans une autre agence (Q26), RG-14 contre les listes de clients accessibles à l'admin (Q27), statut « Réservé » ambigu (Q28), transitions manquantes dans la machine à états (Q29), statuts de réservation manquants (Q31).

**Corrections de forme à apporter au cahier** (sans impact fonctionnel) :

- §8 cite `Chiffrage_Aviator_Laravel_vs_ERPNext.xlsx`, alors que le dépôt contient `docs/reference/chiffrage_aviator.xlsx` ;
- Q1 est attendue « avant la phase 1 » (§9), mais le format des exports « avant la phase 8 » (§4.8).

**Écarts au prompt maître**, proposés dans `docs/decisions.md` : contextes `Rental` et `Approvals` (D2), 23 tables ajoutées au §5.3 (dont `vehicle_blocks`, D1, et `approval_requests`).

## 5. Charge consommée

| | Jours |
| --- | ---: |
| Référence de la phase 1 | 12 |
| Production des livrables (analyse, architecture, maquettes, vérifications), estimée | ≈ 4 |
| Restant prévu : ateliers avec les 4 agences, revue des maquettes, arbitrage des questions avec la direction | ≈ 6 à 8 |
| **Total projeté** | **10 à 12 (dans la référence)** |

La charge réelle est à renseigner dans `docs/projet.md` §5 par le responsable technique.

## 6. Risques nouveaux

1. ~~Version de la base chez Oxahost (Q70)~~ : **résolu le 17/11/2026**. La production est en MariaDB 10.11.19 ; le poste et les tests passent en MariaDB 10.11 (D12).
2. **PHP 8.3** : quatre paquets ont déjà une version majeure qui exige PHP 8.4. Le projet restera une version en retard tant qu'Oxahost ne proposera pas PHP 8.4 ; les correctifs de PHP 8.3 s'arrêtent le 31/12/2027.
3. **Facturation électronique (Q16)** : si elle est obligatoire, la phase 6 dépasse le seuil de +20 %.
4. **Approbations bloquantes** : avec un seuil de remise à 0 % et sans suppléant, une absence du super admin bloque les remises et les retenues au comptoir (Q52).
5. **Fuites du carnet d'adresses** : plusieurs fonctions donnent à l'admin des listes de clients (Q27). Elles doivent être arbitrées avant la matrice des droits de la phase 2.
6. ~~**Poste de développement**~~ : décidé le 17/11/2026. Le dépôt reste dans `D:\aviator`, exposé à Laragon par une jonction `C:\laragon\www\aviator`, avec `D:/aviator` en `safe.directory` global. `CLAUDE.md` et `docs/projet.md` sont à jour.
7. **Matériel** : tablettes des agences non prévues au chiffrage (Q72).

## 7. Questions pour la direction, l'expert-comptable et le conseil juridique

Elles sont toutes dans `docs/questions_ouvertes.md` (§9 : récapitulatif par échéance).

- **À trancher pour valider la phase 1** : Q1 (logiciel de l'expert-comptable), Q5 (langue des documents), Q6 (domaine, logo, charte), Q24 (correction du cahier), Q74 (ateliers et validation des maquettes).
- **À trancher avant la phase 2** : Q2, Q3, Q4, Q7, Q8, Q26, Q27, Q37, Q69, Q71 (Q70 close : MariaDB 10.11.19).
- **Expert-comptable, en priorité** : Q11 et Q30 (taxes, mentions, numérotation, **bloquantes** avant la phase 3), Q16 (TEIF), Q40 (acompte).

## 8. Commandes de déploiement en recette

Aucune : la phase 1 ne produit que de la documentation.

## 9. À valider avant la phase 2

1. Les maquettes E1 à E8, après les ateliers avec les agences (ou leurs corrections).
2. Les réponses aux questions « avant la phase 2 », reportées dans `docs/decisions.md` et `docs/projet.md` §6.
3. Les décisions techniques D1 à D11 (`docs/decisions.md`).
4. Les versions de paquets retenues (`docs/compatibilite_paquets.md` §2), notamment Pest 4 et laravel-activitylog 4.
5. L'estimation des phases 2 à 10 (238 j) et la révision du chiffrage prévue « à la fin de la phase 1 » (chiffrage, feuille Synthèse, note A45).
6. Les prérequis du poste :
   - emplacement du dépôt : déplacer ou cloner dans `C:\laragon\www\aviator`, ou mettre à jour `CLAUDE.md` et `docs/projet.md` ;
   - propriété du dossier pour Git : `git config --global --add safe.directory D:/aviator`, ou correction du propriétaire du dossier ;
   - dépôt Git distant et nom du responsable technique à renseigner dans `docs/projet.md`.
7. La commande de l'hébergement Oxahost, une fois Q8 et Q71 confirmées, puisque la phase 2 se déroule dans les 30 jours de garantie.

## 10. Décision de validation (17/11/2026)

**Phase 1 validée avec réserves.**

| Décision | Suite donnée |
| --- | --- |
| D6 accepté : `config.platform.php = 8.3.16`, versions majeures compatibles PHP 8.3 | `docs/decisions.md` (D6 validée) |
| Planning : `guava/calendar` accepté, prototype obligatoire au début de la phase 4 ; repli : frise Livewire maison | `docs/decisions.md` (D5 validée), `docs/architecture.md` §9.2 |
| Q27 : définition du carnet d'adresses appliquée par défaut | RG-14 précisée (D13) ; matrice des droits de `docs/architecture.md` §8.1 et §8.3 mise à jour |
| Q11, Q30 et Q16 envoyées à l'expert-comptable | Phase 3 bloquée sur les taxes, mentions et numérotation ; `docs/projet.md` §8 |
| Dépôt maintenu dans `D:\aviator`, jonction `C:\laragon\www\aviator` | `CLAUDE.md`, `docs/projet.md` §2 |
| Questions triées par blocage | `docs/questions_ouvertes.md` (colonne « Bloque » et section de tri) |
| Ateliers avec les agences pendant la phase 2, avant la phase 4 ; maquettes publiées sur une page privée | Q74 close |

**Réserves** :

1. Ateliers avec les 4 agences, à tenir pendant la phase 2 et avant la phase 4 ;
2. Q27 à confirmer par la direction ;
3. Q70 : bascule du poste et des tests en MariaDB 10.11, à vérifier par les premiers tests de la phase 2.

**Bloquent la phase 2** : Q2, Q6 (nom de domaine), Q8, Q27 (confirmation), Q71.

Maquettes publiées pour les ateliers (page privée, à partager depuis son menu « Share ») : https://claude.ai/artifact/QZtwjVMonH9wH8CGuLBdPF
