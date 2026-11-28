<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Support\Audit\AuditsChanges;
use App\Support\Privacy\BlindIndex;
use App\Support\Text\SearchNormalizer;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client (K-01). Structure seulement en phase 2 (D18) : écrans et recherche en phase 7.
 *
 * Les coordonnées (téléphone, e-mail, adresse, pièce d'identité) relèvent du
 * carnet d'adresses (RG-14, D13) : leur accès passe par CustomerDataAccess.
 *
 * @property int $id
 * @property CustomerType $type
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company_name
 * @property string|null $tax_id
 * @property string|null $trade_register RNE du client entreprise (D59)
 * @property string $search_name
 * @property string $name_key
 * @property string|null $phone
 * @property string|null $phone_search
 * @property string|null $email
 * @property string|null $id_document_number
 * @property string|null $id_document_hash
 * @property int $origin_agency_id
 */
#[Fillable([
    'type', 'first_name', 'last_name', 'company_name', 'tax_id', 'trade_register', 'birth_date', 'nationality',
    'phone', 'email', 'address', 'city', 'country',
    'id_document_type', 'id_document_number', 'id_document_expires_at',
    'origin_agency_id', 'acquisition_source', 'referrer_customer_id', 'created_by',
])]
#[Hidden(['id_document_number', 'id_document_hash', 'phone_search', 'name_key'])]
class Customer extends Model
{
    use AuditsChanges;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /** Usage de l'empreinte du numéro de pièce d'identité (BlindIndex). */
    public const ID_DOCUMENT_INDEX = 'id_document';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'birth_date' => 'date',
            'id_document_number' => 'encrypted',
            'id_document_expires_at' => 'date',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * Le numéro de pièce n'est jamais copié dans le journal d'audit : seul son changement est signalé.
     *
     * @return list<string>
     */
    public function sensitiveAuditAttributes(): array
    {
        return ['id_document_number'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Customer $customer) => $customer->refreshSearchKeys());
    }

    /**
     * Recalcule les clés de recherche exacte : nom et téléphone normalisés,
     * empreinte HMAC de la pièce (D8). Utilisée à chaque enregistrement et
     * par « aviator:rehash-blind-index » après un changement de clé.
     */
    public function refreshSearchKeys(): void
    {
        $this->search_name = SearchNormalizer::name($this->displayName());
        $this->name_key = SearchNormalizer::nameKey($this->displayName());
        $this->phone_search = $this->phone !== null && $this->phone !== ''
            ? SearchNormalizer::phone($this->phone)
            : null;
        $this->id_document_hash = $this->id_document_number !== null && $this->id_document_number !== ''
            ? app(BlindIndex::class)->hash($this->id_document_number, self::ID_DOCUMENT_INDEX)
            : null;
    }

    /**
     * Nom affiché : raison sociale pour une entreprise, prénom et nom pour un particulier.
     * C'est la seule donnée client visible par l'admin dans les listes métier (D13).
     */
    public function displayName(): string
    {
        if ($this->type === CustomerType::Company && $this->company_name !== null) {
            return $this->company_name;
        }

        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function originAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'origin_agency_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_customer_id');
    }

    /**
     * @return HasMany<Driver, $this>
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /**
     * @return HasMany<CustomerRecommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(CustomerRecommendation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
