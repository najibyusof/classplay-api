<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationLogoRequest;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\SponsorStudent;
use App\Services\Organization\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * List the organizations the authenticated user participates in (as a
     * student, or including organizations of students they sponsor), derived
     * from active class participations.
     */
    public function myOrganizations(Request $request): JsonResponse
    {
        $user = $request->user();

        $sponsoredStudentIds = SponsorStudent::query()
            ->where('sponsor_id', $user->id)
            ->where('status', 'active')
            ->pluck('student_id');

        $visibleUserIds = [$user->id, ...$sponsoredStudentIds->all()];

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $organizations = Organization::query()
            ->whereHas('classes.participants', fn ($query) => $query
                ->whereIn('user_id', $visibleUserIds)
                ->where('status', 'active'))
            ->orderBy('name')
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

    public function showLogo(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return $this->successResponse(
            ['logo_url' => $organization->logo_url],
            'Organization logo URL retrieved successfully.'
        );
    }

    /**
     * Stream the logo file directly through the application instead of relying
     * on the web server serving the `public/storage` symlink, so a missing
     * symlink or static-file permission issue on the host cannot break viewing.
     */
    public function streamLogo(Organization $organization): StreamedResponse
    {
        abort_if(! $organization->logo_path || ! Storage::disk('public')->exists($organization->logo_path), 404);

        return Storage::disk('public')->response($organization->logo_path);
    }

    public function uploadLogo(StoreOrganizationLogoRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $path = $request->file('logo')->store('organization-logos', 'public');

        if ($organization->logo_path) {
            Storage::disk('public')->delete($organization->logo_path);
        }

        $organization->update(['logo_path' => $path]);

        return $this->successResponse(
            new OrganizationResource($organization->refresh()),
            'Organization logo uploaded successfully.'
        );
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
