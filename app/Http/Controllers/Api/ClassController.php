<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassManagement\StoreClassRequest;
use App\Http\Requests\ClassManagement\UpdateClassRequest;
use App\Http\Resources\ClassResource;
use App\Models\ClassModel;
use App\Models\Organization;
use App\Services\Payment\PaymentScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly PaymentScheduleService $paymentScheduleService) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $classes = $organization->classes()->paginate($perPage);

        return $this->successResponse([
            'classes' => ClassResource::collection($classes)->resolve(),
            'pagination' => [
                'current_page' => $classes->currentPage(),
                'per_page' => $classes->perPage(),
                'total' => $classes->total(),
                'last_page' => $classes->lastPage(),
            ],
        ], 'Classes retrieved successfully.');
    }

    public function store(StoreClassRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('create', [ClassModel::class, $organization]);

        $class = $organization->classes()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return $this->successResponse(
            new ClassResource($class),
            'Class created successfully.',
            201
        );
    }

    public function show(ClassModel $class): JsonResponse
    {
        $this->authorize('view', $class);

        return $this->successResponse(
            new ClassResource($class),
            'Class retrieved successfully.'
        );
    }

    public function update(UpdateClassRequest $request, ClassModel $class): JsonResponse
    {
        $this->authorize('update', $class);

        $class->update($request->validated());

        return $this->successResponse(
            new ClassResource($class),
            'Class updated successfully.'
        );
    }

    public function destroy(ClassModel $class): JsonResponse
    {
        $this->authorize('delete', $class);

        $class->delete();

        return $this->successResponse(null, 'Class deleted successfully.');
    }

    /**
     * Activate a class and generate its initial payment schedules in one atomic step.
     */
    public function activate(ClassModel $class): JsonResponse
    {
        $this->authorize('update', $class);

        $class = DB::transaction(function () use ($class) {
            $class->update(['status' => 'active']);

            $this->paymentScheduleService->generateForClass($class);

            return $class;
        });

        return $this->successResponse(
            new ClassResource($class),
            'Class activated and payment schedules generated successfully.'
        );
    }
}
