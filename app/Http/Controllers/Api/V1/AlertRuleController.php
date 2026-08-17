<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AlertRuleRequest;
use App\Models\AlertRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = $request->user()->alertRules()->get()->map(fn (AlertRule $rule): array => $this->present($rule));

        return response()->json(['data' => $rules]);
    }

    public function store(AlertRuleRequest $request): JsonResponse
    {
        $rule = $request->user()->alertRules()->create([
            'metric' => $request->validated('metric'),
            'threshold' => $this->normalizedThreshold($request->validated('metric'), $request->validated('threshold')),
            'enabled' => true,
        ]);

        return response()->json(['data' => $this->present($rule)], 201);
    }

    public function update(AlertRuleRequest $request, AlertRule $rule): JsonResponse
    {
        $this->authorizeRule($request, $rule);

        $rule->update([
            'metric' => $request->validated('metric'),
            'threshold' => $this->normalizedThreshold($request->validated('metric'), $request->validated('threshold')),
        ]);

        return response()->json(['data' => $this->present($rule)]);
    }

    public function toggle(Request $request, AlertRule $rule): JsonResponse
    {
        $this->authorizeRule($request, $rule);

        $rule->update(['enabled' => ! $rule->enabled]);

        return response()->json(['data' => $this->present($rule)]);
    }

    public function destroy(Request $request, AlertRule $rule): JsonResponse
    {
        $this->authorizeRule($request, $rule);

        $rule->delete();

        return response()->json(['data' => ['message' => __('Alert rule deleted.')]]);
    }

    private function normalizedThreshold(string $metric, float $threshold): float
    {
        return AlertRule::METRICS[$metric]['unit'] === 'percent' ? $threshold / 100 : $threshold;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AlertRule $rule): array
    {
        $unit = AlertRule::METRICS[$rule->metric]['unit'];

        return [
            'id' => $rule->id,
            'metric' => $rule->metric,
            'threshold' => $unit === 'percent' ? round($rule->threshold * 100, 1) : (int) round($rule->threshold),
            'enabled' => $rule->enabled,
        ];
    }

    private function authorizeRule(Request $request, AlertRule $rule): void
    {
        abort_unless($rule->user_id === $request->user()->id, 404);
    }
}
