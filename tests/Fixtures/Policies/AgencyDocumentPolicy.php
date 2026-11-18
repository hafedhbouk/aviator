<?php

namespace Tests\Fixtures\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksAgency;
use Tests\Fixtures\Models\AgencyDocument;

/**
 * Policy de test, construite comme le seront les policies des documents métier.
 */
class AgencyDocumentPolicy
{
    use ChecksAgency;

    public function view(User $user, AgencyDocument $document): bool
    {
        return $this->withinAgency($user, $document);
    }

    public function update(User $user, AgencyDocument $document): bool
    {
        return $this->withinAgency($user, $document);
    }
}
