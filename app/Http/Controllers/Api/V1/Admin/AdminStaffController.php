<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\Role;
use App\Models\Staff;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminStaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $query = Staff::query()->with('roles.permissions');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('id')->paginate($perPage);

        return ApiResponse::paginated(
            $paginator,
            StaffResource::collection($paginator->items()),
            'Staff users retrieved successfully.'
        );
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user('admin');
        $data = $request->validated();
        $roleIds = array_map('intval', $data['role_ids']);

        // Only a Super Admin can assign the Super Admin role to a staff user.
        $superAdminRoleId = Role::query()->where('slug', Role::SUPER_ADMIN_SLUG)->value('id');
        if ($superAdminRoleId !== null && in_array((int) $superAdminRoleId, $roleIds, true) && ! $actor->isSuperAdmin()) {
            throw new DomainException('Only a Super Admin can assign the Super Admin role.', 403);
        }

        /** @var Staff $staff */
        $staff = DB::transaction(function () use ($data, $roleIds): Staff {
            /** @var Staff $created */
            $created = Staff::query()->create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'is_active' => $data['is_active'] ?? true,
            ]);

            $created->roles()->sync($roleIds);

            return $created->fresh()->load('roles.permissions');
        });

        return ApiResponse::success(new StaffResource($staff), 'Staff user created successfully.', 201);
    }

    public function show(Staff $staff): JsonResponse
    {
        $staff->load('roles.permissions');

        return ApiResponse::success(new StaffResource($staff), 'Staff user retrieved successfully.');
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user('admin');
        $data = $request->validated();

        // Super Admin protection: non-Super Admin staff cannot modify a Super Admin.
        if ($staff->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw new DomainException('Super Admin accounts cannot be modified by other staff.', 403);
        }

        $superAdminRoleId = (int) (Role::query()->where('slug', Role::SUPER_ADMIN_SLUG)->value('id') ?? 0);

        if (isset($data['role_ids'])) {
            $roleIds = array_map('intval', $data['role_ids']);
            $assigningSuperAdmin = $superAdminRoleId > 0 && in_array($superAdminRoleId, $roleIds, true);

            if ($assigningSuperAdmin && ! $actor->isSuperAdmin()) {
                throw new DomainException('Only a Super Admin can assign the Super Admin role.', 403);
            }

            if ($staff->isSuperAdmin() && ! $assigningSuperAdmin) {
                $superAdminCount = Staff::query()
                    ->whereHas('roles', fn (Builder $q) => $q->where('slug', Role::SUPER_ADMIN_SLUG))
                    ->count();

                if ($superAdminCount <= 1 || (int) $actor->id === (int) $staff->id) {
                    throw new DomainException('Cannot remove protected Super Admin privileges.', 403);
                }
            }
        }

        if ($staff->isSuperAdmin() && array_key_exists('is_active', $data) && ! (bool) $data['is_active']) {
            throw new DomainException('Super Admin account cannot be deactivated.', 403);
        }

        /** @var Staff $updated */
        $updated = DB::transaction(function () use ($staff, $data): Staff {
            $updateFields = collect($data)->only(['name', 'email', 'phone', 'is_active'])->all();
            if (isset($updateFields['email'])) {
                $updateFields['email'] = strtolower((string) $updateFields['email']);
            }
            if (! empty($data['password'])) {
                $updateFields['password'] = Hash::make((string) $data['password']);
            }

            if ($updateFields !== []) {
                $staff->update($updateFields);
            }

            if (isset($data['role_ids'])) {
                $staff->roles()->sync(array_map('intval', $data['role_ids']));
            }

            return $staff->fresh()->load('roles.permissions');
        });

        return ApiResponse::success(new StaffResource($updated), 'Staff user updated successfully.');
    }

    public function destroy(Request $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user('admin');

        if ($staff->isSuperAdmin()) {
            throw new DomainException('Super Admin accounts cannot be deleted.', 403);
        }

        if ((int) $actor->id === (int) $staff->id) {
            throw new DomainException('You cannot delete your own staff account.', 403);
        }

        $staff->tokens()->delete();
        $staff->delete();

        return ApiResponse::success(null, 'Staff user deleted successfully.');
    }
}
