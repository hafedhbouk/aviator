<?php

declare(strict_types=1);

namespace App\Filament\Prototype;

use App\Domain\Availability\Planning\PlanningBlock;
use App\Domain\Availability\Planning\PlanningBlockType;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Données FICTIVES du prototype du planning (plan de la phase 4, lot L0, D35) :
 * 50 véhicules répartis sur les agences données, environ 300 occupations par mois.
 * Générées en mémoire, de façon déterministe ; rien n'est écrit en base.
 * À supprimer avec le prototype au lot L12.
 */
final class PlanningDemoData
{
    public const int VEHICLE_COUNT = 50;

    private const array CATEGORIES = ['Citadine', 'Compacte', 'Berline', 'SUV', 'Utilitaire'];

    private const array MODELS = ['Clio', 'i10', 'Polo', '208', 'Sandero', 'Tucson', 'Duster', 'Kangoo'];

    /** @var list<array{id: int, agency_id: int, label: string, category: string}> */
    private array $vehicles = [];

    /** @var list<PlanningBlock> */
    private array $blocks = [];

    /**
     * @param  list<int>  $agencyIds
     */
    public function __construct(array $agencyIds, CarbonImmutable $today, int $seed = 2026)
    {
        if ($agencyIds === []) {
            return;
        }

        $random = new Randomizer(new Mt19937($seed));

        for ($i = 1; $i <= self::VEHICLE_COUNT; $i++) {
            $model = self::MODELS[$random->getInt(0, count(self::MODELS) - 1)];

            $this->vehicles[] = [
                'id' => $i,
                'agency_id' => $agencyIds[($i - 1) % count($agencyIds)],
                'label' => sprintf('DEMO-%03d · %s', $i, $model),
                'category' => self::CATEGORIES[$random->getInt(0, count(self::CATEGORIES) - 1)],
            ];
        }

        $this->generateBlocks($random, $today->startOfDay());
    }

    /**
     * @return list<array{id: int, agency_id: int, label: string, category: string}>
     */
    public function vehicles(): array
    {
        return $this->vehicles;
    }

    /**
     * Occupations qui chevauchent la période demandée.
     *
     * @return list<PlanningBlock>
     */
    public function blocksBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(array_filter(
            $this->blocks,
            fn (PlanningBlock $block): bool => $block->startsAt < $to && ($block->bufferEndsAt ?? $block->endsAt) > $from,
        ));
    }

    private function generateBlocks(Randomizer $random, CarbonImmutable $today): void
    {
        $now = CarbonImmutable::now();
        $number = 0;

        foreach ($this->vehicles as $vehicle) {
            $cursor = $today->subDays(45)->setTime(8, 0);
            $limit = $today->addDays(75);

            while ($cursor < $limit) {
                $cursor = $cursor->addHours($random->getInt(4, 60));
                $start = $cursor->setTime($random->getInt(8, 17), $random->getInt(0, 1) * 30);
                $end = $start->addHours($random->getInt(20, 150));
                $roll = $random->getInt(1, 100);

                $type = match (true) {
                    $roll <= 8 => PlanningBlockType::Maintenance,
                    $roll <= 12 => PlanningBlockType::Unavailability,
                    $start <= $now => PlanningBlockType::Contract,
                    default => PlanningBlockType::Reservation,
                };

                $customer = $type->concernsCustomer();
                $number++;

                $this->blocks[] = new PlanningBlock(
                    vehicleId: $vehicle['id'],
                    agencyId: $vehicle['agency_id'],
                    type: $type,
                    startsAt: $start,
                    endsAt: $end,
                    bufferEndsAt: $customer ? $end->addMinutes(60) : null,
                    reference: $customer ? sprintf('%s-2026-%05d', $type === PlanningBlockType::Contract ? 'CTR' : 'RES', $number) : null,
                    customerName: $customer ? sprintf('Client fictif %04d', $random->getInt(1, 999)) : null,
                    late: $type === PlanningBlockType::Contract && $end < $now && $random->getInt(1, 100) <= 10,
                    url: $customer ? '#'.strtolower(sprintf('%s-%d', $type->value, $number)) : null,
                );

                $cursor = $end->addMinutes(60);
            }
        }
    }
}
