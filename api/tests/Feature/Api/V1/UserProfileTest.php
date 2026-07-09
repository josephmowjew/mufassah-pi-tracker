<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

describe('User Profile', function () {
    it('returns authenticated user profile', function () {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = getJson(apiUrl('user'), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'John Doe',
                    'email' => 'john@example.com',
                ],
            ]);
    });

    it('updates user profile with valid data', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = putJson(apiUrl('user'), [
            'name' => 'Updated Name',
            'address' => 'Area 47, Lilongwe',
            'city' => 'Lilongwe',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Profile updated successfully.',
                'data' => [
                    'name' => 'Updated Name',
                    'address' => 'Area 47, Lilongwe',
                    'city' => 'Lilongwe',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'address' => 'Area 47, Lilongwe',
        ]);
    });

    it('updates phone number with formatting', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = putJson(apiUrl('user'), [
            'phone_number' => '0888123456',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone_number' => '+265888123456',
        ]);
    });

    it('rejects duplicate phone number update', function () {
        $existingUser = User::factory()->create(['phone_number' => '+265999111111']);
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = putJson(apiUrl('user'), [
            'phone_number' => '+265999111111',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number']);
    });
});

describe('Password Change', function () {
    it('allows a customer-service user to change their own password', function () {
        Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'api']);

        $user = User::factory()->create([
            'password' => 'OldPassword123!',
        ]);
        $user->assignRole('customer_service');
        $token = $user->createToken('auth_token')->plainTextToken;

        putJson(apiUrl('user/password'), [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        postJson(apiUrl('auth/login'), [
            'email' => $user->email,
            'password' => 'NewPassword456!',
        ])->assertOk();
    });

    it('changes password with valid current password', function () {
        $user = User::factory()->create([
            'password' => 'OldPassword123!',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = putJson(apiUrl('user/password'), [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Password changed successfully.',
            ]);

        // Verify new password works
        $loginResponse = postJson(apiUrl('auth/login'), [
            'email' => $user->email,
            'password' => 'NewPassword456!',
        ]);

        $loginResponse->assertOk();
    });

    it('rejects password change with wrong current password', function () {
        $user = User::factory()->create([
            'password' => 'OldPassword123!',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = putJson(apiUrl('user/password'), [
            'current_password' => 'WrongPassword!',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    });

    it('rejects password change when new password is same as current', function () {
        $user = User::factory()->create([
            'password' => 'SamePassword123!',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = putJson(apiUrl('user/password'), [
            'current_password' => 'SamePassword123!',
            'password' => 'SamePassword123!',
            'password_confirmation' => 'SamePassword123!',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    });
});

describe('Avatar Management', function () {
    beforeEach(function () {
        Storage::fake('public');
    });

    it('uploads avatar successfully', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = postJson(apiUrl('user/avatar'), [
            'avatar' => $file,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Avatar uploaded successfully.',
            ]);

        $user->refresh();
        expect($user->avatar)->not->toBeNull();
        Storage::disk('public')->assertExists($user->avatar);
    });

    it('rejects invalid file type for avatar', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = postJson(apiUrl('user/avatar'), [
            'avatar' => $file,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar']);
    });

    it('rejects oversized avatar', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $file = UploadedFile::fake()->image('avatar.jpg')->size(3000); // 3MB

        $response = postJson(apiUrl('user/avatar'), [
            'avatar' => $file,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar']);
    });

    it('deletes avatar successfully', function () {
        Storage::disk('public')->put('avatars/test-avatar.jpg', 'fake content');

        $user = User::factory()->create(['avatar' => 'avatars/test-avatar.jpg']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = deleteJson(apiUrl('user/avatar'), [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Avatar deleted successfully.',
            ]);

        $user->refresh();
        expect($user->avatar)->toBeNull();
        Storage::disk('public')->assertMissing('avatars/test-avatar.jpg');
    });

    it('returns error when deleting non-existent avatar', function () {
        $user = User::factory()->create(['avatar' => null]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = deleteJson(apiUrl('user/avatar'), [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'No avatar to delete.',
            ]);
    });

    it('replaces old avatar when uploading new one', function () {
        Storage::disk('public')->put('avatars/old-avatar.jpg', 'old content');

        $user = User::factory()->create(['avatar' => 'avatars/old-avatar.jpg']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $newFile = UploadedFile::fake()->image('new-avatar.jpg', 200, 200);

        $response = postJson(apiUrl('user/avatar'), [
            'avatar' => $newFile,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk();

        // Old avatar should be deleted
        Storage::disk('public')->assertMissing('avatars/old-avatar.jpg');

        // New avatar should exist
        $user->refresh();
        Storage::disk('public')->assertExists($user->avatar);
    });
});
