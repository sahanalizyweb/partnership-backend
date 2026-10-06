<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerType extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = ['business_id', 'name', 'code', 'description', 'status'];

    public function categories(): HasMany
    {
        return $this->hasMany(PartnerCategory::class);
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }
}
