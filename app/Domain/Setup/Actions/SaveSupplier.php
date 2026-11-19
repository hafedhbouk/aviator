<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Crée ou modifie un fournisseur (G-05) : super admin et admin ; type de la liste,
 * nom obligatoire, matricule fiscal libre (aucun format imposé, comme la fiche
 * société) ; aucune suppression. Modification journalisée (audit).
 */
final class SaveSupplier
{
    /**
     * @param  array<string, mixed>  $data  type, name, tax_id, phone, email, address, is_active
     */
    public function handle(?Supplier $supplier, array $data, User $actor): Supplier
    {
        if (! $actor->can($supplier === null ? 'create' : 'update', $supplier ?? Supplier::class)) {
            throw SetupException::notAllowed('les fournisseurs');
        }

        $values = [];

        foreach (['type', 'name', 'tax_id', 'phone', 'email', 'address'] as $field) {
            $value = is_scalar($data[$field] ?? null) ? trim((string) $data[$field]) : '';
            $values[$field] = $value === '' ? null : $value;
        }

        $values['tax_id'] = $values['tax_id'] === null ? null : mb_strtoupper($values['tax_id']);
        $values['email'] = $values['email'] === null ? null : mb_strtolower($values['email']);

        /** @var array<string, string|null> $validated */
        $validated = Validator::make($values, [
            'type' => ['required', 'in:'.implode(',', array_keys(Supplier::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ], attributes: ['type' => 'type', 'name' => 'nom', 'tax_id' => 'matricule fiscal'])->validate();

        return DB::transaction(function () use ($supplier, $validated, $data): Supplier {
            $supplier ??= new Supplier;
            $supplier->fill($validated + ['is_active' => (bool) ($data['is_active'] ?? true)]);
            $supplier->save();

            return $supplier;
        });
    }
}
