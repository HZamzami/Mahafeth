<?php

use App\Models\User;
use App\Notifications\PortfolioAlertNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';
    public bool $notifyAlerts = true;
    public bool $hasPushSubscription = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->notifyAlerts = Auth::user()->notify_alerts ?? true;
        $this->hasPushSubscription = Auth::user()->pushSubscriptions()->exists();
    }

    /**
     * Toggle portfolio alert emails.
     */
    public function updatedNotifyAlerts(bool $value): void
    {
        Auth::user()->update(['notify_alerts' => $value]);
    }

    /**
     * Persist the browser push subscription for this device.
     *
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $subscription
     */
    public function storePushSubscription(array $subscription): void
    {
        Auth::user()->updatePushSubscription(
            $subscription['endpoint'],
            $subscription['keys']['p256dh'] ?? null,
            $subscription['keys']['auth'] ?? null,
        );

        $this->hasPushSubscription = true;
    }

    /**
     * Fire a sample alert at the user's subscribed devices.
     */
    public function sendTestNotification(): void
    {
        Auth::user()->notify(new PortfolioAlertNotification(
            newAlerts: [[
                'key' => 'Concentration alert: :name is :weight of your portfolio — above the :threshold threshold.',
                'color' => 'amber',
                'params' => ['name' => 'AAPL', 'weight' => '34%', 'threshold' => '30%'],
            ]],
            pushOnly: true,
        ));

        $this->dispatch('toast', message: __('Test notification sent.'));
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
        $this->dispatch('toast', message: __('Saved.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="{{ __('Name') }}" type="text" name="name" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" label="{{ __('Email') }}" type="email" name="email" required autocomplete="email" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail &&! auth()->user()->hasVerifiedEmail())
                    <div>
                        <p class="mt-2 text-sm text-gray-800">
                            {{ __('Your email address is unverified.') }}

                            <button
                                wire:click.prevent="resendVerificationNotification"
                                class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                {{ __('Click here to re-send the verification email.') }}
                            </button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 text-sm font-medium text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <div class="my-6 space-y-6 border-t border-neutral-200 pt-6 dark:border-neutral-700">
            <flux:switch wire:model.live="notifyAlerts" :label="__('Portfolio alert emails')"
                :description="__('Get an email when new risk alerts appear or your health score drops.')" />

            <div x-data="{
                supported: 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window,
                subscribed: $wire.hasPushSubscription,
                busy: false,
                async enable() {
                    this.busy = true;

                    try {
                        if (await Notification.requestPermission() !== 'granted') {
                            return;
                        }

                        const registration = await navigator.serviceWorker.ready;
                        const key = document.querySelector('meta[name=vapid-public-key]')?.content;
                        const subscription = await registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: Uint8Array.from(atob(key.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0)),
                        });

                        await $wire.storePushSubscription(subscription.toJSON());
                        this.subscribed = true;
                    } finally {
                        this.busy = false;
                    }
                },
            }">
                <div x-show="supported">
                    <flux:text class="text-sm font-medium text-zinc-800 dark:text-white">
                        {{ __('Push notifications') }}</flux:text>
                    <flux:text class="mt-1 text-sm">
                        {{ __('Get portfolio alerts on this device even when the app is closed. On iPhone, install the app to your home screen first.') }}
                    </flux:text>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <flux:button size="sm" variant="primary" x-show="! subscribed" x-on:click="enable"
                            x-bind:disabled="busy">
                            {{ __('Enable on this device') }}</flux:button>
                        <flux:badge color="emerald" size="sm" x-show="subscribed" x-cloak>
                            {{ __('Enabled on this device') }}</flux:badge>
                        <flux:button size="sm" variant="outline" x-show="subscribed" x-cloak
                            wire:click="sendTestNotification">
                            {{ __('Send test notification') }}</flux:button>
                    </div>
                </div>
            </div>
        </div>

        <livewire:settings.delete-user-form />
    </x-settings.layout>
</section>
