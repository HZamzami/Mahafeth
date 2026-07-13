<?php

use App\Enums\RiskTolerance;
use App\Enums\TimeHorizon;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public int $step = 1;

    /** @var array<string, ?int> */
    public array $answers = [
        'age' => null,
        'horizon' => null,
        'goal' => null,
        'drop_reaction' => null,
        'experience' => null,
        'liquidity' => null,
        'target_return' => null,
        'contributions' => null,
        'base_currency' => null,
        'shariah' => null,
    ];

    public function mount(): void
    {
        $profile = Auth::user()->riskProfile;

        if ($profile !== null) {
            $this->answers = array_merge($this->answers, $profile->answers);
        }
    }

    /**
     * The IPS questionnaire, following the CFA framework: return objective,
     * risk tolerance, time horizon, liquidity needs, and constraints. Each
     * risk answer scores 1 (most cautious) to 4, except age where the scale
     * is inverted since younger investors have more risk capacity. The
     * Shariah, contribution, and currency questions are constraints, not
     * risk scores, and are left out of the tolerance sum.
     *
     * @return array<string, array{question: string, options: list<string>}>
     */
    public function questions(): array
    {
        return [
            'age' => [
                'question' => __('What is your age bracket?'),
                'options' => [
                    __('Under 30'),
                    __('30–45'),
                    __('45–60'),
                    __('Over 60'),
                ],
            ],
            'horizon' => [
                'question' => __('How long do you plan to keep this money invested?'),
                'options' => [
                    __('Under 3 years'),
                    __('3–7 years'),
                    __('7–15 years'),
                    __('Over 15 years'),
                ],
            ],
            'goal' => [
                'question' => __('What is your primary investment goal?'),
                'options' => [
                    __('Preserve my capital'),
                    __('Generate steady income'),
                    __('Balanced long-term growth'),
                    __('Maximize growth, accepting large swings'),
                ],
            ],
            'drop_reaction' => [
                'question' => __('Your portfolio drops 20% in a market crash. What do you do?'),
                'options' => [
                    __('Sell everything to stop the losses'),
                    __('Sell some and move to safer assets'),
                    __('Hold and wait for recovery'),
                    __('Buy more at the lower prices'),
                ],
            ],
            'experience' => [
                'question' => __('How experienced are you with investing?'),
                'options' => [
                    __('I am new to investing'),
                    __('1–3 years of experience'),
                    __('3–10 years of experience'),
                    __('More than 10 years of experience'),
                ],
            ],
            'liquidity' => [
                'question' => __('How much of this portfolio might you need to withdraw within a year?'),
                'options' => [
                    __('More than half'),
                    __('Around a quarter'),
                    __('Around 10%'),
                    __('Almost none'),
                ],
            ],
            'target_return' => [
                'question' => __('What annual return are you aiming for?'),
                'options' => [
                    __('Around 4% — safety first'),
                    __('Around 7% — modest growth'),
                    __('Around 10% — solid growth'),
                    __('15% or more — high growth'),
                ],
            ],
            'contributions' => [
                'question' => __('Do you plan to add money to your portfolio regularly?'),
                'options' => [
                    __('Yes, monthly'),
                    __('Yes, every few months'),
                    __('Occasionally, with no fixed schedule'),
                    __('No, this is a one-time investment'),
                ],
            ],
            'base_currency' => [
                'question' => __('What is the primary currency you invest in?'),
                'options' => [
                    __('Saudi riyal (SAR)'),
                    __('US dollar (USD)'),
                    __('Euro (EUR)'),
                    __('Another currency'),
                ],
            ],
            'shariah' => [
                'question' => __('Do you require your investments to be Shariah-compliant?'),
                'options' => [
                    __('Yes, my portfolio must be fully Shariah-compliant'),
                    __('I prefer compliant investments while allowing a purification percentage'),
                    __('No requirement'),
                ],
            ],
        ];
    }

    public function next(): void
    {
        $key = array_keys($this->questions())[$this->step - 1];

        $this->validate(
            ["answers.$key" => ['required', 'integer', 'between:1,4']],
            ["answers.$key.required" => __('Please choose an answer to continue.')],
        );

        if ($this->step < count($this->questions())) {
            $this->step++;
        }
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function submit(PortfolioAnalyzer $analyzer): void
    {
        $rules = [];
        foreach (array_keys($this->questions()) as $key) {
            $rules["answers.$key"] = ['required', 'integer', 'between:1,4'];
        }
        $this->validate($rules);

        $riskAnswers = array_intersect_key($this->answers, array_flip(['age', 'horizon', 'goal', 'drop_reaction', 'experience', 'liquidity', 'target_return']));
        $riskAnswers['age'] = 5 - $riskAnswers['age'];
        $tolerance = RiskTolerance::fromQuestionnaireScore((int) array_sum($riskAnswers));

        $horizons = [1 => TimeHorizon::Short, 2 => TimeHorizon::Medium, 3 => TimeHorizon::Long, 4 => TimeHorizon::VeryLong];
        $liquidity = [1 => 'high', 2 => 'elevated', 3 => 'moderate', 4 => 'minimal'];
        $currencies = [1 => 'SAR', 2 => 'USD', 3 => 'EUR', 4 => 'other'];
        $contributions = [1 => 'monthly', 2 => 'quarterly', 3 => 'occasional', 4 => 'none'];

        Auth::user()->riskProfile()->updateOrCreate([], [
            'answers' => $this->answers,
            'risk_tolerance' => $tolerance,
            'time_horizon' => $horizons[$this->answers['horizon']],
            'target_return' => $tolerance->targetReturn(),
            'target_volatility' => $tolerance->targetVolatility(),
            'liquidity_needs' => $liquidity[$this->answers['liquidity']],
            'constraints' => [
                'shariah_required' => $this->answers['shariah'] === 1,
                'shariah_preferred' => $this->answers['shariah'] === 2,
                'base_currency' => $currencies[$this->answers['base_currency']],
                'contribution_frequency' => $contributions[$this->answers['contributions']],
            ],
        ]);

        $analyzer->analyze(Auth::user()->fresh());

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function with(): array
    {
        $questions = $this->questions();
        $key = array_keys($questions)[$this->step - 1];

        return [
            'totalSteps' => count($questions),
            'currentKey' => $key,
            'current' => $questions[$key],
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Investor Profile') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Ten quick questions build your Investment Policy Statement, so Mahafeth can judge whether your portfolio actually fits you.') }}
        </flux:text>
        @if (auth()->user()->riskProfile !== null)
            <flux:callout class="mt-4" color="zinc" icon="check-circle" inline>
                <flux:callout.text>
                    {{ __('Completed on :date — your previous answers are prefilled. Saving again updates your profile.', ['date' => auth()->user()->riskProfile->updated_at->isoFormat('LL')]) }}
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="xs" variant="ghost" icon="rocket-launch" :href="route('plan')" wire:navigate>
                        {{ __('View your investment plan') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    </div>

    @if (auth()->user()->riskProfile !== null)
        <livewire:investor-profile.goals />
    @endif

    <div class="card p-6">
        <div class="mb-6 flex items-center justify-between gap-4">
            <flux:text class="shrink-0 text-xs font-medium uppercase tracking-widest">
                {{ __('Question :current of :total', ['current' => $step, 'total' => $totalSteps]) }}</flux:text>
            <div class="flex w-full max-w-80 gap-1" dir="ltr">
                @for ($i = 1; $i <= $totalSteps; $i++)
                    <span
                        class="h-1 flex-1 rounded-full transition-colors duration-300 {{ $i <= $step ? 'bg-teal-600 dark:bg-teal-400' : 'bg-neutral-200 dark:bg-zinc-700' }}"></span>
                @endfor
            </div>
        </div>

        <flux:heading class="mb-4" size="lg">{{ $current['question'] }}</flux:heading>

        <flux:radio.group wire:model="answers.{{ $currentKey }}" variant="cards" :indicator="false"
            class="flex-col" x-data x-on:change="window.haptic?.(5)">
            @foreach ($current['options'] as $index => $option)
                <flux:radio :value="$index + 1" :label="$option" />
            @endforeach
        </flux:radio.group>

        <flux:error name="answers.{{ $currentKey }}" class="mt-2" />

        <div class="mt-6 flex items-center justify-between">
            <flux:button variant="ghost" wire:click="back" :disabled="$step === 1">{{ __('Back') }}</flux:button>

            @if ($step < $totalSteps)
                <flux:button variant="primary" wire:click="next">{{ __('Next') }}</flux:button>
            @else
                <flux:button variant="primary" wire:click="submit" wire:loading.attr="disabled">
                    {{ __('Save Profile') }}</flux:button>
            @endif
        </div>
    </div>
</div>
