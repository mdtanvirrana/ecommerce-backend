<?php

namespace App\Models;

use App\Enums\UserType;
use App\Notifications\ResetPasswordLinkNotification;
use Illuminate\Database\Eloquent\Builder;

class Staff extends User
{
    protected $table = 'users';

    protected static function booted(): void
    {
        static::addGlobalScope('staff', function (Builder $builder): void {
            $builder->where('users.user_type', UserType::Staff->value);
        });

        static::creating(function (Staff $staff): void {
            $staff->user_type = UserType::Staff;
        });
    }

    public function sendPasswordResetNotification($token): void
    {
        $url = config('services.frontend.url').'/admin/reset-password?token='.urlencode((string) $token).'&email='.urlencode($this->email);
        $this->notify(new ResetPasswordLinkNotification($url, 'staff'));
    }
}
