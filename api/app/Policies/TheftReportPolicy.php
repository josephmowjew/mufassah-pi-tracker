<?php

namespace App\Policies;

use App\Models\TheftReport;
use App\Models\User;

class TheftReportPolicy
{
    /**
     * Determine if the user can view any theft reports.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the theft report.
     */
    public function view(User $user, TheftReport $theftReport): bool
    {
        // Reporter can view their own reports
        if ($theftReport->reported_by === $user->id) {
            return true;
        }

        // Device owner can view reports for their devices
        if ($theftReport->device && $theftReport->device->owner_id === $user->id) {
            return true;
        }

        // Admins and customer service can view any report
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine if the user can create theft reports.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can update the theft report.
     */
    public function update(User $user, TheftReport $theftReport): bool
    {
        // Reporter can update their own reports
        if ($theftReport->reported_by === $user->id) {
            return true;
        }

        // Admins can update any report
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine if the user can delete the theft report.
     */
    public function delete(User $user, TheftReport $theftReport): bool
    {
        // Reporter can delete their own reports (if not yet investigating)
        if ($theftReport->reported_by === $user->id && $theftReport->status->value === 'reported') {
            return true;
        }

        // Only super admin can delete any report
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can update the status of the theft report.
     */
    public function updateStatus(User $user, TheftReport $theftReport): bool
    {
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine if the user can assign the theft report to someone.
     */
    public function assign(User $user, TheftReport $theftReport): bool
    {
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine if the user can add evidence to the theft report.
     */
    public function addEvidence(User $user, TheftReport $theftReport): bool
    {
        // Reporter can add evidence to their own reports
        if ($theftReport->reported_by === $user->id) {
            return true;
        }

        // Admins can add evidence to any report
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine if the user can view updates/history of the theft report.
     */
    public function viewUpdates(User $user, TheftReport $theftReport): bool
    {
        return $this->view($user, $theftReport);
    }
}
