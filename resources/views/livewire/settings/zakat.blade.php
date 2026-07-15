<?php

use App\Support\HijriDate;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $hawlMonth = null;

    public ?int $hawlDay = null;

    public function mount(): void
    {
        $this->hawlMonth = Auth::user()->zakat_hawl_month;
        $this->hawlDay = Auth::user()->zakat_hawl_day;
    }

    public function save(): void
    {
        $this->validate([
            'hawlMonth' => ['required', 'integer', 'between:1,12'],
            'hawlDay' => ['required', 'integer', 'between:1,30'],
        ]);

        Auth::user()->update([
            'zakat_hawl_month' => $this->hawlMonth,
            'zakat_hawl_day' => $this->hawlDay,
        ]);

        $this->dispatch('toast', message: __('Saved.'));
    }

    public function clear(): void
    {
        Auth::user()->update(['zakat_hawl_month' => null, 'zakat_hawl_day' => null]);
        $this->hawlMonth = null;
        $this->hawlDay = null;
    }

    public function with(): array
    {
        return [
            'monthNames' => HijriDate::monthNames(),
            'nextDate' => ($this->hawlMonth !== null && $this->hawlDay !== null)
                ? HijriDate::nextGregorian($this->hawlMonth, $this->hawlDay)
                : null,
        ];
    }
}; ?>

<div>
    <flux:text class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Zakat hawl reminder') }}</flux:text>
    <flux:text class="mt-1 text-sm">
        {{ __('Set the Hijri date your zakat year completes and Mahafeth will remind you a week ahead with your estimated amount.') }}
    </flux:text>

    <div class="mt-3 flex flex-wrap items-end gap-2">
        <flux:select wire:model.live="hawlDay" :label="__('Day')" class="w-24">
            <flux:select.option value="">—</flux:select.option>
            @foreach (range(1, 30) as $day)
                <flux:select.option value="{{ $day }}">{{ $day }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="hawlMonth" :label="__('Hijri month')" class="min-w-40">
            <flux:select.option value="">—</flux:select.option>
            @foreach ($monthNames as $number => $name)
                <flux:select.option value="{{ $number }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">
            {{ __('Save') }}</flux:button>
        @if (Auth::user()->zakat_hawl_month !== null)
            <flux:button variant="ghost" wire:click="clear" wire:loading.attr="disabled">
                {{ __('Clear') }}</flux:button>
        @endif
    </div>

    @if ($nextDate !== null)
        <flux:text class="mt-2 text-xs">
            {{ __('Next hawl: :hijri (:date)', ['hijri' => HijriDate::format($nextDate), 'date' => $nextDate->translatedFormat('j M Y')]) }}
        </flux:text>
    @endif
</div>
