<?php

namespace App\Services\Authentication;

use App\Exceptions\Auth\AuthenticationFailedException;
use App\Exceptions\Auth\IncorrectCurrentPasswordException;
use App\Exceptions\Auth\PasswordAlreadySetException;
use App\Models\User;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationService
{
    /**
     * @return array{user: User, token: string}
     */
    public function register(string $name, string $phone, ?string $email, string $password, string $userType, string $deviceName): array
    {
        $user = User::query()->create([
            'name' => $name,
            'phone' => PhoneNumberNormalizer::normalize($phone),
            'email' => $email,
            'password' => $password,
            'user_type' => $userType,
            'status' => 'active',
        ]);

        return [
            'user' => $user,
            'token' => $user->createToken($deviceName)->plainTextToken,
        ];
    }

    /**
     * @return array{user: User, token: string}
     */
    public function login(string $phone, string $password, string $deviceName): array
    {
        $user = User::query()
            ->where('phone', PhoneNumberNormalizer::normalize($phone))
            ->first();

        if (! $user || ! $user->password || $user->status !== 'active' || ! Hash::check($password, $user->password)) {
            throw new AuthenticationFailedException;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($deviceName)->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    public function logout(PersonalAccessToken $token): void
    {
        $token->delete();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword, ?int $currentTokenId): void
    {
        if (! $user->password || ! Hash::check($currentPassword, $user->password)) {
            throw new IncorrectCurrentPasswordException;
        }

        $user->forceFill(['password' => $newPassword])->save();

        $user->tokens()->when(
            $currentTokenId,
            fn ($query) => $query->where('id', '!=', $currentTokenId)
        )->delete();
    }

    public function setPassword(User $user, string $newPassword): void
    {
        if (! empty($user->password)) {
            throw new PasswordAlreadySetException;
        }

        $user->forceFill(['password' => $newPassword])->save();
    }

    public function refreshToken(User $user, PersonalAccessToken $currentToken): string
    {
        $deviceName = $currentToken->name;

        $currentToken->delete();

        return $user->createToken($deviceName)->plainTextToken;
    }
}
