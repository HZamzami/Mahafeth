<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Holding;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\DividendProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DividendCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function syncedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        return $user;
    }

    public function test_the_calendar_buckets_actuals_and_projects_forward(): void
    {
        $user = $this->syncedUser();

        $calendar = app(DividendProjector::class)->calendar($user);

        $this->assertNotNull($calendar);
        $this->assertCount(24, $calendar['months']);
        $this->assertGreaterThan(0, $calendar['trailing_total']);
        // All dividend payers are still held, so last year repeats in full.
        $this->assertEqualsWithDelta($calendar['trailing_total'], $calendar['projected_total'], $calendar['trailing_total'] * 0.5);

        foreach ($calendar['months'] as $index => $month) {
            if ($index <= 11) {
                $this->assertNull($month['projected']);
            } else {
                $this->assertNull($month['actual']);
            }
        }
    }

    public function test_sold_positions_drop_out_of_the_projection(): void
    {
        $user = $this->syncedUser();

        Holding::whereHas('account.connection', fn ($query) => $query->whereBelongsTo($user))
            ->get()
            ->each(fn (Holding $holding) => $holding->update(['quantity' => 0]));

        $calendar = app(DividendProjector::class)->calendar($user);

        $this->assertGreaterThan(0, $calendar['trailing_total']);
        $this->assertSame(0.0, $calendar['projected_total']);
    }

    public function test_users_without_dividends_get_no_calendar(): void
    {
        $this->assertNull(app(DividendProjector::class)->calendar(User::factory()->create()));
    }

    public function test_the_holdings_page_shows_the_income_card(): void
    {
        $user = $this->syncedUser();
        $this->actingAs($user);

        Volt::test('holdings.income-calendar')
            ->assertSee(__('Dividend Income'))
            ->assertSee(__('Expected, next 12 months'));
    }

    public function test_the_card_hides_without_dividend_history(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('holdings.income-calendar')->assertDontSee(__('Dividend Income'));
    }
}
