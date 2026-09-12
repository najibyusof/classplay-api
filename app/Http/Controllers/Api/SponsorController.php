<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\StoreSponsorRequest;
use App\Http\Requests\Participant\UpdateSponsorRequest;
use App\Http\Resources\SponsorResource;
use App\Models\User;
use App\Services\Participant\SponsorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SponsorController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly SponsorService $sponsorService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $organizationIds = $request->user()->organizationAdmins()->where('status', 'active')->pluck('organization_id');
        $perPage = min((int) $request->integer('per_page', 20), 100) ?: 20;

        $sponsors = User::query()
            ->where('user_type', 'sponsor')
            ->whereHas('classParticipants.classModel', fn ($query) => $query->whereIn('organization_id', $organizationIds))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($perPage);

        return $this->successResponse([
            'sponsors' => SponsorResource::collection($sponsors)->resolve(),
            'pagination' => [
                'current_page' => $sponsors->currentPage(),
                'per_page' => $sponsors->perPage(),
                'total' => $sponsors->total(),
                'last_page' => $sponsors->lastPage(),
            ],
        ], 'Sponsors retrieved successfully.');
    }

    public function store(StoreSponsorRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $sponsor = $this->sponsorService->create($request->validated(), $request->user());

        return $this->successResponse(new SponsorResource($sponsor), 'Sponsor created successfully.', 201);
    }

    public function show(User $sponsor): JsonResponse
    {
        abort_unless($sponsor->user_type === 'sponsor', 404);
        $this->authorize('view', $sponsor);

        $sponsor->load('classParticipants.classModel');

        return $this->successResponse(new SponsorResource($sponsor), 'Sponsor retrieved successfully.');
    }

    public function update(UpdateSponsorRequest $request, User $sponsor): JsonResponse
    {
        abort_unless($sponsor->user_type === 'sponsor', 404);
        $this->authorize('update', $sponsor);

        $sponsor = $this->sponsorService->update($sponsor, $request->validated(), $request->user());

        return $this->successResponse(new SponsorResource($sponsor), 'Sponsor updated successfully.');
    }

    public function destroy(Request $request, User $sponsor): JsonResponse
    {
        abort_unless($sponsor->user_type === 'sponsor', 404);
        $this->authorize('delete', $sponsor);

        $this->sponsorService->delete($sponsor, $request->user());

        return $this->successResponse(null, 'Sponsor deleted successfully.');
    }
}
