<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\DocumentStatus;
use App\Domain\Business\Enums\SenderIdStatus;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessDocument;
use App\Domain\Business\Models\SmsSenderId;
use App\Domain\Business\Services\KycService;
use App\Domain\Business\Services\SenderIdService;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\MemberResource;
use App\Http\Resources\SenderIdResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessReviewController extends Controller
{
    public function __construct(
        private readonly KycService $kyc,
        private readonly SenderIdService $senderIds,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::enum(BusinessStatus::class)]]);

        return BusinessResource::collection(
            Business::query()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($q) => $q
                    ->where('legal_name', 'like', "%{$term}%")
                    ->orWhere('trading_name', 'like', "%{$term}%")
                    ->orWhere('code', $term)
                    ->orWhere('tin', preg_replace('/\D/', '', $term) ?: '-')))
                ->with('senderIds', 'recommendedBy', 'decidedBy', 'owner.user')
                ->orderByRaw("case when status in ('submitted','in_review') then 0 else 1 end")
                ->orderBy('submitted_at')
                ->latest()
                ->paginate(25)
        );
    }

    public function show(Business $business): JsonResponse
    {
        $business->load('senderIds', 'recommendedBy', 'decidedBy', 'owner.user');

        return response()->json([
            'business' => new BusinessResource($business),
            'checklist' => $this->kyc->checklist($business),
            'documents' => DocumentResource::collection($business->documents()->with('uploader')->latest()->get()),
            'members' => MemberResource::collection($business->members()->with('user')->get()),
            'sender_ids' => SenderIdResource::collection($business->senderIds),
            'duplicates' => array_map(fn ($d) => [
                'id' => $d['business']->id,
                'code' => $d['business']->code,
                'name' => $d['business']->displayName(),
                'status' => $d['business']->status->value,
                'matched_on' => $d['matched_on'],
            ], $this->kyc->duplicatesOf($business)),
        ]);
    }

    public function recommend(Request $request, Business $business): BusinessResource
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return new BusinessResource($this->kyc->recommend($business, $data['decision'], $data['notes'] ?? null, $request->user())->load('recommendedBy'));
    }

    public function decide(Request $request, Business $business): BusinessResource
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return new BusinessResource($this->kyc->decide($business, $data['decision'], $data['reason'] ?? null, $request->user())->load('recommendedBy', 'decidedBy'));
    }

    public function suspend(Request $request, Business $business): BusinessResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return new BusinessResource($this->kyc->setSuspended($business, true, $data['reason'], $request->user()));
    }

    public function reinstate(Request $request, Business $business): BusinessResource
    {
        return new BusinessResource($this->kyc->setSuspended($business, false, $request->input('reason'), $request->user()));
    }

    public function reviewDocument(Request $request, Business $business, BusinessDocument $document): DocumentResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(DocumentStatus::class)],
            'notes' => ['nullable', 'string', 'max:1000', 'required_if:status,rejected'],
        ]);

        return new DocumentResource($this->kyc->reviewDocument($document, DocumentStatus::from($data['status']), $data['notes'] ?? null, $request->user()));
    }

    public function downloadDocument(Business $business, BusinessDocument $document): StreamedResponse
    {
        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function senderIdStatus(Request $request, Business $business, SmsSenderId $senderId): SenderIdResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(SenderIdStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:status,rejected'],
        ]);

        return new SenderIdResource($this->senderIds->setStatus($senderId, SenderIdStatus::from($data['status']), $data['reason'] ?? null, $request->user()));
    }
}
