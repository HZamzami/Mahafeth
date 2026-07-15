{{-- Full-screen overlay shown while the demo POST provisions the account.
     Include INSIDE a form carrying data-demo-form; the delegated submit
     listener in app.js reveals it (inline display:none so it works before
     Alpine boots — the hero button is clickable earlier than alpine:init). --}}
<div data-demo-overlay style="display: none"
    class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-surface-50/95 px-6 text-center backdrop-blur-sm dark:bg-charcoal-950/95">
    <x-app-logo-icon class="size-14" />

    <div>
        <flux:heading size="lg">{{ __('Building your demo portfolio') }}</flux:heading>
        <flux:text class="mt-2" data-demo-step>{{ __('Connecting accounts…') }}</flux:text>
    </div>

    <div class="demo-progress h-1.5 w-64 overflow-hidden rounded-full bg-neutral-200 dark:bg-zinc-800">
        <div class="demo-progress-bar h-full w-1/3 rounded-full bg-teal-600 dark:bg-teal-400"></div>
    </div>
</div>
