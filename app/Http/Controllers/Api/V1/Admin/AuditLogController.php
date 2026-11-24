<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        return AuditLogResource::collection(
            AuditLog::query()
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->when($request->query('business_id'), fn ($q, $id) => $q->where('business_id', $id))
                ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                ->with('user', 'business')
                ->latest('id')
                ->paginate(50)
        );
    }
}
