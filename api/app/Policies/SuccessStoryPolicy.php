<?php

namespace App\Policies;

use App\Models\SuccessStory;
use App\Models\User;

class SuccessStoryPolicy
{
    /**
     * Determine whether the user can view any stories.
     * Published stories can be viewed by anyone.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the story.
     */
    public function view(User $user, SuccessStory $successStory): bool
    {
        // Published stories can be viewed by anyone
        if ($successStory->isPublished()) {
            return true;
        }

        // Author can view their own unpublished stories
        if ($successStory->author_id === $user->id) {
            return true;
        }

        // Admins can view any story
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can create stories.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the story.
     */
    public function update(User $user, SuccessStory $successStory): bool
    {
        // Author can update their own unpublished stories
        if ($successStory->author_id === $user->id && ! $successStory->isPublished()) {
            return true;
        }

        // Admins can update any story
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can delete the story.
     */
    public function delete(User $user, SuccessStory $successStory): bool
    {
        // Author can delete their own unpublished stories
        if ($successStory->author_id === $user->id && ! $successStory->isPublished()) {
            return true;
        }

        // Only super admin can delete any story
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can restore the story.
     */
    public function restore(User $user, SuccessStory $successStory): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the story.
     */
    public function forceDelete(User $user, SuccessStory $successStory): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can publish/unpublish the story.
     */
    public function publish(User $user, SuccessStory $successStory): bool
    {
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can feature/unfeature the story.
     */
    public function feature(User $user, SuccessStory $successStory): bool
    {
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine whether the user can like the story.
     */
    public function like(User $user, SuccessStory $successStory): bool
    {
        // Anyone can like published stories
        return $successStory->isPublished();
    }
}
