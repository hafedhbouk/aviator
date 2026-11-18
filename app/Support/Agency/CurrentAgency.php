<?php

declare(strict_types=1);

namespace App\Support\Agency;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Agence active d'un utilisateur d'agence (Q69) : celle choisie pendant la session
 * si elle est autorisée, sinon son agence par défaut, sinon sa première agence
 * autorisée. Les nouveaux documents d'un utilisateur d'agence portent cette agence.
 *
 * Le super admin et l'admin n'ont pas d'agence active : ils choisissent l'agence
 * de chaque document.
 */
final class CurrentAgency
{
    public const SESSION_KEY = 'aviator.current_agency_id';

    public function __construct(private readonly Session $session) {}

    public function id(User $user): ?int
    {
        if ($user->canActOnAllAgencies()) {
            return null;
        }

        $authorized = $user->authorizedAgencyIds();
        $chosen = $this->session->get(self::SESSION_KEY);

        if (is_numeric($chosen) && in_array((int) $chosen, $authorized, true)) {
            return (int) $chosen;
        }

        if ($user->agency_id !== null && in_array($user->agency_id, $authorized, true)) {
            return $user->agency_id;
        }

        return $authorized[0] ?? null;
    }

    /**
     * Change l'agence active (remplacement d'un collègue absent).
     *
     * @throws AgencyAccessException
     */
    public function switchTo(User $user, int $agencyId): void
    {
        if ($user->canActOnAllAgencies() || ! $user->canActOnAgency($agencyId)) {
            throw AgencyAccessException::notAuthorized($agencyId);
        }

        $this->session->put(self::SESSION_KEY, $agencyId);

        activity('audit')
            ->causedBy($user)
            ->event('current_agency_switched')
            ->withProperties(['agency_id' => $agencyId])
            ->log('Agence active modifiée');
    }
}
