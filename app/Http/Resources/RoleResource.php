<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_system' => (bool) $this->is_system,
            'is_super_admin' => $this->isSuperAdmin(),
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'staff_count' => $this->whenCounted('staff'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
