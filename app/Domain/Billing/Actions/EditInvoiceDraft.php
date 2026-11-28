<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Exceptions\InvoiceDraftException;
use App\Domain\Billing\InvoiceDraftTotals;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\TaxCode;
use App\Models\User;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Préparation d'un brouillon (L3, N2, D54) : code fiscal **choisi** pour chaque ligne
 * (jamais déduit du libellé), libellé corrigé, lignes libres ajoutées, modifiées ou
 * retirées, brouillon abandonné (sa période redevient facturable). Une ligne reprise du
 * contrat garde son montant et ne se retire pas. Sous verrou de la facture ; refus dès
 * qu'elle n'est plus un brouillon.
 */
final class EditInvoiceDraft
{
    /**
     * Code fiscal et libellé d'une ligne ; montant d'une ligne libre.
     *
     * @param  array<string, mixed>  $data  tax_code_id, label ; quantity, unit_amount (ligne libre seulement)
     */
    public function updateLine(InvoiceLine $line, array $data, User $actor): InvoiceLine
    {
        return $this->onDraft($line->invoice_id, $actor, function () use ($line, $data): InvoiceLine {
            $locked = InvoiceLine::query()->findOrFail($line->id);

            if ($locked->isFromContract() && (array_key_exists('quantity', $data) || array_key_exists('unit_amount', $data))) {
                throw InvoiceDraftException::contractLine();
            }

            $values = $this->validated($data, partial: true, free: ! $locked->isFromContract());
            $locked->fill($values);

            if (! $locked->isFromContract()) {
                $locked->total_excl_tax_amount = $locked->unit_amount_excl_tax->multipliedBy($locked->quantity, RoundingMode::HalfUp);
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * Ligne de libellé libre, avec son code fiscal (D54).
     *
     * @param  array<string, mixed>  $data  label, quantity, unit_amount, tax_code_id
     */
    public function addFreeLine(Invoice $invoice, array $data, User $actor): InvoiceLine
    {
        return $this->onDraft($invoice->id, $actor, function () use ($invoice, $data): InvoiceLine {
            $values = $this->validated($data, partial: false, free: true);
            /** @var Money $unit */
            $unit = $values['unit_amount_excl_tax'];

            return InvoiceLine::query()->create([
                ...$values,
                'invoice_id' => $invoice->id,
                'position' => (int) InvoiceLine::query()->where('invoice_id', $invoice->id)->max('position') + 1,
                'nature' => InvoiceLineNature::Free,
                'discount_amount' => Money::zero(),
                'total_excl_tax_amount' => $unit->multipliedBy((string) $values['quantity'], RoundingMode::HalfUp),
            ]);
        });
    }

    public function removeFreeLine(InvoiceLine $line, User $actor): void
    {
        $this->onDraft($line->invoice_id, $actor, function () use ($line): void {
            $locked = InvoiceLine::query()->findOrFail($line->id);

            if ($locked->isFromContract()) {
                throw InvoiceDraftException::contractLine();
            }

            $locked->delete();
        });
    }

    /**
     * Abandon du brouillon : ses lignes partent avec lui, sa période redevient facturable.
     */
    public function discard(Invoice $invoice, User $actor): void
    {
        DB::transaction(function () use ($invoice, $actor): void {
            $locked = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== InvoiceStatus::Draft) {
                throw InvoiceDraftException::notDraft();
            }

            if (! $actor->can('delete', $locked)) {
                throw InvoiceDraftException::notAllowed();
            }

            $locked->delete();
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function onDraft(int $invoiceId, User $actor, callable $callback): mixed
    {
        return DB::transaction(function () use ($invoiceId, $actor, $callback): mixed {
            $invoice = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw InvoiceDraftException::notDraft();
            }

            if (! $actor->can('update', $invoice)) {
                throw InvoiceDraftException::notAllowed();
            }

            $result = $callback();
            InvoiceDraftTotals::refresh($invoice);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, bool $partial, bool $free): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $rules = [
            'label' => [$required, 'string', 'min:2', 'max:255'],
            'tax_code_id' => ['nullable', 'integer'],
        ];

        if ($free) {
            $rules['quantity'] = [$required, 'regex:/^\d{1,7}([.,]\d{1,3})?$/'];
            $rules['unit_amount'] = [$required];
        }

        $validated = Validator::make($data, $rules, [], [
            'label' => 'libellé', 'tax_code_id' => 'code fiscal', 'quantity' => 'quantité', 'unit_amount' => 'prix unitaire HT',
        ])->validate();

        $values = [];

        if (array_key_exists('label', $validated)) {
            $values['label'] = trim((string) $validated['label']);
        }

        if (array_key_exists('tax_code_id', $data)) {
            $values['tax_code_id'] = $this->taxCodeId($validated['tax_code_id'] ?? null);
        }

        if (array_key_exists('quantity', $validated)) {
            $quantity = BigDecimal::of(str_replace(',', '.', (string) $validated['quantity']));

            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(['quantity' => 'La quantité doit être supérieure à zéro.']);
            }

            $values['quantity'] = (string) $quantity->toScale(3);
        }

        if (array_key_exists('unit_amount', $validated)) {
            try {
                $unit = AmountInput::parse($validated['unit_amount']);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['unit_amount' => "Le champ prix unitaire HT : {$exception->getMessage()}"]);
            }

            if ($unit === null || $unit->isNegative()) {
                throw ValidationException::withMessages(['unit_amount' => 'Le prix unitaire HT est obligatoire et ne peut pas être négatif.']);
            }

            $values['unit_amount_excl_tax'] = $unit;
        }

        return $values;
    }

    private function taxCodeId(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (! TaxCode::query()->whereKey($id)->exists()) {
            throw InvoiceDraftException::unknownTaxCode();
        }

        return (int) $id;
    }
}
