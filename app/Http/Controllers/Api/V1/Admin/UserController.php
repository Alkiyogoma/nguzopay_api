<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()
                ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $term).'%')))
                ->with('socialAccounts', 'memberships')
                ->latest()
                ->paginate(25)
        );
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('socialAccounts', 'memberships'));
    }
}
