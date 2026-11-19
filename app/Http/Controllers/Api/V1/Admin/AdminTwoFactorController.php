<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Services\TotpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AdminTwoFactorController extends Controller
{
    public function __construct(private readonly TotpService $totpService) {}

    public function setup(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('admin');

        $secret = $this->totpService->generateSecret();
        $issuer = 'SHOP.CO Admin';

        $staff->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return ApiResponse::success([
            'secret' => $secret,
            'issuer' => $issuer,
            'otpauth_url' => $this->totpService->getOtpAuthUri($issuer, $staff->email, $secret),
        ], 'Two-factor setup initialized. Scan the QR code and confirm with a 6-digit code.');
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'code.regex' => 'Enter a valid 6-digit authenticator code.',
        ]);

        /** @var Staff $staff */
        $staff = $request->user('admin');

        if (! $staff->two_factor_secret) {
            return ApiResponse::error('Initialize two-factor setup before confirming.', 422, [
                'code' => ['Two-factor setup has not been started yet.'],
            ]);
        }

        if (! $this->totpService->verifyCode((string) $staff->two_factor_secret, (string) $validated['code'])) {
            return ApiResponse::error('Invalid authentication code.', 422, [
                'code' => ['The 6-digit authentication code is invalid or expired.'],
            ]);
        }

        $recoveryCodes = $this->totpService->generateRecoveryCodes(8);

        $staff->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return ApiResponse::success([
            'two_factor_enabled' => true,
            'recovery_codes' => $recoveryCodes,
            'staff' => new StaffResource($staff->fresh()->load('roles.permissions')),
        ], 'Two-factor authentication has been enabled. Save your recovery codes securely.');
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var Staff $staff */
        $staff = $request->user('admin');

        if (! Hash::check((string) $validated['password'], $staff->password)) {
            return ApiResponse::error('Password is incorrect.', 422, [
                'password' => ['Current password is incorrect.'],
            ]);
        }

        $staff->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return ApiResponse::success([
            'two_factor_enabled' => false,
            'staff' => new StaffResource($staff->fresh()->load('roles.permissions')),
        ], 'Two-factor authentication has been disabled.');
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var Staff $staff */
        $staff = $request->user('admin');

        if (! $staff->hasTwoFactorEnabled()) {
            return ApiResponse::error('Two-factor authentication is not enabled.', 422);
        }

        if (! Hash::check((string) $validated['password'], $staff->password)) {
            return ApiResponse::error('Password is incorrect.', 422, [
                'password' => ['Current password is incorrect.'],
            ]);
        }

        $recoveryCodes = $this->totpService->generateRecoveryCodes(8);
        $staff->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return ApiResponse::success([
            'recovery_codes' => $recoveryCodes,
        ], 'Recovery codes regenerated.');
    }

    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'two_factor_token' => ['required', 'string', 'min:16'],
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        $challengeToken = (string) $validated['two_factor_token'];
        $throttleKey = 'admin-2fa:'.sha1($challengeToken.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return ApiResponse::error(
                "Too many two-factor attempts. Please try again in {$seconds} seconds.",
                429
            );
        }

        $cacheKey = "admin_2fa:{$challengeToken}";
        $staffId = Cache::get($cacheKey);

        if ($staffId === null) {
            return ApiResponse::error('Two-factor session expired. Please sign in again.', 401, [
                'code' => ['Two-factor session expired. Please sign in again.'],
            ]);
        }

        /** @var Staff|null $staff */
        $staff = Staff::query()->with('roles.permissions')->find($staffId);

        if ($staff === null || ! $staff->is_active || ! $staff->hasTwoFactorEnabled()) {
            Cache::forget($cacheKey);

            return ApiResponse::error('Invalid two-factor challenge.', 401);
        }

        $inputCode = strtoupper(trim((string) $validated['code']));
        $verified = false;
        $usedRecovery = false;

        if ($this->totpService->verifyCode((string) $staff->two_factor_secret, $inputCode)) {
            $verified = true;
        } else {
            /** @var list<string> $storedCodes */
            $storedCodes = is_array($staff->two_factor_recovery_codes)
                ? array_values($staff->two_factor_recovery_codes)
                : [];

            foreach ($storedCodes as $idx => $recoveryCode) {
                if (hash_equals(strtoupper((string) $recoveryCode), $inputCode)) {
                    $verified = true;
                    $usedRecovery = true;
                    unset($storedCodes[$idx]);
                    $staff->forceFill([
                        'two_factor_recovery_codes' => array_values($storedCodes),
                    ])->save();
                    break;
                }
            }
        }

        if (! $verified) {
            RateLimiter::hit($throttleKey, 60);

            return ApiResponse::error('Invalid authentication or recovery code.', 422, [
                'code' => ['Invalid 6-digit authentication code or recovery code.'],
            ]);
        }

        RateLimiter::clear($throttleKey);
        Cache::forget($cacheKey);

        $token = $staff->createToken('admin-auth', ['staff'])->plainTextToken;

        return ApiResponse::success([
            'staff' => new StaffResource($staff->fresh()->load('roles.permissions')),
            'token' => $token,
            'used_recovery_code' => $usedRecovery,
        ], 'Two-factor verification successful.');
    }
}
