<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * @group Admin User Management
 *
 * APIs for managing users - create, update, suspend, and assign roles.
 */
class AdminUserController extends Controller
{
    /**
     * List all users with filtering and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $branchId = $request->user()->hasRole('super_admin')
            ? null
            : $request->user()->requiredBranchScopeId();

        $users = User::query()
            ->with(['roles', 'branch'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('role'), function ($query) use ($request) {
                $query->whereHas('roles', function ($q) use ($request) {
                    $q->where('name', $request->input('role'));
                });
            })
            ->when($request->has('verified'), function ($query) use ($request) {
                if ($request->boolean('verified')) {
                    $query->whereNotNull('email_verified_at');
                } else {
                    $query->whereNull('email_verified_at');
                }
            })
            ->when($request->filled('sort'), function ($query) use ($request) {
                $sortField = $request->input('sort');
                $sortDirection = $request->input('direction', 'asc');
                $query->orderBy($sortField, $sortDirection);
            }, function ($query) {
                $query->latest();
            })
            ->paginate($request->input('per_page', 15));

        return UserResource::collection($users);
    }

    /**
     * Create a new user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [Rule::requiredIf(fn () => $request->input('role') !== 'customer'), 'nullable', 'string', 'email', 'max:255', 'unique:users'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'password' => [Rule::requiredIf(fn () => $request->input('role') !== 'customer'), 'nullable', Password::defaults()],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:50', 'unique:users'],
            'verify_email' => ['boolean'],
        ]);

        if (! $request->user()->hasRole('super_admin') && $validated['role'] === 'super_admin') {
            abort(403, 'Only super admins can create super admin users.');
        }

        $branchId = $request->user()->hasRole('super_admin')
            ? ($validated['branch_id']
                ?? $request->user()?->branch_id
                ?? Branch::query()->where('is_active', true)->value('id')
                ?? Branch::query()->value('id'))
            : $request->user()->requiredBranchScopeId();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'password' => Hash::make($validated['password'] ?? Str::random(32)),
            'branch_id' => $branchId,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'national_id' => $validated['national_id'] ?? null,
            'email_verified_at' => ($validated['email'] ?? null) && $request->boolean('verify_email') ? now() : null,
        ]);

        $user->assignRole($validated['role']);
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => new UserResource($user),
        ], 201);
    }

    /**
     * Get a specific user.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        $user->load(['roles', 'branch', 'devices', 'subscriptions.plan']);
        $user->loadCount(['devices', 'subscriptions', 'theftReports']);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
            'statistics' => [
                'devices_count' => $user->devices_count,
                'subscriptions_count' => $user->subscriptions_count,
                'theft_reports_count' => $user->theft_reports_count,
                'active_subscription' => $user->subscriptions->where('status', 'active')->first()?->plan?->name,
            ],
        ]);
    }

    /**
     * Update a user.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        // Rank check. Without this a store admin could change the email and
        // password of any account in their branch, including another admin.
        $this->authorize('manage', $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'password' => ['sometimes', Password::defaults()],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:50', Rule::unique('users')->ignore($user->id)],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        if (! $request->user()->hasRole('super_admin')) {
            $validated['branch_id'] = $request->user()->requiredBranchScopeId();
        }

        $user->update($validated);
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Delete a user.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        // Give the more specific self-deletion error before the role guard.
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete your own account.',
            ], 403);
        }

        if ($user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete super admin users.',
            ], 403);
        }

        // Blocks deleting a peer: a store admin must not be able to remove
        // another store admin in the same branch.
        $this->authorize('manage', $user);

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Assign a role to a user.
     */
    public function assignRole(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
        ]);

        // Only super_admin can assign super_admin role
        if ($validated['role'] === 'super_admin' && ! auth()->user()->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Only super admins can assign the super admin role.',
            ], 403);
        }

        // Blocks re-roling a peer, which would otherwise let a store admin
        // demote a fellow admin and then take over the vacated account.
        $this->authorize('manage', $user);

        $user->syncRoles([$validated['role']]);
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Suspend a user account.
     */
    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        if ($user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot suspend super admin users.',
            ], 403);
        }

        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot suspend your own account.',
            ], 403);
        }

        // Blocks suspending a peer: otherwise a store admin could lock a
        // fellow admin out of the platform.
        $this->authorize('manage', $user);

        $user->update(['suspended_at' => now()]);

        // Revoke all tokens
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'User suspended successfully.',
        ]);
    }

    /**
     * Unsuspend a user account.
     */
    public function unsuspend(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        // This method previously had no rank check at all, so a store admin
        // could reinstate any account in the system, super admins included.
        $this->authorize('manage', $user);

        $user->update(['suspended_at' => null]);

        return response()->json([
            'success' => true,
            'message' => 'User unsuspended successfully.',
        ]);
    }

    /**
     * Get available roles.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::where('guard_name', 'api')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Verify user's email manually.
     */
    public function verifyEmail(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email is already verified.',
            ], 422);
        }

        $user->markEmailAsVerified();

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
        ]);
    }

    private function authorizeUserBranch(Request $request, User $target): void
    {
        abort_unless(
            $request->user()->canAccessBranch($target->branch_id),
            403,
            'You cannot manage users for another branch.'
        );
    }
}
