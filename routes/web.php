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
})->name('locale.update');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
