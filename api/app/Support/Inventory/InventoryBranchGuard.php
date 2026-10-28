<?php

namespace App\Support\Inventory;

use App\Models\User;

/**
 * The single rule for "which branch may this user write stock to?".
 *
 * Extracted because stock now arrives from two directions — the manual receipt
 * form and an uploaded spreadsheet — and an authorisation rule that exists twice
 * is an authorisation rule that will eventually disagree with itself. A store
 * admin must never be able to write into another branch through the back door of
 * a file upload.
 *
 * Super admins may target any branch, and may leave the target unset so the
 * caller decides (the importer falls back to the branch named in each row).
 */
class InventoryBranchGuard
{
    /**
     * @return int|null The branch to write to, or null when the user may write
     *                  anywhere and no specific branch was requested.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403 when the
     *                                                               user has no branch or asks for one that is not theirs.
     */
    public function writeBranchId(?User $user, ?int $requestedBranchId): ?int
    {
        if ($user?->hasRole('super_admin')) {
            return $requestedBranchId;
        }

        $branchId = $user?->branchScopeId();

        if (! $branchId) {
            abort(403, 'Your account must be assigned to a branch before managing inventory.');
        }

        $branchId = (int) $branchId;

        if ($requestedBranchId !== null && $requestedBranchId !== $branchId) {
            abort(403, 'You cannot manage inventory for another branch.');
        }

        return $branchId;
    }
}
