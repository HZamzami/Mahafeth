<?php

namespace App\Services\Insights;

use App\Contracts\ChatResponder;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Answers advisor chat messages via the Claude API (Messages endpoint),
 * grounded in the same portfolio payload the insight generator uses.
 */
class ClaudeChatResponder implements ChatResponder
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function __construct(private PortfolioContext $context) {}

    public function respond(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals, array $history): string
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('mahafeth.ai.api_key'),
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout((int) config('mahafeth.ai.chat_timeout'))
            ->connectTimeout(10)
            ->retry(2, 1000, throw: false)
            ->post(self::API_URL, [
                // Sonnet balances answer quality against chat latency while
                // insights keep the larger model; adaptive thinking at
                // medium effort keeps replies fast without Haiku-level
                // guesswork.
                'model' => config('mahafeth.ai.chat_model'),
                'max_tokens' => (int) config('mahafeth.ai.chat_max_tokens'),
                'thinking' => ['type' => 'adaptive'],
                'output_config' => ['effort' => 'medium'],
                // Server-side web search grounds questions about current
                // prices, news, and company facts that the snapshot payload
                // cannot answer; capped so one message stays cheap.
                'tools' => [['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 3]],
                'system' => $this->systemBlocks($snapshot, $riskProfile, $locale, $goals),
                'messages' => $history,
            ])
            ->throw();

        // With web search the content interleaves thinking, server_tool_use,
        // and web_search_tool_result blocks between text blocks; the reply
        // is the concatenation of every text block.
        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        if ($text === '') {
            throw new RuntimeException('Unexpected response shape from the Claude API.');
        }

        return $text;
    }

    /**
     * Persona block plus the portfolio payload. The payload block is
     * byte-stable across turns within a snapshot, so cache_control gives
     * multi-turn chats prompt-cache hits.
     *
     * @return list<array<string, mixed>>
     */
    private function systemBlocks(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale, array $goals): array
    {
        $language = $locale === 'ar'
            ? 'Reply in Arabic (Modern Standard Arabic, natural financial register).'
            : 'Reply in English.';

        $asOf = $snapshot->as_of->toDateString();

        $persona = <<<PROMPT
        You are Mahafeth AI, the conversational advisor of a portfolio analytics platform that aggregates a retail investor's accounts via Open Banking and evaluates them as one unified portfolio using institutional techniques (health scoring, diversification, correlation, VaR, efficient frontier).

        You are chatting with the investor about their own portfolio, which is provided below. Answer conversationally and concisely (a short paragraph or a few bullet points), referencing concrete numbers from the data. You may format with simple Markdown (bold and bullet lists only — no headings, tables, links, or code blocks). Never invent numbers — only use values present in the provided data. Percentages in the data are decimal fractions (0.26 = 26%). This is educational analysis, not licensed financial advice; keep the tone factual and helpful without disclaimers.

        The portfolio analysis below is as of {$asOf} — treat its figures as of that date, not as live quotes. You have a web search tool: use it when the investor asks about current prices, market news, or company facts that are not present in the provided data, instead of answering from memory. Do not search for what the payload already answers.

        When the investor profile's constraints mark Shariah compliance as required (or preferred), never suggest instruments flagged non-compliant in the metrics, and frame replacements using compliant alternatives already present in the data.

        {$language}
        PROMPT;

        return [
            ['type' => 'text', 'text' => $persona],
            [
                'type' => 'text',
                'text' => "The investor's unified portfolio analysis:\n\n".$this->context->payload($snapshot, $riskProfile, $goals),
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];
    }
}
