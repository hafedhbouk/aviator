<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Enums\DamageSeverity;
use App\Enums\InspectionType;
use App\Enums\VehicleZone;
use App\Models\Contract;
use App\Models\Inspection;
use App\Models\InspectionDamage;
use App\Models\InspectionPhoto;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\InspectionSettings;
use App\Support\Files\ImageReencoder;
use App\Support\Files\PrivateFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Saisie d'un état des lieux (D-06), avant sa validation (départ : StartRental ;
 * retour : lot L8). Tout passe par le contrat : droits de son agence (RG-13), état
 * attendu (brouillon pour le départ, en cours pour le retour).
 *
 * - Ouverture du départ : les dommages du dernier retour validé du véhicule sont repris
 *   automatiquement, marqués « déjà présents ».
 * - Kilométrage : au départ, jamais inférieur au dernier relevé du véhicule ; au retour,
 *   jamais inférieur au départ (RG-04). Carburant en huitièmes (0 à 8).
 * - Photos (D44) : 8 Mo au plus à l'envoi, ré-encodées (ImageReencoder), au plus
 *   `inspections.max_photos` ; rangées sur le disque privé.
 * - Signature (D45) : image PNG dessinée sur la tablette, ré-encodée ; ou, au retour
 *   seulement, client absent (boîte à clés, Q73) avec motif.
 */
final class RecordInspection
{
    public const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

    public const MAX_SIGNATURE_BYTES = 1024 * 1024;

    public function __construct(
        private readonly ImageReencoder $images,
        private readonly InspectionSettings $settings,
    ) {}

    public function open(Contract $contract, InspectionType $type, User $actor): Inspection
    {
        $this->assertCanEdit($contract, $type, $actor);

        return DB::transaction(function () use ($contract, $type, $actor): Inspection {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);
            $this->assertStatus($locked, $type);

            $existing = Inspection::query()->where('contract_id', $locked->id)->where('type', $type->value)->first();

            if ($existing !== null) {
                return $existing;
            }

            $inspection = Inspection::query()->create([
                'contract_id' => $locked->id,
                'type' => $type,
                'created_by' => $actor->id,
            ]);

            $this->carryDamages($inspection, $locked, $type);

            return $inspection;
        });
    }

    /**
     * @param  array<string, mixed>  $data  mileage, fuel_eighths, notes
     */
    public function update(Inspection $inspection, array $data, User $actor): Inspection
    {
        $contract = $this->editable($inspection, $actor);

        $values = Validator::make([
            'mileage' => $data['mileage'] ?? $inspection->mileage,
            'fuel_eighths' => $data['fuel_eighths'] ?? $inspection->fuel_eighths,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $inspection->notes,
        ], [
            'mileage' => ['nullable', 'integer', 'between:0,2000000'],
            'fuel_eighths' => ['nullable', 'integer', 'between:0,8'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['mileage' => 'kilométrage', 'fuel_eighths' => 'carburant', 'notes' => 'observations'])->validate();

        if ($values['mileage'] !== null) {
            $this->assertMileage($inspection, $contract, (int) $values['mileage']);
        }

        $inspection->fill([
            'mileage' => $values['mileage'] === null ? null : (int) $values['mileage'],
            'fuel_eighths' => $values['fuel_eighths'] === null ? null : (int) $values['fuel_eighths'],
            'notes' => $values['notes'],
        ])->save();

        return $inspection;
    }

    /**
     * @param  array<string, mixed>  $data  zone, x, y (0 à 100), description, severity
     */
    public function addDamage(Inspection $inspection, array $data, User $actor): InspectionDamage
    {
        $this->editable($inspection, $actor);

        $values = Validator::make($data, [
            'zone' => ['required', Rule::enum(VehicleZone::class)],
            'x' => ['required', 'regex:/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/'],
            'y' => ['required', 'regex:/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/'],
            'description' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::enum(DamageSeverity::class)],
        ], attributes: ['zone' => 'zone', 'description' => 'description', 'severity' => 'gravité'])->validate();

        return InspectionDamage::query()->create([
            'inspection_id' => $inspection->id,
            'zone' => $values['zone'],
            'x' => (string) $values['x'],
            'y' => (string) $values['y'],
            'description' => trim((string) $values['description']),
            'severity' => $values['severity'],
            'is_new' => true,
        ]);
    }

    /**
     * Un dommage repris du dernier retour ne se retire pas : il existe toujours.
     */
    public function removeDamage(InspectionDamage $damage, User $actor): void
    {
        $this->editable($damage->inspection, $actor);

        if (! $damage->is_new) {
            throw ContractException::invalidStatus('dommage déjà présent', 'retrait');
        }

        $damage->delete();
    }

    public function addPhoto(Inspection $inspection, string $uploadedPath, User $actor): InspectionPhoto
    {
        $this->editable($inspection, $actor);
        $disk = Storage::disk(PrivateFiles::DISK);

        if (! str_starts_with($uploadedPath, PrivateFiles::UPLOAD_DIRECTORY.'/') || str_contains($uploadedPath, '..')
            || str_contains($uploadedPath, '\\') || str_contains($uploadedPath, "\0") || ! $disk->exists($uploadedPath)) {
            throw new InvalidArgumentException('Photo introuvable : prenez-la de nouveau.');
        }

        if ($inspection->photos()->count() >= $this->settings->max_photos) {
            $disk->delete($uploadedPath);

            throw new InvalidArgumentException("{$this->settings->max_photos} photos au plus par état des lieux.");
        }

        if ($disk->size($uploadedPath) > self::MAX_UPLOAD_BYTES) {
            $disk->delete($uploadedPath);

            throw new InvalidArgumentException('Photo trop volumineuse : 8 Mo au plus.');
        }

        try {
            $image = $this->images->photo((string) $disk->get($uploadedPath));
        } finally {
            $disk->delete($uploadedPath);
        }

        $path = "inspections/{$inspection->id}/".Str::uuid()->toString().'.jpg';
        $disk->put($path, $image['bytes']);

        return InspectionPhoto::query()->create([
            'inspection_id' => $inspection->id,
            'path' => $path,
            'size_bytes' => strlen($image['bytes']),
            'sha256' => hash('sha256', $image['bytes']),
            'width' => $image['width'],
            'height' => $image['height'],
            'created_by' => $actor->id,
        ]);
    }

    public function removePhoto(InspectionPhoto $photo, User $actor): void
    {
        $this->editable($photo->inspection, $actor);

        Storage::disk(PrivateFiles::DISK)->delete($photo->path);
        $photo->delete();
    }

    /**
     * @param  string  $dataUrl  « data:image/png;base64,… » produit par le champ de signature
     */
    public function sign(Inspection $inspection, string $dataUrl, User $actor): Inspection
    {
        $this->editable($inspection, $actor);

        if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $matches) !== 1) {
            throw new InvalidArgumentException('Signature illisible : faites signer de nouveau.');
        }

        $bytes = base64_decode($matches[1], true);

        if ($bytes === false || strlen($bytes) > self::MAX_SIGNATURE_BYTES) {
            throw new InvalidArgumentException('Signature illisible : faites signer de nouveau.');
        }

        $image = $this->images->signature($bytes);
        $disk = Storage::disk(PrivateFiles::DISK);
        $path = "inspections/{$inspection->id}/signature-".Str::uuid()->toString().'.png';
        $disk->put($path, $image['bytes']);

        $previous = $inspection->signature_path;
        $inspection->fill([
            'signature_path' => $path,
            'signature_sha256' => hash('sha256', $image['bytes']),
            'customer_absent_reason' => null,
        ])->save();

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $inspection;
    }

    /**
     * Retour hors des heures d'ouverture (boîte à clés, Q73) : pas de signature, motif
     * obligatoire et heure de retour déclarée par le client.
     */
    public function markCustomerAbsent(Inspection $inspection, string $reason, User $actor): Inspection
    {
        $this->editable($inspection, $actor);

        if ($inspection->type !== InspectionType::Return) {
            throw ContractException::invalidStatus('état des lieux de départ', 'absence du client');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < CancelContract::MIN_REASON_LENGTH) {
            throw ContractException::reasonRequired(CancelContract::MIN_REASON_LENGTH);
        }

        $inspection->fill(['customer_absent_reason' => $reason])->save();

        return $inspection;
    }

    private function editable(Inspection $inspection, User $actor): Contract
    {
        $contract = Contract::query()->withoutGlobalScopes()->findOrFail($inspection->contract_id);
        $this->assertCanEdit($contract, $inspection->type, $actor);

        if ($inspection->isValidated()) {
            throw ContractException::invalidStatus('état des lieux validé', 'modification');
        }

        return $contract;
    }

    private function assertCanEdit(Contract $contract, InspectionType $type, User $actor): void
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        $this->assertStatus($contract, $type);
    }

    private function assertStatus(Contract $contract, InspectionType $type): void
    {
        $expected = $type === InspectionType::Departure ? ContractStatus::Draft : ContractStatus::Active;

        if ($contract->status !== $expected) {
            throw ContractException::invalidStatus($contract->status->label(), 'état des lieux de '.mb_strtolower($type->label()));
        }
    }

    private function assertMileage(Inspection $inspection, Contract $contract, int $mileage): void
    {
        if ($inspection->type === InspectionType::Departure) {
            $current = (int) Vehicle::query()->withoutGlobalScopes()->whereKey($contract->vehicle_id)->value('mileage');

            if ($mileage < $current) {
                throw ContractException::mileageBelow($current, 'dernier relevé du véhicule');
            }

            return;
        }

        $departure = Inspection::query()->where('contract_id', $contract->id)->where('type', InspectionType::Departure->value)->value('mileage');

        if ($departure !== null && $mileage < (int) $departure) {
            throw ContractException::mileageBelow((int) $departure, 'kilométrage de départ (RG-04)');
        }
    }

    /**
     * Dommages du dernier retour validé du véhicule, repris au départ suivant.
     */
    private function carryDamages(Inspection $inspection, Contract $contract, InspectionType $type): void
    {
        if ($type !== InspectionType::Departure) {
            // Au retour, les dommages du départ sont repris comme « déjà présents ».
            $source = Inspection::query()->where('contract_id', $contract->id)->where('type', InspectionType::Departure->value)->first();
        } else {
            $source = Inspection::query()
                ->where('type', InspectionType::Return->value)
                ->whereNotNull('validated_at')
                ->whereIn('contract_id', Contract::query()->withoutGlobalScopes()->where('vehicle_id', $contract->vehicle_id)->whereKeyNot($contract->id)->select('id'))
                ->latest('validated_at')->latest('id')
                ->first();
        }

        if ($source === null) {
            return;
        }

        foreach ($source->damages as $damage) {
            InspectionDamage::query()->create([
                'inspection_id' => $inspection->id,
                'zone' => $damage->zone,
                'x' => $damage->x,
                'y' => $damage->y,
                'description' => $damage->description,
                'severity' => $damage->severity,
                'is_new' => false,
                'carried_from_id' => $damage->id,
            ]);
        }
    }
}
