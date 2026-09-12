<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sponsorship\StoreSponsorStudentRequest;
use App\Http\Resources\SponsorStudentResource;
use App\Models\SponsorStudent;
use App\Models\User;
use App\Services\Participant\SponsorStudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SponsorStudentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly SponsorStudentService $sponsorStudentService) {}

    public function index(User $sponsor): JsonResponse
    {
        abort_unless($sponsor->user_type === 'sponsor', 404);
        $this->authorize('viewAny', [SponsorStudent::class, $sponsor]);

        $sponsorStudents = $sponsor->sponsoredStudents()->with(['sponsor', 'student'])->get();

        return $this->successResponse(
            SponsorStudentResource::collection($sponsorStudents),
            'Sponsored students retrieved successfully.'
        );
    }

    public function store(StoreSponsorStudentRequest $request, User $sponsor): JsonResponse
    {
        abort_unless($sponsor->user_type === 'sponsor', 404);

        $student = User::query()->findOrFail($request->validated('student_id'));

        $this->authorize('create', [SponsorStudent::class, $sponsor, $student]);

        $sponsorStudent = $this->sponsorStudentService->add(
            $sponsor,
            $student,
            $request->validated('relationship_type'),
            $request->user(),
        );

        return $this->successResponse(
            new SponsorStudentResource($sponsorStudent->load(['sponsor', 'student'])),
            'Sponsored student added successfully.',
            201
        );
    }

    public function destroy(Request $request, User $sponsor, User $student): JsonResponse
    {
        abort_unless($sponsor->user_type === 'sponsor', 404);

        $sponsorStudent = SponsorStudent::query()
            ->where('sponsor_id', $sponsor->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        $this->authorize('delete', $sponsorStudent);

        $this->sponsorStudentService->remove($sponsorStudent, $request->user());

        return $this->successResponse(null, 'Sponsored student removed successfully.');
    }
}
