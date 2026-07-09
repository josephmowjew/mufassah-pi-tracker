<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @group User Profile
 *
 * APIs for user profile management - view and update profile, change password.
 */
class UserController extends BaseController
{
    /**
     * Get the authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles');

        return $this->success(new UserResource($user));
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update($request->validated());

        $user->load('roles');

        return $this->success(new UserResource($user), 'Profile updated successfully.');
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'password' => $request->password, // Hashed automatically via 'hashed' cast
        ]);

        // Optionally revoke all other tokens except current
        // $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return $this->success(null, 'Password changed successfully.');
    }

    /**
     * Upload avatar for the authenticated user.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
        ]);

        $user = $request->user();

        // Delete old avatar if exists
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        // Store new avatar
        $path = $request->file('avatar')->store('avatars', 'public');

        $user->update(['avatar' => $path]);

        $user->load('roles');

        return $this->success(new UserResource($user), 'Avatar uploaded successfully.');
    }

    /**
     * Delete avatar for the authenticated user.
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->avatar) {
            return $this->error('No avatar to delete.', 400);
        }

        Storage::disk('public')->delete($user->avatar);

        $user->update(['avatar' => null]);

        $user->load('roles');

        return $this->success(new UserResource($user), 'Avatar deleted successfully.');
    }
}
