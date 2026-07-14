{{-- Pressed tabs tint teal instantly, before navigation resolves, so a
     tap always acknowledges itself even when the next page takes a beat.
     :active only lasts while the finger is down, so the click handler
     keeps the tint on until the swap replaces the whole nav. --}}
@php($inactive = 'text-zinc-500 active:text-teal-600 dark:text-zinc-400 dark:active:text-teal-400')
@php($active = 'text-teal-600 dark:text-teal-400')
@php($press = "window.haptic?.(5); \$el.classList.remove('text-zinc-500', 'dark:text-zinc-400'); \$el.classList.add('text-teal-600', 'dark:text-teal-400')")

{{-- Slot order by frequency of use: the daily overview and positions
     first, the AI advisor in the signature center spot, discovery next,
     and everything occasional stashed under More. --}}
{{-- transform-gpu + will-change pin the nav to its own compositing layer:
     without them, WebKit (installed PWA) sometimes drops the fixed layer
     during large repaints and page text paints over the bar. --}}
<nav class="fixed inset-x-0 bottom-0 z-40 transform-gpu border-t border-zinc-200 bg-white pb-[env(safe-area-inset-bottom)] will-change-transform lg:hidden print:hidden dark:border-zinc-700 dark:bg-zinc-900">
    <div class="grid grid-cols-5">
        <a href="{{ route('dashboard') }}" wire:navigate.hover
            x-data x-on:click="{{ $press }}"
            class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition-transform active:scale-95 {{ request()->routeIs('dashboard') ? $active : $inactive }}">
            <flux:icon.home class="size-6" />
            <span class="max-w-full truncate px-1">{{ __('Dashboard') }}</span>
        </a>
        <a href="{{ route('holdings.index') }}" wire:navigate.hover
            x-data x-on:click="{{ $press }}"
            class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition-transform active:scale-95 {{ request()->routeIs('holdings.*') ? $active : $inactive }}">
            <flux:icon.briefcase class="size-6" />
            <span class="max-w-full truncate px-1">{{ __('Holdings') }}</span>
        </a>
        <a href="{{ route('advisor') }}" wire:navigate.hover
            x-data x-on:click="{{ $press }}"
            class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition-transform active:scale-95 {{ request()->routeIs('advisor') ? $active : $inactive }}">
            <flux:icon.chat-bubble-left-right class="size-6" />
            <span class="max-w-full truncate px-1">{{ __('AI Advisor') }}</span>
        </a>
        <a href="{{ route('explore.index') }}" wire:navigate.hover
            x-data x-on:click="{{ $press }}"
            class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition-transform active:scale-95 {{ request()->routeIs('explore.*') ? $active : $inactive }}">
            <flux:icon.magnifying-glass class="size-6" />
            <span class="max-w-full truncate px-1">{{ __('Explore') }}</span>
        </a>

        <flux:dropdown position="top" align="end">
            <button type="button"
                class="flex w-full flex-col items-center gap-1 py-2 text-[10px] font-medium {{ request()->routeIs(['plan', 'analytics', 'activity', 'report', 'connections*', 'investor-profile']) ? $active : $inactive }}">
                <flux:icon.ellipsis-horizontal class="size-6" />
                <span class="max-w-full truncate px-1">{{ __('More') }}</span>
            </button>

            <flux:menu>
                <flux:menu.item icon="rocket-launch" :href="route('plan')" wire:navigate.hover>
                    {{ __('Investment Plan') }}</flux:menu.item>
                <flux:menu.item icon="chart-bar" :href="route('analytics')" wire:navigate.hover>
                    {{ __('Analytics') }}</flux:menu.item>
                <flux:menu.item icon="bell-alert" :href="route('activity')" wire:navigate.hover>
                    {{ __('Activity') }}</flux:menu.item>
                <flux:menu.item icon="document-text" :href="route('report')" wire:navigate.hover>
                    {{ __('Report') }}</flux:menu.item>
                <flux:menu.item icon="building-library" :href="route('connections')" wire:navigate.hover>
                    {{ __('Connections') }}</flux:menu.item>
                <flux:menu.item icon="clipboard-document-check" :href="route('investor-profile')" wire:navigate.hover>
                    {{ __('Investor Profile') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>
</nav>
