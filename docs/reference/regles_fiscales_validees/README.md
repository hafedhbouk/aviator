# Règles fiscales et format des exports validés

Ce dossier est la **seule source** autorisée pour paramétrer les taxes des factures et le format des exports. Claude Code n'utilise rien d'autre, même une règle qui lui semble connue.

## À déposer par l'expert-comptable

| Code | Sujet | Requis avant |
| --- | --- | --- |
| TVA | Taux applicables à la location, aux options et aux frais refacturés (retard, km, carburant, dommages) | Phase 3 |
| TIMBRE | Montant et règle d'application du timbre fiscal | Phase 3 |
| FACTURE | Mentions obligatoires, numérotation (commune ou par agence) | Phase 3 |
| EXPORT | Format des journaux : colonnes, codes, comptes éventuels, Excel ou CSV, séparateur, encodage | Phase 8 |
| TEIF | Obligation ou non de facturation électronique (TEIF / TTN) | Phase 6 |

## Contenu attendu pour chaque règle

1. La règle exacte et ses cas d'application ou d'exclusion.
2. Sa référence (texte, article, note).
3. Sa date d'effet.
4. Le valideur et la date de validation.
5. **Au moins un exemple chiffré** au millime près : il devient un test automatisé.

## Nommage

`AAAA-MM-JJ_<CODE>_<sujet>.<ext>`, par exemple `2026-12-02_TVA_location.pdf`, `2027-01-15_EXPORT_journal_ventes.xlsx`.

Après chaque dépôt, mettez à jour la section 8 de `docs/projet.md`.
