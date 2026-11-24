<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Domain\Platform\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BusinessAuditController extends Controller
{
    public function __invoke(Request $request, Business $business): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', $business);

        return AuditLogResource::collection(
            AuditLog::query()
                ->where('business_id', $business->id)
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->with('user', 'business')
                ->latest('id')
                ->paginate(50)
        );
    }
}
