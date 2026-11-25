<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Domain\Developers\Models\ApiKey;
use App\Domain\Developers\Services\ApiKeyService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** API keys (D-028). The secret is in the create response only. */
class ApiKeyController extends Controller
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function index(Business $business): JsonResponse
    {
        $this->authorize('manageApiKeys', $business);

        return response()->json([
            'data' => ApiKeyResource::collection($business->apiKeys()->with('creator')->orderByRaw('case when revoked_at is null then 0 else 1 end')->latest()->get()),
            'scopes' => collect(ApiKey::SCOPES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorize('manageApiKeys', $business);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(ApiKey::SCOPES))],
            'expires_in_days' => ['nullable', 'integer', Rule::in([30, 90, 180, 365])],
        ]);

        [$key, $secret] = $this->keys->create(
            $business, $data['name'], $data['scopes'],
            ! empty($data['expires_in_days']) ? CarbonImmutable::now()->addDays($data['expires_in_days']) : null,
            $request->user(),
        );

        return response()->json(['data' => new ApiKeyResource($key->load('creator')), 'secret' => $secret], 201);
    }

    public function destroy(Request $request, Business $business, ApiKey $apiKey): ApiKeyResource
    {
        $this->authorize('manageApiKeys', $business);

        return new ApiKeyResource($this->keys->revoke($apiKey, $request->user()));
    }

    public function revokeAll(Request $request, Business $business): JsonResponse
    {
        $this->authorize('manageApiKeys', $business);

        return response()->json(['revoked' => $this->keys->revokeAll($business, $request->user())]);
    }
}
