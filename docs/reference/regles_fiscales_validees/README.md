# Règles fiscales et format des exports validés

Ce dossier est la **seule source** autorisée pour paramétrer les taxes des factures et le format des exports. Claude Code n'utilise rien d'autre, même une règle qui lui semble connue.

Une règle est validée si elle figure ici avec une **confirmation écrite du cabinet** ou une **attestation écrite de la direction d'Aviator**. Une version validée **ne se modifie jamais** : une nouvelle version du référentiel s'ajoute à côté, avec sa propre confirmation ou attestation (`CLAUDE.md`, règle 3).

## Versions validées

### Référentiel fiscal et comptable v1.0 (novembre 2026)

**Source de la validation** : confirmation du cabinet conservée par Aviator, attestée par Faouzi Bannour, directeur général, le 18/11/2026.

| Fichier | Contenu | SHA-256 |
| --- | --- | --- |
| `referentiel_fiscal_aviator_v1.0_2026-11.md` | « AVIATOR – Référentiel fiscal et comptable final – Version 1.0 – Novembre 2026 », copie sans modification de `docs/reference/cabinet/referentiel_fiscal_aviator_v1.0_2026-11.md` | `43b2de817cb6590f2e90875afbff9c29c795f50599073fe3ecd7cbb98158e887` |
| `attestation_direction_2026-11-18.pdf` | Attestation de la direction (courriel du 18/11/2026) : le référentiel ci-dessus a été validé par écrit par le cabinet le 18/11/2026 ; l'original de la confirmation est conservé par Aviator | `5a95db08800042db010b147bccf5ccb1ee1e3bd39064b8ac8cbcf86b80e6fa87` |

Vérifier que le référentiel n'a pas changé :

```bash
sha256sum docs/reference/regles_fiscales_validees/referentiel_fiscal_aviator_v1.0_2026-11.md
# attendu : 43b2de817cb6590f2e90875afbff9c29c795f50599073fe3ecd7cbb98158e887
```

**Portée** : la validation est donnée « sous réserve de la conformité des opérations effectivement réalisées, des contrats applicables et des justificatifs ». Les règles CONFIRMED s'appliquent ; les règles CONDITIONAL exigent la nature réelle et les justificatifs de chaque opération ; les règles PENDING_DOCUMENTATION restent inactives tant que la pièce manque. Registre des règles et statuts : `docs/projet.md` §8.

| Sujet | Couvert par le référentiel v1.0 |
| --- | --- |
| TVA | Oui (§2) |
| Timbre | Oui (§3) |
| Facture : mentions, numérotation, avoirs | Oui (§4, §6) ; Q30 (série des avoirs) reste ouverte |
| Arrondi | Oui (§5), avec exemple chiffré |
| Exports | Colonnes et formats (§9) ; logiciel du cabinet, séparateur et codes comptables non fournis (Q1) |
| Facturation électronique | Principes (§7) ; situation d'Aviator : Q16, Q84 |

## À déposer pour une règle nouvelle ou modifiée

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

`AAAA-MM-JJ_<CODE>_<sujet>.<ext>`, par exemple `2026-12-02_TVA_location.pdf`, `2027-01-15_EXPORT_journal_ventes.xlsx`. Les fichiers du référentiel v1.0 gardent leur nom d'origine.

Après chaque dépôt, mettez à jour la section 8 de `docs/projet.md`.
