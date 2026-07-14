{{-- Full-screen overlay shown while the demo POST provisions the account.
     Include INSIDE a demo form with x-data="demoBuilding" on the form. --}}
<div x-show="building" x-cloak
    class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-surface-50/95 px-6 text-center backdrop-blur-sm dark:bg-charcoal-950/95">
    <x-app-logo-icon class="size-14" />

    <div>
        <flux:heading size="lg">{{ __('Building your demo portfolio') }}</flux:heading>
        <flux:text class="mt-2" x-text="steps[step]">{{ __('Connecting accounts…') }}</flux:text>
    </div>

    <div class="demo-progress h-1.5 w-64 overflow-hidden rounded-full bg-neutral-200 dark:bg-zinc-800">
        <div class="demo-progress-bar h-full w-1/3 rounded-full bg-teal-600 dark:bg-teal-400"></div>
    </div>
</div>
