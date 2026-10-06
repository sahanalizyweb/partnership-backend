<?php

namespace Database\Seeders;

use App\Models\BusinessSource;
use Illuminate\Database\Seeder;

class BusinessSourceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'LizyMart', 'code' => 'lizymart'],
            ['name' => 'LizyNet', 'code' => 'lizynet'],
            ['name' => 'Lizyweb', 'code' => 'LIZYWEB'],
            ['name' => 'Service', 'code' => 'service'],
            ['name' => 'Referral', 'code' => 'referral'],
            ['name' => 'Affiliate', 'code' => 'affiliate'],
            ['name' => 'Sales', 'code' => 'sales'],
            ['name' => 'Reseller', 'code' => 'reseller'],
            ['name' => 'Other', 'code' => 'other'],
        ] as $source) {
            BusinessSource::firstOrCreate(
                ['code' => $source['code'], 'business_id' => null],
                $source + ['status' => 'active']
            );
        }
    }
}
