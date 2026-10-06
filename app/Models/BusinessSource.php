<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Configurable earning-source lookup: LizyMart, LizyNet, Service, Referral, Affiliate, Sales, Reseller, Other. */
class BusinessSource extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = ['business_id', 'name', 'code', 'description', 'status'];
}
