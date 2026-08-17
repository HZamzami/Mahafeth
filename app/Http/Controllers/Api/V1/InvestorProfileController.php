<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RiskTolerance;
use App\Enums\TimeHorizon;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubmitInvestorProfileRequest;
use App\Jobs\GenerateInsightsJob;
use App\Models\RiskProfile;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestorProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->riskProfile;

        if ($profile === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->present($profile)]);
    }

    public function update(SubmitInvestorProfileRequest $request, PortfolioAnalyzer $analyzer): JsonResponse
    {
        $user = $request->user();
        $answers = $request->answers();

        $riskAnswers = array_intersect_key($answers, array_flip(['age', 'horizon', 'goal', 'drop_reaction', 'experience', 'liquidity', 'target_return']));
        $riskAnswers['age'] = 5 - $riskAnswers['age'];
        $tolerance = RiskTolerance::fromQuestionnaireScore((int) array_sum($riskAnswers));

        $horizons = [1 => TimeHorizon::Short, 2 => TimeHorizon::Medium, 3 => TimeHorizon::Long, 4 => TimeHorizon::VeryLong];
        $liquidity = [1 => 'high', 2 => 'elevated', 3 => 'moderate', 4 => 'minimal'];
        $currencies = [1 => 'SAR', 2 => 'USD', 3 => 'EUR', 4 => 'other'];
        $contributions = [1 => 'monthly', 2 => 'quarterly', 3 => 'occasional', 4 => 'none'];

        $profile = $user->riskProfile()->updateOrCreate([], [
            'answers' => $answers,
            'risk_tolerance' => $tolerance,
            'time_horizon' => $horizons[$answers['horizon']],
            'target_return' => $tolerance->targetReturn(),
            'target_volatility' => $tolerance->targetVolatility(),
            'liquidity_needs' => $liquidity[$answers['liquidity']],
            'constraints' => [
                'shariah_required' => $answers['shariah'] === 1,
                'shariah_preferred' => $answers['shariah'] === 2,
                'base_currency' => $currencies[$answers['base_currency']],
                'contribution_frequency' => $contributions[$answers['contributions']],
            ],
        ]);

        $analyzer->analyze($user->fresh());

        if ($user->latestSnapshot() !== null) {
            GenerateInsightsJob::request($user, app()->getLocale());
        }

        return response()->json(['data' => $this->present($profile)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RiskProfile $profile): array
    {
        return [
            'answers' => $profile->answers,
            'risk_tolerance' => $profile->risk_tolerance->value,
            'time_horizon' => $profile->time_horizon->value,
            'target_return' => $profile->target_return,
            'target_volatility' => $profile->target_volatility,
            'liquidity_needs' => $profile->liquidity_needs,
            'constraints' => $profile->constraints,
        ];
    }
}
