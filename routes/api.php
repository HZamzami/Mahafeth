<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AdvisorController;
use App\Http\Controllers\Api\V1\AlertRuleController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExploreController;
use App\Http\Controllers\Api\V1\GoalController;
use App\Http\Controllers\Api\V1\HoldingController;
use App\Http\Controllers\Api\V1\InstrumentController;
use App\Http\Controllers\Api\V1\InvestmentPlanController;
use App\Http\Controllers\Api\V1\InvestorProfileController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ZakatController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,10')
        ->name('auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');

        Route::get('dashboard', [DashboardController::class, 'show'])
            ->name('dashboard.show');

        Route::post('dashboard/refresh', [DashboardController::class, 'refresh'])
            ->middleware('throttle:6,1')
            ->name('dashboard.refresh');

        Route::get('holdings', [HoldingController::class, 'index'])
            ->name('holdings.index');

        Route::get('holdings/income-calendar', [HoldingController::class, 'incomeCalendar'])
            ->name('holdings.income-calendar');

        Route::get('holdings/{asset:symbol}', [HoldingController::class, 'show'])
            ->name('holdings.show');

        Route::get('instruments/{symbol}', [InstrumentController::class, 'show'])
            ->where('symbol', '[A-Za-z0-9.\-]+')
            ->middleware('throttle:30,1')
            ->name('instruments.show');

        Route::post('instruments/{symbol}/what-if', [InstrumentController::class, 'whatIf'])
            ->where('symbol', '[A-Za-z0-9.\-]+')
            ->name('instruments.what-if');

        Route::get('explore/search', [ExploreController::class, 'search'])
            ->middleware('throttle:30,1')
            ->name('explore.search');

        Route::get('analytics', [AnalyticsController::class, 'index'])
            ->name('analytics.index');

        Route::get('analytics/rebalance-plan', [AnalyticsController::class, 'rebalancePlan'])
            ->name('analytics.rebalance-plan');

        Route::get('analytics/stress-test', [AnalyticsController::class, 'stressTest'])
            ->name('analytics.stress-test');

        Route::get('activity', [ActivityController::class, 'index'])
            ->name('activity.index');

        Route::get('report', [ReportController::class, 'show'])
            ->name('report.show');

        Route::get('connections', [ConnectionController::class, 'index'])
            ->name('connections.index');

        Route::post('connections/manual', [ConnectionController::class, 'storeManual'])
            ->name('connections.manual');

        Route::get('connections/instruments/search', [AccountController::class, 'instrumentSearch'])
            ->middleware('throttle:30,1')
            ->name('connections.instruments.search');

        Route::get('connections/accounts/{account}', [AccountController::class, 'show'])
            ->name('connections.accounts.show');

        Route::post('connections/accounts/{account}/transactions', [AccountController::class, 'storeTransaction'])
            ->name('connections.accounts.transactions.store');

        Route::delete('connections/accounts/{account}/transactions/{transaction}', [AccountController::class, 'destroyTransaction'])
            ->name('connections.accounts.transactions.destroy');

        Route::post('connections/accounts/{account}/import', [AccountController::class, 'import'])
            ->middleware('throttle:10,1')
            ->name('connections.accounts.import');

        Route::get('connections/{institution:slug}/consent', [ConnectionController::class, 'consent'])
            ->name('connections.consent.show');

        Route::post('connections/{institution:slug}/consent', [ConnectionController::class, 'approveConsent'])
            ->name('connections.consent.approve');

        Route::post('connections/{connection}/sync', [ConnectionController::class, 'sync'])
            ->name('connections.sync');

        Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])
            ->name('connections.destroy');

        Route::get('investor-profile', [InvestorProfileController::class, 'show'])
            ->name('investor-profile.show');

        Route::put('investor-profile', [InvestorProfileController::class, 'update'])
            ->name('investor-profile.update');

        Route::get('goals', [GoalController::class, 'index'])
            ->name('goals.index');

        Route::post('goals', [GoalController::class, 'store'])
            ->name('goals.store');

        Route::put('goals/{goal}', [GoalController::class, 'update'])
            ->name('goals.update');

        Route::delete('goals/{goal}', [GoalController::class, 'destroy'])
            ->name('goals.destroy');

        Route::get('investment-plan', [InvestmentPlanController::class, 'show'])
            ->name('investment-plan.show');

        Route::post('investment-plan', [InvestmentPlanController::class, 'store'])
            ->name('investment-plan.store');

        Route::get('settings/profile', [ProfileController::class, 'show'])
            ->name('settings.profile.show');

        Route::put('settings/profile', [ProfileController::class, 'update'])
            ->name('settings.profile.update');

        Route::delete('settings/account', [ProfileController::class, 'destroy'])
            ->name('settings.account.destroy');

        Route::put('settings/password', [PasswordController::class, 'update'])
            ->name('settings.password.update');

        Route::get('settings/alert-rules', [AlertRuleController::class, 'index'])
            ->name('settings.alert-rules.index');

        Route::post('settings/alert-rules', [AlertRuleController::class, 'store'])
            ->name('settings.alert-rules.store');

        Route::put('settings/alert-rules/{rule}', [AlertRuleController::class, 'update'])
            ->name('settings.alert-rules.update');

        Route::patch('settings/alert-rules/{rule}/toggle', [AlertRuleController::class, 'toggle'])
            ->name('settings.alert-rules.toggle');

        Route::delete('settings/alert-rules/{rule}', [AlertRuleController::class, 'destroy'])
            ->name('settings.alert-rules.destroy');

        Route::get('settings/zakat', [ZakatController::class, 'show'])
            ->name('settings.zakat.show');

        Route::put('settings/zakat', [ZakatController::class, 'update'])
            ->name('settings.zakat.update');

        Route::delete('settings/zakat', [ZakatController::class, 'destroy'])
            ->name('settings.zakat.destroy');

        Route::get('settings/sessions', [SessionController::class, 'index'])
            ->name('settings.sessions.index');

        Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])
            ->name('settings.sessions.destroy-others');

        Route::delete('settings/sessions/{tokenId}', [SessionController::class, 'destroy'])
            ->whereNumber('tokenId')
            ->name('settings.sessions.destroy');

        Route::get('advisor/messages', [AdvisorController::class, 'index'])
            ->name('advisor.messages.index');

        Route::post('advisor/messages', [AdvisorController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('advisor.messages.store');

        Route::get('advisor/status', [AdvisorController::class, 'status'])
            ->name('advisor.status');
    });
});
