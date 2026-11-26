<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use App\Support\Privacy\BlindIndex;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conducteur autorisé d'un client (D-03), avancé de la phase 5 pour la recherche par
 * permis (D24). Le client particulier y figure lui-même comme conducteur (Q47).
 * Permis et pièce chiffrés en base (D8) ; recherche exacte par empreinte HMAC
 * (`license_hash`, `id_document_hash`), recalculée à chaque enregistrement et par
 * « aviator:rehash-blind-index ». Coordonnées du carnet (RG-14) : jamais exposées
 * en liste.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $first_name
 * @property string $last_name
 * @property CarbonImmutable|null $birth_date
 * @property string $license_number
 * @property string $license_hash
 * @property CarbonImmutable|null $license_issued_on
 * @property CarbonImmutable|null $license_expires_on
 * @property string|null $license_country
 * @property string|null $id_document_number
 * @property string|null $id_document_hash
 * @property int|null $created_by
 */
#[Fillable([
    'customer_id', 'first_name', 'last_name', 'birth_date', 'license_number', 'license_issued_on',
    'license_expires_on', 'license_country', 'id_document_number', 'created_by',
])]
#[Hidden(['license_number', 'license_hash', 'id_document_number', 'id_document_hash'])]
class Driver extends Model
{
    use AuditsChanges;

    /** Usage de l'empreinte du permis (BlindIndex). */
    public const LICENSE_INDEX = 'license';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'license_number' => 'encrypted',
            'license_issued_on' => 'immutable_date',
            'license_expires_on' => 'immutable_date',
            'id_document_number' => 'encrypted',
        ];
    }

    /**
     * Numéros jamais copiés dans le journal d'audit : seul leur changement est signalé.
     *
     * @return list<string>
     */
    public function sensitiveAuditAttributes(): array
    {
        return ['license_number', 'id_document_number'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Driver $driver) => $driver->refreshSearchKeys());
    }

    /**
     * Empreintes de recherche exacte (D8) ; le numéro de pièce partage l'usage
     * « id_document » avec la fiche client, pour détecter le même document.
     */
    public function refreshSearchKeys(): void
    {
        $index = app(BlindIndex::class);

        $this->license_hash = $index->hash($this->license_number, self::LICENSE_INDEX);
        $this->id_document_hash = $this->id_document_number !== null && $this->id_document_number !== ''
            ? $index->hash($this->id_document_number, Customer::ID_DOCUMENT_INDEX)
            : null;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
