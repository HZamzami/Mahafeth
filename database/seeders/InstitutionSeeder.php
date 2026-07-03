<?php

namespace Database\Seeders;

use App\Enums\InstitutionType;
use App\Models\Institution;
use Illuminate\Database\Seeder;

class InstitutionSeeder extends Seeder
{
    /**
     * Seed the institutions available for connection via Open Banking.
     */
    public function run(): void
    {
        $institutions = [
            ['slug' => 'derayah', 'name' => 'Derayah Financial', 'name_ar' => 'دراية المالية', 'type' => InstitutionType::Brokerage, 'color' => '#6d28d9'],
            ['slug' => 'alrajhi-capital', 'name' => 'Al Rajhi Capital', 'name_ar' => 'الراجحي المالية', 'type' => InstitutionType::Brokerage, 'color' => '#1d4ed8'],
            ['slug' => 'rain', 'name' => 'Rain', 'name_ar' => 'رين', 'type' => InstitutionType::CryptoExchange, 'color' => '#0891b2'],
        ];

        foreach ($institutions as $institution) {
            Institution::updateOrCreate(['slug' => $institution['slug']], $institution);
        }
    }
}
