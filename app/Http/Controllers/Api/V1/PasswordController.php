<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Models\ActivityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update(['password' => Hash::make($request->validated('password'))]);

        ActivityEvent::record($user, ActivityType::PasswordChanged);

        return response()->json(['data' => ['message' => __('Password updated.')]]);
    }
}
