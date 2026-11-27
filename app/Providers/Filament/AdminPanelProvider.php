<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Auth\ResetPassword;
use App\Filament\Pages\SwitchAgency;
use App\Http\Controllers\DamageFileDocumentController;
use App\Http\Controllers\InspectionFileController;
use App\Http\Controllers\PrintFiscalConfigurationReport;
use App\Http\Controllers\VehicleDocumentFileController;
use App\Http\Middleware\EnsurePrivilegedTwoFactor;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Aviator')
            // Pages d'authentification d'Aviator : règles D19 sur le mot de passe et
            // journalisation des blocages de connexion (app/Filament/Auth).
            ->login(Login::class)
            ->passwordReset(resetAction: ResetPassword::class)
            ->profile(EditProfile::class, isSimple: false)
            // 2FA par application (TOTP) avec codes de récupération. « isRequired » enregistre la
            // page d'activation ; EnsurePrivilegedTwoFactor ne l'impose qu'au super admin et à l'admin (D9).
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsurePrivilegedTwoFactor::class)
            ->colors([
                'primary' => Color::Sky,
            ])
            // Centre de notifications (alertes B-07, B-13, limite de recherches D24) ;
            // le push s'y ajoute en phase 9. Rafraîchi chaque minute (mutualisé).
            // Agence active (Q69, lot L13) : seulement pour un utilisateur d'agence qui a le choix.
            ->userMenuItems([
                'switch-agency' => Action::make('switchAgency')
                    ->label(fn (): string => auth()->user() instanceof User ? SwitchAgency::menuLabel(auth()->user()) : '')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->url(fn (): string => SwitchAgency::getUrl())
                    ->visible(fn (): bool => SwitchAgency::canAccess()),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPollingInterval('60s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Routes hors pages Filament : la 2FA y est imposée explicitement (D9).
            ->authenticatedRoutes(function (): void {
                Route::get('fiscal/rapport/imprimer', PrintFiscalConfigurationReport::class)
                    ->middleware(EnsurePrivilegedTwoFactor::class)
                    ->name('fiscal-report.print');
                // Fichiers privés (D21) : servis après la policy du document propriétaire.
                Route::get('fichiers/vehicules/documents/{document}', VehicleDocumentFileController::class)
                    ->whereNumber('document')
                    ->middleware(EnsurePrivilegedTwoFactor::class)
                    ->name('private-files.vehicle-document');
                Route::get('fichiers/etats-des-lieux/photos/{photo}', [InspectionFileController::class, 'photo'])
                    ->whereNumber('photo')
                    ->middleware(EnsurePrivilegedTwoFactor::class)
                    ->name('private-files.inspection-photo');
                Route::get('fichiers/etats-des-lieux/{inspection}/signature', [InspectionFileController::class, 'signature'])
                    ->whereNumber('inspection')
                    ->middleware(EnsurePrivilegedTwoFactor::class)
                    ->name('private-files.inspection-signature');
                Route::get('fichiers/dossiers-de-dommage/pieces/{document}', DamageFileDocumentController::class)
                    ->whereNumber('document')
                    ->middleware(EnsurePrivilegedTwoFactor::class)
                    ->name('private-files.damage-document');
            });
    }
}
