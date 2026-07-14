{{-- One-time nudge toward passkey sign-in; only for users without one,
     on devices that support WebAuthn, until dismissed. --}}
@if (auth()->user()->passkeys()->doesntExist())
    <div x-data="{
        dismissed: localStorage.getItem('passkeyNudgeDismissed') === '1',
        supported: !! window.PublicKeyCredential,
        dismiss() {
            this.dismissed = true;
            localStorage.setItem('passkeyNudgeDismissed', '1');
        },
    }" x-show="supported && ! dismissed" x-cloak
        class="card flex items-center gap-3 p-4">
        <flux:icon.finger-print class="size-5 shrink-0 text-teal-700 dark:text-teal-400" />

        <flux:text class="min-w-0 flex-1 text-sm">
            {{ __('Sign in with Face ID or fingerprint next time? Add a passkey in under a minute.') }}
        </flux:text>

        <flux:button size="sm" variant="primary" :href="route('settings.passkeys')" wire:navigate>
            {{ __('Add passkey') }}</flux:button>

        <flux:button size="sm" variant="subtle" icon="x-mark" x-on:click="dismiss()"
            :aria-label="__('Dismiss')" />
    </div>
@endif
