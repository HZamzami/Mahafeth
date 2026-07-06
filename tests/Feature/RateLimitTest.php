<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_locale_route_is_throttled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get('/locale/ar')->assertRedirect();
        }

        $this->get('/locale/ar')->assertStatus(429);
    }

    public function test_statement_imports_are_rate_limited_per_user(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->import()->create(['slug' => 'alinma-capital']);

        $this->actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('import-holdings:'.$user->id);
        }

        Volt::test('connections.index')
            ->set('statement', UploadedFile::fake()->createWithContent('holdings.csv', "symbol,quantity,avg_cost\n2222.SR,800,8.10"))
            ->call('import', $institution->id)
            ->assertHasErrors('statement');

        $this->assertSame(0, $user->connections()->count());
    }
}
