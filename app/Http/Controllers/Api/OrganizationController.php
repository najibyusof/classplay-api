<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Organization\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OrganizationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly OrganizationService $organizationService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        $perPage = min((int) $request->integer('per_page', 20), 100) ?: 20;

        $organizations = Organization::query()
            ->whereHas('organizationAdmins', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'active'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($perPage);

        return $this->successResponse([
            'organizations' => OrganizationResource::collection($organizations)->resolve(),
            'pagination' => [
                'current_page' => $organizations->currentPage(),
                'per_page' => $organizations->perPage(),
                'total' => $organizations->total(),
                'last_page' => $organizations->lastPage(),
            ],
        ], 'Organizations retrieved successfully.');
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $this->authorize('create', Organization::class);

        $organization = $this->organizationService->create($request->validated(), $request->user());

        return $this->successResponse(
            new OrganizationResource($organization),
            'Organization created successfully.',
            201
        );
    }

    public function show(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return $this->successResponse(new OrganizationResource($organization), 'Organization retrieved successfully.');
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $organization = $this->organizationService->update($organization, $request->validated(), $request->user());

        return $this->successResponse(new OrganizationResource($organization), 'Organization updated successfully.');
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('delete', $organization);

        try {
            $this->organizationService->delete($organization, $request->user());
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage());
        }

        return $this->successResponse(null, 'Organization deleted successfully.');
    }
}
