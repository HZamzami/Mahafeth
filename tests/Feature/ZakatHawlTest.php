<?php

namespace Tests\Feature;

use App\Enums\ObligationKind;
use App\Models\ObligationSettlement;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Notifications\ZakatReminderNotification;
use App\Support\HijriDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ZakatHawlTest extends TestCase
{
    use RefreshDatabase;

    public function test_hijri_conversion_round_trips(): void
    {
        $hijri = HijriDate::toHijri(today());
        $back = HijriDate::gregorian($hijri['year'], $hijri['month'], $hijri['day']);

        $this->assertTrue($back->isSameDay(today()));
    }

    public function test_next_gregorian_is_never_in_the_past_and_matches_the_hijri_day(): void
    {
        $today = HijriDate::toHijri(today());

        // Yesterday's Hijri anniversary must resolve to next year.
        $yesterday = HijriDate::gregorian($today['year'], $today['month'], $today['day'])->subDay();
        $anniversary = HijriDate::toHijri($yesterday);

        $next = HijriDate::nextGregorian($anniversary['month'], $anniversary['day']);

        $this->assertTrue($next->gte(today()));

        $converted = HijriDate::toHijri($next);
        $this->assertSame($anniversary['month'], $converted['month']);
        $this->assertSame($anniversary['day'], $converted['day']);
    }

    public function test_a_day_thirty_hawl_clamps_in_short_months(): void
    {
        // Every Hijri year has 29-day months; day 30 must not blow up.
        foreach (range(1, 12) as $month) {
            $date = HijriDate::nextGregorian($month, 30);
            $this->assertTrue($date->gte(today()));
        }
    }

    public function test_the_reminder_goes_out_once_inside_the_window(): void
    {
        Notification::fake();

        $hawl = HijriDate::toHijri(today()->addDays(3));
        $user = User::factory()->create([
            'zakat_hawl_month' => $hawl['month'],
            'zakat_hawl_day' => $hawl['day'],
        ]);

        PortfolioSnapshot::factory()->create([
            'user_id' => $user->id,
            'metrics' => ['zakat' => ['zakat_due' => 1234.56, 'zakatable_value' => 50000.0, 'below_nisab' => false]],
        ]);

        $this->artisan('mahafeth:zakat-reminders')->assertSuccessful();

        Notification::assertSentTo($user, ZakatReminderNotification::class, function (ZakatReminderNotification $notification): bool {
            return $notification->zakatDue === 1234.56 && ! $notification->belowNisab;
        });

        // The second run within the same occurrence stays silent.
        Notification::fake();
        $this->artisan('mahafeth:zakat-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_users_outside_the_window_or_opted_out_are_skipped(): void
    {
        Notification::fake();

        $farHawl = HijriDate::toHijri(today()->addDays(60));
        User::factory()->create([
            'zakat_hawl_month' => $farHawl['month'],
            'zakat_hawl_day' => $farHawl['day'],
        ]);

        $soonHawl = HijriDate::toHijri(today()->addDays(2));
        User::factory()->create([
            'zakat_hawl_month' => $soonHawl['month'],
            'zakat_hawl_day' => $soonHawl['day'],
            'notify_alerts' => false,
        ]);

        $this->artisan('mahafeth:zakat-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_demo_accounts_never_receive_reminders(): void
    {
        Notification::fake();

        $soonHawl = HijriDate::toHijri(today()->addDays(2));
        User::factory()->create([
            'email' => 'guest-abc@demo.mahafeth.test',
            'zakat_hawl_month' => $soonHawl['month'],
            'zakat_hawl_day' => $soonHawl['day'],
        ]);

        $this->artisan('mahafeth:zakat-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_settings_section_saves_and_clears_the_hawl_date(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.zakat')
            ->set('hawlDay', 15)
            ->set('hawlMonth', 9)
            ->call('save');

        $this->assertSame(9, $user->fresh()->zakat_hawl_month);
        $this->assertSame(15, $user->fresh()->zakat_hawl_day);

        Volt::test('settings.zakat')->call('clear');

        $this->assertNull($user->fresh()->zakat_hawl_month);
    }

    public function test_the_card_shows_the_countdown_and_marks_zakat_paid(): void
    {
        $hawl = HijriDate::toHijri(today()->addDays(10));
        $user = User::factory()->create([
            'zakat_hawl_month' => $hawl['month'],
            'zakat_hawl_day' => $hawl['day'],
        ]);

        PortfolioSnapshot::factory()->create([
            'user_id' => $user->id,
            'metrics' => [
                'shariah' => [
                    'compliant_weight' => 1.0, 'non_compliant_weight' => 0.0, 'unknown_weight' => 0.0,
                    'purification_amount' => 0.0, 'purification_outstanding' => 0.0,
                    'non_compliant_positions' => [], 'mixed_positions' => [],
                ],
                'zakat' => ['zakat_due' => 1000.0, 'zakatable_value' => 40000.0, 'below_nisab' => false],
            ],
        ]);

        $this->actingAs($user);

        Volt::test('dashboard.shariah-compliance')
            ->assertSee(__('Mark zakat paid'))
            ->set('zakatPaidAmount', '1000')
            ->call('markZakatPaid')
            ->assertSee(__('Zakat paid on :date for this hawl.', ['date' => today()->translatedFormat('j M Y')]));

        $settlement = ObligationSettlement::whereBelongsTo($user)->where('kind', ObligationKind::Zakat)->first();
        $this->assertNotNull($settlement);
        $this->assertEqualsWithDelta(1000.0, $settlement->amount, 0.01);
    }
}
