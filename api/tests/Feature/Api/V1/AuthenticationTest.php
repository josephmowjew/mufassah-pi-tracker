<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Auth;
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

    it('rejects login for a suspended account', function () {
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => 'password',
            'suspended_at' => now(),
        ]);

        $response = postJson(apiUrl('auth/login'), [
            'email' => 'suspended@example.com',
            'password' => 'password',
        ]);

        $response->assertForbidden()
            ->assertJson(['success' => false]);

        // No token may be issued to a suspended account.
        expect($user->tokens()->count())->toBe(0);
    });

    it('does not disclose suspension to a caller with the wrong password', function () {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => 'password',
            'suspended_at' => now(),
        ]);

        // Wrong password on a suspended account must look identical to any other
        // failed login, so account state is not leaked to an attacker.
        $response = postJson(apiUrl('auth/login'), [
            'email' => 'suspended@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized();
    });

    it('allows login again once an account is unsuspended', function () {
        $user = User::factory()->create([
            'email' => 'restored@example.com',
            'password' => 'password',
            'suspended_at' => now(),
        ]);

        $user->update(['suspended_at' => null]);

        $response = postJson(apiUrl('auth/login'), [
            'email' => 'restored@example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
        expect($response->json('data.token'))->not->toBeNull();
    });
});

describe('Suspended Account Enforcement', function () {
    it('blocks an existing token once the account is suspended', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        // Token works before suspension.
        getJson(apiUrl('user'), ['Authorization' => "Bearer {$token}"])->assertOk();

        // Suspension takes effect immediately, even for an already-issued token.
        $user->update(['suspended_at' => now()]);

        // The auth guard caches the resolved user for the lifetime of the
        // application instance, so it must be reset for the next request to
        // re-resolve from the database (as a fresh request would in production).
        Auth::forgetGuards();

        getJson(apiUrl('user'), ['Authorization' => "Bearer {$token}"])
            ->assertForbidden()
            ->assertJson(['success' => false]);
    });

    it('does not block unsuspended accounts', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        getJson(apiUrl('user'), ['Authorization' => "Bearer {$token}"])->assertOk();
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
