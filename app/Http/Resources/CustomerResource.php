<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'is_email_verified' => $this->email_verified_at !== null,
            'orders_count' => $this->whenCounted('orders'),
            'total_spent' => $this->when(isset($this->total_spent), fn (): string => number_format((float) $this->total_spent, 2, '.', '')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
