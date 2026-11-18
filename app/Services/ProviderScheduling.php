<?php

namespace App\Services;

use App\Models\ProviderRosterEntry;
use Carbon\CarbonInterface;

class ProviderScheduling
{
    public static function isAvailable(int $organisationId, int $providerId, CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        $available = ProviderRosterEntry::where('organisation_id', $organisationId)->where('provider_id', $providerId)
            ->where('kind', 'availability')->where('status', 'approved')->where('starts_at', '<=', $startsAt)->where('ends_at', '>=', $endsAt)->exists();
        $absence = ProviderRosterEntry::where('organisation_id', $organisationId)->where('provider_id', $providerId)
            ->whereIn('kind', ['leave', 'travel'])->where('status', 'approved')->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists();

        return $available && ! $absence;
    }
}
