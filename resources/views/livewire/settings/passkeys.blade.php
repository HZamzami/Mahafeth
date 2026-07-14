<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Support\Config;

new class extends Component {
    public string $name = '';

    /**
     * Registration options for the WebAuthn ceremony, kept in the session
     * so storePasskey can verify the attestation against the challenge.
     */
    public function getRegisterOptions(): string
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        $options = Config::getAction('generate_passkey_register_options', GeneratePasskeyRegisterOptionsAction::class)
            ->execute(Auth::user());

        session()->put('passkey-registration-options', $options);

        return $options;
    }

    public function storePasskey(string $response): void
    {
        try {
            Config::getAction('store_passkey', StorePasskeyAction::class)->execute(
                Auth::user(),
                $response,
                session()->pull('passkey-registration-options'),
                request()->getHost(),
                ['name' => $this->name],
            );
        } catch (Throwable) {
            $this->reportEnrollmentFailure();

            return;
        }

        $this->reset('name');
        $this->dispatch('toast', message: __('Passkey added.'));
    }

    public function reportEnrollmentFailure(): void
    {
        $this->addError('name', __('Something went wrong while adding the passkey. Please try again.'));
    }

    public function deletePasskey(int $passkeyId): void
    {
        Auth::user()->passkeys()->whereKey($passkeyId)->delete();

        $this->dispatch('toast', message: __('Passkey removed.'));
    }

    public function with(): array
    {
        return ['passkeys' => Auth::user()->passkeys()->latest()->get()];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Passkeys')" :subheading="__('Sign in with Face ID, fingerprint, or your device screen lock instead of a password')">
        <div class="mt-6 space-y-6" x-data="passkeyCreate">
            <template x-if="supported">
                <form @submit.prevent="create" class="space-y-4">
                    <flux:input
                        wire:model="name"
                        :label="__('Passkey name')"
                        type="text"
                        name="name"
                        :placeholder="__('e.g. My phone')"
                    />

                    <flux:button type="submit" variant="primary" icon="finger-print" x-bind:disabled="busy">
                        {{ __('Add passkey') }}
                    </flux:button>
                </form>
            </template>

            <template x-if="!supported">
                <flux:text>{{ __('This device or browser does not support passkeys.') }}</flux:text>
            </template>

            <div class="space-y-3">
                @forelse ($passkeys as $passkey)
                    <div class="card flex items-center justify-between gap-3 p-4" wire:key="passkey-{{ $passkey->id }}">
                        <div class="min-w-0">
                            <flux:heading size="sm" class="truncate">{{ $passkey->name }}</flux:heading>
                            <flux:text class="text-sm">
                                {{ __('Added :date', ['date' => $passkey->created_at->translatedFormat('j F Y')]) }}
                                @if ($passkey->last_used_at)
                                    · {{ __('Last used :date', ['date' => $passkey->last_used_at->diffForHumans()]) }}
                                @endif
                            </flux:text>
                        </div>

                        <flux:button
                            variant="subtle"
                            icon="trash"
                            size="sm"
                            wire:click="deletePasskey({{ $passkey->id }})"
                            wire:confirm="{{ __('Remove this passkey? You will no longer be able to sign in with it.') }}"
                            :aria-label="__('Delete')"
                        />
                    </div>
                @empty
                    <flux:text>{{ __('No passkeys yet. Add one to sign in without your password.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </x-settings.layout>
</section>
