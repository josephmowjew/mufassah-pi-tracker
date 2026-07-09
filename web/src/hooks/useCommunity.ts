import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import communityApi, {
  type CommunityAlertFilters,
  type TipFilters,
  type ClaimFilters,
  type SuccessStoryFilters,
  type CreateCommunityAlertData,
  type UpdateCommunityAlertData,
  type SubmitTipData,
  type SubmitClaimData,
} from '@/api/community'

// -------------------------------------------------------------------------
// Alerts
// -------------------------------------------------------------------------

export function useCommunityAlerts(filters: CommunityAlertFilters = {}) {
  return useQuery({
    queryKey: ['community-alerts', filters],
    queryFn: () => communityApi.getAlerts(filters),
  })
}

export function useMyAlerts(filters: { page?: number; per_page?: number } = {}) {
  return useQuery({
    queryKey: ['my-alerts', filters],
    queryFn: () => communityApi.getMyAlerts(filters),
  })
}

export function useCommunityAlert(id: number) {
  return useQuery({
    queryKey: ['community-alert', id],
    queryFn: () => communityApi.getAlert(id),
    enabled: !!id,
  })
}

export function useCreateCommunityAlert() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateCommunityAlertData) => communityApi.createAlert(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['community-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['my-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['theft-reports'] })
    },
  })
}

export function useUpdateCommunityAlert() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateCommunityAlertData }) =>
      communityApi.updateAlert(id, data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['community-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['my-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['community-alert', id] })
    },
  })
}

export function useDeleteCommunityAlert() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => communityApi.deleteAlert(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['community-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['my-alerts'] })
    },
  })
}

export function usePauseAlert() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => communityApi.pauseAlert(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['community-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['my-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['community-alert', id] })
    },
  })
}

export function useResumeAlert() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => communityApi.resumeAlert(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['community-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['my-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['community-alert', id] })
    },
  })
}

export function useResolveAlert() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => communityApi.resolveAlert(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['community-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['my-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['community-alert', id] })
    },
  })
}

export function useSetBounty() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, amount, currency }: { id: number; amount: number; currency?: string }) =>
      communityApi.setBounty(id, amount, currency),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['community-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['my-alerts'] })
      queryClient.invalidateQueries({ queryKey: ['community-alert', id] })
    },
  })
}

export function useRecordShare() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => communityApi.recordShare(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['community-alert', id] })
    },
  })
}

// -------------------------------------------------------------------------
// Tips
// -------------------------------------------------------------------------

export function useAlertTips(alertId: number, filters: TipFilters = {}) {
  return useQuery({
    queryKey: ['alert-tips', alertId, filters],
    queryFn: () => communityApi.getAlertTips(alertId, filters),
    enabled: !!alertId,
  })
}

export function useSubmitTip() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ alertId, data }: { alertId: number; data: SubmitTipData }) =>
      communityApi.submitTip(alertId, data),
    onSuccess: (_, { alertId }) => {
      queryClient.invalidateQueries({ queryKey: ['alert-tips', alertId] })
      queryClient.invalidateQueries({ queryKey: ['community-alert', alertId] })
      queryClient.invalidateQueries({ queryKey: ['my-hero-profile'] })
    },
  })
}

export function useVerifyTip() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (tipId: number) => communityApi.verifyTip(tipId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['alert-tips'] })
      queryClient.invalidateQueries({ queryKey: ['leaderboard'] })
    },
  })
}

export function useRejectTip() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ tipId, notes }: { tipId: number; notes?: string }) =>
      communityApi.rejectTip(tipId, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['alert-tips'] })
    },
  })
}

// -------------------------------------------------------------------------
// Claims
// -------------------------------------------------------------------------

export function useAlertClaims(alertId: number, filters: ClaimFilters = {}) {
  return useQuery({
    queryKey: ['alert-claims', alertId, filters],
    queryFn: () => communityApi.getAlertClaims(alertId, filters),
    enabled: !!alertId,
  })
}

export function useSubmitClaim() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ alertId, data }: { alertId: number; data: SubmitClaimData }) =>
      communityApi.submitClaim(alertId, data),
    onSuccess: (_, { alertId }) => {
      queryClient.invalidateQueries({ queryKey: ['alert-claims', alertId] })
      queryClient.invalidateQueries({ queryKey: ['community-alert', alertId] })
    },
  })
}

export function useApproveClaim() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ claimId, approved_amount }: { claimId: number; approved_amount: number }) =>
      communityApi.approveClaim(claimId, approved_amount),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['alert-claims'] })
      queryClient.invalidateQueries({ queryKey: ['leaderboard'] })
    },
  })
}

export function useRejectClaim() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ claimId, reason }: { claimId: number; reason: string }) =>
      communityApi.rejectClaim(claimId, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['alert-claims'] })
    },
  })
}

export function useMarkClaimPaid() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ claimId, payment_reference, payment_method }: { claimId: number; payment_reference: string; payment_method?: string }) =>
      communityApi.markClaimPaid(claimId, payment_reference, payment_method),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['alert-claims'] })
      queryClient.invalidateQueries({ queryKey: ['leaderboard'] })
    },
  })
}

// -------------------------------------------------------------------------
// Leaderboard & Heroes
// -------------------------------------------------------------------------

export function useLeaderboard(type: 'reputation' | 'recoveries' = 'reputation', limit: number = 10) {
  return useQuery({
    queryKey: ['leaderboard', type, limit],
    queryFn: () => communityApi.getLeaderboard(type, limit),
  })
}

export function useMyHeroProfile() {
  return useQuery({
    queryKey: ['my-hero-profile'],
    queryFn: () => communityApi.getMyHeroProfile(),
  })
}

// -------------------------------------------------------------------------
// Success Stories
// -------------------------------------------------------------------------

export function useSuccessStories(filters: SuccessStoryFilters = {}) {
  return useQuery({
    queryKey: ['success-stories', filters],
    queryFn: () => communityApi.getSuccessStories(filters),
  })
}

export function useSuccessStory(id: number) {
  return useQuery({
    queryKey: ['success-story', id],
    queryFn: () => communityApi.getSuccessStory(id),
    enabled: !!id,
  })
}

export function useToggleLike() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => communityApi.toggleLike(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['success-stories'] })
      queryClient.invalidateQueries({ queryKey: ['success-story', id] })
    },
  })
}

// -------------------------------------------------------------------------
// Statistics
// -------------------------------------------------------------------------

export function useCommunityStats() {
  return useQuery({
    queryKey: ['community-stats'],
    queryFn: () => communityApi.getStats(),
    staleTime: 1000 * 60 * 5, // Cache for 5 minutes
  })
}
