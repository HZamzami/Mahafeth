<x-layouts.app.sidebar>
    {{-- The bottom padding reserves room for the fixed bottom nav, whose
         height grows by the home-indicator inset in the installed PWA. --}}
    <flux:main class="max-lg:p-4! max-lg:pb-[calc(6rem+env(safe-area-inset-bottom))]!">
        {{ $slot }}
    </flux:main>
</x-layouts.app.sidebar>
