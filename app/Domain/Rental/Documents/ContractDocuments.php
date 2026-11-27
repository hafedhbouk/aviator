<?php

declare(strict_types=1);

namespace App\Domain\Rental\Documents;

use App\Enums\InspectionType;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inspection;
use App\Settings\ContractSettings;
use App\Support\Files\PrivateFiles;
use Barryvdh\DomPDF\Facade\Pdf;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Documents du contrat en PDF (D-10, D43, Q5, Q15), par dompdf, en français, police
 * DejaVu Sans, sans ressource distante :
 *
 * - **contrat** : société, agence, parties, conducteurs, véhicule, période, conditions
 *   figées, montants HT figés, prolongations, frais du retour, signature du client au
 *   départ ; généré à la demande ;
 * - **état des lieux** : relevés, dommages, photos réduites, signature ; à la validation,
 *   l'exemplaire signé est **figé** sur le disque privé avec son empreinte, puis servi
 *   tel quel (un paramètre modifié ensuite ne le change pas) ;
 * - **conditions générales** : texte saisi par le super admin (`contracts.terms_text`).
 *
 * Tant que `contracts.template_validated` est faux, chaque page porte « Modèle non
 * validé » (Q15). Le contrat imprime l'identité du client, sa pièce et les permis ; ni
 * téléphone, ni courriel, ni adresse (D13), à confirmer par le conseil juridique (Q15).
 */
final class ContractDocuments
{
    /** Photos insérées dans l'état des lieux, réduites (mémoire du mutualisé). */
    private const PDF_PHOTOS = 6;

    private const PDF_PHOTO_SIDE = 520;

    public function __construct(private readonly ContractSettings $settings) {}

    public function contractPdf(Contract $contract): string
    {
        return $this->render('pdf.contract', ['contract' => $contract, 'departure' => $this->inspection($contract, InspectionType::Departure)]);
    }

    public function inspectionPdf(Inspection $inspection): string
    {
        $contract = Contract::query()->withoutGlobalScopes()->findOrFail($inspection->contract_id);

        return $this->render('pdf.inspection', ['contract' => $contract, 'inspection' => $inspection->load(['damages', 'photos'])]);
    }

    public function termsPdf(): string
    {
        return $this->render('pdf.terms', []);
    }

    /**
     * Fige l'exemplaire signé d'un état des lieux validé (D43) : PDF rangé sur le disque
     * privé avec son empreinte SHA-256. Sans effet s'il existe déjà.
     */
    public function archiveSignedInspection(Inspection $inspection): Inspection
    {
        if ($inspection->signed_pdf_path !== null) {
            return $inspection;
        }

        $bytes = $this->inspectionPdf($inspection);
        $path = "inspections/{$inspection->id}/etat-des-lieux-{$inspection->type->value}-signe.pdf";
        Storage::disk(PrivateFiles::DISK)->put($path, $bytes);

        $inspection->forceFill(['signed_pdf_path' => $path, 'signed_pdf_sha256' => hash('sha256', $bytes)])->save();

        return $inspection;
    }

    /**
     * Données communes : société, mention du modèle non validé, aides d'affichage.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function viewData(array $data): array
    {
        return $data + [
            'company' => Company::current(),
            'unvalidated' => ! $this->settings->template_validated,
            'terms' => $this->settings->terms_text,
            'docs' => $this,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data): string
    {
        return Pdf::loadView($view, $this->viewData($data))
            ->setPaper('a4')
            ->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])
            ->output();
    }

    public function inspection(Contract $contract, InspectionType $type): ?Inspection
    {
        return Inspection::query()->with(['damages', 'photos'])->where('contract_id', $contract->id)->where('type', $type->value)->first();
    }

    public function customer(Contract $contract): Customer
    {
        return Customer::query()->withoutGlobalScopes()->findOrFail($contract->customer_id);
    }

    /**
     * Signature du client, en image intégrée au PDF (jamais d'URL).
     */
    public function signatureDataUri(?Inspection $inspection): ?string
    {
        if ($inspection?->signature_path === null) {
            return null;
        }

        $bytes = Storage::disk(PrivateFiles::DISK)->get($inspection->signature_path);

        return $bytes === null ? null : 'data:image/png;base64,'.base64_encode($bytes);
    }

    /**
     * Photos réduites pour le PDF, en images intégrées.
     *
     * @return list<string>
     */
    public function photoDataUris(Inspection $inspection): array
    {
        $uris = [];

        foreach ($inspection->photos->take(self::PDF_PHOTOS) as $photo) {
            $bytes = Storage::disk(PrivateFiles::DISK)->get($photo->path);
            $image = $bytes === null ? false : @imagecreatefromstring($bytes);

            if (! $image instanceof GdImage) {
                continue;
            }

            $largest = max(imagesx($image), imagesy($image), self::PDF_PHOTO_SIDE);
            $width = max(1, intdiv(imagesx($image) * self::PDF_PHOTO_SIDE, $largest));
            $height = max(1, intdiv(imagesy($image) * self::PDF_PHOTO_SIDE, $largest));
            $thumb = imagecreatetruecolor($width, $height);
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

            ob_start();
            imagejpeg($thumb, null, 70);
            $uris[] = 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        }

        return $uris;
    }
}
