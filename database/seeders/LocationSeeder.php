<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $india = Location::firstOrCreate(['type' => 'country', 'name' => 'India', 'parent_id' => null]);

        $states = [
            'Karnataka' => [
                'Bengaluru Urban' => [
                    'Bengaluru' => ['Koramangala', 'Whitefield', 'Indiranagar'],
                ],
            ],
            'Maharashtra' => [
                'Mumbai Suburban' => [
                    'Mumbai' => ['Andheri', 'Bandra', 'Powai'],
                ],
            ],
            'Delhi' => [
                'New Delhi' => [
                    'New Delhi' => ['Connaught Place', 'Saket', 'Dwarka'],
                ],
            ],
        ];

        foreach ($states as $stateName => $districts) {
            $state = Location::firstOrCreate(['type' => 'state', 'name' => $stateName, 'parent_id' => $india->id]);

            foreach ($districts as $districtName => $cities) {
                $district = Location::firstOrCreate(['type' => 'district', 'name' => $districtName, 'parent_id' => $state->id]);

                foreach ($cities as $cityName => $areas) {
                    $city = Location::firstOrCreate(['type' => 'city', 'name' => $cityName, 'parent_id' => $district->id]);

                    foreach ($areas as $areaName) {
                        Location::firstOrCreate(['type' => 'area', 'name' => $areaName, 'parent_id' => $city->id]);
                    }
                }
            }
        }
    }
}
