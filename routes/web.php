<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, SetLocale::SUPPORTED_LOCALES, true), 404);

    session(['locale' => $locale]);
    request()->user()?->update(['locale' => $locale]);

    return back();
})->middleware('throttle:30,1')->name('locale.update');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Volt::route('advisor', 'advisor.index')
    ->middleware(['auth', 'verified'])
    ->name('advisor');

Volt::route('connections', 'connections.index')
    ->middleware(['auth', 'verified'])
    ->name('connections');

Volt::route('connections/consent/{institution:slug}', 'connections.consent')
    ->middleware(['auth', 'verified'])
    ->name('connections.consent');

Volt::route('analytics', 'analytics.index')
    ->middleware(['auth', 'verified'])
    ->name('analytics');

Volt::route('activity', 'activity.index')
    ->middleware(['auth', 'verified'])
    ->name('activity');

Volt::route('holdings', 'holdings.index')
    ->middleware(['auth', 'verified'])
    ->name('holdings.index');

Volt::route('holdings/{asset:symbol}', 'holdings.detail')
    ->middleware(['auth', 'verified'])
    ->name('holdings.detail');

Volt::route('explore/{symbol}', 'explore.instrument')
    ->middleware(['auth', 'verified'])
    ->where('symbol', '[A-Za-z0-9.\-]+')
    ->name('explore.instrument');

Volt::route('investor-profile', 'investor-profile.index')
    ->middleware(['auth', 'verified'])
    ->name('investor-profile');

Volt::route('report', 'report.index')
    ->middleware(['auth', 'verified'])
    ->name('report');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
