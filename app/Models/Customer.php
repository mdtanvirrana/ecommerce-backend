<?php

namespace App\Models;

use App\Enums\UserType;
use App\Notifications\ResetPasswordLinkNotification;
use Illuminate\Database\Eloquent\Builder;

class Customer extends User
{
    protected $table = 'users';

    protected static function booted(): void
    {
        static::addGlobalScope('customer', function (Builder $builder): void {
            $builder->where('users.user_type', UserType::Customer->value);
        });

        static::creating(function (Customer $customer): void {
            $customer->user_type = UserType::Customer;
        });
    }

    public function sendPasswordResetNotification($token): void
    {
        $url = config('services.frontend.url').'/reset-password?token='.urlencode((string) $token).'&email='.urlencode($this->email);
        $this->notify(new ResetPasswordLinkNotification($url, 'customer'));
    }
}
