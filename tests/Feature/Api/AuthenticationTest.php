<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'phone' => '+60123456789',
            'password' => Hash::make('password123'),
        ], $attributes));
    }

    // 1. Successful login
    public function test_successful_login_returns_user_and_token(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '0123456789',
            'password' => 'password123',
            'device_name' => 'Android Phone',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'phone'], 'token', 'token_type']]);
    }

    // 2. Login with incorrect password
    public function test_login_with_incorrect_password_returns_generic_401(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+60123456789',
            'password' => 'wrong-password',
            'device_name' => 'Android Phone',
        ]);

        $response->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid phone number or password.',
                'errors' => [],
            ]);
    }

    // 3. Login with unknown phone
    public function test_login_with_unknown_phone_returns_same_generic_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+60199999999',
            'password' => 'password123',
            'device_name' => 'Android Phone',
        ]);

        $response->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid phone number or password.',
                'errors' => [],
            ]);
    }

    // 4. Login with inactive user
    public function test_login_with_inactive_user_returns_same_generic_401(): void
    {
        $this->createUser(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+60123456789',
            'password' => 'password123',
            'device_name' => 'Android Phone',
        ]);

        $response->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid phone number or password.',
                'errors' => [],
            ]);
    }

    // 5. Login token creation
    public function test_successful_login_creates_sanctum_token_and_updates_last_login(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+60123456789',
            'password' => 'password123',
            'device_name' => 'Android Phone',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Android Phone']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    // 6. Get current authenticated user
    public function test_authenticated_user_can_fetch_their_profile(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.phone', $user->phone)
            ->assertJsonMissingPath('data.user.password');
    }

    // 7. Get current user without token
    public function test_me_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized()
            ->assertJson(['success' => false, 'errors' => []]);
    }

    // 8. Successful logout
    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->createUser();
        $tokenA = $user->createToken('Android Phone');
        $tokenB = $user->createToken('iPhone');

        $response = $this->withHeader('Authorization', "Bearer {$tokenA->plainTextToken}")
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Logout successful.',
            'data' => null,
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenA->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenB->accessToken->id]);
    }

    // 9. Logout without token
    public function test_logout_without_token_returns_401(): void
    {
        $this->postJson('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'errors' => []]);
    }

    // 10. Change password successfully
    public function test_change_password_successfully(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'password' => 'newPassword123',
            'password_confirmation' => 'newPassword123',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Password changed successfully.',
            'data' => null,
        ]);
        $this->assertTrue(Hash::check('newPassword123', $user->fresh()->password));
    }

    // 11. Change password with incorrect current password
    public function test_change_password_with_incorrect_current_password_returns_422(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'newPassword123',
            'password_confirmation' => 'newPassword123',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Current password is incorrect.',
            'errors' => [],
        ]);
    }

    // 12. Change password with mismatched confirmation
    public function test_change_password_with_mismatched_confirmation_returns_422(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'password' => 'newPassword123',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('password');
    }

    // 13. Set password successfully
    public function test_set_password_successfully_for_user_without_password(): void
    {
        $user = User::factory()->withoutPassword()->create();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/auth/set-password', [
            'password' => 'newPassword123',
            'password_confirmation' => 'newPassword123',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Password set successfully.',
            'data' => null,
        ]);
        $this->assertTrue(Hash::check('newPassword123', $user->fresh()->password));
    }

    // 14. Prevent set-password when user already has a password
    public function test_set_password_is_rejected_when_password_already_set(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/auth/set-password', [
            'password' => 'newPassword123',
            'password_confirmation' => 'newPassword123',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'A password has already been set for this account. Use change-password instead.',
            'errors' => [],
        ]);
    }

    // 15. Multiple device tokens
    public function test_user_can_login_from_multiple_devices(): void
    {
        $this->createUser();

        $this->postJson('/api/v1/auth/login', [
            'phone' => '+60123456789',
            'password' => 'password123',
            'device_name' => 'Android Phone',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'phone' => '+60123456789',
            'password' => 'password123',
            'device_name' => 'iPhone',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Android Phone']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'iPhone']);
    }

    // 16. Password is never returned in API response
    public function test_password_is_never_present_in_login_or_me_responses(): void
    {
        $user = $this->createUser();

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'phone' => '+60123456789',
            'password' => 'password123',
            'device_name' => 'Android Phone',
        ]);
        $loginResponse->assertJsonMissingPath('data.user.password');

        Sanctum::actingAs($user);
        $meResponse = $this->getJson('/api/v1/auth/me');
        $meResponse->assertJsonMissingPath('data.user.password');
    }

    // 17. User cannot access another user's authentication data
    public function test_authenticated_user_only_sees_their_own_profile(): void
    {
        $user = $this->createUser(['phone' => '+60111111111']);
        $otherUser = $this->createUser(['phone' => '+60122222222']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertNotSame($otherUser->id, $response->json('data.user.id'));
    }

    // Bonus: token refresh rotates the token used for the request
    public function test_refresh_token_revokes_old_token_and_issues_a_new_one(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('Android Phone');

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->postJson('/api/v1/auth/refresh-token');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'token_type']]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotSame($token->plainTextToken, $response->json('data.token'));
    }
}
