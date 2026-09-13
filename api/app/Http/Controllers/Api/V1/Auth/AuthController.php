<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * @group Authentication
 *
 * APIs for user authentication - registration, login, logout, and token management.
 */
class AuthController extends BaseController
{
    /**
     * Register a new user.
     *
     * Create a new user account and receive an authentication token.
     *
     * @unauthenticated
     *
     * @response 201 scenario="Success" {"success":true,"message":"Registration successful. Please check your email to verify your account.","data":{"user":{"id":1,"name":"John Doe","email":"john@example.com","roles":["customer"]},"token":"1|abc123..."}}
     * @response 422 scenario="Validation Error" {"success":false,"message":"Validation failed.","errors":{"email":["The email has already been taken."]}}
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // Hashed automatically via 'hashed' cast
            'phone_number' => $request->phone_number,
        ]);

        // Assign customer role by default
        $customerRole = Role::findByName('customer', 'api');
        $user->assignRole($customerRole);

        // Send email verification notification
        $user->sendEmailVerificationNotification();

        // Send welcome email
        $user->notify(new WelcomeNotification);

        // Create API token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Load roles, permissions, and branch for resource
        $user->load('roles', 'permissions', 'branch');

        return $this->created([
            'user' => new UserResource($user),
            'token' => $token,
        ], 'Registration successful. Please check your email to verify your account.');
    }

    /**
     * Login user and create token.
     *
     * Authenticate with email and password to receive an API token.
     *
     * @unauthenticated
     *
     * @response 200 scenario="Success" {"success":true,"message":"Login successful.","data":{"user":{"id":1,"name":"John Doe","email":"john@example.com","roles":["customer"]},"token":"2|xyz789..."}}
     * @response 401 scenario="Invalid Credentials" {"success":false,"message":"Invalid credentials. Please check your email and password."}
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->unauthorized('Invalid credentials. Please check your email and password.');
        }

        // A suspended account must not be able to obtain a new token. This is
        // checked after credential verification so that the response does not
        // disclose account state to a caller who does not know the password.
        if ($user->isSuspended()) {
            return $this->forbidden('Your account has been suspended. Please contact support for assistance.');
        }

        // Revoke all existing tokens for this user (optional - for single device login)
        // $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        // Load roles, permissions, and branch for resource
        $user->load('roles', 'permissions', 'branch');

        return $this->success([
            'user' => new UserResource($user),
            'token' => $token,
        ], 'Login successful.');
    }

    /**
     * Logout user (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * Get authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles', 'permissions', 'branch');

        return $this->success(new UserResource($user));
    }

    /**
     * Refresh token (revoke current and issue new).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Issue new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->success([
            'token' => $token,
        ], 'Token refreshed successfully.');
    }
}
