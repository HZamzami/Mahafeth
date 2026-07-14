<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : view('welcome');
})->name('home');

Route::get('locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, SetLocale::SUPPORTED_LOCALES, true), 404);

    session(['locale' => $locale]);
    request()->user()?->update(['locale' => $locale]);

    return back();
})->middleware('throttle:30,1')->name('locale.update');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Volt::route('advisor', 'advisor.index')
    ->middleware(['auth'])
    ->name('advisor');

Volt::route('connections', 'connections.index')
    ->middleware(['auth'])
    ->name('connections');

Volt::route('connections/consent/{institution:slug}', 'connections.consent')
    ->middleware(['auth'])
    ->name('connections.consent');

Volt::route('analytics', 'analytics.index')
    ->middleware(['auth'])
    ->name('analytics');

Volt::route('activity', 'activity.index')
    ->middleware(['auth'])
    ->name('activity');

Volt::route('holdings', 'holdings.index')
    ->middleware(['auth'])
    ->name('holdings.index');

Volt::route('holdings/{asset:symbol}', 'holdings.detail')
    ->middleware(['auth'])
    ->name('holdings.detail');

Volt::route('explore', 'explore.index')
    ->middleware(['auth'])
    ->name('explore.index');

Volt::route('explore/{symbol}', 'explore.instrument')
    ->middleware(['auth'])
    ->where('symbol', '[A-Za-z0-9.\-]+')
    ->name('explore.instrument');

Volt::route('investor-profile', 'investor-profile.index')
    ->middleware(['auth'])
    ->name('investor-profile');

Volt::route('plan', 'investment-plan.index')
    ->middleware(['auth'])
    ->name('plan');

Volt::route('whats-new', 'changelog.index')
    ->middleware(['auth'])
    ->name('whats-new');

Volt::route('report', 'report.index')
    ->middleware(['auth'])
    ->name('report');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    Volt::route('settings/passkeys', 'settings.passkeys')->name('settings.passkeys');
});

Route::middleware('throttle:10,1')->group(function () {
    Route::passkeys();
});

require __DIR__.'/auth.php';
