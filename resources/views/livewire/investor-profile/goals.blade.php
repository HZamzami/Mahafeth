<?php

use App\Models\Goal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $editingId = null;

    public string $name = '';

    public string $targetAmount = '';

    public string $targetDate = '';

    public string $monthlyContribution = '';

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'targetAmount' => ['required', 'numeric', 'min:1'],
            'targetDate' => ['required', 'date', 'after:today'],
            'monthlyContribution' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function edit(?int $goalId = null): void
    {
        $goal = $goalId === null ? null : Auth::user()->goals()->findOrFail($goalId);

        $this->editingId = $goal?->id;
        $this->name = $goal->name ?? '';
        $this->targetAmount = $goal !== null ? (string) $goal->target_amount : '';
        $this->targetDate = $goal?->target_date?->toDateString() ?? '';
        $this->monthlyContribution = $goal?->monthly_contribution !== null ? (string) $goal->monthly_contribution : '';
        $this->resetErrorBag();

        $this->modal('goal-form')->show();
    }

    public function save(): void
    {
        $this->validate($this->rules());

        Auth::user()->goals()->updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => $this->name,
                'target_amount' => (float) $this->targetAmount,
                'target_date' => $this->targetDate,
                'monthly_contribution' => $this->monthlyContribution === '' ? null : (float) $this->monthlyContribution,
            ],
        );

        $this->modal('goal-form')->close();
        $this->reset('editingId', 'name', 'targetAmount', 'targetDate', 'monthlyContribution');
    }

    public function delete(int $goalId): void
    {
        Auth::user()->goals()->findOrFail($goalId)->delete();
    }

    public function with(): array
    {
        return [
            'goals' => Auth::user()->goals()->orderBy('target_date')->get(),
        ];
    }
}; ?>

<div class="card p-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="lg">{{ __('Financial Goals') }}</flux:heading>
            <flux:text class="mt-1 text-sm">
                {{ __('Set what you are investing for, and Mahafeth will track your odds of getting there.') }}
            </flux:text>
        </div>
        <flux:button size="sm" variant="primary" icon="plus" wire:click="edit">{{ __('Add Goal') }}</flux:button>
    </div>

    @if ($goals->isEmpty())
        <flux:text class="mt-4 text-sm">{{ __('No goals yet. Add your first goal to unlock forecasts.') }}</flux:text>
    @else
        <div class="mt-4 space-y-3">
            @foreach ($goals as $goal)
                <div wire:key="goal-{{ $goal->id }}" wire:transition
                    class="flex items-center justify-between rounded-lg border border-neutral-100 p-3 dark:border-zinc-800">
                    <div>
                        <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">{{ $goal->name }}</flux:text>
                        <flux:text class="text-xs">
                            {{ __('⃁ :amount by :date', ['amount' => Number::format($goal->target_amount, 0), 'date' => $goal->target_date->isoFormat('MMM YYYY')]) }}
                            @if ($goal->monthly_contribution !== null)
                                &bull; {{ __('⃁ :amount monthly', ['amount' => Number::format($goal->monthly_contribution, 0)]) }}
                            @endif
                        </flux:text>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <flux:button size="sm" variant="subtle" icon="pencil" wire:click="edit({{ $goal->id }})"
                            :aria-label="__('Edit')" />
                        <flux:button size="sm" variant="subtle" icon="trash" wire:click="delete({{ $goal->id }})"
                            wire:confirm="{{ __('Delete this goal?') }}" :aria-label="__('Delete')" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <flux:modal name="goal-form" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? __('Add Goal') : __('Edit Goal') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Goal name')" :placeholder="__('e.g. Retirement')" />
            <flux:input wire:model="targetAmount" type="number" min="1" step="1000"
                :label="__('Target amount (⃁)')" />
            <flux:date-picker wire:model="targetDate" selectable-header :label="__('Target date')" />
            <flux:input wire:model="monthlyContribution" type="number" min="0" step="100"
                :label="__('Monthly contribution (⃁, optional)')" />

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">
                    {{ __('Save Goal') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
