<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRole;
use App\Http\Requests\Api\V1\Family\InviteFamilyMemberRequest;
use App\Http\Requests\Api\V1\Family\UpdateFamilyMemberRequest;
use App\Http\Resources\FamilyMemberResource;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Family Members
 *
 * APIs for managing family members - invite, update roles, and remove members.
 */
class FamilyMemberController extends BaseController
{
    /**
     * List family members.
     */
    public function index(Request $request, Family $family): JsonResponse
    {
        $this->authorize('view', $family);

        $members = $family->memberRecords()
            ->with(['user', 'inviter'])
            ->orderByRaw("FIELD(role, 'admin', 'guardian', 'member', 'child')")
            ->orderBy('joined_at', 'asc')
            ->get();

        return $this->success(FamilyMemberResource::collection($members));
    }

    /**
     * Invite a new member to the family.
     */
    public function invite(InviteFamilyMemberRequest $request, Family $family): JsonResponse
    {
        $this->authorize('manageMember', $family);

        $currentUser = $request->user();
        $invitedUser = User::where('email', $request->email)->firstOrFail();

        // Cannot invite self
        if ($invitedUser->id === $currentUser->id) {
            return $this->error('You cannot invite yourself.', 400);
        }

        // Check if user is already a member
        $existingMembership = $family->getMemberRecord($invitedUser);
        if ($existingMembership) {
            if ($existingMembership->isActive()) {
                return $this->error('This user is already a member of the family.', 400);
            }
            if ($existingMembership->isPending()) {
                return $this->error('This user already has a pending invitation.', 400);
            }
        }

        // Check role hierarchy - can only invite roles below yours
        $currentMembership = $family->getMemberRecord($currentUser);
        $requestedRole = FamilyRole::from($request->role);

        if (! $currentMembership->role->outranks($requestedRole) && $currentMembership->role !== $requestedRole) {
            return $this->error('You cannot invite someone with a higher or equal role than yours.', 403);
        }

        // Create pending membership
        $member = FamilyMember::create([
            'family_id' => $family->id,
            'user_id' => $invitedUser->id,
            'role' => $requestedRole,
            'nickname' => $request->nickname,
            'status' => FamilyMemberStatus::Pending,
            'invited_by' => $currentUser->id,
        ]);

        $member->load(['user', 'inviter']);

        return $this->created(
            new FamilyMemberResource($member),
            'Invitation sent successfully.'
        );
    }

    /**
     * Update a family member.
     */
    public function update(UpdateFamilyMemberRequest $request, Family $family, FamilyMember $member): JsonResponse
    {
        // Verify member belongs to this family
        if ($member->family_id !== $family->id) {
            return $this->error('Member not found in this family.', 404);
        }

        $currentUser = $request->user();
        $currentMembership = $family->getMemberRecord($currentUser);

        // Members can update their own settings
        $isUpdatingSelf = $member->user_id === $currentUser->id;

        if ($isUpdatingSelf) {
            // Self-update: can only change nickname and personal settings
            $allowedFields = ['nickname', 'location_sharing_enabled', 'receive_family_alerts'];

            // Children must always share location
            if ($member->mustShareLocation() && isset($request->location_sharing_enabled) && ! $request->location_sharing_enabled) {
                return $this->error('Children must have location sharing enabled.', 403);
            }

            $data = $request->only($allowedFields);
        } else {
            // Updating another member - need manageMember permission
            $this->authorize('manageMember', $family);

            // Cannot update someone with higher/equal role
            if (! $currentMembership->role->outranks($member->role)) {
                return $this->error('You cannot modify a member with an equal or higher role.', 403);
            }

            // If changing role, cannot promote above own level
            if ($request->has('role')) {
                $newRole = FamilyRole::from($request->role);
                if (! $currentMembership->role->outranks($newRole) && $currentMembership->role !== $newRole) {
                    return $this->error('You cannot assign a role equal to or higher than your own.', 403);
                }
            }

            $data = $request->validated();
        }

        $member->update($data);
        $member->load(['user', 'inviter']);

        return $this->success(
            new FamilyMemberResource($member),
            'Member updated successfully.'
        );
    }

    /**
     * Remove a member from the family.
     */
    public function destroy(Family $family, FamilyMember $member): JsonResponse
    {
        // Verify member belongs to this family
        if ($member->family_id !== $family->id) {
            return $this->error('Member not found in this family.', 404);
        }

        $this->authorize('manageMember', $family);

        $currentUser = request()->user();
        $currentMembership = $family->getMemberRecord($currentUser);

        // Cannot remove self (use leave instead)
        if ($member->user_id === $currentUser->id) {
            return $this->error('Use the leave endpoint to leave a family.', 400);
        }

        // Cannot remove owner
        if ($family->owner_id === $member->user_id) {
            return $this->error('The family owner cannot be removed.', 403);
        }

        // Cannot remove someone with higher/equal role (unless super admin)
        if (! $currentMembership->role->outranks($member->role)) {
            return $this->error('You cannot remove a member with an equal or higher role.', 403);
        }

        $member->remove();

        return $this->success(null, 'Member removed from family.');
    }

    /**
     * Approve a pending member join request.
     */
    public function approve(Family $family, FamilyMember $member): JsonResponse
    {
        if ($member->family_id !== $family->id) {
            return $this->error('Member not found in this family.', 404);
        }

        $this->authorize('manageMember', $family);

        if (! $member->isPending()) {
            return $this->error('This member is not pending approval.', 400);
        }

        $member->accept();
        $member->load(['user', 'inviter']);

        return $this->success(
            new FamilyMemberResource($member),
            'Member approved successfully.'
        );
    }

    /**
     * Transfer family ownership.
     */
    public function transferOwnership(Request $request, Family $family, FamilyMember $member): JsonResponse
    {
        if ($member->family_id !== $family->id) {
            return $this->error('Member not found in this family.', 404);
        }

        $currentUser = $request->user();

        // Only owner can transfer ownership
        if (! $family->isOwner($currentUser)) {
            return $this->error('Only the family owner can transfer ownership.', 403);
        }

        if (! $member->isActive()) {
            return $this->error('Can only transfer to active members.', 400);
        }

        // Update family owner
        $family->update(['owner_id' => $member->user_id]);

        // Promote new owner to admin if not already
        if (! $member->isAdmin()) {
            $member->update(['role' => FamilyRole::Admin]);
        }

        // Demote old owner to guardian (still high level access)
        $oldOwnerMembership = $family->getMemberRecord($currentUser);
        $oldOwnerMembership->update(['role' => FamilyRole::Guardian]);

        $family->load(['owner', 'activeMemberRecords.user']);

        return $this->success(
            [
                'message' => 'Ownership transferred successfully.',
                'new_owner_id' => $member->user_id,
            ],
            'Ownership transferred successfully.'
        );
    }
}
