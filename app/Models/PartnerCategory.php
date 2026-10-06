<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerCategory extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = ['business_id', 'partner_type_id', 'parent_id', 'name', 'description', 'rules', 'status'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function partnerType(): BelongsTo
    {
        return $this->belongsTo(PartnerType::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(PartnerCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(PartnerCategory::class, 'parent_id');
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }
}
