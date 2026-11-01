<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRole;
use App\Http\Requests\Api\V1\Family\JoinFamilyRequest;
use App\Http\Requests\Api\V1\Family\StoreFamilyRequest;
use App\Http\Requests\Api\V1\Family\UpdateFamilyRequest;
use App\Http\Resources\FamilyResource;
use App\Models\Family;
use App\Models\FamilyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @group Family Management
 *
 * APIs for managing families - create, update, join, and leave family groups.
 */
class FamilyController extends BaseController
{
    /**
     * List user's families.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $families = Family::forUser($user)
            ->with(['owner', 'activeMemberRecords.user'])
            ->withCount('activeMemberRecords')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success(FamilyResource::collection($families));
    }

    /**
     * Create a new family.
     */
    public function store(StoreFamilyRequest $request): JsonResponse
    {
        $user = $request->user();

        $family = DB::transaction(function () use ($request, $user) {
            $data = $request->validated();
            $data['owner_id'] = $user->id;

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                $data['avatar'] = $request->file('avatar')->store('families/avatars', 'public');
            }

            $family = Family::create($data);

            // Add owner as admin member
            FamilyMember::create([
                'family_id' => $family->id,
                'user_id' => $user->id,
                'role' => FamilyRole::Admin,
                'status' => FamilyMemberStatus::Active,
                'joined_at' => now(),
            ]);

            return $family;
        });

        $family->load(['owner', 'activeMemberRecords.user']);
        $family->loadCount('activeMemberRecords');

        return $this->created(
            new FamilyResource($family),
            'Family created successfully.'
        );
    }

    /**
     * View a specific family.
     */
    public function show(Family $family): JsonResponse
    {
        $this->authorize('view', $family);

        $family->load(['owner', 'activeMemberRecords.user', 'activeMemberRecords.inviter']);
        $family->loadCount('activeMemberRecords');

        return $this->success(new FamilyResource($family));
    }

    /**
     * Update a family.
     */
    public function update(UpdateFamilyRequest $request, Family $family): JsonResponse
    {
        $this->authorize('update', $family);

        $data = $request->validated();

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar
            if ($family->avatar) {
                Storage::disk('public')->delete($family->avatar);
            }
            $data['avatar'] = $request->file('avatar')->store('families/avatars', 'public');
        }

        // Merge settings with existing
        if (isset($data['settings'])) {
            $data['settings'] = array_merge($family->settings ?? [], $data['settings']);
        }

        $family->update($data);
        $family->load(['owner', 'activeMemberRecords.user']);
        $family->loadCount('activeMemberRecords');

        return $this->success(
            new FamilyResource($family),
            'Family updated successfully.'
        );
    }

    /**
     * Delete a family.
     */
    public function destroy(Family $family): JsonResponse
    {
        $this->authorize('delete', $family);

        // Delete avatar from storage
        if ($family->avatar) {
            Storage::disk('public')->delete($family->avatar);
        }

        $family->delete();

        return $this->success(null, 'Family deleted successfully.');
    }

    /**
     * Join a family using invite code.
     */
    public function join(JoinFamilyRequest $request): JsonResponse
    {
        $user = $request->user();
        $family = Family::where('code', strtoupper($request->code))->firstOrFail();

        // Check if user is already a member
        if ($family->hasMember($user)) {
            return $this->error('You are already a member of this family.', 400);
        }

        // Check if user has a pending invite
        $existingMembership = $family->getMemberRecord($user);
        if ($existingMembership) {
            if ($existingMembership->isPending()) {
                // Accept the pending invite
                $existingMembership->accept();
                $family->load(['owner', 'activeMemberRecords.user']);
                $family->loadCount('activeMemberRecords');

                return $this->success(
                    new FamilyResource($family),
                    'You have joined the family.'
                );
            }

            if ($existingMembership->status === FamilyMemberStatus::Removed) {
                return $this->error('You have been removed from this family.', 403);
            }
        }

        // Check if family requires approval
        $requireApproval = $family->getSetting('require_approval_for_join', false);

        FamilyMember::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'role' => FamilyRole::Member,
            'status' => $requireApproval ? FamilyMemberStatus::Pending : FamilyMemberStatus::Active,
            'joined_at' => $requireApproval ? null : now(),
        ]);

        $family->load(['owner', 'activeMemberRecords.user']);
        $family->loadCount('activeMemberRecords');

        $message = $requireApproval
            ? 'Join request submitted. Waiting for approval.'
            : 'You have joined the family.';

        return $this->success(new FamilyResource($family), $message);
    }

    /**
     * Leave a family.
     */
    public function leave(Family $family): JsonResponse
    {
        $user = request()->user();

        // Check if user is a member
        $membership = $family->getMemberRecord($user);
        if (! $membership || ! $membership->isActive()) {
            return $this->error('You are not a member of this family.', 400);
        }

        // Owner cannot leave - must transfer or delete
        if ($family->isOwner($user)) {
            return $this->error('As the owner, you cannot leave. Transfer ownership first or delete the family.', 400);
        }

        $membership->remove();

        return $this->success(null, 'You have left the family.');
    }

    /**
     * Regenerate family invite code.
     */
    public function regenerateCode(Family $family): JsonResponse
    {
        $this->authorize('update', $family);

        $newCode = $family->regenerateCode();

        return $this->success(['code' => $newCode], 'Invite code regenerated successfully.');
    }

    /**
     * Get pending invitations for the current user.
     */
    public function pendingInvitations(Request $request): JsonResponse
    {
        $user = $request->user();

        $pendingMemberships = FamilyMember::where('user_id', $user->id)
            ->where('status', FamilyMemberStatus::Pending)
            ->with(['family.owner', 'inviter'])
            ->get();

        $invitations = $pendingMemberships->map(function ($membership) {
            return [
                'id' => $membership->id,
                'family' => [
                    'id' => $membership->family->id,
                    'name' => $membership->family->name,
                    'avatar' => $membership->family->avatar,
                    'owner' => [
                        'id' => $membership->family->owner->id,
                        'name' => $membership->family->owner->name,
                    ],
                ],
                'role' => [
                    'value' => $membership->role->value,
                    'label' => $membership->role->label(),
                ],
                'invited_by' => $membership->inviter ? [
                    'id' => $membership->inviter->id,
                    'name' => $membership->inviter->name,
                ] : null,
                'created_at' => $membership->created_at->toLocalIso8601String(),
            ];
        });

        return $this->success($invitations);
    }

    /**
     * Respond to a family invitation.
     */
    public function respondToInvitation(Request $request, FamilyMember $invitation): JsonResponse
    {
        $user = $request->user();

        // Verify this invitation belongs to the user
        if ($invitation->user_id !== $user->id) {
            return $this->error('This invitation is not for you.', 403);
        }

        if (! $invitation->isPending()) {
            return $this->error('This invitation is no longer pending.', 400);
        }

        $request->validate([
            'accept' => ['required', 'boolean'],
        ]);

        if ($request->accept) {
            $invitation->accept();
            $message = 'You have joined the family.';
        } else {
            $invitation->remove();
            $message = 'Invitation declined.';
        }

        return $this->success(null, $message);
    }
}
