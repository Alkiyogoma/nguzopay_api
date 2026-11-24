<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\SenderIdStatus;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\SmsSenderId;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byStatus = Business::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'businesses' => collect(BusinessStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'kyc_queue' => (int) (($byStatus[BusinessStatus::Submitted->value] ?? 0) + ($byStatus[BusinessStatus::InReview->value] ?? 0)),
            'sender_ids_pending' => SmsSenderId::whereIn('status', [SenderIdStatus::Requested, SenderIdStatus::Submitted])->count(),
            'users' => [
                'total' => User::count(),
                'new_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }
}
