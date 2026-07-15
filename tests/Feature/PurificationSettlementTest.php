<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\ObligationKind;
use App\Enums\TransactionType;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\ObligationSettlement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Analytics\ShariahComplianceAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PurificationSettlementTest extends TestCase
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

    public function test_outstanding_equals_trailing_purification_when_never_settled(): void
    {
        $user = $this->syncedUser();

        $shariah = app(PortfolioAnalyzer::class)->analyze($user)->metrics['shariah'];

        $this->assertGreaterThan(0, $shariah['purification_amount']);
        $this->assertSame($shariah['purification_amount'], $shariah['purification_outstanding']);
        $this->assertNull($shariah['last_purified_through']);
    }

    public function test_settling_today_zeroes_the_outstanding_amount(): void
    {
        $user = $this->syncedUser();

        ObligationSettlement::factory()->create([
            'user_id' => $user->id,
            'settled_through' => today()->toDateString(),
        ]);

        $shariah = app(PortfolioAnalyzer::class)->analyze($user)->metrics['shariah'];

        // The trailing-year context stays visible, but nothing is owed.
        $this->assertGreaterThan(0, $shariah['purification_amount']);
        $this->assertEquals(0, $shariah['purification_outstanding']);
        $this->assertSame(today()->toDateString(), $shariah['last_purified_through']);
    }

    public function test_new_impure_dividends_after_settlement_reaccrue(): void
    {
        $user = $this->syncedUser();

        ObligationSettlement::factory()->create([
            'user_id' => $user->id,
            'settled_through' => today()->toDateString(),
        ]);

        // A fresh dividend from the non-compliant holding lands after the
        // settlement date, so it must be owed again.
        $jpm = Transaction::whereHas('asset', fn ($query) => $query->where('symbol', 'JPM'))
            ->where('type', TransactionType::Dividend)
            ->whereHas('account.connection', fn ($query) => $query->whereBelongsTo($user))
            ->firstOrFail();

        Transaction::create([
            'account_id' => $jpm->account_id,
            'asset_id' => $jpm->asset_id,
            'type' => TransactionType::Dividend,
            'amount' => 100.0,
            'executed_at' => now()->addDay(),
        ]);

        $shariah = app(PortfolioAnalyzer::class)->analyze($user)->metrics['shariah'];

        $this->assertGreaterThan(0, $shariah['purification_outstanding']);
        $this->assertLessThan($shariah['purification_amount'], $shariah['purification_outstanding']);
    }

    public function test_a_published_rate_on_a_compliant_asset_accrues_partial_purification(): void
    {
        $result = app(ShariahComplianceAnalyzer::class)->analyze(
            ['2222.SR' => 1.0],
            ['2222.SR' => ['name' => 'Saudi Aramco', 'shariah_status' => 'compliant', 'purification_rate' => 0.05]],
            ['2222.SR' => 1000.0],
        );

        $this->assertSame(0.0, $result['purification_amount']);
        $this->assertSame(50.0, $result['purification_outstanding']);
        $this->assertSame('2222.SR', $result['mixed_positions'][0]['symbol']);
    }

    public function test_a_published_rate_overrides_the_all_or_nothing_default(): void
    {
        $result = app(ShariahComplianceAnalyzer::class)->analyze(
            ['JPM' => 1.0],
            ['JPM' => ['name' => 'JPMorgan', 'shariah_status' => 'non_compliant', 'purification_rate' => 0.30]],
            ['JPM' => 1000.0],
        );

        $this->assertSame(300.0, $result['purification_amount']);
        $this->assertSame(300.0, $result['non_compliant_positions'][0]['purification']);
    }

    public function test_only_dividends_after_the_settlement_count_toward_outstanding(): void
    {
        $result = app(ShariahComplianceAnalyzer::class)->analyze(
            ['JPM' => 1.0],
            ['JPM' => ['name' => 'JPMorgan', 'shariah_status' => 'non_compliant']],
            ['JPM' => 1000.0],
            ['JPM' => 250.0],
        );

        $this->assertSame(1000.0, $result['purification_amount']);
        $this->assertSame(250.0, $result['purification_outstanding']);
    }

    public function test_the_card_records_a_settlement_and_shows_the_settled_state(): void
    {
        $user = $this->syncedUser();
        app(PortfolioAnalyzer::class)->analyze($user);

        $this->actingAs($user);

        $outstanding = $user->latestSnapshot()->metrics['shariah']['purification_outstanding'];
        $this->assertGreaterThan(0, $outstanding);

        Volt::test('dashboard.shariah-compliance')
            ->assertSee(__('Mark as purified'))
            ->set('purifiedAmount', (string) $outstanding)
            ->call('markPurified')
            ->assertSee(__('Settled'));

        $settlement = $user->obligationSettlements()->first();
        $this->assertSame(ObligationKind::Purification, $settlement->kind);
        $this->assertEqualsWithDelta($outstanding, $settlement->amount, 0.01);
        $this->assertEquals(0, $user->latestSnapshot()->metrics['shariah']['purification_outstanding']);
    }

    public function test_the_card_rejects_an_empty_amount(): void
    {
        $user = $this->syncedUser();
        app(PortfolioAnalyzer::class)->analyze($user);

        $this->actingAs($user);

        Volt::test('dashboard.shariah-compliance')
            ->set('purifiedAmount', null)
            ->call('markPurified')
            ->assertHasErrors('purifiedAmount');

        $this->assertSame(0, $user->obligationSettlements()->count());
    }
}
