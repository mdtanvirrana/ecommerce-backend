<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminRoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('staff')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(RoleResource::collection($roles), 'Roles retrieved successfully.');
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            PermissionResource::collection($permissions),
            'Permissions retrieved successfully.'
        );
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $slug = Str::slug((string) $data['name']);

        if ($slug === Role::SUPER_ADMIN_SLUG || Role::query()->where('slug', $slug)->exists()) {
            throw new DomainException('A role with that name/slug already exists.', 422, ['name' => ['Role already exists.']]);
        }

        /** @var Role $role */
        $role = DB::transaction(function () use ($data, $slug): Role {
            /** @var Role $created */
            $created = Role::query()->create([
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);

            if (! empty($data['permission_ids'])) {
                $created->permissions()->sync(array_map('intval', $data['permission_ids']));
            }

            return $created->fresh()->load('permissions')->loadCount('staff');
        });

        return ApiResponse::success(new RoleResource($role), 'Role created successfully.', 201);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions')->loadCount('staff');

        return ApiResponse::success(new RoleResource($role), 'Role retrieved successfully.');
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        if ($role->isSuperAdmin()) {
            throw new DomainException('The Super Admin role cannot be modified.', 403);
        }

        $data = $request->validated();

        /** @var Role $updated */
        $updated = DB::transaction(function () use ($role, $data): Role {
            $fields = [];
            if (isset($data['name']) && ! $role->is_system) {
                $fields['name'] = $data['name'];
                $fields['slug'] = Str::slug((string) $data['name']);
            }
            if (array_key_exists('description', $data)) {
                $fields['description'] = $data['description'];
            }
            if ($fields !== []) {
                $role->update($fields);
            }
            if (array_key_exists('permission_ids', $data)) {
                $role->permissions()->sync(array_map('intval', $data['permission_ids'] ?? []));
            }

            return $role->fresh()->load('permissions')->loadCount('staff');
        });

        return ApiResponse::success(new RoleResource($updated), 'Role updated successfully.');
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        if ($role->isSuperAdmin()) {
            throw new DomainException('Protected Super Admin permissions cannot be removed or modified.', 403);
        }

        $data = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role->permissions()->sync(array_map('intval', $data['permission_ids']));

        return ApiResponse::success(
            new RoleResource($role->fresh()->load('permissions')->loadCount('staff')),
            'Role permissions updated successfully.'
        );
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->isSuperAdmin() || $role->is_system) {
            throw new DomainException('System and Super Admin roles cannot be deleted.', 403);
        }

        if ($role->staff()->exists()) {
            throw new DomainException('Cannot delete a role that is still assigned to staff users.', 409);
        }

        $role->delete();

        return ApiResponse::success(null, 'Role deleted successfully.');
    }
}
