<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationAdminRequest;
use App\Http\Requests\Organization\UpdateOrganizationAdminRequest;
use App\Http\Resources\OrganizationAdminResource;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use App\Services\Organization\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OrganizationAdminController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly OrganizationService $organizationService) {}

    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('viewAny', [OrganizationAdmin::class, $organization]);

        $admins = $organization->organizationAdmins()->with('user')->get();

        return $this->successResponse(
            OrganizationAdminResource::collection($admins),
            'Organization administrators retrieved successfully.'
        );
    }

    public function store(StoreOrganizationAdminRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('create', [OrganizationAdmin::class, $organization]);

        $targetUser = User::query()->findOrFail($request->validated('user_id'));

        $admin = $this->organizationService->addAdmin(
            $organization,
            $targetUser,
            (bool) $request->validated('is_primary', false),
            $request->user(),
        );

        return $this->successResponse(
            new OrganizationAdminResource($admin->load('user')),
            'Organization administrator added successfully.',
            201
        );
    }

    public function update(UpdateOrganizationAdminRequest $request, Organization $organization, User $user): JsonResponse
    {
        $organizationAdmin = $organization->organizationAdmins()->where('user_id', $user->id)->firstOrFail();

        $this->authorize('update', $organizationAdmin);

        $organizationAdmin = $this->organizationService->updateAdmin(
            $organization,
            $organizationAdmin,
            $request->validated(),
            $request->user(),
        );

        return $this->successResponse(
            new OrganizationAdminResource($organizationAdmin->load('user')),
            'Organization administrator updated successfully.'
        );
    }

    public function destroy(Request $request, Organization $organization, User $user): JsonResponse
    {
        $organizationAdmin = $organization->organizationAdmins()->where('user_id', $user->id)->firstOrFail();

        $this->authorize('delete', $organizationAdmin);

        try {
            $this->organizationService->removeAdmin($organization, $organizationAdmin, $request->user());
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage());
        }

        return $this->successResponse(null, 'Organization administrator removed successfully.');
    }
}
