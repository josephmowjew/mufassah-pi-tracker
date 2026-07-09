<?php

use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

// =============================================================================
// FAMILY CRUD TESTS
// =============================================================================

describe('Family CRUD', function () {
    it('can create a family', function () {
        $response = $this->actingAs($this->user)->postJson('/api/v1/families', [
            'name' => 'Smith Family',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Smith Family')
            ->assertJsonPath('data.is_owner', true);

        $this->assertDatabaseHas('families', [
            'name' => 'Smith Family',
            'owner_id' => $this->user->id,
        ]);

        // Owner should be added as admin member
        $this->assertDatabaseHas('family_members', [
            'family_id' => $response->json('data.id'),
            'user_id' => $this->user->id,
            'role' => 'admin',
            'status' => 'active',
        ]);
    });

    it('can list user families', function () {
        // Create a family with the user as admin
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/families');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('can view a family', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/families/{$family->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $family->id)
            ->assertJsonPath('data.name', $family->name);
    });

    it('cannot view a family user is not member of', function () {
        $family = Family::factory()->create();

        $response = $this->actingAs($this->user)->getJson("/api/v1/families/{$family->id}");

        $response->assertForbidden();
    });

    it('can update a family as admin', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/families/{$family->id}", [
            'name' => 'Updated Family Name',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Family Name');
    });

    it('cannot update a family as regular member', function () {
        $family = Family::factory()->create();
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
            'role' => FamilyRole::Member,
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/families/{$family->id}", [
            'name' => 'Updated Family Name',
        ]);

        $response->assertForbidden();
    });

    it('can delete a family as owner', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/families/{$family->id}");

        $response->assertSuccessful();
        $this->assertSoftDeleted('families', ['id' => $family->id]);
    });

    it('cannot delete a family as non-owner admin', function () {
        $owner = User::factory()->create();
        $family = Family::factory()->create(['owner_id' => $owner->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/families/{$family->id}");

        $response->assertForbidden();
    });
});

// =============================================================================
// JOIN/LEAVE FAMILY TESTS
// =============================================================================

describe('Join/Leave Family', function () {
    it('can join a family with invite code', function () {
        $family = Family::factory()->create();

        $response = $this->actingAs($this->user)->postJson('/api/v1/families/join', [
            'code' => $family->code,
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('family_members', [
            'family_id' => $family->id,
            'user_id' => $this->user->id,
            'role' => 'member',
            'status' => 'active',
        ]);
    });

    it('cannot join with invalid code', function () {
        $response = $this->actingAs($this->user)->postJson('/api/v1/families/join', [
            'code' => 'INVALID1',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    });

    it('cannot join a family twice', function () {
        $family = Family::factory()->create();
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/families/join', [
            'code' => $family->code,
        ]);

        $response->assertStatus(400);
    });

    it('can leave a family', function () {
        $family = Family::factory()->create();
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/leave");

        $response->assertSuccessful();

        $this->assertDatabaseHas('family_members', [
            'family_id' => $family->id,
            'user_id' => $this->user->id,
            'status' => 'removed',
        ]);
    });

    it('owner cannot leave family', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/leave");

        $response->assertStatus(400);
    });

    it('joining family with approval requirement creates pending membership', function () {
        $family = Family::factory()->requiresApproval()->create();

        $response = $this->actingAs($this->user)->postJson('/api/v1/families/join', [
            'code' => $family->code,
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('family_members', [
            'family_id' => $family->id,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);
    });
});

// =============================================================================
// FAMILY MEMBER MANAGEMENT TESTS
// =============================================================================

describe('Family Member Management', function () {
    it('admin can invite a member', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $invitee = User::factory()->create();

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/members/invite", [
            'email' => $invitee->email,
            'role' => 'member',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('family_members', [
            'family_id' => $family->id,
            'user_id' => $invitee->id,
            'role' => 'member',
            'status' => 'pending',
            'invited_by' => $this->user->id,
        ]);
    });

    it('regular member cannot invite others', function () {
        $family = Family::factory()->create();
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
            'role' => FamilyRole::Member,
        ]);

        $invitee = User::factory()->create();

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/members/invite", [
            'email' => $invitee->email,
            'role' => 'member',
        ]);

        $response->assertForbidden();
    });

    it('admin can remove a member', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $member = User::factory()->create();
        $membership = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $member->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/families/{$family->id}/members/{$membership->id}");

        $response->assertSuccessful();

        $this->assertDatabaseHas('family_members', [
            'id' => $membership->id,
            'status' => 'removed',
        ]);
    });

    it('cannot remove member with higher role', function () {
        $owner = User::factory()->create();
        $family = Family::factory()->create(['owner_id' => $owner->id]);

        // User is a guardian
        FamilyMember::factory()->guardian()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        // Admin member
        $adminMembership = FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $owner->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/families/{$family->id}/members/{$adminMembership->id}");

        $response->assertForbidden();
    });

    it('member can update their own settings', function () {
        $family = Family::factory()->create();
        $membership = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/families/{$family->id}/members/{$membership->id}", [
            'nickname' => 'My Nickname',
            'location_sharing_enabled' => false,
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('family_members', [
            'id' => $membership->id,
            'nickname' => 'My Nickname',
            'location_sharing_enabled' => false,
        ]);
    });

    it('admin can approve pending member', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $pendingMember = User::factory()->create();
        $pendingMembership = FamilyMember::factory()->pending()->create([
            'family_id' => $family->id,
            'user_id' => $pendingMember->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/members/{$pendingMembership->id}/approve");

        $response->assertSuccessful();

        $this->assertDatabaseHas('family_members', [
            'id' => $pendingMembership->id,
            'status' => 'active',
        ]);
    });
});

// =============================================================================
// INVITE CODE TESTS
// =============================================================================

describe('Invite Code', function () {
    it('admin can regenerate invite code', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $oldCode = $family->code;

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/regenerate-code");

        $response->assertSuccessful()
            ->assertJsonStructure(['data' => ['code']]);

        $family->refresh();
        expect($family->code)->not->toBe($oldCode);
    });

    it('regular member cannot regenerate code', function () {
        $family = Family::factory()->create();
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
            'role' => FamilyRole::Member,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/regenerate-code");

        $response->assertForbidden();
    });

    it('invite code is only visible to guardians and above', function () {
        $family = Family::factory()->create();

        // Regular member should not see the code
        $memberMembership = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
            'role' => FamilyRole::Member,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/families/{$family->id}");

        $response->assertSuccessful();
        expect($response->json('data.code'))->toBeNull();

        // Guardian should see the code
        $memberMembership->update(['role' => FamilyRole::Guardian]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/families/{$family->id}");

        $response->assertSuccessful();
        expect($response->json('data.code'))->toBe($family->code);
    });
});

// =============================================================================
// INVITATION RESPONSE TESTS
// =============================================================================

describe('Invitation Response', function () {
    it('user can accept invitation', function () {
        $family = Family::factory()->create();
        $invitation = FamilyMember::factory()->pending()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/invitations/{$invitation->id}/respond", [
            'accept' => true,
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('family_members', [
            'id' => $invitation->id,
            'status' => 'active',
        ]);
    });

    it('user can decline invitation', function () {
        $family = Family::factory()->create();
        $invitation = FamilyMember::factory()->pending()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/invitations/{$invitation->id}/respond", [
            'accept' => false,
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('family_members', [
            'id' => $invitation->id,
            'status' => 'removed',
        ]);
    });

    it('user can view pending invitations', function () {
        $family = Family::factory()->create();
        FamilyMember::factory()->pending()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/families/invitations');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });
});

// =============================================================================
// OWNERSHIP TRANSFER TESTS
// =============================================================================

describe('Ownership Transfer', function () {
    it('owner can transfer ownership', function () {
        $family = Family::factory()->create(['owner_id' => $this->user->id]);
        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $newOwner = User::factory()->create();
        $newOwnerMembership = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $newOwner->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/members/{$newOwnerMembership->id}/transfer-ownership");

        $response->assertSuccessful();

        $family->refresh();
        expect($family->owner_id)->toBe($newOwner->id);

        // New owner should be admin
        $this->assertDatabaseHas('family_members', [
            'family_id' => $family->id,
            'user_id' => $newOwner->id,
            'role' => 'admin',
        ]);
    });

    it('non-owner cannot transfer ownership', function () {
        $owner = User::factory()->create();
        $family = Family::factory()->create(['owner_id' => $owner->id]);

        FamilyMember::factory()->admin()->create([
            'family_id' => $family->id,
            'user_id' => $this->user->id,
        ]);

        $anotherUser = User::factory()->create();
        $anotherMembership = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'user_id' => $anotherUser->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/families/{$family->id}/members/{$anotherMembership->id}/transfer-ownership");

        $response->assertForbidden();
    });
});
