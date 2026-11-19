<?php

use App\Mail\VerifyCustomerEmailMail;
use App\Models\Customer;
use App\Models\Staff;
use App\Services\TotpService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

it('queues a signed email verification link on customer registration, verifies it, and rejects tampered signatures', function (): void {
    Mail::fake();

    $register = $this->postJson('/api/v1/register', [
        'name' => 'Verify Test Buyer',
        'email' => 'verifybuyer@example.com',
        'password' => 'StrongPass#2026',
        'password_confirmation' => 'StrongPass#2026',
    ]);

    $register->assertCreated()
        ->assertJsonPath('data.user.is_email_verified', false);

    $capturedUrl = '';
    Mail::assertQueued(VerifyCustomerEmailMail::class, function (VerifyCustomerEmailMail $mail) use (&$capturedUrl): bool {
        $capturedUrl = $mail->verificationUrl;

        return $mail->hasTo('verifybuyer@example.com');
    });

    expect($capturedUrl)->toContain('/verify-email?');

    $query = [];
    parse_str((string) parse_url($capturedUrl, PHP_URL_QUERY), $query);

    // Tampered signature must be rejected
    $this->getJson("/api/v1/email/verify/{$query['id']}/{$query['hash']}?expires={$query['expires']}&signature=forged")
        ->assertForbidden();

    // Valid signed link verifies the email
    $this->getJson("/api/v1/email/verify/{$query['id']}/{$query['hash']}?expires={$query['expires']}&signature={$query['signature']}")
        ->assertOk()
        ->assertJsonPath('data.is_email_verified', true);

    /** @var Customer $customer */
    $customer = Customer::query()->where('email', 'verifybuyer@example.com')->firstOrFail();
    expect($customer->email_verified_at)->not->toBeNull();

    // Resend on already-verified customer does not queue duplicate mail
    $token = (string) $register->json('data.token');
    $this->withToken($token)
        ->postJson('/api/v1/email/verification-notification')
        ->assertOk()
        ->assertJsonPath('message', 'Email address is already verified.');

    Mail::assertQueued(VerifyCustomerEmailMail::class, 1);
});

it('supports full admin TOTP 2FA setup, confirmation, login challenge, single-use recovery codes, and disable', function (): void {
    $totp = app(TotpService::class);

    // 1. Staff signs in normally before enabling 2FA
    $login1 = $this->postJson('/api/v1/admin/login', [
        'email' => 'superadmin@shopco.local',
        'password' => 'SuperAdmin@123!',
    ]);
    $login1->assertOk()
        ->assertJsonPath('data.requires_two_factor', false)
        ->assertJsonPath('data.staff.two_factor_enabled', false);

    $adminToken = (string) $login1->json('data.token');

    // 2. Initialize 2FA setup
    $setup = $this->withToken($adminToken)->postJson('/api/v1/admin/two-factor/setup');
    $setup->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'issuer', 'otpauth_url']]);

    $secret = (string) $setup->json('data.secret');
    expect($secret)->toHaveLength(32);

    // Invalid code rejected
    $this->withToken($adminToken)
        ->postJson('/api/v1/admin/two-factor/confirm', ['code' => '000000'])
        ->assertUnprocessable();

    // 3. Confirm with valid RFC 6238 TOTP code
    $validCode = $totp->generateCode($secret);
    $confirm = $this->withToken($adminToken)
        ->postJson('/api/v1/admin/two-factor/confirm', ['code' => $validCode]);

    $confirm->assertOk()
        ->assertJsonPath('data.two_factor_enabled', true)
        ->assertJsonCount(8, 'data.recovery_codes');

    $recoveryCodes = $confirm->json('data.recovery_codes');

    // Verify /admin/me never leaks 2FA secrets or recovery codes
    $me = $this->withToken($adminToken)->getJson('/api/v1/admin/me');
    $me->assertOk()
        ->assertJsonPath('data.two_factor_enabled', true)
        ->assertJsonMissingPath('data.two_factor_secret')
        ->assertJsonMissingPath('data.two_factor_recovery_codes');

    // 4. Subsequent login requires 2FA challenge and does NOT issue Sanctum token yet
    auth()->forgetGuards();
    $login2 = $this->postJson('/api/v1/admin/login', [
        'email' => 'superadmin@shopco.local',
        'password' => 'SuperAdmin@123!',
    ]);
    $login2->assertOk()
        ->assertJsonPath('data.requires_two_factor', true)
        ->assertJsonMissingPath('data.token');

    $challengeToken = (string) $login2->json('data.two_factor_token');

    // Wrong OTP fails
    $this->postJson('/api/v1/admin/two-factor/challenge', [
        'two_factor_token' => $challengeToken,
        'code' => '111111',
    ])->assertUnprocessable();

    // Valid TOTP succeeds and issues Sanctum staff token
    $challengeOk = $this->postJson('/api/v1/admin/two-factor/challenge', [
        'two_factor_token' => $challengeToken,
        'code' => $totp->generateCode($secret),
    ]);
    $challengeOk->assertOk()
        ->assertJsonPath('data.staff.email', 'superadmin@shopco.local')
        ->assertJsonPath('data.used_recovery_code', false)
        ->assertJsonStructure(['data' => ['token']]);

    // 5. Recovery code works ONCE and cannot be reused
    $login3 = $this->postJson('/api/v1/admin/login', [
        'email' => 'superadmin@shopco.local',
        'password' => 'SuperAdmin@123!',
    ]);
    $challengeToken2 = (string) $login3->json('data.two_factor_token');

    $recoveryOk = $this->postJson('/api/v1/admin/two-factor/challenge', [
        'two_factor_token' => $challengeToken2,
        'code' => $recoveryCodes[0],
    ]);
    $recoveryOk->assertOk()
        ->assertJsonPath('data.used_recovery_code', true);

    $freshToken = (string) $recoveryOk->json('data.token');

    // Reusing the same recovery code on a new challenge must fail
    $login4 = $this->postJson('/api/v1/admin/login', [
        'email' => 'superadmin@shopco.local',
        'password' => 'SuperAdmin@123!',
    ]);
    $this->postJson('/api/v1/admin/two-factor/challenge', [
        'two_factor_token' => (string) $login4->json('data.two_factor_token'),
        'code' => $recoveryCodes[0],
    ])->assertUnprocessable();

    // 6. Disabling requires current password
    auth()->forgetGuards();
    $this->withToken($freshToken)
        ->postJson('/api/v1/admin/two-factor/disable', ['password' => 'WrongPassword!1'])
        ->assertUnprocessable();

    $this->withToken($freshToken)
        ->postJson('/api/v1/admin/two-factor/disable', ['password' => 'SuperAdmin@123!'])
        ->assertOk()
        ->assertJsonPath('data.two_factor_enabled', false);

    /** @var Staff $staff */
    $staff = Staff::query()->where('email', 'superadmin@shopco.local')->firstOrFail();
    expect($staff->hasTwoFactorEnabled())->toBeFalse();
});
