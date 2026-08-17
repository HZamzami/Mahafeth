<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoalRequest;
use App\Models\ActivityEvent;
use App\Models\Goal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $goals = $request->user()->goals()->orderBy('target_date')->get()
            ->map(fn (Goal $goal): array => $this->present($goal));

        return response()->json(['data' => $goals]);
    }

    public function store(GoalRequest $request): JsonResponse
    {
        $goal = $request->user()->goals()->create($request->validated());

        ActivityEvent::record($request->user(), ActivityType::GoalSaved, ['name' => $goal->name]);

        return response()->json(['data' => $this->present($goal)], 201);
    }

    public function update(GoalRequest $request, Goal $goal): JsonResponse
    {
        $this->authorizeGoal($request, $goal);

        $goal->update($request->validated());

        ActivityEvent::record($request->user(), ActivityType::GoalSaved, ['name' => $goal->name]);

        return response()->json(['data' => $this->present($goal)]);
    }

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeGoal($request, $goal);

        $name = $goal->name;
        $goal->delete();

        ActivityEvent::record($request->user(), ActivityType::GoalDeleted, ['name' => $name]);

        return response()->json(['data' => ['message' => __('Goal deleted.')]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Goal $goal): array
    {
        return [
            'id' => $goal->id,
            'name' => $goal->name,
            'target_amount' => $goal->target_amount,
            'target_date' => $goal->target_date->toDateString(),
            'monthly_contribution' => $goal->monthly_contribution,
            'months_remaining' => $goal->monthsRemaining(),
        ];
    }

    private function authorizeGoal(Request $request, Goal $goal): void
    {
        abort_unless($goal->user_id === $request->user()->id, 404);
    }
}
