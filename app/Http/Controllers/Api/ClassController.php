<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassManagement\StoreClassRequest;
use App\Http\Requests\ClassManagement\UpdateClassRequest;
use App\Http\Resources\ClassResource;
use App\Models\ClassModel;
use App\Models\Organization;
use App\Models\SponsorStudent;
use App\Services\Payment\PaymentScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        $validated = $request->validated();

        $class = DB::transaction(function () use ($request, $organization, $validated) {
            $class = $organization->classes()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'teacher_name' => $validated['teacher_name'],
                'day_of_week' => $validated['day_of_week'],
                'start_time' => $validated['start_time'],
                'frequency' => $validated['recurrence_type'],
                'payment_amount' => $validated['payment_amount'],
                'status' => $validated['status'] ?? 'draft',
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $class->schedules()->create([
                'day_of_week' => $validated['day_of_week'],
                'start_time' => $validated['start_time'],
                'recurrence_type' => $validated['recurrence_type'],
                'effective_from' => $validated['start_date'] ?? now()->toDateString(),
            ]);

            $class->paymentSetting()->create([
                'required_amount' => $validated['payment_amount'],
                'currency' => 'MYR',
                'payment_frequency' => $validated['recurrence_type'],
            ]);

            return $class->load(['schedules', 'paymentSetting']);
        });

        return $this->successResponse(
            new ClassResource($class),
            'Class created successfully.',
            201
        );
    }

    /**
     * List the classes the authenticated user participates in (as a student,
     * or including classes of students they sponsor).
     */
    public function myClasses(Request $request): JsonResponse
    {
        $user = $request->user();

        $sponsoredStudentIds = SponsorStudent::query()
            ->where('sponsor_id', $user->id)
            ->where('status', 'active')
            ->pluck('student_id');

        $visibleUserIds = [$user->id, ...$sponsoredStudentIds->all()];

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $classes = ClassModel::query()
            ->whereHas('participants', fn ($query) => $query
                ->whereIn('user_id', $visibleUserIds)
                ->where('status', 'active'))
            ->with(['organization', 'paymentSetting'])
            ->orderBy('name')
            ->paginate($perPage);

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

    public function show(ClassModel $class): JsonResponse
    {
        $this->authorize('view', $class);

        return $this->successResponse(
            new ClassResource($class),
            'Class retrieved successfully.'
        );
    }

    public function showScoped(Organization $organization, ClassModel $class): JsonResponse
    {
        return $this->show($class);
    }

    public function update(UpdateClassRequest $request, ClassModel $class): JsonResponse
    {
        $this->authorize('update', $class);

        $validated = $request->validated();

        DB::transaction(function () use ($class, $validated): void {
            // recurrence_type is the request field name; ClassModel stores it as `frequency`.
            $classData = Arr::except($validated, 'recurrence_type');
            if (array_key_exists('recurrence_type', $validated)) {
                $classData['frequency'] = $validated['recurrence_type'];
            }
            $class->update($classData);

            if (array_intersect(['day_of_week', 'start_time', 'recurrence_type'], array_keys($validated)) !== []) {
                $class->schedules()->first()?->update(array_filter([
                    'day_of_week' => $validated['day_of_week'] ?? null,
                    'start_time' => $validated['start_time'] ?? null,
                    'recurrence_type' => $validated['recurrence_type'] ?? null,
                ], fn ($value) => $value !== null));
            }

            if (array_intersect(['payment_amount', 'recurrence_type'], array_keys($validated)) !== []) {
                $class->paymentSetting?->update(array_filter([
                    'required_amount' => $validated['payment_amount'] ?? null,
                    'payment_frequency' => $validated['recurrence_type'] ?? null,
                ], fn ($value) => $value !== null));
            }
        });

        return $this->successResponse(
            new ClassResource($class->fresh(['schedules', 'paymentSetting'])),
            'Class updated successfully.'
        );
    }

    public function updateScoped(UpdateClassRequest $request, Organization $organization, ClassModel $class): JsonResponse
    {
        return $this->update($request, $class);
    }

    public function destroy(ClassModel $class): JsonResponse
    {
        $this->authorize('delete', $class);

        $class->delete();

        return $this->successResponse(null, 'Class deleted successfully.');
    }

    public function destroyScoped(Organization $organization, ClassModel $class): JsonResponse
    {
        return $this->destroy($class);
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

    public function activateScoped(Organization $organization, ClassModel $class): JsonResponse
    {
        return $this->activate($class);
    }
}
