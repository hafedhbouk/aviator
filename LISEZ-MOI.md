# Kit de démarrage — Aviator (Laravel)

À décompresser à la racine du dépôt Git du projet.

| Fichier | Rôle |
| --- | --- |
| `CLAUDE.md` | Règles chargées par Claude Code à chaque session |
| `PROMPT_MAITRE_AVIATOR_LARAVEL.md` | Référence complète, à transformer en skill `aviator-laravel` ; contient les prompts des 11 phases |
| `docs/projet.md` | Fiche projet : environnements, personnes, budget, suivi des phases, décisions |
| `docs/installation_poste_dev.md` | Préparation du PC Windows 11 + Laragon (extensions PHP, MariaDB 10.11 et ses bases, Node, Git Bash, Claude Code) |
| `docs/installation_hebergement_oxahost.md` | Installation de la recette et de la production sur Oxahost Ultimate |
| `scripts/build-release.sh` | Construction de l'archive de version sur le poste (D25) |
| `scripts/install-release.sh`, `scripts/rollback-release.sh` | Installation et retour arrière dans le terminal web de cPanel, lancés par le responsable technique |
| `docs/reference/cahier_des_charges_aviator.md` | Cahier des charges Aviator (version du 17/11/2026) |
| `docs/reference/chiffrage_aviator.xlsx` | Chiffrage Laravel et comparaison ERPNext |
| `docs/reference/regles_fiscales_validees/` | Dossier réservé aux règles validées par l'expert-comptable |
| `docs/rapports/` | Rapports de fin de phase |

## Démarrage en 5 étapes

1. Suivre `docs/installation_poste_dev.md` (sections 1 à 6).
2. Vérifier que le cahier des charges est bien dans `docs/reference/cahier_des_charges_aviator.md`.
3. Renseigner dans `docs/projet.md` le dépôt Git et votre nom.
4. Dans Claude Code (lancé depuis `C:\laragon\www\aviator`) : créer la skill `aviator-laravel` à partir du prompt maître.
5. Envoyer le prompt de l'annexe A du prompt maître : la phase 1 commence.
