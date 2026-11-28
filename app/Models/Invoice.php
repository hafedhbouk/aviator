<?php

namespace App\Models;

use App\Domain\Billing\Exceptions\InvoiceLockedException;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Support\Agency\BelongsToAgency;
use App\Support\Audit\AuditsChanges;
use App\Support\Billing\InvoiceBuilder;
use App\Support\Documents\HasSequenceNumber;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Money\UnsupportedCurrencyException;
use App\Support\Sequences\Series;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Facture, facture complémentaire ou avoir (modules E, phase 6). Document de
 * l'agence émettrice (RG-13, BelongsToAgency), numéroté à la validation dans sa
 * série (RG-07, D27) et jamais supprimé une fois numéroté (HasSequenceNumber).
 * Écrit uniquement par les actions de App\Domain\Billing.
 *
 * Verrou (RG-06, D56), par le modèle et par le constructeur de requêtes
 * (InvoiceBuilder) : une fois validée, la facture ne change plus, sauf
 * - son statut, selon InvoiceStatus::canBecome ;
 * - les informations d'émission ou d'annulation, enregistrées une seule fois ;
 * - son statut de paiement, calculé depuis les imputations.
 *
 * Dinar seul (D26) : toute autre devise est refusée, rien n'est enregistré.
 *
 * @property int $id
 * @property int $agency_id
 * @property InvoiceType $type
 * @property string|null $number
 * @property Series|null $series
 * @property string|null $sequence_key
 * @property int|null $fiscal_year
 * @property InvoiceStatus $status
 * @property PaymentStatus $payment_status
 * @property int|null $contract_id
 * @property int $customer_id
 * @property int|null $credited_invoice_id
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string $currency
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $issuer_snapshot
 * @property Money $total_excl_tax_amount
 * @property Money $total_tax_amount
 * @property Money $total_incl_tax_amount
 * @property Money $stamp_duty_amount
 * @property Money $total_due_amount
 * @property int|null $stamp_duty_rule_id
 * @property int|null $stamp_exemption_rule_id
 * @property string|null $stamp_exemption_reason
 * @property string|null $stamp_exemption_file_path
 * @property string|null $stamp_exemption_file_sha256
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $issued_at
 * @property string|null $issued_pdf_path
 * @property string|null $issued_pdf_sha256
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $created_by
 * @property int|null $validated_by
 * @property int|null $issued_by
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $created_at
 * @property-read Agency $agency
 * @property-read Customer $customer
 * @property-read Contract|null $contract
 * @property-read Invoice|null $creditedInvoice
 */
#[Fillable([
    'agency_id', 'type', 'number', 'series', 'sequence_key', 'fiscal_year', 'status', 'payment_status',
    'contract_id', 'customer_id', 'credited_invoice_id', 'period_start', 'period_end', 'currency',
    'customer_snapshot', 'issuer_snapshot', 'total_excl_tax_amount', 'total_tax_amount', 'total_incl_tax_amount',
    'stamp_duty_amount', 'total_due_amount', 'stamp_duty_rule_id', 'stamp_exemption_rule_id', 'stamp_exemption_reason',
    'stamp_exemption_file_path', 'stamp_exemption_file_sha256', 'due_on', 'validated_at', 'issued_at',
    'issued_pdf_path', 'issued_pdf_sha256', 'cancellation_reason', 'cancelled_at',
    'created_by', 'validated_by', 'issued_by', 'cancelled_by',
])]
class Invoice extends Model
{
    use AuditsChanges;
    use BelongsToAgency;
    use HasSequenceNumber;

    /** Colonnes écrites une seule fois, à l'émission ou à l'annulation. */
    private const SET_ONCE = [
        InvoiceStatus::Issued->value => ['issued_at', 'issued_by', 'issued_pdf_path', 'issued_pdf_sha256'],
        InvoiceStatus::Cancelled->value => ['cancellation_reason', 'cancelled_at', 'cancelled_by'],
    ];

    /** Colonnes qui changent toujours, même sur une facture validée. */
    private const ALWAYS_WRITABLE = ['payment_status', 'updated_at'];

    /** Colonnes obligatoires à la validation (D59, référentiel §4.1). */
    private const REQUIRED_AT_VALIDATION = ['number', 'series', 'sequence_key', 'fiscal_year', 'validated_at', 'customer_snapshot', 'issuer_snapshot'];

    protected $attributes = [
        'currency' => Money::CURRENCY,
        'status' => 'draft',
        'payment_status' => 'unpaid',
    ];

    protected static function booted(): void
    {
        static::saving(fn (Invoice $invoice) => UnsupportedCurrencyException::assertTnd($invoice->currency));

        static::creating(function (Invoice $invoice): void {
            if ($invoice->status !== InvoiceStatus::Draft || $invoice->isNumbered()) {
                throw InvoiceLockedException::createdAsDraft();
            }

            if ($invoice->type === InvoiceType::CreditNote && $invoice->credited_invoice_id === null) {
                throw InvoiceLockedException::creditNoteWithoutOrigin();
            }
        });

        static::updating(fn (Invoice $invoice) => $invoice->assertUpdateAllowed());

        static::deleting(function (Invoice $invoice): void {
            if ($invoice->getOriginal('status') !== InvoiceStatus::Draft) {
                throw InvoiceLockedException::cannotDelete();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'series' => Series::class,
            'fiscal_year' => 'integer',
            'status' => InvoiceStatus::class,
            'payment_status' => PaymentStatus::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'customer_snapshot' => 'array',
            'issuer_snapshot' => 'array',
            'total_excl_tax_amount' => MoneyCast::class,
            'total_tax_amount' => MoneyCast::class,
            'total_incl_tax_amount' => MoneyCast::class,
            'stamp_duty_amount' => MoneyCast::class,
            'total_due_amount' => MoneyCast::class,
            'due_on' => 'immutable_date',
            'validated_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    private function assertUpdateAllowed(): void
    {
        /** @var InvoiceStatus $from */
        $from = $this->getOriginal('status');
        $to = $this->status;

        if ($to !== $from && ! $from->canBecome($to)) {
            throw InvoiceLockedException::transition($from->label(), $to->label());
        }

        if ($from === InvoiceStatus::Draft) {
            if ($to === InvoiceStatus::Validated) {
                foreach (self::REQUIRED_AT_VALIDATION as $column) {
                    if ($this->getAttribute($column) === null || $this->getAttribute($column) === '') {
                        throw InvoiceLockedException::incompleteValidation();
                    }
                }
            }

            return;
        }

        $writable = $to !== $from ? ['status', ...(self::SET_ONCE[$to->value] ?? [])] : [];

        foreach (array_keys($this->getDirty()) as $column) {
            if (in_array($column, self::ALWAYS_WRITABLE, true)) {
                continue;
            }

            if (! in_array($column, $writable, true)) {
                throw InvoiceLockedException::locked($this->type->label());
            }

            if ($column !== 'status' && $this->getOriginal($column) !== null) {
                throw InvoiceLockedException::setOnce($column);
            }
        }

        if ($to === InvoiceStatus::Cancelled && trim((string) $this->cancellation_reason) === '') {
            throw InvoiceLockedException::cancellationReasonRequired();
        }
    }

    /**
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): InvoiceBuilder
    {
        return new InvoiceBuilder($query);
    }

    /**
     * Marque la requête d'enregistrement du modèle : ses contrôles ont déjà joué
     * dans l'événement « updating ».
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        self::markModelSave($query);

        return parent::performUpdate($query);
    }

    /**
     * @param  Builder<*>  $query
     */
    private static function markModelSave(Builder $query): void
    {
        if ($query instanceof InvoiceBuilder) {
            $query->forModelSave();
        }
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Facture d'origine d'un avoir.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credited_invoice_id');
    }

    /**
     * Avoirs d'une facture.
     *
     * @return HasMany<Invoice, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credited_invoice_id');
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<InvoiceTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class);
    }

    /**
     * @return BelongsTo<StampDutyRule, $this>
     */
    public function stampDutyRule(): BelongsTo
    {
        return $this->belongsTo(StampDutyRule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
