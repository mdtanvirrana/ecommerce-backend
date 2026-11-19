<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Enforce database-driven permissions (never hardcoded role-name checks).
     * Supports single permission or pipe-separated OR list: `permission:orders.view|orders.update_status`.
     */
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $staff = $request->user('admin');

        if (! $staff instanceof Staff) {
            return ApiResponse::error('Unauthenticated.', 401);
        }

        $required = array_values(array_filter(array_map('trim', explode('|', $permissions))));

        if ($required === [] || ! $staff->hasAnyPermission($required)) {
            return ApiResponse::error('You do not have permission to perform this action.', 403);
        }

        return $next($request);
    }
}
