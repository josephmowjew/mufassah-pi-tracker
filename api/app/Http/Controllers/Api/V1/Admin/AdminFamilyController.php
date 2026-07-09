<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\FamilyMemberResource;
use App\Http\Resources\UserResource;
use App\Models\Family;
use App\Models\FamilyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * @group Admin Family Management
 *
 * APIs for managing families on behalf of users.
 */
class AdminFamilyController extends Controller
{
    /**
     * List all families with filtering and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $families = Family::query()
            ->with(['owner', 'activeMemberRecords.user'])
            ->withCount('activeMemberRecords')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('owner', function ($ownerQuery) use ($search) {
                            $ownerQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->filled('owner_id'), function ($query) use ($request) {
                $query->where('owner_id', $request->input('owner_id'));
            })
            ->when($request->filled('sort'), function ($query) use ($request) {
                $sortField = $request->input('sort');
                $sortDirection = $request->input('direction', 'asc');
                $query->orderBy($sortField, $sortDirection);
            }, function ($query) {
                $query->latest();
            })
            ->paginate($request->input('per_page', 15));

        // Transform to admin view (showing code always)
        return $this->transformFamiliesForAdmin($families);
    }

    /**
     * Get a specific family with all details.
     */
    public function show(Family $family): JsonResponse
    {
        $family->load(['owner', 'memberRecords.user', 'memberRecords.inviter']);
        $family->loadCount(['activeMemberRecords', 'memberRecords']);

        return response()->json([
            'success' => true,
            'data' => $this->transformFamilyForAdmin($family),
            'statistics' => [
                'total_members' => $family->member_records_count,
                'active_members' => $family->active_member_records_count,
                'pending_members' => $family->memberRecords->where('status', FamilyMemberStatus::Pending)->count(),
                'removed_members' => $family->memberRecords->where('status', FamilyMemberStatus::Removed)->count(),
            ],
        ]);
    }

    /**
     * Update a family.
     */
    public function update(Request $request, Family $family): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $family->update($validated);
        $family->load('owner');

        return response()->json([
            'success' => true,
            'message' => 'Family updated successfully.',
            'data' => $this->transformFamilyForAdmin($family),
        ]);
    }

    /**
     * Delete a family.
     */
    public function destroy(Family $family): JsonResponse
    {
        $familyName = $family->name;
        $family->delete();

        return response()->json([
            'success' => true,
            'message' => "Family '{$familyName}' deleted successfully.",
        ]);
    }

    /**
     * Regenerate family invite code.
     */
    public function regenerateCode(Family $family): JsonResponse
    {
        $family->update([
            'code' => Family::generateUniqueCode(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Invite code regenerated successfully.',
            'data' => [
                'code' => $family->code,
            ],
        ]);
    }

    /**
     * Transfer ownership to another member.
     */
    public function transferOwnership(Request $request, Family $family): JsonResponse
    {
        $validated = $request->validate([
            'new_owner_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $newOwnerId = $validated['new_owner_id'];

        // Ensure the new owner is a member of the family
        $memberRecord = $family->getMemberRecord(\App\Models\User::find($newOwnerId));
        if (! $memberRecord || ! $memberRecord->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'The new owner must be an active member of the family.',
            ], 422);
        }

        // Get current owner's member record
        $currentOwnerRecord = $family->getMemberRecord($family->owner);

        // Update ownership
        $family->update(['owner_id' => $newOwnerId]);

        // Update roles: new owner becomes admin, old owner becomes guardian
        $memberRecord->update(['role' => FamilyRole::Admin]);
        if ($currentOwnerRecord) {
            $currentOwnerRecord->update(['role' => FamilyRole::Guardian]);
        }

        $family->load('owner');

        return response()->json([
            'success' => true,
            'message' => 'Ownership transferred successfully.',
            'data' => $this->transformFamilyForAdmin($family),
        ]);
    }

    /**
     * List members of a family.
     */
    public function members(Family $family): AnonymousResourceCollection
    {
        $members = $family->memberRecords()
            ->with(['user', 'inviter'])
            ->orderByRaw("FIELD(status, 'pending', 'active', 'removed')")
            ->orderByRaw("FIELD(role, 'admin', 'guardian', 'member', 'child')")
            ->get();

        return FamilyMemberResource::collection($members);
    }

    /**
     * Add a member to a family (admin can add any user).
     */
    public function addMember(Request $request, Family $family): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', Rule::enum(FamilyRole::class)],
            'nickname' => ['nullable', 'string', 'max:50'],
        ]);

        $userId = $validated['user_id'];

        // Check if user is already a member
        $existingMember = $family->memberRecords()->where('user_id', $userId)->first();
        if ($existingMember) {
            if ($existingMember->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already a member of this family.',
                ], 422);
            }

            // Reactivate removed member
            $existingMember->update([
                'status' => FamilyMemberStatus::Active,
                'role' => FamilyRole::from($validated['role']),
                'nickname' => $validated['nickname'] ?? null,
                'joined_at' => now(),
            ]);

            $existingMember->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Member reactivated successfully.',
                'data' => new FamilyMemberResource($existingMember),
            ]);
        }

        // Create new member
        $member = $family->memberRecords()->create([
            'user_id' => $userId,
            'role' => FamilyRole::from($validated['role']),
            'status' => FamilyMemberStatus::Active,
            'nickname' => $validated['nickname'] ?? null,
            'invited_by' => auth()->id(),
            'joined_at' => now(),
        ]);

        $member->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Member added successfully.',
            'data' => new FamilyMemberResource($member),
        ], 201);
    }

    /**
     * Update a member's role or settings.
     */
    public function updateMember(Request $request, Family $family, FamilyMember $member): JsonResponse
    {
        // Ensure member belongs to this family
        if ($member->family_id !== $family->id) {
            return response()->json([
                'success' => false,
                'message' => 'Member does not belong to this family.',
            ], 404);
        }

        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::enum(FamilyRole::class)],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'string', Rule::enum(FamilyMemberStatus::class)],
            'location_sharing_enabled' => ['sometimes', 'boolean'],
            'receive_family_alerts' => ['sometimes', 'boolean'],
        ]);

        // Convert string enums if present
        if (isset($validated['role'])) {
            $validated['role'] = FamilyRole::from($validated['role']);
        }
        if (isset($validated['status'])) {
            $validated['status'] = FamilyMemberStatus::from($validated['status']);
        }

        $member->update($validated);
        $member->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Member updated successfully.',
            'data' => new FamilyMemberResource($member),
        ]);
    }

    /**
     * Remove a member from a family.
     */
    public function removeMember(Family $family, FamilyMember $member): JsonResponse
    {
        // Ensure member belongs to this family
        if ($member->family_id !== $family->id) {
            return response()->json([
                'success' => false,
                'message' => 'Member does not belong to this family.',
            ], 404);
        }

        // Cannot remove the owner
        if ($member->user_id === $family->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove the family owner. Transfer ownership first.',
            ], 422);
        }

        $member->update(['status' => FamilyMemberStatus::Removed]);

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully.',
        ]);
    }

    /**
     * Transform a family collection for admin view.
     */
    private function transformFamiliesForAdmin($families): AnonymousResourceCollection
    {
        $families->getCollection()->transform(function ($family) {
            return $this->transformFamilyForAdmin($family);
        });

        return new AnonymousResourceCollection($families, \Illuminate\Http\Resources\Json\JsonResource::class);
    }

    /**
     * Transform a single family for admin view (showing all fields).
     */
    private function transformFamilyForAdmin(Family $family): array
    {
        return [
            'id' => $family->id,
            'name' => $family->name,
            'code' => $family->code, // Always visible to admin
            'avatar' => $family->avatar,
            'settings' => $family->settings,
            'owner' => $family->relationLoaded('owner') ? new UserResource($family->owner) : null,
            'members_count' => $family->active_member_records_count ?? $family->activeMemberRecords()->count(),
            'created_at' => $family->created_at?->toIso8601String(),
            'updated_at' => $family->updated_at?->toIso8601String(),
        ];
    }
}
