<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Crée ou modifie un mode de paiement (A-07). Super admin et admin ; code de 2 à 32
 * caractères (minuscules, chiffres, « _ »), unique, **fixé à la création** (il
 * identifie le mode dans les encaissements et les exports) ; aucune suppression.
 * Modification journalisée (audit).
 */
final class SavePaymentMethod
{
    /**
     * @param  array<string, mixed>  $data  code (création), name, requires_reference, is_active
     */
    public function handle(?PaymentMethod $method, array $data, User $actor): PaymentMethod
    {
        if (! $actor->can($method === null ? 'create' : 'update', $method ?? PaymentMethod::class)) {
            throw SetupException::notAllowed('les modes de paiement');
        }

        $code = is_scalar($data['code'] ?? null) ? mb_strtolower(trim((string) $data['code'])) : null;

        if ($method !== null && array_key_exists('code', $data) && $code !== $method->code) {
            throw SetupException::codeImmutable('d\'un mode de paiement');
        }

        $values = Validator::make([
            'code' => $method !== null ? $method->code : $code,
            'name' => is_scalar($data['name'] ?? null) ? trim((string) $data['name']) : null,
        ], [
            'code' => ['required', 'regex:/^[a-z0-9_]{2,32}$/', 'unique:payment_methods,code'.($method !== null ? ','.$method->id : '')],
            'name' => ['required', 'string', 'max:100'],
        ], attributes: ['name' => 'nom'])->validate();

        return DB::transaction(function () use ($method, $values, $data): PaymentMethod {
            $method ??= new PaymentMethod(['code' => $values['code']]);
            $method->fill([
                'name' => $values['name'],
                'requires_reference' => (bool) ($data['requires_reference'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $method->save();

            return $method;
        });
    }
}
