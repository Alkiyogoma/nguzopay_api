<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Enums\DocumentType;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessDocument;
use App\Domain\Business\Services\KycService;
use App\Domain\Business\Services\SenderIdService;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\SenderIdResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycController extends Controller
{
    public function __construct(
        private readonly KycService $kyc,
        private readonly SenderIdService $senderIds,
    ) {}

    public function overview(Business $business): JsonResponse
    {
        $this->authorize('view', $business);

        return response()->json([
            'status' => $business->status->value,
            'checklist' => $this->kyc->checklist($business),
            'documents' => DocumentResource::collection($business->documents()->with('uploader')->latest()->get()),
            'sender_id' => ($s = $business->senderIds()->latest()->first()) ? new SenderIdResource($s) : null,
        ]);
    }

    public function upload(Request $request, Business $business): DocumentResource
    {
        $this->authorize('manageKyc', $business);

        $data = $request->validate([
            'type' => ['required', Rule::enum(DocumentType::class)],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('nguzopay.kyc.max_upload_kb')],
        ]);

        return new DocumentResource($this->kyc->upload($business, DocumentType::from($data['type']), $request->file('file'), $request->user()));
    }

    public function download(Business $business, BusinessDocument $document): StreamedResponse
    {
        $this->authorize('view', $business);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function destroy(Request $request, Business $business, BusinessDocument $document): JsonResponse
    {
        $this->authorize('manageKyc', $business);
        $this->kyc->delete($document, $request->user());

        return response()->json(['message' => 'Document deleted.']);
    }

    public function submit(Request $request, Business $business): BusinessResource
    {
        $this->authorize('manageKyc', $business);

        return new BusinessResource($this->kyc->submit($business, $request->user())->load('senderIds'));
    }

    public function requestSenderId(Request $request, Business $business): SenderIdResource
    {
        $this->authorize('update', $business);
        $request->validate(['sender_id' => ['required', 'string', 'max:20']]);

        return new SenderIdResource($this->senderIds->request($business, $request->input('sender_id'), $request->user()));
    }
}
