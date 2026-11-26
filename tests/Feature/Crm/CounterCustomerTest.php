<?php

use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Crm\Actions\CreateCounterCustomer;
use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Crm\CustomerSearch;
use App\Domain\Crm\Exceptions\CrmException;
use App\Domain\Crm\Exceptions\CustomerBlocked;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\CustomerRecommendation;
use App\Models\Driver;
use App\Models\User;
use App\Support\Authorization\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
 * Clients au comptoir (phase 4, lot L8) : création minimale (D40 et sa condition),
 * liste noire entre agences (RG-15), recherche (D24), recommandations en ajout seul.
 */

beforeEach(function () {
    $this->superAdmin = userWithProfile(Role::SuperAdmin);
    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agentSfax = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->create = app(CreateCounterCustomer::class);
});

function counterCustomer(array $overrides = [], ?User $actor = null): array
{
    return test()->create->handle(array_merge([
        'type' => 'individual',
        'first_name' => 'Mohamed',
        'last_name' => 'Ben Salah',
        'phone' => '98 765 432',
        'id_document_type' => 'cin',
        'id_document_number' => '01234567',
    ], $overrides), $actor ?? test()->agentSfax);
}

// --- D40 : création minimale ------------------------------------------------------

it('refuse une création sans pièce d\'identité ni permis, sans rien enregistrer (D40)', function () {
    expect(fn () => counterCustomer(['id_document_number' => null, 'license_number' => '  ']))
        ->toThrow(CrmException::class, 'au moins le numéro de la pièce d\'identité ou celui du permis');

    expect(Customer::query()->count())->toBe(0)
        ->and(Driver::query()->count())->toBe(0);
});

it('crée un client avec sa pièce, dans l\'agence de l\'agent, et le particulier comme conducteur s\'il donne son permis', function () {
    $result = counterCustomer(['license_number' => 'TN-12 345']);
    $customer = $result['customer'];

    expect($result['created'])->toBeTrue()
        ->and($customer->origin_agency_id)->toBe($this->sfax->id)
        ->and($customer->created_by)->toBe($this->agentSfax->id)
        ->and($customer->phone_search)->toBe('+21698765432')
        ->and($customer->id_document_hash)->not->toBeNull()
        ->and($customer->drivers()->sole()->license_hash)->not->toBeNull()
        ->and(DB::table('drivers')->value('license_number'))->not->toContain('12345');
});

it('retrouve un client sur liste noire recréé avec la même pièce, sans nouvelle fiche, et refuse sa réservation (D40, RG-15)', function (string $retyped) {
    $original = counterCustomer()['customer'];
    app(AddRecommendation::class)->handle($original, ['level' => 'blacklist', 'reason_code' => 'non_paiement', 'comment' => 'Motif confidentiel'], $this->superAdmin);

    // Un autre agent, dans une autre agence, saisit la même pièce autrement, avec un autre nom.
    $result = counterCustomer(['first_name' => 'Mohamed', 'last_name' => 'Bensalah', 'id_document_number' => $retyped], $this->agentSousse);

    expect($result['created'])->toBeFalse()
        ->and($result['customer']->id)->toBe($original->id)
        ->and($result['blacklisted'])->toBeTrue()
        ->and(Customer::query()->count())->toBe(1);

    try {
        app(CustomerRiskGate::class)->assertCanRent($result['customer']);
        $this->fail('La réservation d\'un client sur liste noire aurait dû être refusée.');
    } catch (CustomerBlocked $exception) {
        expect($exception->getMessage())->not->toContain('non_paiement')
            ->and($exception->getMessage())->not->toContain('confidentiel');
    }
})->with(['0123 4567', '0123-4567', ' 01234567 ']);

it('retrouve aussi un client sur liste noire par son permis', function () {
    $original = counterCustomer(['license_number' => 'TN 12345'])['customer'];
    app(AddRecommendation::class)->handle($original, ['level' => 'blacklist', 'reason_code' => 'degats'], $this->superAdmin);

    $result = counterCustomer(['id_document_number' => null, 'license_number' => 'tn-12345'], $this->agentSousse);

    expect($result['created'])->toBeFalse()
        ->and($result['customer']->id)->toBe($original->id)
        ->and($result['blacklisted'])->toBeTrue();
});

it('avertit d\'un téléphone déjà connu sans bloquer la création', function () {
    counterCustomer();

    $result = counterCustomer(['first_name' => 'Amel', 'id_document_number' => '99999999', 'phone' => '+216 98-765-432']);

    expect($result['created'])->toBeTrue()
        ->and($result['phone_matches'])->toBe(1);
});

it('exige la raison sociale d\'une entreprise et refuse une agence hors de portée', function () {
    expect(fn () => counterCustomer(['type' => 'company', 'company_name' => null]))->toThrow(ValidationException::class)
        ->and(fn () => counterCustomer(['origin_agency_id' => $this->sousse->id]))->toThrow(CrmException::class);
});

it('ne crée qu\'une fiche par document (verrou en base)', function () {
    counterCustomer();
    $second = counterCustomer();

    expect($second['created'])->toBeFalse()
        ->and(Customer::query()->count())->toBe(1);
});

// --- RG-15 entre agences ----------------------------------------------------------

it('bloque à Sousse un client mis sur liste noire depuis Sfax (RG-15)', function () {
    $customer = counterCustomer()['customer'];
    app(AddRecommendation::class)->handle($customer, ['level' => 'blacklist', 'reason_code' => 'fraude'], $this->superAdmin);

    $found = app(CustomerSearch::class)->search($this->agentSousse, 'id_document', '0123 4567');

    expect($found)->toHaveCount(1)
        ->and($found[0]['blacklisted'])->toBeTrue()
        ->and(fn () => app(CustomerRiskGate::class)->assertCanRent($customer))->toThrow(CustomerBlocked::class);
});

it('prend la dernière recommandation effective, en ajout seul (RG-16)', function () {
    $customer = counterCustomer()['customer'];
    $add = app(AddRecommendation::class);
    $first = $add->handle($customer, ['level' => 'blacklist', 'reason_code' => 'fraude'], $this->superAdmin);
    $add->handle($customer, ['level' => 'standard', 'reason_code' => 'levee'], $this->superAdmin);

    expect(app(CustomerRiskGate::class)->isBlacklisted($customer))->toBeFalse()
        ->and(CustomerRecommendation::query()->count())->toBe(2)
        ->and(fn () => $first->update(['level' => 'standard']))->toThrow(LogicException::class)
        ->and(fn () => $first->delete())->toThrow(LogicException::class);
});

it('réserve les recommandations au super admin en phase 4', function () {
    $customer = counterCustomer()['customer'];

    expect(fn () => app(AddRecommendation::class)->handle($customer, ['level' => 'blacklist', 'reason_code' => 'x'], $this->agentSfax))
        ->toThrow(CrmException::class);
});

// --- D24 : recherche ----------------------------------------------------------------

it('trouve un client par son nom complet, dans n\'importe quel ordre, sans accents ni casse, jamais par un nom partiel', function () {
    counterCustomer(['first_name' => 'Hédi', 'last_name' => 'Ben Ammar']);
    $search = app(CustomerSearch::class);

    expect($search->search($this->agentSousse, 'name', 'BEN AMMAR hedi'))->toHaveCount(1)
        ->and($search->search($this->agentSousse, 'name', 'hédi ben  ammar'))->toHaveCount(1)
        ->and($search->search($this->agentSousse, 'name', 'Ben Ammar'))->toHaveCount(0)
        ->and($search->search($this->agentSousse, 'name', 'Hédi'))->toHaveCount(0);
});

it('trouve un client par sa pièce, son permis ou son téléphone, quelle que soit la présentation', function (string $criterion, string $value) {
    counterCustomer(['license_number' => 'TN 777 888']);

    expect(app(CustomerSearch::class)->search($this->agentSousse, $criterion, $value))->toHaveCount(1);
})->with([
    'pièce avec espace' => ['id_document', '0123 4567'],
    'pièce avec tiret' => ['id_document', '0123-4567'],
    'pièce compacte' => ['id_document', '01234567'],
    'permis' => ['license', 'tn-777888'],
    'téléphone national' => ['phone', '98765432'],
    'téléphone international' => ['phone', '+216 98 765 432'],
    'téléphone 00' => ['phone', '00216 98-765-432'],
]);

it('renvoie 10 résultats au plus, sans aucune coordonnée', function () {
    foreach (range(1, 12) as $i) {
        counterCustomer(['first_name' => 'Sami', 'last_name' => 'Trabelsi', 'id_document_number' => sprintf('1000%04d', $i)]);
    }

    $found = app(CustomerSearch::class)->search($this->agentSfax, 'name', 'Sami Trabelsi');

    expect($found)->toHaveCount(10)
        ->and(array_keys($found[0]))->toBe(['customer_id', 'name', 'blacklisted', 'watched', 'rentals']);
});

it('journalise chaque recherche sans jamais la valeur recherchée', function () {
    counterCustomer();

    app(CustomerSearch::class)->search($this->agentSfax, 'id_document', '0123 4567');

    $log = Activity::query()->where('log_name', 'customer_access')->where('event', 'customer_search')->sole();

    expect($log->causer_id)->toBe($this->agentSfax->id)
        ->and($log->properties->all())->toBe(['criterion' => 'id_document', 'results' => 1])
        ->and(json_encode($log->toArray()))->not->toContain('4567');
});

it('refuse la 31e recherche de l\'heure et prévient le super admin une seule fois, sans donnée client', function () {
    counterCustomer();
    $search = app(CustomerSearch::class);

    foreach (range(1, 30) as $i) {
        $search->search($this->agentSfax, 'name', 'Mohamed Ben Salah');
    }

    expect(fn () => $search->search($this->agentSfax, 'name', 'Mohamed Ben Salah'))->toThrow(CrmException::class, '30 recherches')
        ->and(fn () => $search->search($this->agentSfax, 'name', 'Mohamed Ben Salah'))->toThrow(CrmException::class);

    $notifications = DB::table('notifications')->where('notifiable_id', $this->superAdmin->id)->get();

    expect($notifications)->toHaveCount(1);

    // Données stockées en JSON (accents encodés) : comparaison sur le texte décodé.
    $text = implode(' ', array_filter(json_decode($notifications[0]->data, true, flags: JSON_THROW_ON_ERROR), 'is_string'));

    expect($text)->toContain($this->agentSfax->name)
        ->and($text)->not->toContain('Ben Salah')
        ->and($text)->not->toContain('Mohamed');

    // Un autre agent n'est pas concerné.
    expect($search->search($this->agentSousse, 'name', 'Mohamed Ben Salah'))->toHaveCount(1);
});
