<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassManagement\StoreClassScheduleRequest;
use App\Http\Requests\ClassManagement\UpdateClassScheduleRequest;
use App\Http\Resources\ClassScheduleResource;
use App\Models\ClassModel;
use App\Models\ClassSchedule;
use Illuminate\Http\JsonResponse;

class ClassScheduleController extends Controller
{
    use ApiResponseTrait;

    public function index(ClassModel $class): JsonResponse
    {
        $this->authorize('view', $class);

        $schedules = $class->schedules()->get();

        return $this->successResponse([
            'schedules' => ClassScheduleResource::collection($schedules)->resolve(),
        ], 'Class schedules retrieved successfully.');
    }

    public function store(StoreClassScheduleRequest $request, ClassModel $class): JsonResponse
    {
        $this->authorize('create', [ClassSchedule::class, $class]);

        $schedule = $class->schedules()->create($request->validated());

        return $this->successResponse(
            new ClassScheduleResource($schedule),
            'Class schedule created successfully.',
            201
        );
    }

    public function update(UpdateClassScheduleRequest $request, ClassSchedule $classSchedule): JsonResponse
    {
        $this->authorize('update', $classSchedule);

        $classSchedule->update($request->validated());

        return $this->successResponse(
            new ClassScheduleResource($classSchedule),
            'Class schedule updated successfully.'
        );
    }

    public function destroy(ClassSchedule $classSchedule): JsonResponse
    {
        $this->authorize('delete', $classSchedule);

        $classSchedule->delete();

        return $this->successResponse(null, 'Class schedule deleted successfully.');
    }
}
