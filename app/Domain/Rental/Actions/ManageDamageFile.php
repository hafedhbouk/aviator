<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ChargeNature;
use App\Enums\ContractStatus;
use App\Enums\DamageDocumentKind;
use App\Enums\InspectionType;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\DamageFile;
use App\Models\DamageFileDocument;
use App\Models\Inspection;
use App\Models\InspectionDamage;
use App\Models\User;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Dossier de dommage (analyse du cabinet N2) : justificatif d'une indemnité pour dégâts
 * ou d'une franchise, exigé par la facture en phase 6 pour une ligne hors champ.
 *
 * - Ouverture : pour un dommage **nouveau** relevé à l'état des lieux de retour validé du
 *   contrat ; le frais du dommage (lot L8) y est rattaché. Un seul dossier par dommage.
 * - Pièces : constat, devis, facture de réparation, autre ; PDF ou images contrôlés sur
 *   leur contenu (PrivateFiles), disque privé, empreinte SHA-256 ; ajout seul.
 * - Complet : au moins un constat et un devis ou une facture de réparation ; il n'accepte
 *   plus de pièce. Les états des lieux, photos et la référence du contrat sont repris par
 *   le contrat lui-même.
 */
final class ManageDamageFile
{
    public function open(Contract $contract, int $damageId, User $actor): DamageFile
    {
        $this->assertCanEdit($contract, $actor);

        return DB::transaction(function () use ($contract, $damageId, $actor): DamageFile {
            $damage = InspectionDamage::query()->lockForUpdate()->find($damageId);
            $return = $damage === null ? null : Inspection::query()->find($damage->inspection_id);

            if ($damage === null || ! $damage->is_new || $return === null || $return->contract_id !== $contract->id
                || $return->type !== InspectionType::Return || ! $return->isValidated()) {
                throw ContractException::damageNotOfContract();
            }

            $file = DamageFile::query()->firstOrCreate(['inspection_damage_id' => $damage->id], [
                'contract_id' => $contract->id,
                'status' => DamageFile::OPEN,
                'created_by' => $actor->id,
            ]);

            ContractCharge::query()->where('contract_id', $contract->id)->where('nature', ChargeNature::Damage->value)
                ->where('inspection_damage_id', $damage->id)->whereNull('damage_file_id')
                ->get()->each(fn (ContractCharge $charge) => $charge->forceFill(['damage_file_id' => $file->id])->save());

            return $file;
        });
    }

    public function attach(DamageFile $file, DamageDocumentKind $kind, string $uploadedPath, User $actor): DamageFileDocument
    {
        $contract = Contract::query()->withoutGlobalScopes()->findOrFail($file->contract_id);
        $this->assertCanEdit($contract, $actor);

        if ($file->isComplete()) {
            throw ContractException::invalidStatus('dossier complet', 'ajout de pièce');
        }

        $path = PrivateFiles::attach($uploadedPath, "damage-files/{$file->id}");
        $disk = Storage::disk(PrivateFiles::DISK);

        return DamageFileDocument::query()->create([
            'damage_file_id' => $file->id,
            'kind' => $kind,
            'path' => $path,
            'sha256' => hash('sha256', (string) $disk->get($path)),
            'size_bytes' => (int) $disk->size($path),
            'uploaded_by' => $actor->id,
        ]);
    }

    public function complete(DamageFile $file, User $actor): DamageFile
    {
        $contract = Contract::query()->withoutGlobalScopes()->findOrFail($file->contract_id);
        $this->assertCanEdit($contract, $actor);

        $missing = $this->missing($file);

        if ($missing !== []) {
            throw ContractException::damageFileIncomplete($missing);
        }

        $file->forceFill(['status' => DamageFile::COMPLETE, 'completed_at' => CarbonImmutable::now(), 'completed_by' => $actor->id])->save();

        return $file;
    }

    /**
     * Pièces qui manquent pour que le dossier soit complet (vide = complet possible).
     *
     * @return list<string>
     */
    public function missing(DamageFile $file): array
    {
        $kinds = $file->documents()->pluck('kind')->map(fn ($kind): string => $kind instanceof DamageDocumentKind ? $kind->value : (string) $kind)->all();
        $missing = [];

        if (! in_array(DamageDocumentKind::Report->value, $kinds, true)) {
            $missing[] = 'constat';
        }

        if (! in_array(DamageDocumentKind::Quote->value, $kinds, true) && ! in_array(DamageDocumentKind::RepairInvoice->value, $kinds, true)) {
            $missing[] = 'devis ou facture de réparation';
        }

        return $missing;
    }

    private function assertCanEdit(Contract $contract, User $actor): void
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        if (! in_array($contract->status, [ContractStatus::Returned, ContractStatus::Closed], true)) {
            throw ContractException::invalidStatus($contract->status->label(), 'dossier de dommage');
        }
    }
}
