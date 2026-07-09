<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    // Ensure customer role exists
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
});

describe('User Registration', function () {
    it('allows user to register with valid data', function () {
        Notification::fake();

        $response = postJson(apiUrl('auth/register'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '+265999123456',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'phone_number'],
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'phone_number' => '+265999123456',
        ]);

        // Verify the user has customer role
        $user = User::where('email', 'john@example.com')->first();
        expect($user->hasRole('customer'))->toBeTrue();

        // Verify email verification notification was sent
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    it('rejects registration with invalid email', function () {
        $response = postJson(apiUrl('auth/register'), [
            'name' => 'John Doe',
            'email' => 'invalid-email',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '+265999123456',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('rejects registration with weak password', function () {
        $response = postJson(apiUrl('auth/register'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
            'phone_number' => '+265999123456',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    });

    it('rejects registration with mismatched password confirmation', function () {
        $response = postJson(apiUrl('auth/register'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'DifferentPassword123!',
            'phone_number' => '+265999123456',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    });

    it('rejects registration with duplicate email', function () {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = postJson(apiUrl('auth/register'), [
            'name' => 'John Doe',
            'email' => 'existing@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '+265999123456',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('rejects registration with invalid phone number', function () {
        $response = postJson(apiUrl('auth/register'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '12345',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number']);
    });

    it('formats phone number correctly during registration', function () {
        Notification::fake();

        $response = postJson(apiUrl('auth/register'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '0999123456', // Without country code
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'phone_number' => '+265999123456', // Formatted with country code
        ]);
    });
});

describe('User Login', function () {
    it('allows user to login with valid credentials', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response = postJson(apiUrl('auth/login'), [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ]);

        expect($response->json('data.token'))->not->toBeNull();
    });

    it('rejects login with invalid credentials', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response = postJson(apiUrl('auth/login'), [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized();
    });

    it('rejects login with non-existent email', function () {
        $response = postJson(apiUrl('auth/login'), [
            'email' => 'nonexistent@example.com',
            'password' => 'password',
        ]);

        $response->assertUnauthorized();
    });

    it('rejects login with missing credentials', function () {
        $response = postJson(apiUrl('auth/login'), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    });
});

describe('User Logout', function () {
    it('allows authenticated user to logout', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = postJson(apiUrl('auth/logout'), [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully.',
            ]);

        // Verify token is revoked
        expect($user->tokens()->count())->toBe(0);
    });

    it('rejects logout without authentication', function () {
        $response = postJson(apiUrl('auth/logout'));

        $response->assertUnauthorized();
    });
});

describe('Protected Routes', function () {
    it('denies access to protected routes without authentication', function () {
        $response = getJson(apiUrl('user'));

        $response->assertUnauthorized();
    });

    it('allows access to protected routes with valid token', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = getJson(apiUrl('user'), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'name', 'email'],
            ]);
    });

    it('denies access with invalid token', function () {
        $response = getJson(apiUrl('user'), [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $response->assertUnauthorized();
    });
});

describe('Token Refresh', function () {
    it('allows authenticated user to refresh token', function () {
        $user = User::factory()->create();
        $oldToken = $user->createToken('auth_token')->plainTextToken;

        $response = postJson(apiUrl('auth/refresh'), [], [
            'Authorization' => "Bearer {$oldToken}",
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['token'],
            ]);

        $newToken = $response->json('data.token');
        expect($newToken)->not->toBeNull();
        expect($newToken)->not->toBe($oldToken);
    });
});
