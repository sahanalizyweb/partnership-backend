<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category', 'requires_expiry', 'is_mandatory', 'status'];

    protected function casts(): array
    {
        return [
            'requires_expiry' => 'boolean',
            'is_mandatory' => 'boolean',
        ];
    }
}
