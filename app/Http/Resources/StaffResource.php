<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Staff */
class StaffResource extends JsonResource
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
            'is_super_admin' => $this->isSuperAdmin(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'two_factor_confirmed_at' => $this->two_factor_confirmed_at?->toIso8601String(),
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'permissions' => $this->effectivePermissions()->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
