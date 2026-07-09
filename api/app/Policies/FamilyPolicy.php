<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\User;

class FamilyPolicy
{
    /**
     * Determine if the user can view any families.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the family.
     */
    public function view(User $user, Family $family): bool
    {
        // Members can view their family
        if ($family->hasMember($user)) {
            return true;
        }

        // Users with pending invites can view basic family info
        if ($family->hasPendingInvite($user)) {
            return true;
        }

        // Admins can view any family
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine if the user can create families.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can update the family.
     */
    public function update(User $user, Family $family): bool
    {
        // Owner can always update
        if ($family->isOwner($user)) {
            return true;
        }

        // Admins of the family can update
        if ($family->isAdmin($user)) {
            return true;
        }

        // System admins can update any family
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can delete the family.
     */
    public function delete(User $user, Family $family): bool
    {
        // Only owner can delete
        if ($family->isOwner($user)) {
            return true;
        }

        // Super admin can delete any family
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can manage family members.
     */
    public function manageMember(User $user, Family $family): bool
    {
        // Check if user is guardian or above in the family
        $membership = $family->getMemberRecord($user);

        if ($membership && $membership->isActive() && $membership->canManageMembers()) {
            return true;
        }

        // System admins can manage members
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can manage family settings.
     */
    public function manageSettings(User $user, Family $family): bool
    {
        // Check if user is admin of the family
        $membership = $family->getMemberRecord($user);

        if ($membership && $membership->isActive() && $membership->canManageSettings()) {
            return true;
        }

        // System admins can manage settings
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can invite members.
     */
    public function invite(User $user, Family $family): bool
    {
        $membership = $family->getMemberRecord($user);

        if (! $membership || ! $membership->isActive()) {
            return false;
        }

        // Check if user's role can invite
        if ($membership->role->canInviteMembers()) {
            return true;
        }

        // Check family setting for allowing member invites
        if ($family->getSetting('allow_member_invites', false)) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can view all devices shared in family.
     */
    public function viewDevices(User $user, Family $family): bool
    {
        $membership = $family->getMemberRecord($user);

        if (! $membership || ! $membership->isActive()) {
            return false;
        }

        return $membership->role->canViewAllDevices();
    }
}
