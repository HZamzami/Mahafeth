<?php

use App\Actions\SendChatMessage;
use App\Jobs\GenerateInsightsJob;
use App\Models\AiInsight;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Component;

new class extends Component {
    public string $message = '';

    public ?string $error = null;

    /**
     * A question seeded through the "Ask Mahafeth AI" deep-link
     * (?ask=...), sent right after the page renders so navigation from a
     * filing or news item lands in a live conversation.
     */
    public ?string $pending = null;

    public function mount(): void
    {
        $ask = mb_substr(trim((string) request()->query('ask')), 0, 1000);

        $this->pending = $ask === '' ? null : $ask;
    }

    public function sendPending(SendChatMessage $sendChatMessage): void
    {
        if ($this->pending !== null) {
            $this->sendContent($sendChatMessage, $this->pending);
            $this->pending = null;

            // Drop ?ask= from the address bar, otherwise a page refresh
            // re-sends the seeded question.
            $this->js("window.history.replaceState({}, '', '".route('advisor')."')");
        }
    }

    /**
     * Queue insight generation, mirroring the dashboard card, so the
     * Advisor page is self-sufficient.
     */
    public function generate(): void
    {
        $user = Auth::user();
        $locale = app()->getLocale();

        Cache::put(GenerateInsightsJob::cacheKey($user, $locale), true, now()->addMinutes(5));
        GenerateInsightsJob::dispatch($user, $locale);
    }

    public function send(SendChatMessage $sendChatMessage): void
    {
        $this->sendContent($sendChatMessage, trim($this->message));
        $this->message = '';
    }

    /**
     * Starter chips seed the chat with a suggested question.
     */
    public function ask(SendChatMessage $sendChatMessage, int $index): void
    {
        $question = $this->starters()[$index] ?? null;

        if ($question !== null) {
            $this->sendContent($sendChatMessage, $question);
        }
    }

    /**
     * @return list<string>
     */
    private function starters(): array
    {
        return [
            __('Why is my health score :score?', ['score' => Auth::user()->latestSnapshot()?->health_score ?? '—']),
            __('What is my biggest hidden risk?'),
            __('How can I improve my diversification?'),
        ];
    }

    /**
     * "Discuss this" on a recommendation seeds the chat with it.
     */
    public function discuss(SendChatMessage $sendChatMessage, int $index): void
    {
        $recommendation = $this->latestInsight()?->recommendations[$index] ?? null;

        if ($recommendation === null) {
            return;
        }

        $this->sendContent($sendChatMessage, __('I\'d like to discuss this recommendation: ":title". How do I do this?', [
            'title' => $recommendation['title'],
        ]));
    }

    public function clearChat(): void
    {
        Auth::user()->chatMessages()->delete();
    }

    private function sendContent(SendChatMessage $sendChatMessage, string $content): void
    {
        $this->error = null;

        if ($content === '') {
            return;
        }

        if (mb_strlen($content) > 1000) {
            $this->error = __('That message is too long — please keep it under 1,000 characters.');

            return;
        }

        if (Auth::user()->latestSnapshot() === null) {
            $this->error = __('Connect your accounts and run an analysis first — then I can answer questions about your portfolio.');

            return;
        }

        try {
            $sendChatMessage->handle(Auth::user(), $content, app()->getLocale());
        } catch (\Throwable $exception) {
            report($exception);
            $this->error = __('The assistant could not be reached — your message was not lost, please try sending it again.');
        }

        $this->dispatch('chat-updated');
    }

    private function latestInsight(): ?AiInsight
    {
        $snapshot = Auth::user()->latestSnapshot();

        return $snapshot === null ? null : AiInsight::query()
            ->where('portfolio_snapshot_id', $snapshot->id)
            ->where('locale', app()->getLocale())
            ->first();
    }

    public function with(): array
    {
        $snapshot = Auth::user()->latestSnapshot();
        $insight = $this->latestInsight();

        return [
            'hasSnapshot' => $snapshot !== null,
            'insight' => $insight,
            'isGenerating' => Cache::has(GenerateInsightsJob::cacheKey(Auth::user(), app()->getLocale())),
            // Same-day re-analysis updates the snapshot row in place, so an
            // insight older than its snapshot explains numbers that changed.
            'isStale' => $insight !== null && $insight->updated_at->lt($snapshot->updated_at),
            'messages' => Auth::user()->chatMessages()->oldest('id')->get(),
            'starters' => $this->starters(),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6"
    @if ($isGenerating) wire:poll.3s @endif @if ($pending !== null) wire:init="sendPending" @endif>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('AI Advisor') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Ask anything about your unified portfolio — answers are grounded in your own numbers.') }}
            </flux:text>
        </div>
        @if ($messages->isNotEmpty())
            <flux:button size="sm" variant="subtle" icon="trash" wire:click="clearChat"
                wire:confirm="{{ __('Clear the whole conversation?') }}">
                {{ __('Clear conversation') }}</flux:button>
        @endif
    </div>

    @if (! $hasSnapshot)
        <div class="flex flex-col items-center gap-4 card p-12 text-center">
            <flux:text class="max-w-64 text-sm">
                {{ __('Connect your accounts and Mahafeth AI will explain your portfolio in plain language.') }}
            </flux:text>
            <flux:button size="sm" variant="primary" :href="route('connections')" wire:navigate>
                {{ __('Connect accounts') }}</flux:button>
        </div>
    @else
        {{-- Insight entry point: summary and recommendations to discuss --}}
        @if ($insight !== null)
            <div class="card space-y-4 p-5">
                @if ($isStale && ! $isGenerating)
                    <flux:callout color="amber" icon="exclamation-triangle" inline>
                        <flux:callout.text>
                            {{ __('Your analysis has changed since this was generated.') }}
                            <flux:link class="cursor-pointer" wire:click="generate">{{ __('Regenerate') }}</flux:link>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <flux:callout color="blue" icon="light-bulb">
                    <flux:callout.heading>{{ __('Executive Summary') }}</flux:callout.heading>
                    <flux:callout.text>{{ $insight->summary }}</flux:callout.text>
                </flux:callout>

                <details class="group">
                    <summary
                        class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-teal-700 hover:underline dark:text-teal-300">
                        <flux:icon.chevron-down class="size-4 transition-transform group-open:rotate-180" />
                        {{ __('View the action plan') }}
                        <flux:badge size="sm">{{ count($insight->recommendations) }}</flux:badge>
                    </summary>

                    <div class="mt-3 space-y-3">
                        @foreach ($insight->recommendations as $index => $recommendation)
                        <div
                            class="flex flex-col rounded-lg border border-neutral-200/60 bg-neutral-50 p-3 dark:border-neutral-700/60 dark:bg-zinc-800/50">
                            <div class="flex items-start justify-between gap-2">
                                <flux:heading size="sm">{{ $recommendation['title'] }}</flux:heading>
                                <flux:badge size="sm" inset="top bottom"
                                    :color="['high' => 'red', 'medium' => 'amber', 'low' => 'zinc'][$recommendation['priority']] ?? 'zinc'">
                                    {{ __(ucfirst($recommendation['priority'])) }}</flux:badge>
                            </div>
                            <flux:text class="mt-1 text-sm">{{ $recommendation['body'] }}</flux:text>

                            @if (($recommendation['evidence'] ?? []) !== [])
                                <details class="mt-2">
                                    <summary
                                        class="cursor-pointer text-xs font-medium text-teal-700 hover:underline dark:text-teal-300">
                                        {{ __('Show the math') }}</summary>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach ($recommendation['evidence'] as $evidence)
                                            {{-- dir=auto keeps Arabic metric names reading right-to-left
                                                 while the LTR span stops signs and % from shuffling. --}}
                                            <flux:badge size="sm" dir="auto">
                                                {{ $evidence['metric'] }}: <span dir="ltr">{{ $evidence['value'] }}</span></flux:badge>
                                        @endforeach
                                    </div>
                                </details>
                            @endif

                            <flux:button class="mt-3 self-start" size="sm" icon="chat-bubble-oval-left"
                                wire:click="discuss({{ $index }})" wire:loading.attr="disabled">
                                {{ __('Discuss this') }}</flux:button>
                        </div>
                        @endforeach
                    </div>
                </details>
            </div>
        @elseif ($isGenerating)
            <div class="flex flex-col items-center gap-3 card p-10 text-center">
                <flux:icon.loading class="size-6 text-teal-700 dark:text-teal-300" />
                <flux:text class="text-sm">{{ __('Analyzing your portfolio…') }}</flux:text>
                <flux:text class="max-w-56 text-xs">
                    {{ __('This runs in the background — feel free to keep browsing.') }}</flux:text>
            </div>
        @else
            <div class="flex flex-col items-center gap-4 card p-10 text-center">
                <flux:text class="max-w-72 text-sm">
                    {{ __('Generate insights first to start a conversation grounded in your portfolio.') }}
                </flux:text>
                <flux:button variant="primary" icon="sparkles" wire:click="generate" wire:loading.attr="disabled">
                    {{ __('Generate Insights') }}</flux:button>
            </div>
        @endif

        {{-- Chat --}}
        <div class="flex flex-col card">
            <div class="max-h-[55vh] min-h-40 space-y-3 overflow-y-auto p-5" x-data
                x-init="$el.scrollTop = $el.scrollHeight"
                @chat-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
                @forelse ($messages as $chatMessage)
                    <div @class([
                        'w-fit max-w-[85%] rounded-2xl px-4 py-2.5 text-sm',
                        'ms-auto bg-teal-600 text-white dark:bg-teal-500' => $chatMessage->role === 'user',
                        'me-auto bg-neutral-100 text-neutral-800 dark:bg-zinc-800 dark:text-neutral-100' => $chatMessage->role !== 'user',
                    ])>
                        @if ($chatMessage->role === 'user')
                            <p class="whitespace-pre-wrap" dir="auto">{{ $chatMessage->content }}</p>
                        @else
                            {{-- Model output rendered as Markdown: html_input strip
                                 removes any raw HTML and unsafe links are blocked,
                                 so a prompt-injected reply cannot become XSS. --}}
                            <div class="[&_li]:mt-1 [&_ol]:list-decimal [&_ol]:ps-5 [&_p:not(:last-child)]:mb-2 [&_strong]:font-semibold [&_ul]:list-disc [&_ul]:ps-5"
                                dir="auto">
                                {!! Illuminate\Support\Str::markdown($chatMessage->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-3 py-6 text-center">
                        <flux:text class="text-sm">
                            {{ __('Start with one of these, or ask your own question.') }}</flux:text>
                        <div class="flex flex-wrap justify-center gap-2">
                            @foreach ($starters as $index => $starter)
                                <flux:button size="sm" wire:loading.attr="disabled"
                                    wire:click="ask({{ $index }})">{{ $starter }}</flux:button>
                            @endforeach
                        </div>
                    </div>
                @endforelse

                <div wire:loading.flex wire:target="send, ask, discuss"
                    class="me-auto max-w-[85%] items-center gap-1.5 rounded-2xl bg-neutral-100 px-4 py-3 dark:bg-zinc-800">
                    <span class="size-1.5 animate-bounce rounded-full bg-neutral-400 [animation-delay:-0.3s]"></span>
                    <span class="size-1.5 animate-bounce rounded-full bg-neutral-400 [animation-delay:-0.15s]"></span>
                    <span class="size-1.5 animate-bounce rounded-full bg-neutral-400"></span>
                    <span class="sr-only">{{ __('Mahafeth AI is thinking…') }}</span>
                </div>
            </div>

            @if ($error !== null)
                <div class="px-5 pb-3">
                    <flux:callout color="red" icon="exclamation-triangle" inline>
                        <flux:callout.text>{{ $error }}</flux:callout.text>
                    </flux:callout>
                </div>
            @endif

            <div class="flex items-center gap-2 border-t border-neutral-200 p-3 dark:border-neutral-700">
                <flux:input class="grow" wire:model="message" wire:keydown.enter="send"
                    :placeholder="__('Ask about your portfolio…')" maxlength="1000" />
                <flux:button variant="primary" icon="paper-airplane" wire:click="send"
                    wire:loading.attr="disabled" :aria-label="__('Send')" />
            </div>
        </div>
    @endif
</div>
