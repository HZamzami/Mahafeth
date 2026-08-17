<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateManualAccount;
use App\Actions\SyncConnection;
use App\Enums\AccountType;
use App\Enums\ActivityType;
use App\Enums\ConnectionStatus;
use App\Enums\ConsentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateManualAccountRequest;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\Connection;
use App\Models\Institution;
use App\Services\Analytics\HoldingsSummarizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    public function index(Request $request, HoldingsSummarizer $summarizer): JsonResponse
    {
        $user = $request->user();

        $connections = $user->connections()->with(['accounts', 'institution', 'latestConsent'])->get();
        $value = fn (?Account $account): float => $account === null ? 0.0 : $summarizer->forAccount($account)['totalValue'];

        $yourAccounts = $connections
            ->filter(fn (Connection $connection): bool => $connection->isManual())
            ->map(fn (Connection $connection): array => [
                'connection_id' => $connection->id,
                'account_id' => $connection->accounts->first()?->id,
                'name' => $connection->displayName(),
                'type' => $connection->accounts->first()?->type->value,
                'value' => $value($connection->accounts->first()),
            ])
            ->values();

        $demoAccounts = $connections
            ->filter(fn (Connection $connection): bool => ! $connection->isManual() && $connection->status === ConnectionStatus::Connected)
            ->map(fn (Connection $connection): array => [
                'connection_id' => $connection->id,
                'account_id' => $connection->accounts->first()?->id,
                'institution' => [
                    'slug' => $connection->institution->slug,
                    'name' => $connection->institution->localizedName(),
                    'color' => $connection->institution->color,
                ],
                'type' => $connection->accounts->first()?->type->value,
                'value' => $value($connection->accounts->first()),
            ])
            ->values();

        $connectedInstitutionIds = $connections->pluck('institution_id')->filter()->all();

        return response()->json([
            'data' => [
                'your_accounts' => $yourAccounts,
                'demo_accounts' => $demoAccounts,
                'available_demos' => Institution::where('provider', '!=', 'import')
                    ->whereNotIn('id', $connectedInstitutionIds)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Institution $institution): array => [
                        'slug' => $institution->slug,
                        'name' => $institution->localizedName(),
                        'type' => $institution->type->value,
                        'color' => $institution->color,
                    ]),
                'account_types' => array_map(fn (AccountType $type): string => $type->value, AccountType::cases()),
            ],
        ]);
    }

    public function storeManual(CreateManualAccountRequest $request, CreateManualAccount $createManualAccount): JsonResponse
    {
        $account = $createManualAccount->handle(
            $request->user(),
            trim($request->validated('name')),
            AccountType::from($request->validated('type')),
            $request->validated('currency'),
        );

        return response()->json(['data' => ['account_id' => $account->id]], 201);
    }

    public function consent(Institution $institution): JsonResponse
    {
        abort_if($institution->provider === 'import', 404);

        return response()->json([
            'data' => [
                'institution' => [
                    'slug' => $institution->slug,
                    'name' => $institution->localizedName(),
                    'color' => $institution->color,
                ],
                'scopes' => config('mahafeth.consent_scopes'),
                'ttl_days' => (int) config('mahafeth.consent_ttl_days'),
            ],
        ]);
    }

    public function approveConsent(Request $request, Institution $institution, SyncConnection $syncConnection): JsonResponse
    {
        abort_if($institution->provider === 'import', 404);

        $user = $request->user();

        $connection = $user->connections()->firstOrCreate(['institution_id' => $institution->id]);

        $user->consents()->create([
            'institution_id' => $institution->id,
            'connection_id' => $connection->id,
            'scopes' => config('mahafeth.consent_scopes'),
            'status' => ConsentStatus::Active,
            'granted_at' => now(),
            'expires_at' => now()->addDays((int) config('mahafeth.consent_ttl_days')),
        ]);

        ActivityEvent::record($user, ActivityType::ConsentGranted, [
            'institution' => $institution->localizedName(),
            'expires' => now()->addDays((int) config('mahafeth.consent_ttl_days'))->toDateString(),
        ]);

        $syncConnection->handle($connection);
        AnalyzePortfolioJob::dispatch($user);

        return response()->json(['data' => ['connection_id' => $connection->id]], 201);
    }

    public function sync(Request $request, Connection $connection, SyncConnection $syncConnection): JsonResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 404);

        $connection->load('latestConsent');

        if ($connection->isManual()) {
            return response()->json(['message' => __('This account has no external source to sync.')], 422);
        }

        if ($connection->source === 'api' && ! ($connection->latestConsent?->isActive() ?? false)) {
            return response()->json(['message' => __('The consent for this connection has expired. Please reauthorize access.')], 409);
        }

        $syncConnection->handle($connection);
        AnalyzePortfolioJob::dispatch($request->user());

        return response()->json(['data' => ['message' => __('Sync queued.')]], 202);
    }

    public function destroy(Request $request, Connection $connection): JsonResponse
    {
        $user = $request->user();
        abort_unless($connection->user_id === $user->id, 404);

        if ($connection->isManual()) {
            $connection->delete();
        } else {
            $connection->update(['status' => ConnectionStatus::Disconnected]);

            $user->consents()
                ->where('connection_id', $connection->id)
                ->where('status', ConsentStatus::Active)
                ->update(['status' => ConsentStatus::Revoked, 'revoked_at' => now()]);

            ActivityEvent::record($user, ActivityType::ConnectionDisconnected, [
                'institution' => $connection->institution->localizedName(),
            ]);
        }

        AnalyzePortfolioJob::dispatch($user);

        return response()->json(['data' => ['message' => __('Account removed.')]]);
    }
}
