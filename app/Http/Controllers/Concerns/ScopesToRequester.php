<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared scoping rules applied across module controllers:
 *  - Non-global staff users only see their own business's records.
 *  - Partner-portal users only ever see records belonging to their own partner.
 */
trait ScopesToRequester
{
    protected function scopeBusiness(Builder $query, Request $request): Builder
    {
        $user = $request->user();

        return $user->business_id ? $query->where('business_id', $user->business_id) : $query;
    }

    protected function scopePartner(Builder $query, Request $request, string $column = 'partner_id'): Builder
    {
        $user = $request->user();

        return $user->isPartnerUser() ? $query->where($column, $user->partner_id) : $query;
    }
}
