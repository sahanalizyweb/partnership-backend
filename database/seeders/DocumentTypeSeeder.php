<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Aadhaar Card', 'category' => 'identity', 'requires_expiry' => false, 'is_mandatory' => true],
            ['name' => 'PAN Card', 'category' => 'identity', 'requires_expiry' => false, 'is_mandatory' => true],
            ['name' => 'Business Registration Certificate', 'category' => 'business', 'requires_expiry' => false, 'is_mandatory' => false],
            ['name' => 'Trade License', 'category' => 'license', 'requires_expiry' => true, 'is_mandatory' => false],
            ['name' => 'Driving License', 'category' => 'license', 'requires_expiry' => true, 'is_mandatory' => false],
            ['name' => 'GST Certificate', 'category' => 'tax', 'requires_expiry' => false, 'is_mandatory' => false],
            ['name' => 'Cancelled Cheque', 'category' => 'bank', 'requires_expiry' => false, 'is_mandatory' => true],
            ['name' => 'Bank Passbook', 'category' => 'bank', 'requires_expiry' => false, 'is_mandatory' => false],
            ['name' => 'Signed Agreement', 'category' => 'agreement', 'requires_expiry' => true, 'is_mandatory' => true],
        ] as $type) {
            DocumentType::firstOrCreate(['name' => $type['name']], $type + ['status' => 'active']);
        }
    }
}
