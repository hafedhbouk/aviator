# Phase 8 — Maintenance, dépenses, exports : éléments reportés

Le plan complet sera établi au démarrage de la phase et soumis à validation.

## Demande d'évolution du cabinet (18/11/2026)

Source : `docs/analyse_ecarts_cabinet.md`, validée le 18/11/2026 avec décisions provisoires, à confirmer par la direction. Format des exports : spécifications du cabinet, §9 (Q1, partiellement répondue).

### Indispensable, intégré au lot 1

| Élément (analyse) | Charge (j) | Contenu |
| --- | ---: | --- |
| E16. Colonnes des journaux | 0,5 | Journal des ventes (code client, type, contrat, immatriculation, nature, timbre, net à payer, statut) et des cautions ; forme selon Q82 ; nom et matricule fiscal seulement (D13) |
| E17. Export des encaissements | 0,25 | Une ligne par imputation, plus une ligne pour la part non imputée |
| E18. TVA des dépenses | 1 | HT, taux, TVA, TTC, numéro de pièce, mode de paiement |
| E20. Identifiants stables, exports rejouables | 1 | Identifiant immuable par ligne ; même plage de dates = même export ; versions conservées |
| **Total** | **2,75** | |

**Exports : manuels, par période** (et par agence, avec consolidation), dans le lot 1. La génération quotidienne automatique (E21) est **reportée** (décision du 18/11/2026).

### Option, selon Q78

| Élément | Charge (j) | Contenu |
| --- | ---: | --- |
| E24 (a). Multidevise minimale | 0,25 | Colonnes devise, montant d'origine et taux dans les exports d'encaissements |

### Déplacé en phase 9

La clôture mensuelle (E26, E27 : 3,5 j) est déplacée en phase 9, si la direction la retient, pour que la phase 8 reste sous le seuil d'alerte. La clôture d'une période exportée (H-06, RG-12) reste en phase 8.

| Phase 8 | Charge (j) |
| --- | ---: |
| Référence | 20 |
| Demande d'évolution (indispensable) | +2,75 |
| **Nouveau total** | **22,75** |
| **Écart** | **+2,75 j (+13,75 %)**, sous le seuil d'alerte de 20 % ; 23 j (+15 %) avec l'option multidevise |
