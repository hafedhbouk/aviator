<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\TaxReporting\FiscalConfigurationReport;
use App\Models\Company;
use App\Models\TaxCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Version imprimable du rapport de configuration fiscale, à signer par la direction
 * (phase 10). Route authentifiée du panneau, 2FA exigée (EnsurePrivilegedTwoFactor),
 * super admin et admin seulement.
 */
class PrintFiscalConfigurationReport extends Controller
{
    public function __invoke(FiscalConfigurationReport $report): View
    {
        Gate::authorize('viewAny', TaxCode::class);

        return view('reports.fiscal-configuration-print', [
            'report' => $report->build(now()),
            'companyName' => Company::current()->legal_name ?? 'Fiche société non renseignée',
        ]);
    }
}
