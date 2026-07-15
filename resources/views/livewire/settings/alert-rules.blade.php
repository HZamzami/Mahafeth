<?php

use App\Models\AlertRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $editingId = null;

    public string $metric = 'volatility';

    public string $threshold = '';

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'metric' => ['required', 'string', 'in:'.implode(',', array_keys(AlertRule::METRICS))],
            'threshold' => ['required', 'numeric', 'min:1', 'max:100'],
        ];
    }

    public function edit(?int $ruleId = null): void
    {
        $rule = $ruleId === null ? null : Auth::user()->alertRules()->findOrFail($ruleId);

        $this->editingId = $rule?->id;
        $this->metric = $rule?->metric ?? 'volatility';
        $this->threshold = $rule === null
            ? ''
            : (string) $this->displayThreshold($rule);
        $this->resetErrorBag();

        $this->modal('alert-rule-form')->show();
    }

    public function save(): void
    {
        $this->validate($this->rules());

        // Ratio metrics are entered as percentages but stored as decimal
        // fractions; the health score is whole points either way.
        $stored = AlertRule::METRICS[$this->metric]['unit'] === 'percent'
            ? (float) $this->threshold / 100
            : (float) $this->threshold;

        Auth::user()->alertRules()->updateOrCreate(
            ['id' => $this->editingId],
            ['metric' => $this->metric, 'threshold' => $stored, 'enabled' => true],
        );

        $this->modal('alert-rule-form')->close();
        $this->reset('editingId', 'metric', 'threshold');
    }

    public function toggle(int $ruleId): void
    {
        $rule = Auth::user()->alertRules()->findOrFail($ruleId);

        $rule->update(['enabled' => ! $rule->enabled]);
    }

    public function delete(int $ruleId): void
    {
        Auth::user()->alertRules()->findOrFail($ruleId)->delete();
    }

    private function displayThreshold(AlertRule $rule): float|int
    {
        return AlertRule::METRICS[$rule->metric]['unit'] === 'percent'
            ? round($rule->threshold * 100, 1)
            : (int) round($rule->threshold);
    }

    public function with(): array
    {
        return [
            'alertRules' => Auth::user()->alertRules()->oldest('id')->get(),
            'metrics' => AlertRule::METRICS,
            // Translated threshold-field copy per metric, handed to Alpine so
            // the label and hint swap client-side as the metric changes.
            'metricMeta' => collect(AlertRule::METRICS)->mapWithKeys(fn (array $definition, string $key): array => [
                $key => [
                    'label' => ($definition['direction'] ?? 'above') === 'below'
                        ? __('Alert when it falls below')
                        : __('Alert when it rises above'),
                    'description' => ($definition['unit'] ?? 'percent') === 'percent'
                        ? __('As a percentage, e.g. 20 for 20%.')
                        : __('Score points, 1–100.'),
                ],
            ])->all(),
            'formatted' => fn (AlertRule $rule): string => AlertRule::METRICS[$rule->metric]['unit'] === 'percent'
                ? Number::percentage($rule->threshold * 100, 1)
                : __(':points points', ['points' => (int) round($rule->threshold)]),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:text class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Custom alerts') }}</flux:text>
            <flux:text class="mt-1 text-sm">
                {{ __('Get notified when a metric you choose crosses your own limit.') }}</flux:text>
        </div>
        <flux:button size="sm" icon="plus" wire:click="edit" wire:loading.attr="disabled">
            {{ __('Add rule') }}</flux:button>
    </div>

    @foreach ($alertRules as $rule)
        <div wire:key="alert-rule-{{ $rule->id }}"
            class="flex items-center justify-between gap-3 rounded-lg border border-neutral-200/60 bg-neutral-50 p-3 dark:border-neutral-700/60 dark:bg-zinc-800/50">
            <div class="min-w-0">
                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                    {{ __($metrics[$rule->metric]['label'] ?? $rule->metric) }}</flux:text>
                <flux:text class="text-xs">
                    {{ $metrics[$rule->metric]['direction'] === 'below' ? __('Alert below :threshold', ['threshold' => $formatted($rule)]) : __('Alert above :threshold', ['threshold' => $formatted($rule)]) }}
                </flux:text>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <flux:switch :checked="$rule->enabled" wire:click="toggle({{ $rule->id }})"
                    :aria-label="__('Enabled')" />
                <flux:button size="sm" variant="subtle" icon="pencil" wire:click="edit({{ $rule->id }})"
                    wire:loading.attr="disabled" :aria-label="__('Edit')" />
                <flux:button size="sm" variant="subtle" icon="trash" wire:click="delete({{ $rule->id }})"
                    wire:confirm="{{ __('Delete this alert rule?') }}" wire:loading.attr="disabled"
                    :aria-label="__('Delete')" />
            </div>
        </div>
    @endforeach

    <flux:modal name="alert-rule-form" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? __('Add alert rule') : __('Edit alert rule') }}
            </flux:heading>

            <div class="space-y-6"
                x-data="{ meta: @js($metricMeta), get current() { return this.meta[$wire.metric] ?? Object.values(this.meta)[0]; } }">
                <flux:select wire:model="metric" :label="__('Watch this metric')">
                    @foreach ($metrics as $key => $definition)
                        <flux:select.option value="{{ $key }}">{{ __($definition['label']) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:field>
                    <flux:label x-text="current.label"></flux:label>
                    <flux:input wire:model="threshold" type="number" min="1" max="100" step="0.5" />
                    <flux:description x-text="current.description"></flux:description>
                    <flux:error name="threshold" />
                </flux:field>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">
                    {{ __('Save rule') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
