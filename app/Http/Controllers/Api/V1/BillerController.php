<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Collections\Billers\CrdbBiller;
use App\Domain\Collections\Billers\NmbBiller;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Banks call these (CRDB now, NMB next). No user session: the bank's signature authenticates. */
class BillerController extends Controller
{
    public function crdb(Request $request, CrdbBiller $crdb): JsonResponse
    {
        return response()->json($crdb->handle($request->getContent())->toArray());
    }

    public function nmb(Request $request, NmbBiller $nmb): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        return response()->json(is_array($data) ? $nmb->handle($data) : ['body' => (object) [], 'message' => 'Malformed request', 'statusCode' => 649]);
    }
}
