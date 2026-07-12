<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-sand-50 dark:bg-charcoal-950">
    <flux:sidebar sticky stashable
        class="border-e border-zinc-200 bg-sand-100 pt-[env(safe-area-inset-top)] print:hidden dark:border-zinc-700 dark:bg-zinc-900">
        <a href="{{ route('dashboard') }}" class="me-5 mb-2 mt-3 flex items-center space-x-2" wire:navigate>
            <x-app-logo class="size-8"></x-app-logo>
        </a>

        <flux:navlist variant="outline">
            <flux:navlist.group :heading="__('Platform')" class="grid">
                <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')"
                    wire:navigate>{{ __('Dashboard') }}</flux:navlist.item>
                <flux:modal.trigger name="instrument-search">
                    <flux:navlist.item icon="magnifying-glass" as="button" class="w-full">
                        {{ __('Search Stocks') }}</flux:navlist.item>
                </flux:modal.trigger>
                <flux:navlist.item icon="briefcase" :href="route('holdings.index')"
                    :current="request()->routeIs('holdings.*')" wire:navigate>{{ __('Holdings') }}
                </flux:navlist.item>
                <flux:navlist.item icon="chat-bubble-left-right" :href="route('advisor')"
                    :current="request()->routeIs('advisor')" wire:navigate>{{ __('AI Advisor') }}
                </flux:navlist.item>
                <flux:navlist.item icon="building-library" :href="route('connections')"
                    :current="request()->routeIs('connections')" wire:navigate>{{ __('Connections') }}
                </flux:navlist.item>
                <flux:navlist.item icon="clipboard-document-check" :href="route('investor-profile')"
                    :current="request()->routeIs('investor-profile')" wire:navigate>{{ __('Investor Profile') }}
                </flux:navlist.item>
                <flux:navlist.item icon="chart-bar" :href="route('analytics')"
                    :current="request()->routeIs('analytics')" wire:navigate>{{ __('Analytics') }}
                </flux:navlist.item>
                <flux:navlist.item icon="document-text" :href="route('report')"
                    :current="request()->routeIs('report')" wire:navigate>{{ __('Report') }}
                </flux:navlist.item>
            </flux:navlist.group>
        </flux:navlist>

        <flux:spacer />

        {{-- <flux:navlist variant="outline">
            <flux:navlist.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit"
                target="_blank">
                Repository
            </flux:navlist.item>

            <flux:navlist.item icon="book-open-text" href="https://laravel.com/docs/starter-kits" target="_blank">
                Documentation
            </flux:navlist.item>
        </flux:navlist> --}}

        <!-- Desktop User Menu -->
        <flux:dropdown position="bottom" align="start">
            <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()"
                icon-trailing="chevrons-up-down" />

            <flux:menu class="w-[220px]">
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                <span
                                    class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                    {{ auth()->user()->initials() }}
                                </span>
                            </span>

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    <flux:menu.item as="button" type="button" x-data icon="moon"
                        @click.prevent.stop="$flux.dark = ! $flux.dark">
                        <span x-text="$flux.dark ? '{{ __('Light Mode') }}' : '{{ __('Dark Mode') }}'"></span>
                    </flux:menu.item>
                    <flux:menu.item :href="route('locale.update', app()->getLocale() === 'ar' ? 'en' : 'ar')" icon="language">
                        {{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('Log Out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>

    <!-- Mobile User Menu -->
    <flux:header class="pt-[env(safe-area-inset-top)] lg:hidden print:hidden">
        {{-- Equal flex-1 side zones keep the page title truly centered. --}}
        <div class="flex flex-1 items-center">
            <a href="{{ route('dashboard') }}" class="flex items-center" wire:navigate>
                <x-app-logo class="size-8" />
            </a>
        </div>

        @php($pageTitle = match (true) {
            request()->routeIs('holdings.*') => __('Holdings'),
            request()->routeIs('analytics') => __('Analytics'),
            request()->routeIs('advisor') => __('AI Advisor'),
            request()->routeIs('connections*') => __('Connections'),
            request()->routeIs('investor-profile') => __('Investor Profile'),
            request()->routeIs('report') => __('Report'),
            request()->routeIs('settings.*') => __('Settings'),
            default => null,
        })
        @if ($pageTitle !== null)
            <flux:heading class="shrink-0 truncate" size="lg">{{ $pageTitle }}</flux:heading>
        @endif

        <div class="flex flex-1 items-center justify-end">
        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                <span
                                    class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                    {{ auth()->user()->initials() }}
                                </span>
                            </span>

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    <flux:menu.item as="button" type="button" x-data icon="moon"
                        @click.prevent.stop="$flux.dark = ! $flux.dark">
                        <span x-text="$flux.dark ? '{{ __('Light Mode') }}' : '{{ __('Dark Mode') }}'"></span>
                    </flux:menu.item>
                    <flux:menu.item :href="route('locale.update', app()->getLocale() === 'ar' ? 'en' : 'ar')" icon="language">
                        {{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('Log Out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
        </div>
    </flux:header>

    {{ $slot }}

    <livewire:explore.search />

    <x-bottom-nav />

    <x-snackbar />

    @include('partials.pwa-install-banner')

    @fluxScripts
</body>

</html>
