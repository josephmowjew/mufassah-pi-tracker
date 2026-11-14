<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BountyStatus;
use App\Enums\ClaimStatus;
use App\Enums\CommunityAlertStatus;
use App\Enums\TipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBountyClaimRequest;
use App\Http\Requests\StoreCommunityAlertRequest;
use App\Http\Requests\StoreCommunityTipRequest;
use App\Http\Requests\UpdateCommunityAlertRequest;
use App\Http\Resources\BountyClaimResource;
use App\Http\Resources\CommunityAlertResource;
use App\Http\Resources\CommunityHeroResource;
use App\Http\Resources\CommunityTipResource;
use App\Http\Resources\SuccessStoryResource;
use App\Models\BountyClaim;
use App\Models\CommunityAlert;
use App\Models\CommunityHero;
use App\Models\CommunityTip;
use App\Models\SuccessStory;
use App\Models\TheftReport;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @group Community Recovery Network
 *
 * APIs for the community recovery network - alerts, tips, bounty claims, and leaderboards.
 */
class CommunityAlertController extends Controller
{
    use PaginatesLists;

    /**
     * List active community alerts.
     *
     * Returns active alerts, optionally filtered by location (nearby alerts).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CommunityAlert::query()
            ->with(['theftReport.device', 'creator'])
            ->active()
            ->notExpired();

        // Filter by nearby location
        if ($request->has(['latitude', 'longitude'])) {
            $radiusKm = $request->input('radius_km', 10);
            $query->nearby(
                $request->input('latitude'),
                $request->input('longitude'),
                $radiusKm
            );
        }

        // Filter by bounty only
        if ($request->boolean('with_bounty')) {
            $query->withBounty();
        }

        $alerts = $query->latest()->paginate($this->perPage($request));

        return CommunityAlertResource::collection($alerts);
    }

    /**
     * Get user's own alerts.
     */
    public function myAlerts(Request $request): AnonymousResourceCollection
    {
        $alerts = CommunityAlert::query()
            ->with(['theftReport.device', 'tips', 'claims'])
            ->where('created_by', $request->user()->id)
            ->withCount(['tips', 'claims'])
            ->latest()
            ->paginate($this->perPage($request));

        return CommunityAlertResource::collection($alerts);
    }

    /**
     * Create a new community alert.
     */
    public function store(StoreCommunityAlertRequest $request): CommunityAlertResource|JsonResponse
    {
        $theftReport = TheftReport::findOrFail($request->theft_report_id);

        // Verify user owns the theft report or is admin
        if ($theftReport->reported_by !== $request->user()->id &&
            ! $request->user()->hasRole(['super_admin', 'store_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'You can only create alerts for your own theft reports.',
            ], 403);
        }

        // Check if alert already exists for this theft report
        if (CommunityAlert::where('theft_report_id', $theftReport->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A community alert already exists for this theft report.',
            ], 422);
        }

        $alert = CommunityAlert::create([
            'theft_report_id' => $theftReport->id,
            'created_by' => $request->user()->id,
            'status' => CommunityAlertStatus::Active,
            'broadcast_latitude' => $request->broadcast_latitude,
            'broadcast_longitude' => $request->broadcast_longitude,
            'alert_radius_km' => $request->input('alert_radius_km', 10),
            'opt_in_notifications' => $request->boolean('opt_in_notifications', true),
            'bounty_amount' => $request->bounty_amount,
            'bounty_currency' => $request->input('bounty_currency', 'MWK'),
            'bounty_status' => $request->bounty_amount ? BountyStatus::Offered : BountyStatus::None,
            'expires_at' => $request->expires_at,
        ]);

        $alert->load(['theftReport.device', 'creator']);

        return new CommunityAlertResource($alert);
    }

    /**
     * Get a specific alert.
     */
    public function show(CommunityAlert $communityAlert): CommunityAlertResource
    {
        Gate::authorize('view', $communityAlert);

        $communityAlert->load(['theftReport.device', 'creator', 'tips', 'claims', 'successStory']);
        $communityAlert->incrementViewCount();

        return new CommunityAlertResource($communityAlert);
    }

    /**
     * Update an alert.
     */
    public function update(UpdateCommunityAlertRequest $request, CommunityAlert $communityAlert): CommunityAlertResource
    {
        Gate::authorize('update', $communityAlert);

        $data = $request->validated();

        // Handle bounty status update
        if (isset($data['bounty_amount'])) {
            $data['bounty_status'] = $data['bounty_amount'] > 0 ? BountyStatus::Offered : BountyStatus::None;
        }

        $communityAlert->update($data);

        return new CommunityAlertResource($communityAlert);
    }

    /**
     * Delete (soft delete) an alert.
     */
    public function destroy(CommunityAlert $communityAlert): JsonResponse
    {
        Gate::authorize('delete', $communityAlert);

        $communityAlert->delete();

        return response()->json([
            'success' => true,
            'message' => 'Community alert deleted successfully.',
        ]);
    }

    /**
     * Pause an alert.
     */
    public function pause(CommunityAlert $communityAlert): CommunityAlertResource
    {
        Gate::authorize('toggleStatus', $communityAlert);

        $communityAlert->pause();

        return new CommunityAlertResource($communityAlert);
    }

    /**
     * Resume a paused alert.
     */
    public function resume(CommunityAlert $communityAlert): CommunityAlertResource
    {
        Gate::authorize('toggleStatus', $communityAlert);

        $communityAlert->resume();

        return new CommunityAlertResource($communityAlert);
    }

    /**
     * Resolve an alert (device recovered).
     */
    public function resolve(CommunityAlert $communityAlert): CommunityAlertResource
    {
        Gate::authorize('resolve', $communityAlert);

        $communityAlert->resolve();

        return new CommunityAlertResource($communityAlert);
    }

    /**
     * Set or update bounty on an alert.
     */
    public function setBounty(Request $request, CommunityAlert $communityAlert): CommunityAlertResource
    {
        Gate::authorize('manageBounty', $communityAlert);

        $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
        ]);

        if ($request->amount > 0) {
            $communityAlert->setBounty(
                $request->amount,
                $request->input('currency', 'MWK')
            );
        } else {
            $communityAlert->removeBounty();
        }

        return new CommunityAlertResource($communityAlert);
    }

    /**
     * Record a share of the alert.
     */
    public function recordShare(CommunityAlert $communityAlert): JsonResponse
    {
        $communityAlert->incrementShareCount();

        return response()->json([
            'success' => true,
            'share_count' => $communityAlert->share_count,
        ]);
    }

    // -------------------------------------------------------------------------
    // Tips
    // -------------------------------------------------------------------------

    /**
     * Get tips for an alert.
     */
    public function tips(Request $request, CommunityAlert $communityAlert): AnonymousResourceCollection
    {
        Gate::authorize('viewTips', $communityAlert);

        $tips = $communityAlert->tips()
            ->with('tipster')
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->latest()
            ->paginate($this->perPage($request));

        return CommunityTipResource::collection($tips);
    }

    /**
     * Submit a tip for an alert.
     */
    public function submitTip(StoreCommunityTipRequest $request, CommunityAlert $communityAlert): CommunityTipResource|JsonResponse
    {
        if (! $communityAlert->canReceiveTips()) {
            return response()->json([
                'success' => false,
                'message' => 'This alert is not accepting tips at this time.',
            ], 422);
        }

        $tip = CommunityTip::create([
            'community_alert_id' => $communityAlert->id,
            'tipster_id' => $request->boolean('is_anonymous') ? null : $request->user()->id,
            'tip_type' => $request->tip_type,
            'description' => $request->description,
            'spotted_latitude' => $request->spotted_latitude,
            'spotted_longitude' => $request->spotted_longitude,
            'spotted_address' => $request->spotted_address,
            'spotted_at' => $request->spotted_at,
            'is_anonymous' => $request->boolean('is_anonymous'),
            'contact_preference' => $request->contact_preference,
            'contact_info' => $request->contact_info,
            'photos' => $request->photos,
            'status' => TipStatus::Pending,
        ]);

        $communityAlert->incrementTipsCount();

        // Update hero stats if not anonymous
        if (! $request->boolean('is_anonymous')) {
            CommunityHero::forUser($request->user())->incrementTipsSubmitted();
        }

        return new CommunityTipResource($tip);
    }

    /**
     * Verify a tip (mark as verified/helpful).
     */
    public function verifyTip(CommunityTip $communityTip): CommunityTipResource
    {
        Gate::authorize('verify', $communityTip);

        $communityTip->markAsVerified();

        // Update hero stats
        if ($communityTip->tipster_id) {
            CommunityHero::forUser($communityTip->tipster)->incrementTipsVerified();
        }

        return new CommunityTipResource($communityTip);
    }

    /**
     * Mark a tip as false lead.
     */
    public function rejectTip(Request $request, CommunityTip $communityTip): CommunityTipResource
    {
        Gate::authorize('verify', $communityTip);

        $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $communityTip->markAsFalseLead(null, $request->notes);

        return new CommunityTipResource($communityTip);
    }

    // -------------------------------------------------------------------------
    // Bounty Claims
    // -------------------------------------------------------------------------

    /**
     * Get claims for an alert.
     */
    public function claims(Request $request, CommunityAlert $communityAlert): AnonymousResourceCollection
    {
        Gate::authorize('viewClaims', $communityAlert);

        $claims = $communityAlert->claims()
            ->with('claimant')
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->latest()
            ->paginate($this->perPage($request));

        return BountyClaimResource::collection($claims);
    }

    /**
     * Submit a bounty claim.
     */
    public function submitClaim(StoreBountyClaimRequest $request, CommunityAlert $communityAlert): BountyClaimResource|JsonResponse
    {
        if (! $communityAlert->hasBounty()) {
            return response()->json([
                'success' => false,
                'message' => 'This alert does not have a bounty.',
            ], 422);
        }

        // Check if user already has a pending claim
        $existingClaim = BountyClaim::where('community_alert_id', $communityAlert->id)
            ->where('claimant_id', $request->user()->id)
            ->whereIn('status', [ClaimStatus::Pending, ClaimStatus::Approved])
            ->first();

        if ($existingClaim) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending or approved claim for this alert.',
            ], 422);
        }

        $claim = BountyClaim::create([
            'community_alert_id' => $communityAlert->id,
            'claimant_id' => $request->user()->id,
            'claim_type' => $request->claim_type,
            'description' => $request->description,
            'evidence_photos' => $request->evidence_photos,
            'recovery_latitude' => $request->recovery_latitude,
            'recovery_longitude' => $request->recovery_longitude,
            'recovery_address' => $request->recovery_address,
            'recovery_date' => $request->recovery_date,
            'status' => ClaimStatus::Pending,
            'claim_amount' => $request->claim_amount ?? $communityAlert->bounty_amount,
            'payment_method' => $request->payment_method,
            'payment_account' => $request->payment_account,
        ]);

        return new BountyClaimResource($claim);
    }

    /**
     * Approve a bounty claim.
     */
    public function approveClaim(Request $request, BountyClaim $bountyClaim): BountyClaimResource
    {
        Gate::authorize('review', $bountyClaim);

        $request->validate([
            'approved_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $bountyClaim->approve($request->approved_amount);

        // Update alert bounty status
        $bountyClaim->alert->update(['bounty_status' => BountyStatus::Claimed]);

        // Update hero stats
        CommunityHero::forUser($bountyClaim->claimant)->incrementClaimsApproved();

        return new BountyClaimResource($bountyClaim);
    }

    /**
     * Reject a bounty claim.
     */
    public function rejectClaim(Request $request, BountyClaim $bountyClaim): BountyClaimResource
    {
        Gate::authorize('review', $bountyClaim);

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $bountyClaim->reject($request->reason);

        return new BountyClaimResource($bountyClaim);
    }

    /**
     * Mark a claim as paid.
     */
    public function markClaimPaid(Request $request, BountyClaim $bountyClaim): BountyClaimResource
    {
        Gate::authorize('markPaid', $bountyClaim);

        $request->validate([
            'payment_reference' => ['required', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'in:airtel_money,tnm_mpamba,bank_transfer,cash'],
        ]);

        $bountyClaim->markAsPaid($request->payment_reference, $request->payment_method);

        // Update alert bounty status
        $bountyClaim->alert->update(['bounty_status' => BountyStatus::Paid]);

        // Update hero stats
        CommunityHero::forUser($bountyClaim->claimant)->addBountyEarned($bountyClaim->approved_amount);

        return new BountyClaimResource($bountyClaim);
    }

    // -------------------------------------------------------------------------
    // Leaderboard & Success Stories
    // -------------------------------------------------------------------------

    /**
     * Get community heroes leaderboard.
     */
    public function leaderboard(Request $request): AnonymousResourceCollection
    {
        $type = $request->input('type', 'reputation');
        $limit = min($request->input('limit', 10), 50);

        $query = CommunityHero::query()->with('user');

        if ($type === 'recoveries') {
            $query->topByRecoveries($limit);
        } else {
            $query->topByReputation($limit);
        }

        return CommunityHeroResource::collection($query->get());
    }

    /**
     * Get user's hero profile.
     */
    public function myHeroProfile(Request $request): CommunityHeroResource
    {
        $hero = CommunityHero::forUser($request->user());
        $hero->load('user');

        return new CommunityHeroResource($hero);
    }

    /**
     * Get published success stories.
     */
    public function successStories(Request $request): AnonymousResourceCollection
    {
        $query = SuccessStory::query()
            ->with(['author', 'alert.theftReport.device'])
            ->published();

        if ($request->boolean('featured')) {
            $query->featured();
        }

        if ($request->has('city')) {
            $query->fromCity($request->input('city'));
        }

        $sortBy = $request->input('sort', 'recent');
        if ($sortBy === 'popular') {
            $query->popular();
        } else {
            $query->recent();
        }

        return SuccessStoryResource::collection(
            $query->paginate($this->perPage($request, 10))
        );
    }

    /**
     * Get a single success story.
     */
    public function showSuccessStory(SuccessStory $successStory): SuccessStoryResource
    {
        Gate::authorize('view', $successStory);

        $successStory->load(['author', 'alert.theftReport.device']);
        $successStory->incrementViewCount();

        return new SuccessStoryResource($successStory);
    }

    /**
     * Like/unlike a success story.
     */
    public function toggleLike(Request $request, SuccessStory $successStory): JsonResponse
    {
        Gate::authorize('like', $successStory);

        $liked = $successStory->toggleLike($request->user());

        return response()->json([
            'success' => true,
            'liked' => $liked,
            'likes_count' => $successStory->fresh()->likes_count,
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin Methods
    // -------------------------------------------------------------------------

    /**
     * List all tips (admin only).
     */
    public function allTips(Request $request): AnonymousResourceCollection
    {
        $tips = CommunityTip::query()
            ->with(['tipster', 'alert.theftReport.device'])
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->has('alert_id'), function ($query) use ($request) {
                $query->where('community_alert_id', $request->input('alert_id'));
            })
            ->latest()
            ->paginate($this->perPage($request));

        return CommunityTipResource::collection($tips);
    }

    /**
     * List all claims (admin only).
     */
    public function allClaims(Request $request): AnonymousResourceCollection
    {
        $claims = BountyClaim::query()
            ->with(['claimant', 'alert.theftReport.device'])
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->has('alert_id'), function ($query) use ($request) {
                $query->where('community_alert_id', $request->input('alert_id'));
            })
            ->latest()
            ->paginate($this->perPage($request));

        return BountyClaimResource::collection($claims);
    }

    /**
     * List all alerts (admin only - includes all statuses).
     */
    public function allAlerts(Request $request): AnonymousResourceCollection
    {
        $query = CommunityAlert::query()
            ->with(['theftReport.device', 'creator'])
            ->withCount(['tips', 'claims']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by bounty
        if ($request->boolean('with_bounty')) {
            $query->withBounty();
        }

        $alerts = $query->latest()->paginate($this->perPage($request));

        return CommunityAlertResource::collection($alerts);
    }

    /**
     * List all success stories (admin only - includes unpublished).
     */
    public function allSuccessStories(Request $request): AnonymousResourceCollection
    {
        $query = SuccessStory::query()
            ->with(['author', 'alert.theftReport.device']);

        // Filter by published status
        if ($request->has('published')) {
            if ($request->boolean('published')) {
                $query->published();
            } else {
                $query->whereNull('published_at');
            }
        }

        // Filter by featured
        if ($request->boolean('featured')) {
            $query->featured();
        }

        $sortBy = $request->input('sort', 'recent');
        if ($sortBy === 'popular') {
            $query->popular();
        } else {
            $query->orderByDesc('created_at');
        }

        return SuccessStoryResource::collection(
            $query->paginate($this->perPage($request))
        );
    }

    /**
     * Approve a success story.
     */
    public function approveSuccessStory(SuccessStory $successStory): SuccessStoryResource
    {
        $successStory->update([
            'is_published' => true,
            'published_at' => now(),
            'approved_by' => request()->user()->id,
            'approved_at' => now(),
        ]);

        return new SuccessStoryResource($successStory);
    }

    /**
     * Reject a success story.
     */
    public function rejectSuccessStory(Request $request, SuccessStory $successStory): SuccessStoryResource
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $successStory->update([
            'is_published' => false,
            'published_at' => null,
            'rejection_reason' => $request->reason,
        ]);

        return new SuccessStoryResource($successStory);
    }

    /**
     * Feature/unfeature a success story.
     */
    public function toggleFeaturedStory(SuccessStory $successStory): SuccessStoryResource
    {
        $successStory->update([
            'is_featured' => ! $successStory->is_featured,
        ]);

        return new SuccessStoryResource($successStory);
    }

    /**
     * Get community statistics.
     */
    public function stats(): JsonResponse
    {
        // Total stories
        $totalStories = SuccessStory::published()->count();

        // Total recoveries (resolved alerts)
        $totalRecoveries = CommunityAlert::where('status', CommunityAlertStatus::Resolved)->count();

        // Total alerts ever created
        $totalAlerts = CommunityAlert::count();

        // Recovery rate (resolved / total that were active)
        $recoveryRate = $totalAlerts > 0
            ? round(($totalRecoveries / $totalAlerts) * 100)
            : 0;

        // Average days to recover
        $avgDaysToRecover = SuccessStory::published()
            ->whereNotNull('days_to_recover')
            ->avg('days_to_recover');

        // Total bounties paid
        $totalBountiesPaid = BountyClaim::where('status', ClaimStatus::Paid)
            ->sum('approved_amount');

        // Active alerts count
        $activeAlerts = CommunityAlert::active()->notExpired()->count();

        // Total tips submitted
        $totalTips = CommunityTip::count();

        // Verified tips
        $verifiedTips = CommunityTip::where('status', TipStatus::Verified)->count();

        // Total community heroes
        $totalHeroes = CommunityHero::where('successful_recoveries', '>', 0)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_stories' => $totalStories,
                'total_recoveries' => $totalRecoveries,
                'recovery_rate' => $recoveryRate,
                'avg_days_to_recover' => $avgDaysToRecover ? round($avgDaysToRecover, 1) : null,
                'total_bounties_paid' => $totalBountiesPaid,
                'active_alerts' => $activeAlerts,
                'total_tips' => $totalTips,
                'verified_tips' => $verifiedTips,
                'total_heroes' => $totalHeroes,
            ],
        ]);
    }
}
