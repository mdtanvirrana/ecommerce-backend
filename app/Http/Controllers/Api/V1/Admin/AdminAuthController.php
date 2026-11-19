<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    private const int MAX_LOGIN_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->validated('email')));
        $throttleKey = 'admin-login:'.sha1($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return ApiResponse::error(
                "Too many login attempts. Please try again in {$seconds} seconds.",
                429
            );
        }

        /** @var Staff|null $staff */
        $staff = Staff::query()->with('roles.permissions')->where('email', $email)->first();

        if ($staff === null || ! Hash::check((string) $request->validated('password'), $staff->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            return ApiResponse::error('Invalid credentials.', 401);
        }

        if (! $staff->is_active) {
            return ApiResponse::error('Invalid credentials.', 401);
        }

        RateLimiter::clear($throttleKey);

        if ($staff->hasTwoFactorEnabled()) {
            $challengeToken = Str::random(64);
            Cache::put("admin_2fa:{$challengeToken}", $staff->id, now()->addMinutes(5));

            return ApiResponse::success([
                'requires_two_factor' => true,
                'two_factor_token' => $challengeToken,
            ], 'Two-factor authentication code required.');
        }

        $token = $staff->createToken('admin-auth', ['staff'])->plainTextToken;

        return ApiResponse::success([
            'requires_two_factor' => false,
            'staff' => new StaffResource($staff),
            'token' => $token,
        ], 'Staff login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('admin');
        $staff->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Staff logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('admin')->load('roles.permissions');

        return ApiResponse::success(new StaffResource($staff), 'Staff profile retrieved successfully.');
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('admin');
        $validated = $request->validated();

        $emailChanged = isset($validated['email']) && strtolower($validated['email']) !== strtolower($staff->email);
        if ($emailChanged) {
            $validated['email'] = strtolower($validated['email']);
            $validated['email_verified_at'] = null;
        }

        $staff->update($validated);

        return ApiResponse::success(
            new StaffResource($staff->fresh()->load('roles.permissions')),
            'Profile updated successfully.'
        );
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('admin');

        if (! Hash::check((string) $request->validated('current_password'), $staff->password)) {
            return ApiResponse::error(
                'Current password is incorrect.',
                422,
                ['current_password' => ['Current password is incorrect.']]
            );
        }

        $staff->forceFill([
            'password' => Hash::make((string) $request->validated('password')),
        ])->save();

        // Revoke all other staff tokens except the active one.
        $currentId = $staff->currentAccessToken()?->id;
        if ($currentId !== null) {
            $staff->tokens()->where('id', '!=', $currentId)->delete();
        }

        return ApiResponse::success(null, 'Password updated successfully.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('staff')->sendResetLink(['email' => strtolower($request->validated('email'))]);

        return ApiResponse::success(
            null,
            'If a staff account exists for that email, a password reset link has been sent.'
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('staff')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Staff $staff, string $password): void {
                $staff->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $staff->tokens()->delete();

                event(new PasswordReset($staff));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('Unable to reset password. The token may be invalid or expired.', 422);
        }

        return ApiResponse::success(null, 'Password has been reset successfully.');
    }
}
