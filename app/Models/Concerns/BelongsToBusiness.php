<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared by every business-scoped model. A user with a null business_id (e.g. Super
 * Administrator) sees records across all businesses; every other user is restricted to
 * their own business_id.
 */
trait BelongsToBusiness
{
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->business_id
            ? $query->where('business_id', $user->business_id)
            : $query;
    }
}
