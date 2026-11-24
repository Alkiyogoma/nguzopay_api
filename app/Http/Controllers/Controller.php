<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Support\PhoneNumber;
use App\Http\Resources\UserResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

abstract class Controller
{
    use AuthorizesRequests;

    /** Validate and normalise a Tanzanian mobile number to 255XXXXXXXXX. */
    protected function phone(Request $request, string $field = 'phone', bool $required = true): ?string
    {
        $value = $request->input($field);

        if (blank($value)) {
            if ($required) {
                throw ValidationException::withMessages([$field => 'Enter your mobile number.']);
            }

            return null;
        }

        try {
            return PhoneNumber::normalize((string) $value);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    /** Session payload shared by every sign-in endpoint. */
    protected function sessionResponse(array $result, int $status = 200)
    {
        if (isset($result['two_factor_required'])) {
            return response()->json($result, 202);
        }

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at'],
            'user' => new UserResource($result['user']),
        ], $status);
    }
}
