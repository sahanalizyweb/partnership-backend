<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            BusinessSeeder::class,
            PartnerTypeSeeder::class,
            BusinessSourceSeeder::class,
            DocumentTypeSeeder::class,
            LocationSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
