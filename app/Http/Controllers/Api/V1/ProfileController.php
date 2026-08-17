<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteAccountRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'notify_alerts' => $user->notify_alerts,
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $emailChanged = $request->validated('email') !== $user->email;

        $user->fill($request->safe()->only(['name', 'email', 'notify_alerts']));

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'notify_alerts' => $user->notify_alerts,
            ],
        ]);
    }

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['data' => ['message' => __('Account deleted.')]]);
    }
}
