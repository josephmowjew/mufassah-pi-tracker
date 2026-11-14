<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Support\LikePattern;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Traits\SortsLists;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
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
    use PaginatesLists;

    use SortsLists;

    /** @var array<int, string> */
    private const SORTABLE = ['name', 'email', 'phone_number', 'branch_id', 'created_at', 'updated_at'];

    /**
     * List all users with filtering and pagination.
     *
     * Supported filters: `search`, `role`, `type` (staff|customer),
     * `verified`, `sort`/`direction`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', Rule::in(['staff', 'customer'])],
        ]);

        $branchId = $request->user()->hasRole('super_admin')
            ? null
            : $request->user()->requiredBranchScopeId();

        $users = User::query()
            ->with(['roles', 'branch'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->whereRaw('name LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                        ->orWhereRaw('email LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                        ->orWhereRaw('phone_number LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)]);
                });
            })
            ->when($request->filled('role'), function ($query) use ($request) {
                $query->whereHas('roles', function ($q) use ($request) {
                    $q->where('name', $request->input('role'));
                });
            })
            ->when($request->filled('type'), function ($query) use ($request) {
                // staff / customer is a coarse split of the role filter above:
                // staff = any internal role, customer = everything else.
                $request->input('type') === 'staff'
                    ? $query->staff()
                    : $query->customers();
            })
            ->when($request->has('verified'), function ($query) use ($request) {
                if ($request->boolean('verified')) {
                    $query->whereNotNull('email_verified_at');
                } else {
                    $query->whereNull('email_verified_at');
                }
            })
            ->when($request->filled('sort'), function ($query) use ($request) {
                $this->applySort($query, $request, self::SORTABLE, ['created_at', 'desc']);
            }, function ($query) {
                $query->latest();
            })
            ->paginate($this->perPage($request));

        return AdminUserResource::collection($users);
    }

    /**
     * Create a new user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [Rule::requiredIf(fn () => $request->input('role') !== 'customer'), 'nullable', 'string', 'email', 'max:255', 'unique:users'],
            // users.phone_number carries a unique index, so without this rule a
            // duplicate reaches MySQL and surfaces as a 500 instead of telling
            // the operator which field is wrong. Two walk-in customers sharing
            // a phone number is a common front-desk mistake.
            'phone_number' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone_number')],
            'password' => [Rule::requiredIf(fn () => $request->input('role') !== 'customer'), 'nullable', Password::defaults()],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
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
            'phone_number' => filled($validated['phone_number'] ?? null) ? $validated['phone_number'] : null,
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
            'data' => new AdminUserResource($user),
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
            'data' => new AdminUserResource($user),
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
            // The unique index is on the column, not the form. Editing a user
            // must ignore their own row, and must still refuse a number that
            // belongs to someone else.
            'phone_number' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone_number')->ignore($user->id)],
            'password' => ['sometimes', Password::defaults()],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:50', Rule::unique('users')->ignore($user->id)],
        ]);

        // A password is never written as a plain attribute update. Changing one
        // has to revoke live tokens, invalidate outstanding reset links and tell
        // the account holder - applyPasswordChange owns all three.
        $newPassword = $validated['password'] ?? null;
        unset($validated['password']);

        // Blank means "no phone number", not the empty string: two blanks would
        // otherwise collide on the unique index the moment a second one is saved.
        if (array_key_exists('phone_number', $validated) && blank($validated['phone_number'])) {
            $validated['phone_number'] = null;
        }

        if (! $request->user()->hasRole('super_admin')) {
            $validated['branch_id'] = $request->user()->requiredBranchScopeId();
        }

        $user->update($validated);

        if ($newPassword !== null) {
            $this->applyPasswordChange($user, $newPassword, $request->user()->name);
        }

        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => new AdminUserResource($user),
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
            'data' => new AdminUserResource($user),
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

    /**
     * Email the user a password reset link.
     *
     * Uses the same broker as the public "forgot password" flow, so the token
     * lifetime, single-use semantics and throttle all match what a user would
     * get if they asked for it themselves.
     */
    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        $this->authorize('manage', $user);

        if (blank($user->email)) {
            return response()->json([
                'success' => false,
                'message' => 'This user has no email address on file, so a reset link cannot be sent. Set a password manually instead.',
            ], 422);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => 'This account is suspended. Unsuspend it before sending a password reset.',
            ], 422);
        }

        try {
            $status = PasswordBroker::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $e) {
            // Transport failures surface here as exceptions, not statuses.
            // Report the real cause but answer with something actionable.
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'The reset link could not be emailed right now. Try again, or set a password directly.',
            ], 500);
        }

        return match ($status) {
            PasswordBroker::RESET_LINK_SENT => response()->json([
                'success' => true,
                'message' => "Password reset link sent to {$user->email}.",
                'data' => ['emailed' => true],
            ]),
            PasswordBroker::RESET_THROTTLED => response()->json([
                'success' => false,
                'message' => 'A reset link was sent recently. Please wait a minute before sending another.',
            ], 429),
            default => response()->json([
                'success' => false,
                'message' => 'Unable to send the password reset link. Please try again.',
            ], 500),
        };
    }

    /**
     * Set a new password on a user's behalf.
     *
     * This is the "admin knows the password" path: the administrator chooses
     * the password and the account holder is emailed it as confirmation. It
     * therefore also revokes every live session, because the whole point is
     * that the previous credentials - and whoever was holding them - are out.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserBranch($request, $user);
        $this->authorize('manage', $user);

        // UserPolicy::manage returns true for self so that profile edits keep
        // working; here self-service would bypass the current-password check on
        // the profile page, so it is refused explicitly.
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Use your profile page to change your own password.',
            ], 403);
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
            'revoke_sessions' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        $notify = $request->boolean('notify', true);

        $emailed = $this->applyPasswordChange(
            $user,
            $validated['password'],
            $request->user()->name,
            $notify,
            $request->boolean('revoke_sessions', true),
        );

        // Three outcomes, three different messages. Collapsing "admin opted out"
        // into "no email address" sends the admin hunting for a missing email
        // that is actually there.
        $message = match (true) {
            $emailed => "Password updated. The new password was emailed to {$user->email}.",
            ! $notify => 'Password updated. No notification was sent.',
            default => 'Password updated. This account has no email address, so no notification was sent.',
        };

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['emailed' => $emailed],
        ]);
    }

    /**
     * Apply a password change and everything that must accompany it.
     *
     * Ordering matters: the password is written first, then live tokens and
     * outstanding reset tokens are invalidated, then the notification goes out.
     * If the notification is the last step, a mail failure cannot leave the
     * account in a half-changed state.
     *
     * @return bool Whether the account holder was emailed the new password.
     */
    private function applyPasswordChange(
        User $user,
        string $plainPassword,
        ?string $changedBy = null,
        bool $notify = true,
        bool $revokeSessions = true,
    ): bool {
        $user->forceFill([
            'password' => Hash::make($plainPassword),
            'remember_token' => Str::random(60),
        ])->save();

        if ($revokeSessions) {
            $user->tokens()->delete();
        }

        // A link emailed before this change would still be live, and following
        // it would silently overwrite the password the admin just set. Drop the
        // token so the only working credential is the new one.
        if (filled($user->email)) {
            DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))
                ->where('email', $user->email)
                ->delete();
        }

        $emailed = $notify && filled($user->email);

        if ($emailed) {
            try {
                $user->notify(new PasswordChangedNotification($plainPassword, $changedBy));
            } catch (\Throwable $e) {
                // The password is already changed and the old sessions are
                // already dead - a mail failure must not turn that into a 500
                // that tells the admin nothing happened. With QUEUE_CONNECTION
                // set to sync the send happens inline, so this path is real.
                report($e);
                $emailed = false;
            }
        }

        return $emailed;
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
