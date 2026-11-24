<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Enums\MemberStatus;
use App\Domain\Business\Enums\RegistrationAuthority;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Services\BusinessRegistrar;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessRegistrar $registrar,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $businesses = Business::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', MemberStatus::Active))
            ->with('senderIds')
            ->orderBy('legal_name')
            ->get();

        return BusinessResource::collection($businesses);
    }

    public function store(Request $request): BusinessResource
    {
        $data = $this->validated($request, creating: true);

        return new BusinessResource($this->registrar->register($request->user(), $data)->load('senderIds'));
    }

    public function show(Request $request, Business $business): BusinessResource
    {
        $this->authorize('view', $business);

        return new BusinessResource($business->load('senderIds'));
    }

    public function update(Request $request, Business $business): BusinessResource
    {
        $this->authorize('update', $business);

        $business = $this->registrar->update($business, $this->validated($request, creating: false), $request->user());

        return new BusinessResource($business->load('senderIds'));
    }

    public function logo(Request $request, Business $business): BusinessResource
    {
        $this->authorize('update', $business);
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000']]);

        $old = $business->logo_path;
        $business->forceFill(['logo_path' => $request->file('logo')->store("logos/{$business->id}", 'public')])->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        $this->audit->record('business.logo_updated', $business);

        return new BusinessResource($business->load('senderIds'));
    }

    private function validated(Request $request, bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];

        $data = $request->validate([
            'type' => [...$sometimes, 'required', Rule::enum(BusinessType::class)],
            'legal_name' => [...$sometimes, 'required', 'string', 'max:190'],
            'trading_name' => ['nullable', 'string', 'max:190'],
            'registration_authority' => ['nullable', Rule::enum(RegistrationAuthority::class)],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'tin' => ['nullable', 'regex:/^\d{3}-?\d{3}-?\d{3}$/'],
            'vrn' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'website' => ['nullable', 'url', 'max:190'],
            'region' => ['nullable', 'string', 'max:60'],
            'district' => ['nullable', 'string', 'max:60'],
            'physical_address' => ['nullable', 'string', 'max:255'],
            'postal_address' => ['nullable', 'string', 'max:120'],
        ], [
            'tin.regex' => 'A TIN has 9 digits, for example 123-456-789.',
        ]);

        if (isset($data['tin'])) {
            $data['tin'] = preg_replace('/\D/', '', $data['tin']);
        }

        if ($request->has('phone')) {
            $data['phone'] = $this->phone($request, 'phone', required: false);
        }

        return $data;
    }
}
