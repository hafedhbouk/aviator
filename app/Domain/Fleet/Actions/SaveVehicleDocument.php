<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\DocumentStatusSync;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Enums\VehicleDocumentType;
use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Files\PrivateFiles;
use App\Support\Money\AmountInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Enregistre un document daté d'un véhicule (B-05) : assurance (compagnie, police,
 * échéance), visite technique (date, échéance), vignette, carte grise.
 *
 * - Super admin et admin du siège seulement (fleet.manage).
 * - Échéance obligatoire, sauf pour la carte grise ; fin postérieure ou égale au début.
 * - Une nouvelle échéance s'enregistre comme un nouveau document : l'historique est
 *   conservé ; la modification sert à corriger une saisie (journalisée).
 * - Le véhicule ne doit pas être sorti du parc. Le document reste attaché à son
 *   véhicule : il ne change jamais de véhicule.
 */
final class SaveVehicleDocument
{
    public function __construct(private readonly DocumentStatusSync $documents) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Vehicle $vehicle, ?VehicleDocument $document, array $data, User $actor): VehicleDocument
    {
        if (! $actor->can($document === null ? 'create' : 'update', $document ?? VehicleDocument::class)) {
            throw FleetException::notAllowed('les documents des véhicules');
        }

        if ($document !== null && $document->vehicle_id !== $vehicle->id) {
            throw FleetException::notAllowed('ce document');
        }

        if ($vehicle->status === VehicleStatus::Retired) {
            throw FleetException::vehicleRetired($vehicle->label());
        }

        $values = $this->validated($data);
        $file = $this->attachedFile($vehicle, $document, $data['file'] ?? null);

        return DB::transaction(function () use ($vehicle, $document, $values, $actor, $file): VehicleDocument {
            $document ??= new VehicleDocument(['vehicle_id' => $vehicle->id, 'created_by' => $actor->id]);
            $document->fill($values);

            if ($file !== null) {
                $document->file_path = $file;
            }

            $document->save();

            // Un document ajouté ou corrigé peut rendre le véhicule de nouveau
            // disponible, ou indisponible (DocumentStatusSync, lot L5).
            $this->documents->sync($vehicle, $actor);

            return $document;
        });
    }

    /**
     * Pièce jointe (B-08, D21) : un nouveau fichier n'est accepté que depuis le dossier
     * de dépôt, puis rangé sous le dossier du véhicule. Le chemin déjà enregistré vaut
     * « inchangé » ; un champ vide garde la pièce existante (aucune suppression).
     *
     * @return string|null nouveau chemin, ou null si la pièce ne change pas
     */
    private function attachedFile(Vehicle $vehicle, ?VehicleDocument $document, mixed $file): ?string
    {
        if (is_array($file)) {
            $file = array_values($file)[0] ?? null;
        }

        if (! is_string($file) || $file === '' || $file === $document?->file_path) {
            return null;
        }

        try {
            return PrivateFiles::attach($file, "vehicle-documents/{$vehicle->id}");
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $text = function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;
            $value = $value instanceof \BackedEnum ? $value->value : $value;

            return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        };

        $values = [
            'type' => $text('type'),
            'supplier_id' => $text('supplier_id'),
            'reference' => $text('reference'),
            'valid_from' => $text('valid_from'),
            'valid_until' => $text('valid_until'),
        ];

        $type = VehicleDocumentType::tryFrom((string) $values['type']);

        $untilRules = [($type?->hasExpiry() ?? true) ? 'required' : 'nullable', 'date_format:Y-m-d'];
        if ($values['valid_from'] !== null) {
            $untilRules[] = 'after_or_equal:valid_from';
        }

        $validated = Validator::make($values, [
            'type' => ['required', Rule::enum(VehicleDocumentType::class)],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:64'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => $untilRules,
        ], attributes: [
            'type' => 'type de document',
            'supplier_id' => 'compagnie ou fournisseur',
            'reference' => 'référence',
            'valid_from' => 'début de validité',
            'valid_until' => 'échéance',
        ])->validate();

        $validated['supplier_id'] = $validated['supplier_id'] === null ? null : (int) $validated['supplier_id'];

        try {
            $validated['amount'] = AmountInput::parse($data['amount'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => "Le champ montant : {$exception->getMessage()}"]);
        }

        return $validated;
    }
}
