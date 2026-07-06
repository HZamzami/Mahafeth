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
            ['slug' => 'alinma-bank', 'name' => 'Alinma Bank', 'name_ar' => 'مصرف الإنماء', 'type' => InstitutionType::Bank, 'provider' => 'alinma_ais', 'color' => '#7c5e3c'],
            ['slug' => 'alinma-capital', 'name' => 'Alinma Capital', 'name_ar' => 'الإنماء المالية', 'type' => InstitutionType::Brokerage, 'provider' => 'import', 'color' => '#a16207'],
            ['slug' => 'derayah', 'name' => 'Derayah Financial', 'name_ar' => 'دراية المالية', 'type' => InstitutionType::Brokerage, 'provider' => 'fake', 'color' => '#6d28d9'],
            ['slug' => 'alrajhi-capital', 'name' => 'Al Rajhi Capital', 'name_ar' => 'الراجحي المالية', 'type' => InstitutionType::Brokerage, 'provider' => 'fake', 'color' => '#1d4ed8'],
            ['slug' => 'rain', 'name' => 'Rain', 'name_ar' => 'رين', 'type' => InstitutionType::CryptoExchange, 'provider' => 'fake', 'color' => '#0891b2'],
        ];

        foreach ($institutions as $institution) {
            Institution::updateOrCreate(['slug' => $institution['slug']], $institution);
        }
    }
}
