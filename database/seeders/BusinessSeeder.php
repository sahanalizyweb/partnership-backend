<?php

namespace Database\Seeders;

use App\Models\Business;
use Illuminate\Database\Seeder;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Lizymart', 'code' => 'lizymart'],
            ['name' => 'Lizyweb', 'code' => 'lizyweb'],
            ['name' => 'Lizygo', 'code' => 'lizygo'],
        ] as $business) {
            Business::firstOrCreate(['code' => $business['code']], $business + ['status' => 'active']);
        }
    }
}
