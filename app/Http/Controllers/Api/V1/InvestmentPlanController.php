<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GenerateInvestmentPlanRequest;
use App\Models\InvestmentPlan;
use App\Services\Analytics\InvestmentPlanBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestmentPlanController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = InvestmentPlan::where('user_id', $user->id)->first();

        return response()->json([
            'data' => [
                'has_risk_profile' => $user->riskProfile !== null,
                'plan' => $plan === null ? null : $this->present($plan),
            ],
        ]);
    }

    public function store(GenerateInvestmentPlanRequest $request, InvestmentPlanBuilder $builder): JsonResponse
    {
        $user = $request->user();

        if ($user->riskProfile === null) {
            return response()->json(['message' => __('Complete your investor profile before generating a plan.')], 422);
        }

        $amount = $request->validated('amount');
        $monthlyContribution = $request->validated('monthly_contribution');

        $built = $builder->build($user, $amount, $monthlyContribution);

        if ($built === null) {
            return response()->json(['message' => __('Not enough market data to build a plan right now.')], 422);
        }

        $plan = InvestmentPlan::updateOrCreate(
            ['user_id' => $user->id],
            ['amount' => $amount, 'monthly_contribution' => $monthlyContribution, ...$built],
        );

        return response()->json(['data' => $this->present($plan)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(InvestmentPlan $plan): array
    {
        return [
            'amount' => $plan->amount,
            'monthly_contribution' => $plan->monthly_contribution,
            'weights' => $plan->weights,
            'orders' => $plan->orders,
            'metrics' => $plan->metrics,
            'forecast' => $plan->forecast,
        ];
    }
}
