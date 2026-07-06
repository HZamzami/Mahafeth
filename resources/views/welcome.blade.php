<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="flex min-h-screen items-center justify-center bg-white antialiased dark:bg-zinc-900">
    <main class="flex w-full max-w-4xl flex-col items-center gap-10 p-8 text-center">
        <div class="flex flex-col items-center gap-6">
            <x-app-logo-icon class="size-20" />

            <div>
                <h1 class="text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                    {{ __('From scattered portfolios to one investment vision') }}
                </h1>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-zinc-500 dark:text-zinc-400">
                    {{ __('Mahafeth securely connects your investment accounts through Open Banking, unifies them into a single portfolio, and uses institutional-grade analytics with AI to uncover hidden risks and tell you exactly what to do about them.') }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                @auth
                    <flux:button href="{{ route('dashboard') }}" variant="primary">{{ __('Open Dashboard') }}</flux:button>
                @else
                    <flux:button href="{{ route('register') }}" variant="primary">{{ __('Create account') }}</flux:button>
                    <flux:button href="{{ route('login') }}" variant="ghost">{{ __('Log in') }}</flux:button>
                @endauth
            </div>
        </div>

        <div class="grid w-full gap-4 text-start sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:icon.building-library class="mb-3 size-6 text-blue-600 dark:text-blue-400" />
                <flux:heading size="sm">{{ __('One unified portfolio') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Alinma, local brokerages, and crypto, aggregated securely via Saudi Open Banking.') }}</flux:text>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:icon.heart class="mb-3 size-6 text-emerald-600 dark:text-emerald-400" />
                <flux:heading size="sm">{{ __('Portfolio Health Score') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('One number, built from diversification, risk, performance, and your own goals.') }}</flux:text>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:icon.eye class="mb-3 size-6 text-amber-600 dark:text-amber-400" />
                <flux:heading size="sm">{{ __('Hidden risks, revealed') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Concentration, correlation, and stress behavior that single apps never show you.') }}</flux:text>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:icon.sparkles class="mb-3 size-6 text-purple-600 dark:text-purple-400" />
                <flux:heading size="sm">{{ __('AI that speaks your language') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Plain-language reports and a personalized action plan — in Arabic or English.') }}</flux:text>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:icon.check-badge class="mb-3 size-6 text-emerald-600 dark:text-emerald-400" />
                <flux:heading size="sm">{{ __('Shariah screening built in') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Every holding is screened for compliance, and your score reflects your values.') }}</flux:text>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:icon.rocket-launch class="mb-3 size-6 text-cyan-600 dark:text-cyan-400" />
                <flux:heading size="sm">{{ __('Ready for what is next') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Built on the SAMA Open Banking framework today, ready for investment-account APIs the day they launch.') }}</flux:text>
            </div>
        </div>

        <a href="{{ route('locale.update', app()->getLocale() === 'ar' ? 'en' : 'ar') }}"
            class="text-sm text-zinc-400 underline-offset-4 hover:underline dark:text-zinc-500">
            {{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}
        </a>
    </main>
</body>

</html>
