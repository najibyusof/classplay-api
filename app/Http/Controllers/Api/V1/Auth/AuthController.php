<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\Authentication\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly AuthenticationService $authenticationService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authenticationService->login(
            $request->string('phone')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name')->toString(),
        );

        return $this->successResponse([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ], 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authenticationService->logout($request->user()->currentAccessToken());

        return $this->successResponse(null, 'Logout successful.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->successResponse([
            'user' => new UserResource($request->user()),
        ], 'Authenticated user retrieved successfully.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authenticationService->changePassword(
            $request->user(),
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
            $request->user()->currentAccessToken()?->id,
        );

        return $this->successResponse(null, 'Password changed successfully.');
    }

    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        $this->authenticationService->setPassword(
            $request->user(),
            $request->string('password')->toString(),
        );

        return $this->successResponse(null, 'Password set successfully.');
    }

    /**
     * Rotate the current Sanctum token: Sanctum personal access tokens have no
     * built-in refresh/expiry exchange like JWT, so "refresh" here means
     * revoking the token used for this request and issuing a new one with
     * the same device name.
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $token = $this->authenticationService->refreshToken(
            $request->user(),
            $request->user()->currentAccessToken(),
        );

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Token refreshed successfully.');
    }
}
