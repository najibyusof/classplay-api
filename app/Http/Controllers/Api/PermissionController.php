<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\StorePermissionRequest;
use App\Http\Requests\Rbac\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $permissions = Permission::query()->paginate($perPage);

        return $this->successResponse([
            'permissions' => PermissionResource::collection($permissions)->resolve(),
            'pagination' => [
                'current_page' => $permissions->currentPage(),
                'per_page' => $permissions->perPage(),
                'total' => $permissions->total(),
                'last_page' => $permissions->lastPage(),
            ],
        ], 'Permissions retrieved successfully.');
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $this->authorize('create', Permission::class);

        $permission = Permission::query()->create($request->validated());

        return $this->successResponse(
            new PermissionResource($permission),
            'Permission created successfully.',
            201
        );
    }

    public function show(Permission $permission): JsonResponse
    {
        $this->authorize('view', $permission);

        return $this->successResponse(
            new PermissionResource($permission),
            'Permission retrieved successfully.'
        );
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $this->authorize('update', $permission);

        $permission->update($request->validated());

        return $this->successResponse(
            new PermissionResource($permission),
            'Permission updated successfully.'
        );
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $this->authorize('delete', $permission);

        $permission->delete();

        return $this->successResponse(null, 'Permission deleted successfully.');
    }
}
