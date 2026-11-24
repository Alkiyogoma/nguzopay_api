<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Enums\DocumentType;
use App\Domain\Business\Enums\RegistrationAuthority;
use App\Domain\Business\Enums\StaffRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Option lists for portal forms, so the frontend never hard-codes them. */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $map = fn (array $cases) => array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], $cases);

        return response()->json([
            'business_types' => $map(BusinessType::cases()),
            'registration_authorities' => $map(RegistrationAuthority::cases()),
            'document_types' => array_map(fn (DocumentType $c) => [
                'value' => $c->value,
                'label' => $c->label(),
                'required' => in_array($c, DocumentType::required(), true),
            ], DocumentType::cases()),
            'staff_roles' => array_map(fn (StaffRole $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'permissions' => array_map(fn ($p) => $p->value, $r->permissions()),
            ], StaffRole::cases()),
            'regions' => [
                'Arusha', 'Dar es Salaam', 'Dodoma', 'Geita', 'Iringa', 'Kagera', 'Katavi', 'Kigoma',
                'Kilimanjaro', 'Lindi', 'Manyara', 'Mara', 'Mbeya', 'Morogoro', 'Mtwara', 'Mwanza',
                'Njombe', 'Pemba North', 'Pemba South', 'Pwani', 'Rukwa', 'Ruvuma', 'Shinyanga',
                'Simiyu', 'Singida', 'Songwe', 'Tabora', 'Tanga', 'Unguja North', 'Unguja South',
                'Unguja Urban West',
            ],
            'auth' => [
                'otp_length' => (int) config('nguzopay.otp.length'),
                'social_providers' => ['google', 'github'],
            ],
        ]);
    }
}
