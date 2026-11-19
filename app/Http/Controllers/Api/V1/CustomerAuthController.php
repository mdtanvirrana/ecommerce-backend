<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerRegisterRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class CustomerAuthController extends Controller
{
    private const int MAX_LOGIN_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var Customer $customer */
        $customer = Customer::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

        return ApiResponse::success([
            'user' => new CustomerResource($customer),
            'token' => $token,
        ], 'Registration successful.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->validated('email')));
        $throttleKey = 'customer-login:'.sha1($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return ApiResponse::error(
                "Too many login attempts. Please try again in {$seconds} seconds.",
                429
            );
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('email', $email)->first();

        if ($customer === null || ! Hash::check((string) $request->validated('password'), $customer->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            return ApiResponse::error('Invalid credentials.', 401);
        }

        if (! $customer->is_active) {
            return ApiResponse::error('Invalid credentials.', 401);
        }

        RateLimiter::clear($throttleKey);

        $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

        return ApiResponse::success([
            'user' => new CustomerResource($customer),
            'token' => $token,
        ], 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $customer->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return ApiResponse::success(new CustomerResource($customer), 'Profile retrieved successfully.');
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $customer->update($request->validated());

        return ApiResponse::success(new CustomerResource($customer->fresh()), 'Profile updated successfully.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('customers')->sendResetLink(['email' => strtolower($request->validated('email'))]);

        // Generic message avoids user enumeration.
        return ApiResponse::success(
            null,
            'If an account exists for that email, a password reset link has been sent.'
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Customer $customer, string $password): void {
                $customer->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke existing API tokens after password reset.
                $customer->tokens()->delete();

                event(new PasswordReset($customer));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('Unable to reset password. The token may be invalid or expired.', 422);
        }

        return ApiResponse::success(null, 'Password has been reset successfully.');
    }
}
