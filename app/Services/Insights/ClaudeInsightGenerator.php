<?php

namespace App\Services\Insights;

use App\Contracts\InsightGenerator;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Generates insights via the Claude API (Messages endpoint) with a JSON
 * schema-constrained response, so the output always parses.
 */
class ClaudeInsightGenerator implements InsightGenerator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function generate(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale): array
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('mahafeth.ai.api_key'),
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout((int) config('mahafeth.ai.timeout'))
            ->connectTimeout(10)
            ->retry(2, 1000, throw: false)
            ->post(self::API_URL, [
                'model' => config('mahafeth.ai.model'),
                'max_tokens' => (int) config('mahafeth.ai.max_tokens'),
                'thinking' => ['type' => 'adaptive'],
                'system' => $this->systemPrompt(),
                'messages' => [
                    ['role' => 'user', 'content' => $this->buildPrompt($snapshot, $riskProfile, $locale)],
                ],
                'output_config' => [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $this->outputSchema(),
                    ],
                ],
            ])
            ->throw();

        return $this->parse($response->json());
    }

    /**
     * The user prompt: snapshot metrics, investor profile, and locale
     * instruction. Public so tests can assert its contents.
     */
    public function buildPrompt(PortfolioSnapshot $snapshot, ?RiskProfile $riskProfile, string $locale): string
    {
        $metrics = $snapshot->metrics ?? [];

        $profile = $riskProfile === null ? null : [
            'risk_tolerance' => $riskProfile->risk_tolerance->value,
            'time_horizon' => $riskProfile->time_horizon->value,
            'target_return' => $riskProfile->target_return,
            'target_volatility' => $riskProfile->target_volatility,
            'liquidity_needs' => $riskProfile->liquidity_needs,
            'constraints' => $riskProfile->constraints,
        ];

        $payload = json_encode([
            'as_of' => $snapshot->as_of->toDateString(),
            'total_value_sar' => $snapshot->total_value,
            'health_score' => $snapshot->health_score,
            'component_scores' => $snapshot->component_scores,
            'metrics' => $metrics,
            'investor_profile' => $profile,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $language = $locale === 'ar'
            ? 'Write every field of your response in Arabic (Modern Standard Arabic, natural financial register).'
            : 'Write every field of your response in English.';

        return <<<PROMPT
        Here is the investor's unified portfolio analysis:

        {$payload}

        {$language}

        Produce:
        1. "summary": an executive summary (3-5 sentences) explaining the overall health score, the single biggest problem, and the most important hidden risk. Reference concrete numbers.
        2. "recommendations": 3 to 5 prioritized, concretely actionable steps (each with "title", "body", and "priority" of high/medium/low). Recommendations must follow from the metrics — e.g. trimming the oversized position, moving toward the tangency allocation, adding low-correlation assets — and explain the expected effect on the health score.
        PROMPT;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are Mahafeth AI, the explanation layer of a portfolio analytics platform that aggregates a retail investor's accounts via Open Banking and evaluates them as one unified portfolio using institutional techniques (health scoring, diversification, correlation, VaR, efficient frontier).

        Your job is "from diagnosis to action": translate quantitative metrics into plain language a non-professional investor understands, explain *why* each issue matters, and propose concrete steps. Never invent numbers — only use values present in the provided data. Percentages in the data are decimal fractions (0.26 = 26%). This is educational analysis, not licensed financial advice; keep the tone factual and helpful without disclaimers.

        When the investor profile's constraints mark Shariah compliance as required (or preferred), never suggest instruments flagged non-compliant in the metrics, prioritize divesting the flagged positions listed under metrics.shariah.non_compliant_positions, and frame replacements using compliant alternatives already present in the data.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        ],
                        'required' => ['title', 'body', 'priority'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'recommendations'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{summary: string, recommendations: list<array{title: string, body: string, priority: string}>}
     */
    private function parse(?array $json): array
    {
        $text = collect($json['content'] ?? [])->firstWhere('type', 'text')['text'] ?? null;

        $decoded = $text !== null ? json_decode($text, true) : null;

        if (! is_array($decoded) || ! isset($decoded['summary'], $decoded['recommendations'])) {
            throw new RuntimeException('Unexpected response shape from the Claude API.');
        }

        return $decoded;
    }
}
