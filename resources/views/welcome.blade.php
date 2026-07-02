<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="flex min-h-screen items-center justify-center bg-white antialiased dark:bg-zinc-900">
    <main class="flex flex-col items-center gap-6 p-8 text-center">
        <x-app-logo-icon class="size-20" />

        <div>
            <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">Mahafeth</h1>
            <p class="mt-2 max-w-sm text-zinc-500 dark:text-zinc-400">
                {{ __('All your portfolios, one clear picture.') }}
            </p>
        </div>

        <div class="mt-2 flex items-center gap-3">
            @auth
                <flux:button href="{{ route('dashboard') }}" variant="primary">{{ __('Open Dashboard') }}</flux:button>
            @else
                <flux:button href="{{ route('login') }}" variant="primary">{{ __('Log in') }}</flux:button>
                <flux:button href="{{ route('register') }}" variant="ghost">{{ __('Register') }}</flux:button>
            @endauth
        </div>
    </main>
</body>

</html>
