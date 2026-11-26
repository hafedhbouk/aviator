<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\Exceptions\ReservationException;
use App\Domain\Availability\Period;
use App\Domain\Availability\ReservationHold;
use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Fleet\ReservedStatusSync;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\QuoteCalculator;
use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationOption;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\ReservationSettings;
use App\Support\Agency\CurrentAgency;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequenceDefinition;
use App\Support\Sequences\SequencePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Crée ou modifie une réservation au comptoir (C-01 à C-07, RG-01, RG-02, RG-13,
 * RG-15, Q36). Le même service servira l'espace client au lot 2 (RG-18).
 *
 * 1. Droits : l'agence de départ est une agence où l'acteur agit (Q37 : seule l'agence
 *    de départ crée) ; une réservation se modifie dans son agence.
 * 2. Client : pas de client sur liste noire (RG-15, refus sans motif, D36).
 * 3. Règles de réservation facultatives (Q90 : vides = désactivées) : durée minimale,
 *    préavis ; départ jamais dans le passé.
 * 4. Devis calculé côté serveur (QuoteCalculator) et figé avec la réservation.
 * 5. Confirmée : capacité et véhicule contrôlés sous verrou (ReservationHold), numéro
 *    `RES-AAAA-NNNNN` attribué dans la même transaction (aucun numéro consommé par un
 *    refus), occupation du véhicule. Brouillon : devis enregistré, sans véhicule ni
 *    capacité retenus ; il expire si `reservations.draft_expiry_hours` est renseigné.
 * 6. Statut « Réservé » du véhicule mis à jour (Q28).
 */
final class SaveReservation
{
    public const SEQUENCE_PREFIX = 'RES';

    public function __construct(
        private readonly ReservationHold $hold,
        private readonly QuoteCalculator $quotes,
        private readonly CustomerRiskGate $risk,
        private readonly SequenceAllocator $sequences,
        private readonly CurrentAgency $currentAgency,
        private readonly ReservationSettings $settings,
        private readonly ReservedStatusSync $reserved,
    ) {}

    /**
     * @param  array<string, mixed>  $data  customer_id, agency_id, vehicle_category_id, vehicle_id, starts_at, ends_at,
     *                                      options (identifiant => quantité), discount_amount, pickup_location_id,
     *                                      return_location_id, status (« confirmed » par défaut, ou « draft »)
     */
    public function handle(?Reservation $reservation, array $data, User $actor): Reservation
    {
        if ($reservation !== null && ! in_array($reservation->status, ReservationStatus::open(), true)) {
            throw ReservationException::invalidStatus($reservation->status->label(), 'modification');
        }

        $values = $this->validated($data, $reservation, $actor);

        if (! $actor->can($reservation === null ? 'create' : 'update', $reservation ?? Reservation::class)
            || ! $actor->canActOnAgency($values['agency_id'])) {
            throw ReservationException::notAllowed('les réservations de cette agence');
        }

        $customer = Customer::query()->findOrFail($values['customer_id']);
        $this->risk->assertCanRent($customer);

        $period = new Period($values['starts_at'], $values['ends_at']);
        $this->assertReservationRules($period, $reservation);

        $quote = $this->quotes->quote($values['vehicle_category_id'], $values['agency_id'], $period, $values['options'], $values['discount_amount']);
        $previousVehicleId = $reservation?->vehicle_id;

        $saved = DB::transaction(function () use ($reservation, $values, $period, $quote, $actor): Reservation {
            $buffer = 0;

            if ($values['status'] === ReservationStatus::Confirmed) {
                $buffer = $this->hold->check($values['vehicle_category_id'], $values['agency_id'], $period, $values['vehicle_id'], $reservation?->id);
            }

            $reservation ??= new Reservation([
                'number' => $this->sequences->next(new SequenceDefinition(self::SEQUENCE_PREFIX, SequencePeriod::Year, 5)),
                'channel' => 'counter',
                'created_by' => $actor->id,
            ]);

            $reservation->fill([
                'agency_id' => $values['agency_id'],
                'return_agency_id' => $values['agency_id'],
                'pickup_location_id' => $values['pickup_location_id'],
                'return_location_id' => $values['return_location_id'],
                'customer_id' => $values['customer_id'],
                'vehicle_category_id' => $values['vehicle_category_id'],
                'vehicle_id' => $values['vehicle_id'],
                'starts_at' => $period->startsAt,
                'ends_at' => $period->endsAt,
                'status' => $values['status'],
                'quote' => $quote->toArray(),
                'estimated_total_amount' => $quote->totalExcludingTax,
                'discount_amount' => $quote->discountAmount,
                'expires_at' => $this->expiry($values['status']),
            ]);
            $reservation->save();

            $this->saveOptions($reservation, $quote);
            $this->hold->apply($reservation, $buffer);

            return $reservation;
        });

        foreach (array_unique(array_filter([$previousVehicleId, $saved->vehicle_id])) as $vehicleId) {
            $this->reserved->sync(Vehicle::query()->withoutGlobalScopes()->findOrFail($vehicleId));
        }

        return $saved->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer_id: int, agency_id: int, vehicle_category_id: int, vehicle_id: int|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable, options: array<int, int>, discount_amount: Money|null, pickup_location_id: int|null, return_location_id: int|null, status: ReservationStatus}
     */
    private function validated(array $data, ?Reservation $reservation, User $actor): array
    {
        $agencyId = $data['agency_id'] ?? $reservation->agency_id ?? $this->currentAgency->id($actor) ?? $actor->agency_id;

        $validated = Validator::make([
            'customer_id' => $data['customer_id'] ?? $reservation?->customer_id,
            'agency_id' => $agencyId,
            'vehicle_category_id' => $data['vehicle_category_id'] ?? $reservation?->vehicle_category_id,
            // Modification : un champ absent garde sa valeur actuelle.
            'vehicle_id' => array_key_exists('vehicle_id', $data) ? $data['vehicle_id'] : $reservation?->vehicle_id,
            'starts_at' => $data['starts_at'] ?? $reservation?->starts_at?->format('Y-m-d H:i'),
            'ends_at' => $data['ends_at'] ?? $reservation?->ends_at?->format('Y-m-d H:i'),
            'pickup_location_id' => array_key_exists('pickup_location_id', $data) ? $data['pickup_location_id'] : $reservation?->pickup_location_id,
            'return_location_id' => array_key_exists('return_location_id', $data) ? $data['return_location_id'] : $reservation?->return_location_id,
            'status' => $data['status'] ?? $reservation?->status->value ?? ReservationStatus::Confirmed->value,
        ], [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'vehicle_category_id' => ['required', 'integer', 'exists:vehicle_categories,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'pickup_location_id' => ['nullable', 'integer', 'exists:agency_locations,id'],
            'return_location_id' => ['nullable', 'integer', 'exists:agency_locations,id'],
            'status' => ['required', 'in:'.ReservationStatus::Confirmed->value.','.ReservationStatus::Draft->value],
        ], attributes: [
            'customer_id' => 'client', 'agency_id' => 'agence de départ', 'vehicle_category_id' => 'catégorie',
            'vehicle_id' => 'véhicule', 'starts_at' => 'départ', 'ends_at' => 'retour',
        ])->validate();

        $requestedOptions = array_key_exists('options', $data)
            ? (array) $data['options']
            : ($reservation?->options()->pluck('quantity', 'option_id')->all() ?? []);

        $options = [];
        foreach ($requestedOptions as $optionId => $quantity) {
            if (is_numeric($optionId) && is_numeric($quantity) && (int) $quantity > 0) {
                $options[(int) $optionId] = (int) $quantity;
            }
        }

        try {
            $discount = AmountInput::parse(array_key_exists('discount_amount', $data) ? $data['discount_amount'] : $reservation?->discount_amount);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['discount_amount' => "Le champ remise : {$exception->getMessage()}"]);
        }

        $timezone = (string) config('app.timezone');

        return [
            'customer_id' => (int) $validated['customer_id'],
            'agency_id' => (int) $validated['agency_id'],
            'vehicle_category_id' => (int) $validated['vehicle_category_id'],
            'vehicle_id' => $validated['vehicle_id'] === null ? null : (int) $validated['vehicle_id'],
            'starts_at' => CarbonImmutable::parse((string) $validated['starts_at'], $timezone),
            'ends_at' => CarbonImmutable::parse((string) $validated['ends_at'], $timezone),
            'options' => $options,
            'discount_amount' => $discount,
            'pickup_location_id' => $validated['pickup_location_id'] === null ? null : (int) $validated['pickup_location_id'],
            'return_location_id' => $validated['return_location_id'] === null ? null : (int) $validated['return_location_id'],
            'status' => ReservationStatus::from((string) $validated['status']),
        ];
    }

    private function assertReservationRules(Period $period, ?Reservation $reservation): void
    {
        $now = CarbonImmutable::now();

        // Une réservation déjà commencée peut être prolongée ; une nouvelle ne part pas dans le passé.
        if ($reservation === null && $period->startsAt->lessThan($now->subMinutes(15))) {
            throw ReservationException::startInPast();
        }

        if ($this->settings->min_rental_days !== null
            && $period->startsAt->diffInMinutes($period->endsAt) < $this->settings->min_rental_days * 1440) {
            throw ReservationException::minRentalDays($this->settings->min_rental_days);
        }

        if ($reservation === null && $this->settings->min_notice_hours !== null
            && $period->startsAt->lessThan($now->addHours($this->settings->min_notice_hours))) {
            throw ReservationException::minNotice($this->settings->min_notice_hours);
        }
    }

    private function saveOptions(Reservation $reservation, PriceQuote $quote): void
    {
        $reservation->options()->delete();

        foreach ($quote->options as $line) {
            ReservationOption::query()->create([
                'reservation_id' => $reservation->id,
                'option_id' => $line['option_id'],
                'quantity' => $line['quantity'],
                'unit_amount' => $line['unit_amount'],
                'total_amount' => $line['amount'],
            ]);
        }
    }

    private function expiry(ReservationStatus $status): ?CarbonImmutable
    {
        if ($status !== ReservationStatus::Draft || $this->settings->draft_expiry_hours === null) {
            return null;
        }

        return CarbonImmutable::now()->addHours($this->settings->draft_expiry_hours);
    }
}
