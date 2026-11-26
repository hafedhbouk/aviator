<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Domain\Rental\Actions\RecordInspection;
use App\Domain\Rental\Actions\StartRental;
use App\Enums\ContractStatus;
use App\Enums\DamageSeverity;
use App\Enums\InspectionType;
use App\Enums\VehicleZone;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Reservations\ReservationScreens;
use App\Models\Contract;
use App\Models\Inspection;
use App\Models\InspectionDamage;
use App\Models\InspectionPhoto;
use App\Models\User;
use App\Support\Files\PrivateFiles;
use Closure;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * État des lieux sur tablette (écrans E4 et E5, parcours P3 et P4, D-06), pensé pour une
 * tablette de 10 pouces en portrait (Q72) : relevés (kilométrage, carburant en 8 crans),
 * dommages placés sur le schéma, photos prises avec l'appareil, signature du client,
 * puis validation du départ. Au retour : comparaison avec le départ ; la validation du
 * retour et le calcul des frais arrivent au lot L8.
 *
 * L'écran ne décide rien : chaque saisie appelle App\Domain\Rental (droits de l'agence
 * du contrat, état attendu, RG-04, ré-encodage des images). Un état des lieux validé
 * s'affiche en lecture seule.
 */
class InspectContract extends Page
{
    use InteractsWithRecord;
    use WithFileUploads;

    protected static string $resource = ContractResource::class;

    protected string $view = 'filament.contracts.inspect';

    public string $type = 'departure';

    public ?int $inspectionId = null;

    public ?string $mileage = null;

    public ?int $fuel_eighths = null;

    public ?string $notes = null;

    public bool $placing = false;

    public ?string $zone = null;

    public ?string $x = null;

    public ?string $y = null;

    public ?string $description = null;

    public ?string $severity = null;

    public ?string $absentReason = null;

    /**
     * Photo déjà compressée par le navigateur, en attente de rattachement (propriété non
     * typée : Livewire y place le fichier téléversé).
     *
     * @var TemporaryUploadedFile|null
     */
    public $photo = null;

    public function mount(int|string $record, string $type): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless($this->record instanceof Contract && (auth()->user()?->can('view', $this->record) ?? false), 404);

        $inspectionType = InspectionType::tryFrom($type);
        abort_if($inspectionType === null, 404);
        $this->type = $inspectionType->value;

        $existing = $this->inspectionQuery()->first();

        if ($existing === null && $this->canEdit()) {
            $existing = $this->attempt(fn (User $actor) => app(RecordInspection::class)->open($this->contract(), $inspectionType, $actor));
        }

        abort_if($existing === null, 404);

        $this->inspectionId = $existing->id;
        $this->mileage = $existing->mileage === null ? null : (string) $existing->mileage;
        $this->fuel_eighths = $existing->fuel_eighths;
        $this->notes = $existing->notes;
    }

    public function contract(): Contract
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Contract, 404);

        return $record;
    }

    public function getTitle(): string
    {
        return 'État des lieux de '.mb_strtolower($this->inspectionType()->label())." — {$this->contract()->number}";
    }

    public function inspectionType(): InspectionType
    {
        return InspectionType::from($this->type);
    }

    public function inspection(): Inspection
    {
        return Inspection::query()->with(['damages', 'photos'])->findOrFail($this->inspectionId);
    }

    public function departure(): ?Inspection
    {
        return $this->inspectionType() === InspectionType::Return
            ? Inspection::query()->with('damages')->where('contract_id', $this->contract()->id)->where('type', InspectionType::Departure->value)->first()
            : null;
    }

    public function canEdit(): bool
    {
        $expected = $this->inspectionType() === InspectionType::Departure ? ContractStatus::Draft : ContractStatus::Active;
        $inspection = $this->inspectionId === null ? null : Inspection::query()->find($this->inspectionId);

        return $this->contract()->status === $expected
            && ! ($inspection?->isValidated() ?? false)
            && (auth()->user()?->can('update', $this->contract()) ?? false);
    }

    /**
     * @return array<string, string>
     */
    public function zones(): array
    {
        return collect(VehicleZone::cases())->mapWithKeys(fn (VehicleZone $zone): array => [$zone->value => $zone->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    public function severities(): array
    {
        return collect(DamageSeverity::cases())->mapWithKeys(fn (DamageSeverity $s): array => [$s->value => $s->label()])->all();
    }

    public function photoUrl(InspectionPhoto $photo): string
    {
        return route('filament.admin.private-files.inspection-photo', ['photo' => $photo->id]);
    }

    public function signatureUrl(Inspection $inspection): ?string
    {
        return $inspection->signature_path === null ? null
            : route('filament.admin.private-files.inspection-signature', ['inspection' => $inspection->id]).'?v='.substr((string) $inspection->signature_sha256, 0, 8);
    }

    public function saveReadings(): void
    {
        $this->attempt(fn (User $actor) => app(RecordInspection::class)->update($this->inspection(), [
            'mileage' => $this->mileage === null || trim($this->mileage) === '' ? null : str_replace([' ', "\u{202F}", "\u{00A0}"], '', $this->mileage),
            'fuel_eighths' => $this->fuel_eighths,
            'notes' => $this->notes,
        ], $actor), 'Relevés enregistrés.');
    }

    public function setFuel(int $eighths): void
    {
        $this->fuel_eighths = max(0, min(8, $eighths));
        $this->saveReadings();
    }

    public function addDamage(): void
    {
        $saved = $this->attempt(fn (User $actor) => app(RecordInspection::class)->addDamage($this->inspection(), [
            'zone' => $this->zone, 'x' => $this->x, 'y' => $this->y,
            'description' => $this->description, 'severity' => $this->severity,
        ], $actor), 'Dommage ajouté.');

        if ($saved !== null) {
            $this->reset(['placing', 'zone', 'x', 'y', 'description', 'severity']);
        }
    }

    public function cancelDamage(): void
    {
        $this->reset(['placing', 'zone', 'x', 'y', 'description', 'severity']);
    }

    public function removeDamage(int $damageId): void
    {
        $damage = InspectionDamage::query()->where('inspection_id', $this->inspectionId)->findOrFail($damageId);

        $this->attempt(fn (User $actor) => app(RecordInspection::class)->removeDamage($damage, $actor), 'Dommage retiré.');
    }

    public function storePhoto(): void
    {
        $photo = $this->photo;
        $this->photo = null;

        if (! $photo instanceof TemporaryUploadedFile) {
            return;
        }

        $path = PrivateFiles::UPLOAD_DIRECTORY.'/'.Str::uuid()->toString().'.upload';
        Storage::disk(PrivateFiles::DISK)->put($path, (string) $photo->get());
        $photo->delete();

        $this->attempt(fn (User $actor) => app(RecordInspection::class)->addPhoto($this->inspection(), $path, $actor), 'Photo ajoutée.');
    }

    public function removePhoto(int $photoId): void
    {
        $photo = InspectionPhoto::query()->where('inspection_id', $this->inspectionId)->findOrFail($photoId);

        $this->attempt(fn (User $actor) => app(RecordInspection::class)->removePhoto($photo, $actor), 'Photo retirée.');
    }

    public function saveSignature(string $dataUrl): void
    {
        $this->attempt(fn (User $actor) => app(RecordInspection::class)->sign($this->inspection(), $dataUrl, $actor), 'Signature enregistrée.');
    }

    public function markAbsent(): void
    {
        $this->attempt(fn (User $actor) => app(RecordInspection::class)->markCustomerAbsent($this->inspection(), (string) $this->absentReason, $actor), 'Absence du client enregistrée.');
    }

    public function validateDeparture(): void
    {
        $contract = $this->attempt(fn (User $actor) => app(StartRental::class)->handle($this->contract(), $actor));

        if ($contract instanceof Contract) {
            Notification::make()->success()->title("Départ validé : contrat {$contract->number} en cours.")->send();
            $this->redirect(ContractResource::getUrl('view', ['record' => $contract]));
        }
    }

    /**
     * @return Builder<Inspection>
     */
    private function inspectionQuery(): Builder
    {
        return Inspection::query()->where('contract_id', $this->contract()->id)->where('type', $this->type);
    }

    /**
     * Exécute une saisie du domaine ; un refus métier s'affiche en notification, la saisie
     * reste à l'écran. Les erreurs de validation s'affichent sous leur champ.
     *
     * @template T
     *
     * @param  Closure(User): T  $write
     * @return T|null
     */
    private function attempt(Closure $write, ?string $success = null): mixed
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $result = $write($actor);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return null;
        } catch (Throwable $exception) {
            if (! ReservationScreens::isRefusal($exception)) {
                throw $exception;
            }

            Notification::make()->danger()->title($exception->getMessage())->persistent()->send();

            return null;
        }

        if ($success !== null) {
            Notification::make()->success()->title($success)->send();
        }

        $this->contract()->refresh();

        return $result;
    }
}
