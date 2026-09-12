<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\StoreRoleRequest;
use App\Http\Requests\Rbac\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $roles = Role::query()->paginate($perPage);

        return $this->successResponse([
            'roles' => RoleResource::collection($roles)->resolve(),
            'pagination' => [
                'current_page' => $roles->currentPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'last_page' => $roles->lastPage(),
            ],
        ], 'Roles retrieved successfully.');
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = Role::query()->create($request->validated());

        return $this->successResponse(
            new RoleResource($role),
            'Role created successfully.',
            201
        );
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return $this->successResponse(
            new RoleResource($role),
            'Role retrieved successfully.'
        );
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $role->update($request->validated());

        return $this->successResponse(
            new RoleResource($role),
            'Role updated successfully.'
        );
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $role->delete();

        return $this->successResponse(null, 'Role deleted successfully.');
    }
}
