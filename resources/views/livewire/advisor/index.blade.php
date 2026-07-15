<?php

use App\Actions\SendChatMessage;
use App\Jobs\GenerateChatReplyJob;
use App\Jobs\GenerateInsightsJob;
use App\Models\AiInsight;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Component;

new class extends Component {
    public string $message = '';

    public ?string $error = null;

    /**
     * The newest chat message id already shown, so the poll can tell a
     * fresh reply apart from a re-render and scroll the thread to it.
     */
    public int $lastChatMessageId = 0;

    /**
     * Length of the streamed partial reply already shown, so the thread
     * keeps scrolling while the answer is being written.
     */
    public int $lastPartialLength = 0;

    /**
     * A question seeded through the "Ask Mahafeth AI" deep-link (?ask=...)
     * is persisted and queued during mount, so navigation from a holding,
     * filing, or news item renders straight into a live conversation with
     * the typing indicator already showing. Cache::add is atomic, so a
     * refresh, back/forward replay, or second tab of the same link within
     * five minutes cannot duplicate the question.
     */
    public function mount(SendChatMessage $sendChatMessage): void
    {
        $ask = mb_substr(trim((string) request()->query('ask')), 0, 1000);

        if ($ask !== ''
            && Auth::user()->latestSnapshot() !== null
            && ! $this->isAwaitingReply()
            && Cache::add('chat:ask-lock:'.Auth::id().':'.sha1($ask), true, now()->addMinutes(5))) {
            $sendChatMessage->handle(Auth::user(), $ask, app()->getLocale());
        }

        $this->lastChatMessageId = (int) Auth::user()->chatMessages()->max('id');
    }

    /**
     * Queue insight generation, mirroring the dashboard card, so the
     * Advisor page is self-sufficient.
     */
    public function generate(): void
    {
        // Nothing to explain before the first analysis.
        if (Auth::user()->latestSnapshot() === null) {
            return;
        }

        GenerateInsightsJob::request(Auth::user(), app()->getLocale());
    }

    public function send(SendChatMessage $sendChatMessage): void
    {
        if ($this->sendContent($sendChatMessage, trim($this->message))) {
            $this->message = '';
        }
    }

    /**
     * Re-queue the reply for the last unanswered message after a failure,
     * so the user does not have to retype it.
     */
    public function retry(): void
    {
        $user = Auth::user();

        if (! Cache::has(GenerateChatReplyJob::failedCacheKey($user))
            || $user->latestSnapshot() === null
            || $user->chatMessages()->latest('id')->first()?->role !== 'user') {
            return;
        }

        $this->error = null;
        Cache::forget(GenerateChatReplyJob::failedCacheKey($user));
        Cache::forget(GenerateChatReplyJob::partialCacheKey($user));
        Cache::put(GenerateChatReplyJob::awaitingCacheKey($user), true, now()->addMinutes(5));
        GenerateChatReplyJob::dispatch($user, app()->getLocale());
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
        Cache::forget(GenerateChatReplyJob::awaitingCacheKey(Auth::user()));
        Cache::forget(GenerateChatReplyJob::failedCacheKey(Auth::user()));
        Cache::forget(GenerateChatReplyJob::partialCacheKey(Auth::user()));
        $this->error = null;
    }

    /**
     * Persist the message and queue its reply; returns whether it was
     * accepted, so callers keep the draft when it was not.
     */
    private function sendContent(SendChatMessage $sendChatMessage, string $content): bool
    {
        $this->error = null;

        if ($content === '') {
            return false;
        }

        if ($this->isAwaitingReply()) {
            $this->error = __('Mahafeth AI is still answering — please wait for the reply to finish.');

            return false;
        }

        if (mb_strlen($content) > 1000) {
            $this->error = __('That message is too long — please keep it under 1,000 characters.');

            return false;
        }

        if (Auth::user()->latestSnapshot() === null) {
            $this->error = __('Connect your accounts and run an analysis first — then I can answer questions about your portfolio.');

            return false;
        }

        $sendChatMessage->handle(Auth::user(), $content, app()->getLocale());

        return true;
    }

    private function isAwaitingReply(): bool
    {
        return Cache::has(GenerateChatReplyJob::awaitingCacheKey(Auth::user()));
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
        $isGenerating = Cache::has(GenerateInsightsJob::cacheKey(Auth::user(), app()->getLocale()));
        $isAwaitingReply = $this->isAwaitingReply();
        $messages = Auth::user()->chatMessages()->oldest('id')->get();
        $partialReply = $isAwaitingReply
            ? (string) Cache::get(GenerateChatReplyJob::partialCacheKey(Auth::user()), '')
            : '';

        // A new message since the last render (own send or a reply landed
        // via the poll) scrolls the thread to the bottom; so does the
        // streamed partial as it grows.
        $latestMessageId = (int) ($messages->last()->id ?? 0);
        if ($latestMessageId !== $this->lastChatMessageId || mb_strlen($partialReply) !== $this->lastPartialLength) {
            $this->lastChatMessageId = $latestMessageId;
            $this->lastPartialLength = mb_strlen($partialReply);
            $this->dispatch('chat-updated');
        }

        return [
            'hasSnapshot' => $snapshot !== null,
            'insight' => $insight,
            'isGenerating' => $isGenerating,
            'hasFailed' => ! $isGenerating && Cache::has(GenerateInsightsJob::failedCacheKey(Auth::user(), app()->getLocale())),
            'isAwaitingReply' => $isAwaitingReply,
            'partialReply' => $partialReply,
            'chatFailed' => ! $isAwaitingReply && Cache::has(GenerateChatReplyJob::failedCacheKey(Auth::user())),
            // Same-day re-analysis updates the snapshot row in place, so an
            // insight older than its snapshot explains numbers that changed.
            'isStale' => $insight !== null && $insight->updated_at->lt($snapshot->updated_at),
            'messages' => $messages,
            'starters' => $this->starters(),
        ];
    }
}; ?>

{{-- Fixed height on mobile stretches the thread so the input sits at the
     bottom of the screen like a native chat. 10.5rem = header (3.5rem) +
     main top padding (1rem) + main bottom padding (6rem, which clears the
     bottom nav), so the card ends on the same line as every other page;
     the safe-area insets cover the PWA's status bar and home indicator.
     No stagger-children here: this element re-renders on wire:poll while
     a reply generates, and the entrance animation would replay on every
     poll, making messages blink in and out. --}}
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6 max-lg:h-[calc(100dvh-10.5rem-env(safe-area-inset-top)-env(safe-area-inset-bottom))] max-lg:overflow-y-auto"
    @if ($isAwaitingReply) wire:poll.1s @elseif ($isGenerating) wire:poll.2s @endif>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('AI Advisor') }}</flux:heading>
            <flux:text class="mt-1 text-balance">
                {{ __('Ask anything about your unified portfolio — answers are grounded in your own numbers.') }}
            </flux:text>
        </div>
        @if ($messages->isNotEmpty())
            {{-- Disabled mid-generation: clearing then would let the queued
                 reply land in an emptied thread. --}}
            <flux:button size="sm" variant="subtle" icon="trash" wire:click="clearChat"
                wire:confirm="{{ __('Clear the whole conversation?') }}" :disabled="$isAwaitingReply">
                {{ __('Clear conversation') }}</flux:button>
        @endif
    </div>

    @if (! $hasSnapshot)
        <div class="flex flex-col items-center gap-4 card-cta p-12 text-center">
            <flux:text class="max-w-64 text-sm">
                {{ __('Connect your accounts and Mahafeth AI will explain your portfolio in plain language.') }}
            </flux:text>
            <flux:button size="sm" variant="primary" :href="route('connections')" wire:navigate>
                {{ __('Connect accounts') }}</flux:button>
        </div>
    @else
        {{-- Insight entry point: summary and recommendations to discuss --}}
        @if ($hasFailed)
            <flux:callout color="red" icon="exclamation-triangle" inline>
                <flux:callout.text>
                    {{ __('Insight generation failed — please try again.') }}
                    <flux:link class="cursor-pointer" wire:click="generate">{{ __('Regenerate') }}</flux:link>
                </flux:callout.text>
            </flux:callout>
        @endif

        {{-- Collapsed by default: people open this page for the chat, so
             the insight is one slim row until asked for. --}}
        @if ($insight !== null)
            <div class="card px-5 py-1">
                <flux:accordion transition>
                    <flux:accordion.item>
                        <flux:accordion.heading>
                            <span class="flex items-center gap-2 text-teal-700 dark:text-teal-300">
                                <flux:icon.light-bulb class="size-4 shrink-0" />
                                {{ __('Insight & action plan') }}
                                <flux:badge size="sm">{{ count($insight->recommendations) }}</flux:badge>
                                @if ($isStale && ! $isGenerating)
                                    <flux:badge size="sm" color="amber">{{ __('Outdated') }}</flux:badge>
                                @endif
                            </span>
                        </flux:accordion.heading>

                        <flux:accordion.content>
                            {{-- On phones the expanded insight scrolls inside a
                                 capped region so it can never push the chat off
                                 screen; pb-4 compensates for the card's slim py-1. --}}
                            <div class="pb-4 max-lg:max-h-[45dvh] max-lg:overflow-y-auto">
                                @if ($isStale && ! $isGenerating)
                                    <flux:callout class="mt-3" color="amber" icon="exclamation-triangle" inline>
                                        <flux:callout.text>
                                            {{ __('Your analysis has changed since this was generated.') }}
                                            <flux:link class="cursor-pointer" wire:click="generate">{{ __('Regenerate') }}</flux:link>
                                        </flux:callout.text>
                                    </flux:callout>
                                @endif

                                <flux:callout class="mt-3" color="teal" icon="light-bulb">
                                    <flux:callout.heading>{{ __('Executive Summary') }}</flux:callout.heading>
                                    <flux:callout.text>{{ $insight->summary }}</flux:callout.text>
                                </flux:callout>

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
                                                <flux:accordion class="mt-2" transition>
                                                    <flux:accordion.item>
                                                        <flux:accordion.heading>
                                                            <span class="text-xs font-medium text-teal-700 dark:text-teal-300">
                                                                {{ __('Show the math') }}</span>
                                                        </flux:accordion.heading>
                                                        <flux:accordion.content>
                                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                                @foreach ($recommendation['evidence'] as $evidence)
                                                                    {{-- dir=auto keeps Arabic metric names reading right-to-left
                                                                         while the LTR span stops signs and % from shuffling. --}}
                                                                    <flux:badge size="sm" dir="auto">
                                                                        {{ $evidence['metric'] }}: <span dir="ltr">{{ $evidence['value'] }}</span></flux:badge>
                                                                @endforeach
                                                            </div>
                                                        </flux:accordion.content>
                                                    </flux:accordion.item>
                                                </flux:accordion>
                                            @endif

                                            <flux:button class="mt-3 self-start" size="sm" icon="chat-bubble-oval-left"
                                                wire:click="discuss({{ $index }})" wire:loading.attr="disabled"
                                                :disabled="$isAwaitingReply">
                                                {{ __('Discuss this') }}</flux:button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            </div>
        @elseif ($isGenerating)
            <div class="flex flex-col items-center gap-3 card-cta p-10 text-center">
                <flux:icon.loading class="size-6 text-teal-700 dark:text-teal-300" />
                <flux:text class="text-sm">{{ __('Analyzing your portfolio…') }}</flux:text>
                <flux:text class="max-w-56 text-xs">
                    {{ __('This runs in the background — feel free to keep browsing.') }}</flux:text>
            </div>
        @else
            <div class="flex flex-col items-center gap-4 card-cta p-10 text-center">
                <flux:text class="max-w-72 text-sm">
                    {{ __('Generate insights first to start a conversation grounded in your portfolio.') }}
                </flux:text>
                <flux:button variant="primary" icon="sparkles" wire:click="generate" wire:loading.attr="disabled"
                    :disabled="$isGenerating">
                    {{ __('Generate Insights') }}</flux:button>
            </div>
        @endif

        {{-- Chat --}}
        <div class="flex flex-col card max-lg:min-h-0 max-lg:flex-1">
            {{-- Only pin to the bottom when a conversation exists; doing it on
                 the empty state scrolled the starter chips' title out of view. --}}
            {{-- The mobile min-h floor keeps the thread usable when the insight
                 accordion is open; the page root scrolls for the overflow. --}}
            <div class="min-h-40 space-y-3 overflow-y-auto p-5 max-lg:flex-1 lg:max-h-[70vh]" x-data
                x-init="@if ($messages->isNotEmpty()) $el.scrollTop = $el.scrollHeight @endif"
                @chat-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
                @forelse ($messages as $chatMessage)
                    <div wire:key="chat-message-{{ $chatMessage->id }}" wire:transition @class([
                        'w-fit max-w-[85%] rounded-2xl px-4 py-2.5 text-sm',
                        'ms-auto bg-teal-600 text-white dark:bg-teal-500' => $chatMessage->role === 'user',
                        'me-auto bg-neutral-100 text-neutral-800 dark:bg-zinc-800 dark:text-neutral-100' => $chatMessage->role !== 'user',
                    ])>
                        @if ($chatMessage->role === 'user')
                            <p class="whitespace-pre-wrap break-words" dir="auto">{{ $chatMessage->content }}</p>
                        @else
                            {{-- Model output rendered as Markdown: html_input strip
                                 removes any raw HTML and unsafe links are blocked,
                                 so a prompt-injected reply cannot become XSS. --}}
                            {{-- break-words keeps long unbreakable tokens (URLs from
                                 web-search answers) inside the bubble. --}}
                            <div class="break-words [&_li]:mt-1 [&_ol]:list-decimal [&_ol]:ps-5 [&_p:not(:last-child)]:mb-2 [&_strong]:font-semibold [&_ul]:list-disc [&_ul]:ps-5"
                                dir="auto">
                                {!! Illuminate\Support\Str::markdown($chatMessage->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="flex h-full flex-col items-center justify-center gap-3 py-6 text-center">
                        <flux:text class="text-sm">
                            {{ __('Start with one of these, or ask your own question.') }}</flux:text>
                        <x-scroll-hint class="w-full lg:hidden" surface="card">
                            <div data-scroll-area class="flex w-full gap-2 overflow-x-auto pb-1 scrollbar-thin">
                                @foreach ($starters as $index => $starter)
                                    <flux:button class="shrink-0 whitespace-nowrap" size="sm" wire:loading.attr="disabled"
                                        :disabled="$isAwaitingReply" wire:click="ask({{ $index }})">{{ $starter }}</flux:button>
                                @endforeach
                            </div>
                        </x-scroll-hint>
                        <div class="hidden w-full flex-wrap justify-center gap-2 lg:flex">
                            @foreach ($starters as $index => $starter)
                                <flux:button class="whitespace-nowrap" size="sm" wire:loading.attr="disabled"
                                    :disabled="$isAwaitingReply" wire:click="ask({{ $index }})">{{ $starter }}</flux:button>
                            @endforeach
                        </div>
                    </div>
                @endforelse

                {{-- The reply is composed by a queued job; the flag-driven
                     indicator survives navigation and page refreshes,
                     unlike wire:loading which only covers the request. Once
                     the model starts writing, the streamed partial replaces
                     the dots and grows with every poll. --}}
                @if ($isAwaitingReply)
                    @if ($partialReply !== '')
                        <div class="me-auto w-fit max-w-[85%] rounded-2xl bg-neutral-100 px-4 py-2.5 text-sm text-neutral-800 dark:bg-zinc-800 dark:text-neutral-100">
                            <div class="break-words [&_li]:mt-1 [&_ol]:list-decimal [&_ol]:ps-5 [&_p:not(:last-child)]:mb-2 [&_strong]:font-semibold [&_ul]:list-disc [&_ul]:ps-5"
                                dir="auto">
                                {!! Illuminate\Support\Str::markdown($partialReply, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                            </div>
                            <span class="mt-1 inline-block size-2 animate-pulse rounded-full bg-teal-500"
                                aria-hidden="true"></span>
                            <span class="sr-only">{{ __('Mahafeth AI is thinking…') }}</span>
                        </div>
                    @else
                        <div class="me-auto flex max-w-[85%] items-center gap-1.5 rounded-2xl bg-neutral-100 px-4 py-3 dark:bg-zinc-800">
                            <span class="size-1.5 animate-bounce rounded-full bg-neutral-400 [animation-delay:-0.3s]"></span>
                            <span class="size-1.5 animate-bounce rounded-full bg-neutral-400 [animation-delay:-0.15s]"></span>
                            <span class="size-1.5 animate-bounce rounded-full bg-neutral-400"></span>
                            <span class="sr-only">{{ __('Mahafeth AI is thinking…') }}</span>
                        </div>
                    @endif
                @endif
            </div>

            @if ($chatFailed)
                <div class="px-5 pb-3">
                    <flux:callout color="red" icon="exclamation-triangle" inline>
                        <flux:callout.text>
                            {{ __('The assistant could not be reached — your message was not lost, please try sending it again.') }}
                            <flux:link class="cursor-pointer" wire:click="retry">{{ __('Retry') }}</flux:link>
                        </flux:callout.text>
                    </flux:callout>
                </div>
            @endif

            @if ($error !== null)
                <div class="px-5 pb-3">
                    <flux:callout color="red" icon="exclamation-triangle" inline>
                        <flux:callout.text>{{ $error }}</flux:callout.text>
                    </flux:callout>
                </div>
            @endif

            <div class="border-t border-neutral-200 p-3 dark:border-neutral-700">
                {{-- Enter sends, Shift+Enter adds a newline. --}}
                <flux:composer wire:model="message" :placeholder="__('Ask about your portfolio…')" maxlength="1000"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send(); }">
                    <x-slot:actionsTrailing>
                        <flux:button variant="primary" size="sm" icon="paper-airplane" wire:click="send"
                            wire:loading.attr="disabled" :disabled="$isAwaitingReply" :aria-label="__('Send')" />
                    </x-slot:actionsTrailing>
                </flux:composer>
                <flux:text class="mt-2 text-center text-xs">
                    {{ __('AI-generated analysis can be inaccurate — not licensed financial advice.') }}</flux:text>
            </div>
        </div>
    @endif
</div>

@script
<script>
    // The ?ask= question is already persisted during mount; drop it from
    // the address bar right away so a refresh or copied link is clean.
    if (new URLSearchParams(window.location.search).has('ask')) {
        window.history.replaceState({}, '', @js(route('advisor')));
    }
</script>
@endscript
