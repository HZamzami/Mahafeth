<?php

namespace Database\Seeders;

use App\Actions\SyncConnection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoPortfolioSeeder extends Seeder
{
    /**
     * Seed a demo investor connected to every institution, with a deliberately
     * imperfect portfolio (tech-heavy, oversized Apple position) so all
     * analytics have findings to surface.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@mahafeth.test'],
            [
                'name' => 'Demo Investor',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $syncConnection = app(SyncConnection::class);

        Institution::all()->each(function (Institution $institution) use ($user, $syncConnection): void {
            $connection = $user->connections()->firstOrCreate(['institution_id' => $institution->id]);

            $syncConnection->handle($connection);
        });
    }
}
