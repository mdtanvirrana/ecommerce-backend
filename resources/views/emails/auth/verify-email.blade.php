<x-mail::message>
# Verify Your Email Address

Hello {{ $customer->name }},

Welcome to **SHOP.CO**! Please confirm your email address so we can keep your account and order notifications secure.

<x-mail::button :url="$verificationUrl">
Verify Email Address
</x-mail::button>

This verification link will expire in 60 minutes. If you did not create an account, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
