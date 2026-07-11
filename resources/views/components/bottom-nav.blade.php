@php($inactive = 'text-zinc-500 dark:text-zinc-400')
@php($active = 'text-teal-600 dark:text-teal-400')

<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden print:hidden dark:border-zinc-700 dark:bg-zinc-900">
    <div class="grid grid-cols-5">
        <a href="{{ route('dashboard') }}" wire:navigate
            class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition-transform active:scale-95 {{ request()->routeIs('dashboard') ? $active : $inactive }}">
            <flux:icon.home class="size-6" />
            <span class="max-w-full truncate px-1">{{ __('Dashboard') }}</span>
        </a>
        <a href="{{ route('analytics') }}" wire:navigate
            class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition-transform active:scale-95 {{ request()->routeIs('analytics') ? $active : $inactive }}">
            <flux:icon.chart-bar class="size-6" />
            <span class="max-w-full truncate px-1">{{ __('Analytics') }}</span>
        </a>
        <a href="{{ route('advisor') }}" wire:navigate
            class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition-transform active:scale-95 {{ request()->routeIs('advisor') ? $active : $inactive }}">
            <flux:icon.chat-bubble-left-right class="size-6" />
            <span class="max-w-full truncate px-1">{{ __('AI Advisor') }}</span>
        </a>
        <a href="{{ route('connections') }}" wire:navigate
            class="flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition-transform active:scale-95 {{ request()->routeIs('connections*') ? $active : $inactive }}">
            <flux:icon.building-library class="size-6" />
            <span class="max-w-full truncate px-1">{{ __('Connections') }}</span>
        </a>

        <flux:dropdown position="top" align="end">
            <button type="button"
                class="flex w-full flex-col items-center gap-1 py-2 text-[10px] font-medium {{ request()->routeIs(['report', 'investor-profile']) ? $active : $inactive }}">
                <flux:icon.ellipsis-horizontal class="size-6" />
                <span class="max-w-full truncate px-1">{{ __('More') }}</span>
            </button>

            <flux:menu>
                <flux:menu.item icon="document-text" :href="route('report')" wire:navigate>
                    {{ __('Report') }}</flux:menu.item>
                <flux:menu.item icon="clipboard-document-check" :href="route('investor-profile')" wire:navigate>
                    {{ __('Investor Profile') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>
</nav>
