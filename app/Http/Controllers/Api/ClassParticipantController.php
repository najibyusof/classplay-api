<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassManagement\StoreClassParticipantRequest;
use App\Http\Requests\ClassManagement\UpdateClassParticipantRequest;
use App\Http\Resources\ClassParticipantResource;
use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\User;
use App\Services\Participant\ParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ClassParticipantController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ParticipantService $participantService) {}

    public function index(ClassModel $class): JsonResponse
    {
        $this->authorize('view', $class);

        $participants = $class->participants()->with('user')->get();

        return $this->successResponse(
            ClassParticipantResource::collection($participants),
            'Class participants retrieved successfully.'
        );
    }

    public function store(StoreClassParticipantRequest $request, ClassModel $class): JsonResponse
    {
        $this->authorize('create', [ClassParticipant::class, $class]);

        $participantUser = User::query()->findOrFail($request->validated('user_id'));

        try {
            $participant = $this->participantService->add(
                $class,
                $participantUser,
                $request->validated('participant_type'),
                $request->user(),
            );
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage());
        }

        return $this->successResponse(
            new ClassParticipantResource($participant->load('user')),
            'Participant added successfully.',
            201
        );
    }

    public function update(UpdateClassParticipantRequest $request, ClassModel $class, ClassParticipant $participant): JsonResponse
    {
        $this->authorize('update', $participant);

        $participant = $this->participantService->updateStatus(
            $participant,
            $request->validated(),
            $request->user(),
        );

        return $this->successResponse(
            new ClassParticipantResource($participant->load('user')),
            'Participant updated successfully.'
        );
    }

    public function destroy(Request $request, ClassModel $class, ClassParticipant $participant): JsonResponse
    {
        $this->authorize('delete', $participant);

        $this->participantService->remove($participant, $request->user());

        return $this->successResponse(null, 'Participant removed successfully.');
    }
}
