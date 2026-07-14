<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';

    /**
     * Sign out every other device: rotate the remember token via the
     * framework helper, then drop the other database session rows.
     */
    public function logoutOtherDevices(): void
    {
        if (! Hash::check($this->password, Auth::user()->password)) {
            throw ValidationException::withMessages([
                'password' => __('The provided password is incorrect.'),
            ]);
        }

        Auth::logoutOtherDevices($this->password);

        DB::table('sessions')
            ->where('user_id', Auth::id())
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->reset('password');
        $this->modal('logout-other-devices')->close();
        $this->dispatch('toast', message: __('Signed out everywhere else.'));
    }

    /**
     * Coarse, dependency-free device label from the user agent.
     */
    protected function deviceLabel(?string $userAgent): string
    {
        $agent = $userAgent ?? '';

        $platform = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('Unknown device'),
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        return $browser === null ? $platform : "$platform · $browser";
    }

    public function with(): array
    {
        $sessions = DB::table('sessions')
            ->where('user_id', Auth::id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'device' => $this->deviceLabel($session->user_agent),
                'ip' => $session->ip_address,
                'lastActive' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                'current' => $session->id === session()->getId(),
            ]);

        return ['sessions' => $sessions];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Sessions')" :subheading="__('The devices currently signed in to your account')">
        <div class="mt-6 space-y-3">
            @foreach ($sessions as $session)
                <div class="card flex items-center justify-between gap-3 p-4" wire:key="session-{{ md5($session['id']) }}">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:icon.device-phone-mobile class="size-5 shrink-0 text-zinc-400" />
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ $session['device'] }}</flux:heading>
                                @if ($session['current'])
                                    <flux:badge color="teal" size="sm">{{ __('This device') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="text-sm">
                                {{ $session['ip'] }} · {{ __('Active :time', ['time' => $session['lastActive']]) }}
                            </flux:text>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($sessions->count() > 1)
            <flux:modal.trigger name="logout-other-devices">
                <flux:button class="mt-6" variant="danger">{{ __('Sign out other devices') }}</flux:button>
            </flux:modal.trigger>
        @endif

        <flux:modal name="logout-other-devices" class="w-full max-w-md">
            <form wire:submit="logoutOtherDevices" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Sign out other devices') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('Confirm your password to end every session except this one.') }}
                    </flux:text>
                </div>

                <flux:input
                    wire:model="password"
                    :label="__('Password')"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Sign out other devices') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-settings.layout>
</section>
