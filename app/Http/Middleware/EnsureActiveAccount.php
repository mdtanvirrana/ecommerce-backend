<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next, ?string $requiredAbility = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::error('Unauthenticated.', 401);
        }

        if (! $user->is_active) {
            return ApiResponse::error('Your account has been deactivated.', 403);
        }

        if ($requiredAbility !== null && ! $user->tokenCan($requiredAbility)) {
            return ApiResponse::error('Token is not authorized for this area.', 403);
        }

        return $next($request);
    }
}
