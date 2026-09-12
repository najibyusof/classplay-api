<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\StoreUserDeviceRequest;
use App\Http\Requests\Notification\UpdateUserDeviceRequest;
use App\Http\Resources\UserDeviceResource;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserDeviceController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->devices()->latest('last_seen_at')->get();

        return $this->successResponse([
            'devices' => UserDeviceResource::collection($devices)->resolve(),
        ], 'User devices retrieved successfully.');
    }

    public function store(StoreUserDeviceRequest $request): JsonResponse
    {
        $this->authorize('create', UserDevice::class);

        $validated = $request->validated();
        $token = $validated['device_token'];

        // Update existing device if token exists (prevent uncontrolled duplicate tokens)
        $device = UserDevice::query()->where('device_token', $token)->first();

        if ($device) {
            $device->update([
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? $device->device_name,
                'app_version' => $validated['app_version'] ?? $device->app_version,
                'status' => 'active',
                'last_seen_at' => now(),
            ]);
        } else {
            $device = $request->user()->devices()->create([
                ...$validated,
                'status' => 'active',
                'last_seen_at' => now(),
            ]);
        }

        return $this->successResponse([
            'device' => new UserDeviceResource($device),
        ], 'Device registered successfully.', 201);
    }

    public function show(UserDevice $device): JsonResponse
    {
        $this->authorize('view', $device);

        return $this->successResponse([
            'device' => new UserDeviceResource($device),
        ], 'Device retrieved successfully.');
    }

    public function update(UpdateUserDeviceRequest $request, UserDevice $device): JsonResponse
    {
        $this->authorize('update', $device);

        $device->update([
            ...$request->validated(),
            'last_seen_at' => now(),
        ]);

        return $this->successResponse([
            'device' => new UserDeviceResource($device),
        ], 'Device updated successfully.');
    }

    public function destroy(UserDevice $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $device->update(['status' => 'inactive']);

        return $this->successResponse(null, 'Device deactivated successfully.');
    }
}
