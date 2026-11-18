<?php

use App\Models\Sequence;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequenceDefinition;
use App\Support\Sequences\SequenceOverflowException;
use App\Support\Sequences\SequencePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function allocator(): SequenceAllocator
{
    return app(SequenceAllocator::class);
}

function tunis(string $datetime): CarbonImmutable
{
    return CarbonImmutable::parse($datetime, 'Africa/Tunis');
}

it('attribue des numéros consécutifs au format préfixe-période-numéro', function () {
    $reservations = new SequenceDefinition('RES', SequencePeriod::Year, 5);

    expect(allocator()->next($reservations, tunis('2026-11-18 10:00')))->toBe('RES-2026-00001')
        ->and(allocator()->next($reservations, tunis('2026-11-18 10:01')))->toBe('RES-2026-00002')
        ->and(allocator()->next($reservations, tunis('2026-11-19 09:00')))->toBe('RES-2026-00003');
});

it('repart à 1 à chaque nouvelle période', function () {
    $yearly = new SequenceDefinition('CTR', SequencePeriod::Year, 5);
    $monthly = new SequenceDefinition('MOIS', SequencePeriod::Month, 4);

    allocator()->next($yearly, tunis('2026-12-31 10:00'));

    expect(allocator()->next($yearly, tunis('2027-01-01 08:00')))->toBe('CTR-2027-00001')
        ->and(allocator()->next($monthly, tunis('2026-11-30 10:00')))->toBe('MOIS-202611-0001')
        ->and(allocator()->next($monthly, tunis('2026-12-01 10:00')))->toBe('MOIS-202612-0001');
});

it('détermine la période dans le fuseau Africa/Tunis', function () {
    $yearly = new SequenceDefinition('RES', SequencePeriod::Year, 5);

    // 31/12/2026 à 23:30 UTC = 01/01/2027 à 00:30 à Tunis.
    expect(allocator()->next($yearly, CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC')))->toBe('RES-2027-00001');
});

it('gère une numérotation continue sans période', function () {
    $continuous = new SequenceDefinition('REC', SequencePeriod::None, 6);

    expect(allocator()->next($continuous, tunis('2026-11-18')))->toBe('REC-000001')
        ->and(allocator()->next($continuous, tunis('2027-03-01')))->toBe('REC-000002');
});

it('tient des compteurs séparés par préfixe', function () {
    expect(allocator()->next(new SequenceDefinition('AAA', SequencePeriod::Year, 3), tunis('2026-11-18')))->toBe('AAA-2026-001')
        ->and(allocator()->next(new SequenceDefinition('BBB', SequencePeriod::Year, 3), tunis('2026-11-18')))->toBe('BBB-2026-001');
});

it('ne laisse aucun trou quand l\'enregistrement du document échoue', function () {
    $definition = new SequenceDefinition('RES', SequencePeriod::Year, 5);
    allocator()->next($definition, tunis('2026-11-18'));

    try {
        DB::transaction(function () use ($definition): void {
            allocator()->next($definition, tunis('2026-11-18'));

            throw new RuntimeException('Échec de l\'enregistrement du document');
        });
    } catch (RuntimeException) {
        // Transaction annulée : l'incrément l'est aussi.
    }

    expect(allocator()->next($definition, tunis('2026-11-18')))->toBe('RES-2026-00002');
});

it('refuse d\'allonger le format en silence quand la longueur est atteinte', function () {
    $definition = new SequenceDefinition('XX', SequencePeriod::None, 2);
    Sequence::query()->create(['key' => 'XX', 'last_value' => 99]);

    allocator()->next($definition, tunis('2026-11-18'));
})->throws(SequenceOverflowException::class, '2 chiffres');

it('refuse une définition invalide', function (Closure $make) {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'préfixe en minuscules' => [fn () => new SequenceDefinition('res', SequencePeriod::Year, 5)],
    'préfixe vide' => [fn () => new SequenceDefinition('', SequencePeriod::Year, 5)],
    'longueur nulle' => [fn () => new SequenceDefinition('RES', SequencePeriod::Year, 0)],
]);

it('numérote avec un format de démonstration, qui n\'est pas le format fiscal des factures', function () {
    // FORMAT DE DÉMONSTRATION, NON FISCAL : le format réel des factures et avoirs
    // (préfixe, période, séquence commune ou par agence) viendra des règles fiscales
    // validées (Q11, Q30, Q16). Aucune définition de facture n'existe dans le code.
    $demo = new SequenceDefinition('DEMO-FACT', SequencePeriod::Year, 6);

    expect(allocator()->next($demo, tunis('2026-11-18')))->toBe('DEMO-FACT-2026-000001');
});
