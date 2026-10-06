<?php

namespace App\Services;

use App\Models\PartnerDocument;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds partner documents expiring within the given window, used to drive the
 * Dashboard's "alerts and notifications" panel and the scheduled expiry-check command.
 */
class DocumentExpiryService
{
    public function expiringWithin(int $days = 30): Collection
    {
        return PartnerDocument::query()
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->with(['partner:id,name,business_id', 'documentType:id,name'])
            ->orderBy('expiry_date')
            ->get();
    }

    public function expired(): Collection
    {
        return PartnerDocument::query()
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->toDateString())
            ->with(['partner:id,name,business_id', 'documentType:id,name'])
            ->orderBy('expiry_date')
            ->get();
    }
}
