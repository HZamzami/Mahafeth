<div class="flex items-start max-md:flex-col">
    {{-- Desktop: side nav --}}
    <div class="me-10 hidden w-[220px] pb-4 md:block">
        <flux:navlist>
            <flux:navlist.item href="{{ route('settings.profile') }}" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item href="{{ route('settings.password') }}" wire:navigate>{{ __('Password') }}</flux:navlist.item>
            <flux:navlist.item href="{{ route('settings.passkeys') }}" wire:navigate>{{ __('Passkeys') }}</flux:navlist.item>
            <flux:navlist.item href="{{ route('settings.appearance') }}" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    {{-- Mobile: horizontal pill row --}}
    <x-scroll-hint class="w-full md:hidden">
        <div data-scroll-area class="flex w-full gap-2 overflow-x-auto pb-4 scrollbar-thin">
            @foreach ([
                'settings.profile' => __('Profile'),
                'settings.password' => __('Password'),
                'settings.passkeys' => __('Passkeys'),
                'settings.appearance' => __('Appearance'),
            ] as $routeName => $label)
                <a href="{{ route($routeName) }}" wire:navigate
                    class="shrink-0 rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap {{ request()->routeIs($routeName)
                        ? 'bg-teal-600 text-white dark:bg-teal-500'
                        : 'bg-neutral-100 text-zinc-700 hover:bg-neutral-200/60 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700/60' }}">
                    {{ $label }}</a>
            @endforeach
        </div>
    </x-scroll-hint>

    <div class="flex-1 self-stretch max-md:pt-2">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
