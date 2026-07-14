<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="dark overflow-x-clip">

<head>
    @include('partials.head')
</head>

<body class="overflow-x-clip bg-surface-50 antialiased dark:bg-charcoal-950">
    {{-- The top padding folds in the status-bar inset so the logo clears the notch in the installed PWA. --}}
    <main class="relative mx-auto flex w-full max-w-6xl flex-col gap-24 px-6 pb-16 pt-[calc(env(safe-area-inset-top)+2.5rem)] sm:gap-32">

        {{-- ============ Hero ============ --}}
        <section class="relative" x-data="pointerParallax">
            {{-- Gradient glow blobs behind everything --}}
            <div aria-hidden="true" class="pointer-events-none absolute -top-24 start-[-10%] size-96 rounded-full bg-teal-500/20 blur-3xl"></div>
            <div aria-hidden="true" class="pointer-events-none absolute top-40 end-[-15%] size-[28rem] rounded-full bg-purple-500/10 blur-3xl"></div>

            <div class="relative grid items-center gap-14 lg:grid-cols-2">
                <div class="flex flex-col items-start gap-6 max-lg:items-center max-lg:text-center">
                    <div class="flex items-center gap-3">
                        <x-app-logo-icon class="size-12" />
                        <span class="rounded-full border border-teal-500/30 bg-teal-500/10 px-3 py-1 text-xs font-medium text-teal-700 dark:text-teal-300">
                            {{ __('Built on the SAMA Open Banking framework') }}
                        </span>
                    </div>

                    <h1 class="text-balance text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white sm:text-5xl">
                        {{ __('From scattered portfolios to one investment vision') }}
                    </h1>

                    <p class="max-w-xl text-pretty text-lg text-zinc-500 dark:text-zinc-400">
                        {{ __('Mahafeth securely connects your investment accounts through Open Banking, unifies them into a single portfolio, and uses institutional-grade analytics with AI to uncover hidden risks and tell you exactly what to do about them.') }}
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button href="{{ route('register') }}" variant="primary">{{ __('Create account') }}</flux:button>
                        <flux:button href="{{ route('login') }}" variant="ghost">{{ __('Log in') }}</flux:button>
                        <form method="POST" action="{{ route('demo.start') }}">
                            @csrf
                            <flux:button type="submit" variant="subtle" icon="play">{{ __('Try the demo') }}</flux:button>
                        </form>
                    </div>

                    <p class="text-sm text-zinc-500">
                        {{ __('Consent-based access you can revoke anytime. Your data never leaves your control.') }}
                    </p>
                </div>

                {{-- Phone mockup with floating fragments --}}
                <div class="relative mx-auto w-full max-w-sm" aria-hidden="true">
                    <div class="welcome-phone relative mx-auto w-64 rounded-[2.5rem] border-4 border-zinc-700/80 bg-zinc-950 p-4 shadow-2xl sm:w-72">
                        <div class="mx-auto mb-4 h-1.5 w-16 rounded-full bg-zinc-700"></div>

                        <div class="space-y-3">
                            <div>
                                <p class="text-xs text-zinc-500">{{ __('Total Portfolio') }}</p>
                                <p class="text-2xl font-semibold text-white" dir="ltr">487,250&nbsp;&#8385;</p>
                                <p class="text-xs font-medium text-emerald-400" dir="ltr">+8.4%</p>
                            </div>

                            <div class="space-y-2 rounded-xl border border-zinc-800 bg-zinc-900 p-3">
                                <p class="text-xs text-zinc-400">{{ __('Asset Allocation') }}</p>
                                <div class="flex h-2 gap-0.5 overflow-hidden rounded-full">
                                    <div class="w-[45%] bg-blue-500"></div>
                                    <div class="w-[25%] bg-emerald-500"></div>
                                    <div class="w-[18%] bg-amber-500"></div>
                                    <div class="w-[12%] bg-purple-500"></div>
                                </div>
                            </div>

                            <div class="space-y-2 rounded-xl border border-zinc-800 bg-zinc-900 p-3">
                                <p class="text-xs text-zinc-400">{{ __('Performance') }}</p>
                                <svg viewBox="0 0 200 60" class="w-full">
                                    <path d="M0 48 C 30 44, 45 52, 70 40 S 120 30, 145 22 S 180 14, 200 8"
                                        fill="none" stroke="var(--color-teal-400)" stroke-width="2.5" stroke-linecap="round"
                                        class="draw-path" stroke-dasharray="230" stroke-dashoffset="230"
                                        data-draw-offset="0" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    {{-- Floating fragment: health-score ring --}}
                    <div class="welcome-parallax absolute -top-6 end-0 sm:-end-6">
                        <div class="welcome-float card flex items-center gap-3 p-4 shadow-2xl dark:!border-zinc-700/60">
                            <svg viewBox="0 0 100 100" class="size-14 -rotate-90">
                                <circle cx="50" cy="50" r="40" fill="none" stroke-width="9" class="stroke-zinc-200 dark:stroke-zinc-800" />
                                <circle cx="50" cy="50" r="40" fill="none" stroke-width="9" stroke-linecap="round"
                                    class="gauge-fill stroke-teal-600 dark:stroke-teal-400" stroke-dasharray="251.33" stroke-dashoffset="251.33"
                                    data-draw-offset="45.2" />
                            </svg>
                            <div>
                                <p class="text-2xl font-semibold text-zinc-900 dark:text-white" data-count-to="82">82</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Portfolio Health Score') }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Floating fragment: concentration alert --}}
                    <div class="welcome-parallax absolute bottom-24 start-0 sm:-start-10" style="--depth: 1.6">
                        <div class="welcome-float card max-w-52 p-3.5 shadow-2xl dark:!border-amber-500/20" style="animation-delay: -2s">
                            <div class="flex items-start gap-2.5">
                                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-amber-500 dark:text-amber-400" />
                                <div>
                                    <p class="text-xs font-semibold text-zinc-900 dark:text-white">{{ __('Concentration alert') }}</p>
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('41% of your portfolio sits in a single stock.') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Floating fragment: advisor chat bubble --}}
                    <div class="welcome-parallax absolute -bottom-6 end-2 sm:-end-2" style="--depth: 2.2">
                        <div class="welcome-float card max-w-56 p-3.5 shadow-2xl dark:!border-teal-500/20" style="animation-delay: -4s">
                            <div class="flex items-start gap-2.5">
                                <flux:icon.sparkles class="mt-0.5 size-4 shrink-0 text-teal-600 dark:text-teal-400" />
                                <p class="text-xs text-zinc-600 dark:text-zinc-300">{{ __('Selling 6% of your tech exposure would lift your health score to 88. Want the plan?') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Stats strip ============ --}}
        <section class="grid grid-cols-2 gap-8 text-center lg:grid-cols-4">
            @foreach ([
                ['value' => 6, 'suffix' => '', 'label' => __('institutional risk dimensions in one score')],
                ['value' => 5, 'suffix' => '+', 'label' => __('Saudi institutions ready to connect')],
                ['value' => 24, 'suffix' => '/7', 'label' => __('monitoring, alerts, and portfolio news')],
                ['value' => 2, 'suffix' => '', 'label' => __('languages, Arabic first')],
            ] as $stat)
                <div class="welcome-reveal">
                    <p class="text-4xl font-semibold text-zinc-900 dark:text-white" dir="ltr">
                        <span data-count-to="{{ $stat['value'] }}">{{ $stat['value'] }}</span>{{ $stat['suffix'] }}
                    </p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $stat['label'] }}</p>
                </div>
            @endforeach
        </section>

        {{-- ============ Feature deck ============ --}}
        <section>
            <h2 class="welcome-reveal mx-auto max-w-2xl text-balance text-center text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                {{ __('Not another dashboard. An intelligence layer over everything you own.') }}
            </h2>

            <div class="welcome-deck mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['icon' => 'building-library', 'tint' => 'text-teal-700 bg-teal-500/10 dark:text-teal-300 dark:bg-teal-500/15', 'title' => __('One unified portfolio'), 'body' => __('Alinma, local brokerages, and crypto, aggregated securely via Saudi Open Banking.')],
                    ['icon' => 'heart', 'tint' => 'text-emerald-700 bg-emerald-500/10 dark:text-emerald-300 dark:bg-emerald-500/15', 'title' => __('Portfolio Health Score'), 'body' => __('One number, built from diversification, risk, performance, and your own goals.')],
                    ['icon' => 'eye', 'tint' => 'text-amber-700 bg-amber-500/10 dark:text-amber-300 dark:bg-amber-500/15', 'title' => __('Hidden risks, revealed'), 'body' => __('Concentration, correlation, and stress behavior that single apps never show you.')],
                    ['icon' => 'sparkles', 'tint' => 'text-purple-700 bg-purple-500/10 dark:text-purple-300 dark:bg-purple-500/15', 'title' => __('AI that speaks your language'), 'body' => __('Plain-language reports and a personalized action plan — in Arabic or English.')],
                    ['icon' => 'check-badge', 'tint' => 'text-emerald-700 bg-emerald-500/10 dark:text-emerald-300 dark:bg-emerald-500/15', 'title' => __('Shariah screening built in'), 'body' => __('Every holding is screened for compliance, and your score reflects your values.')],
                    ['icon' => 'rocket-launch', 'tint' => 'text-cyan-700 bg-cyan-500/10 dark:text-cyan-300 dark:bg-cyan-500/15', 'title' => __('Ready for what is next'), 'body' => __('Built on the SAMA Open Banking framework today, ready for investment-account APIs the day they launch.')],
                ] as $feature)
                    <div class="welcome-reveal welcome-deck-card card p-6 dark:!border-zinc-700/60">
                        <span class="mb-4 inline-flex size-11 items-center justify-center rounded-xl {{ $feature['tint'] }}">
                            <flux:icon :name="$feature['icon']" class="size-6" />
                        </span>
                        <flux:heading size="lg">{{ $feature['title'] }}</flux:heading>
                        <flux:text class="mt-2">{{ $feature['body'] }}</flux:text>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ============ See it work ============ --}}
        <section class="welcome-reveal card relative overflow-hidden p-8 dark:!border-zinc-700/60 sm:p-12" aria-label="{{ __('Portfolio Health Score') }}">
            <div aria-hidden="true" class="pointer-events-none absolute -top-20 end-[-10%] size-72 rounded-full bg-teal-500/10 blur-3xl"></div>

            <div class="relative grid items-center gap-10 lg:grid-cols-2">
                <div>
                    <h2 class="text-balance text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                        {{ __('From diagnosis to action') }}
                    </h2>
                    <ul class="mt-6 space-y-4">
                        <li class="flex items-start gap-3">
                            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-teal-600 dark:text-teal-400" />
                            <flux:text>{{ __('Every score explains itself: which holdings drag it down, and what would raise it.') }}</flux:text>
                        </li>
                        <li class="flex items-start gap-3">
                            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-teal-600 dark:text-teal-400" />
                            <flux:text>{{ __('Concrete rebalancing steps sized to your goals, not generic advice.') }}</flux:text>
                        </li>
                        <li class="flex items-start gap-3">
                            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-teal-600 dark:text-teal-400" />
                            <flux:text>{{ __('Set your own alert thresholds and get notified the moment one is crossed.') }}</flux:text>
                        </li>
                    </ul>
                </div>

                <div class="mx-auto flex items-center gap-8" aria-hidden="true">
                    <svg viewBox="0 0 100 100" class="size-40 -rotate-90 sm:size-48">
                        <circle cx="50" cy="50" r="40" fill="none" stroke-width="8" class="stroke-zinc-200 dark:stroke-zinc-800" />
                        <circle cx="50" cy="50" r="40" fill="none" stroke-width="8" stroke-linecap="round"
                            class="gauge-fill stroke-teal-600 dark:stroke-teal-400" stroke-dasharray="251.33" stroke-dashoffset="251.33"
                            data-draw-offset="45.2" />
                    </svg>
                    <div>
                        <p class="text-6xl font-semibold text-zinc-900 dark:text-white" data-count-to="82">82</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Portfolio Health Score') }}</p>
                        <p class="mt-3 inline-flex rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300" dir="ltr">▲ +6 {{ __('this month') }}</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Institutions marquee ============ --}}
        <section class="welcome-reveal">
            <p class="text-center text-sm text-zinc-500">{{ __('Connects where Saudi investors actually invest') }}</p>
            <div class="welcome-marquee-mask mt-6 overflow-hidden">
                <div class="welcome-marquee flex w-max items-center gap-4">
                    @foreach (range(1, 2) as $copy)
                        <div class="flex items-center gap-4" @if ($copy === 2) aria-hidden="true" @endif>
                            @foreach ([
                                ['Alinma Bank', 'مصرف الإنماء'],
                                ['Alinma Capital', 'الإنماء المالية'],
                                ['Derayah Financial', 'دراية المالية'],
                                ['Al Rajhi Capital', 'الراجحي المالية'],
                                ['Rain', 'رين'],
                                ['Tadawul', 'تداول'],
                            ] as [$en, $ar])
                                <span class="shrink-0 rounded-full border border-zinc-200 bg-white px-5 py-2.5 text-sm font-medium text-zinc-600 dark:border-zinc-700/60 dark:bg-zinc-900 dark:text-zinc-300">
                                    {{ app()->getLocale() === 'ar' ? $ar : $en }}
                                </span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ Final CTA ============ --}}
        <section class="welcome-reveal relative overflow-hidden rounded-3xl border border-teal-500/20 bg-gradient-to-br from-teal-500/15 via-white to-purple-500/10 dark:via-zinc-900 p-10 text-center sm:p-16">
            <div aria-hidden="true" class="pointer-events-none absolute -bottom-24 start-1/2 size-80 -translate-x-1/2 rounded-full bg-teal-500/15 blur-3xl rtl:translate-x-1/2"></div>

            <h2 class="relative text-balance text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white sm:text-4xl">
                {{ __('Your portfolios already hold the answers. See them together.') }}
            </h2>

            <div class="relative mt-8 flex flex-wrap items-center justify-center gap-3">
                <flux:button href="{{ route('register') }}" variant="primary">{{ __('Create account') }}</flux:button>
                <flux:button href="{{ route('login') }}" variant="ghost">{{ __('Log in') }}</flux:button>
                <form method="POST" action="{{ route('demo.start') }}">
                    @csrf
                    <flux:button type="submit" variant="subtle" icon="play">{{ __('Try the demo') }}</flux:button>
                </form>
            </div>

            <p class="relative mt-3 text-xs text-zinc-500">{{ __('The demo opens a ready-made portfolio. No signup needed.') }}</p>

            <a href="{{ route('locale.update', app()->getLocale() === 'ar' ? 'en' : 'ar') }}"
                class="relative mt-8 inline-block text-sm text-zinc-500 underline-offset-4 dark:text-zinc-400 hover:underline">
                {{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}
            </a>
        </section>
    </main>

    @include('partials.pwa-install-banner')

    @fluxScripts
</body>

</html>
