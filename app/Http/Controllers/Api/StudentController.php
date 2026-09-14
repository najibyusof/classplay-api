<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\StoreStudentRequest;
use App\Http\Requests\Participant\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\User;
use App\Services\Participant\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly StudentService $studentService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $organizationIds = $request->user()->organizationAdmins()->where('status', 'active')->pluck('organization_id');
        $perPage = min((int) $request->integer('per_page', 20), 100) ?: 20;

        $students = User::query()
            ->where('user_type', 'student')
            // ->whereHas('classParticipants.classModel', fn ($query) => $query->whereIn('organization_id', $organizationIds))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($perPage);

        return $this->successResponse([
            'students' => StudentResource::collection($students)->resolve(),
            'pagination' => [
                'current_page' => $students->currentPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'last_page' => $students->lastPage(),
            ],
        ], 'Students retrieved successfully.');
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $student = $this->studentService->create($request->validated(), $request->user());

        return $this->successResponse(new StudentResource($student), 'Student created successfully.', 201);
    }

    public function show(User $student): JsonResponse
    {
        abort_unless($student->user_type === 'student', 404);
        $this->authorize('view', $student);

        $student->load('classParticipants.classModel');

        return $this->successResponse(new StudentResource($student), 'Student retrieved successfully.');
    }

    public function update(UpdateStudentRequest $request, User $student): JsonResponse
    {
        abort_unless($student->user_type === 'student', 404);
        $this->authorize('update', $student);

        $student = $this->studentService->update($student, $request->validated(), $request->user());

        return $this->successResponse(new StudentResource($student), 'Student updated successfully.');
    }

    public function destroy(Request $request, User $student): JsonResponse
    {
        abort_unless($student->user_type === 'student', 404);
        $this->authorize('delete', $student);

        $this->studentService->delete($student, $request->user());

        return $this->successResponse(null, 'Student deleted successfully.');
    }
}
