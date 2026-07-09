import apiClient, { type ApiResponse, type PaginatedResponse } from './client'
import type { CommunityAlert, CommunityTip, BountyClaim, CommunityHero, SuccessStory, CommunityStats, TipStatusValue, ClaimStatusValue } from '@/types'

// Filter types
export interface CommunityAlertFilters {
  page?: number
  per_page?: number
  latitude?: number
  longitude?: number
  radius_km?: number
  with_bounty?: boolean
}

export interface TipFilters {
  page?: number
  per_page?: number
  status?: TipStatusValue
}

export interface ClaimFilters {
  page?: number
  per_page?: number
  status?: ClaimStatusValue
}

export interface SuccessStoryFilters {
  page?: number
  per_page?: number
  featured?: boolean
  city?: string
  sort?: 'recent' | 'popular'
}

// Create/Update types
export interface CreateCommunityAlertData {
  theft_report_id: number
  broadcast_latitude: number
  broadcast_longitude: number
  alert_radius_km?: number
  opt_in_notifications?: boolean
  bounty_amount?: number
  bounty_currency?: string
  expires_at?: string
}

export interface UpdateCommunityAlertData {
  broadcast_latitude?: number
  broadcast_longitude?: number
  alert_radius_km?: number
  opt_in_notifications?: boolean
  bounty_amount?: number
  bounty_currency?: string
  expires_at?: string
}

export interface SubmitTipData {
  tip_type: 'sighting' | 'location' | 'information' | 'recovery'
  description: string
  spotted_latitude?: number
  spotted_longitude?: number
  spotted_address?: string
  spotted_at?: string
  is_anonymous?: boolean
  contact_preference?: 'phone' | 'email' | 'whatsapp' | 'none'
  contact_info?: string
  photos?: string[]
}

export interface SubmitClaimData {
  claim_type: 'recovery' | 'tip_leading_to_recovery' | 'partial_recovery'
  description: string
  evidence_photos: string[]
  recovery_latitude: number
  recovery_longitude: number
  recovery_address?: string
  recovery_date: string
  claim_amount?: number
  payment_method: 'airtel_money' | 'tnm_mpamba' | 'bank_transfer' | 'cash'
  payment_account: string
}

// Helper to build query string
const buildParams = <T extends object>(filters: T): string => {
  const params = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      params.append(key, String(value))
    }
  })
  return params.toString()
}

export const communityApi = {
  // -------------------------------------------------------------------------
  // Alerts
  // -------------------------------------------------------------------------

  // Get community alerts (nearby)
  getAlerts: async (filters: CommunityAlertFilters = {}): Promise<PaginatedResponse<CommunityAlert>> => {
    const response = await apiClient.get<PaginatedResponse<CommunityAlert>>(`/community/alerts?${buildParams(filters)}`)
    return response.data
  },

  // Get my alerts
  getMyAlerts: async (filters: { page?: number; per_page?: number } = {}): Promise<PaginatedResponse<CommunityAlert>> => {
    const response = await apiClient.get<PaginatedResponse<CommunityAlert>>(`/community/alerts/my?${buildParams(filters)}`)
    return response.data
  },

  // Get single alert
  getAlert: async (id: number): Promise<CommunityAlert> => {
    const response = await apiClient.get<ApiResponse<CommunityAlert>>(`/community/alerts/${id}`)
    return response.data.data
  },

  // Create alert
  createAlert: async (data: CreateCommunityAlertData): Promise<CommunityAlert> => {
    const response = await apiClient.post<ApiResponse<CommunityAlert>>('/community/alerts', data)
    return response.data.data
  },

  // Update alert
  updateAlert: async (id: number, data: UpdateCommunityAlertData): Promise<CommunityAlert> => {
    const response = await apiClient.put<ApiResponse<CommunityAlert>>(`/community/alerts/${id}`, data)
    return response.data.data
  },

  // Delete alert
  deleteAlert: async (id: number): Promise<void> => {
    await apiClient.delete(`/community/alerts/${id}`)
  },

  // Pause alert
  pauseAlert: async (id: number): Promise<CommunityAlert> => {
    const response = await apiClient.post<ApiResponse<CommunityAlert>>(`/community/alerts/${id}/pause`)
    return response.data.data
  },

  // Resume alert
  resumeAlert: async (id: number): Promise<CommunityAlert> => {
    const response = await apiClient.post<ApiResponse<CommunityAlert>>(`/community/alerts/${id}/resume`)
    return response.data.data
  },

  // Resolve alert
  resolveAlert: async (id: number): Promise<CommunityAlert> => {
    const response = await apiClient.post<ApiResponse<CommunityAlert>>(`/community/alerts/${id}/resolve`)
    return response.data.data
  },

  // Set bounty
  setBounty: async (id: number, amount: number, currency?: string): Promise<CommunityAlert> => {
    const response = await apiClient.post<ApiResponse<CommunityAlert>>(`/community/alerts/${id}/bounty`, { amount, currency })
    return response.data.data
  },

  // Record share
  recordShare: async (id: number): Promise<{ success: boolean; share_count: number }> => {
    const response = await apiClient.post<{ success: boolean; share_count: number }>(`/community/alerts/${id}/share`)
    return response.data
  },

  // -------------------------------------------------------------------------
  // Tips
  // -------------------------------------------------------------------------

  // Get tips for an alert
  getAlertTips: async (alertId: number, filters: TipFilters = {}): Promise<PaginatedResponse<CommunityTip>> => {
    const response = await apiClient.get<PaginatedResponse<CommunityTip>>(`/community/alerts/${alertId}/tips?${buildParams(filters)}`)
    return response.data
  },

  // Submit tip
  submitTip: async (alertId: number, data: SubmitTipData): Promise<CommunityTip> => {
    const response = await apiClient.post<ApiResponse<CommunityTip>>(`/community/alerts/${alertId}/tips`, data)
    return response.data.data
  },

  // Verify tip
  verifyTip: async (tipId: number): Promise<CommunityTip> => {
    const response = await apiClient.post<ApiResponse<CommunityTip>>(`/community/tips/${tipId}/verify`)
    return response.data.data
  },

  // Reject tip
  rejectTip: async (tipId: number, notes?: string): Promise<CommunityTip> => {
    const response = await apiClient.post<ApiResponse<CommunityTip>>(`/community/tips/${tipId}/reject`, { notes })
    return response.data.data
  },

  // -------------------------------------------------------------------------
  // Claims
  // -------------------------------------------------------------------------

  // Get claims for an alert
  getAlertClaims: async (alertId: number, filters: ClaimFilters = {}): Promise<PaginatedResponse<BountyClaim>> => {
    const response = await apiClient.get<PaginatedResponse<BountyClaim>>(`/community/alerts/${alertId}/claims?${buildParams(filters)}`)
    return response.data
  },

  // Submit claim
  submitClaim: async (alertId: number, data: SubmitClaimData): Promise<BountyClaim> => {
    const response = await apiClient.post<ApiResponse<BountyClaim>>(`/community/alerts/${alertId}/claims`, data)
    return response.data.data
  },

  // Approve claim
  approveClaim: async (claimId: number, approved_amount: number): Promise<BountyClaim> => {
    const response = await apiClient.post<ApiResponse<BountyClaim>>(`/community/claims/${claimId}/approve`, { approved_amount })
    return response.data.data
  },

  // Reject claim
  rejectClaim: async (claimId: number, reason: string): Promise<BountyClaim> => {
    const response = await apiClient.post<ApiResponse<BountyClaim>>(`/community/claims/${claimId}/reject`, { reason })
    return response.data.data
  },

  // Mark claim paid
  markClaimPaid: async (claimId: number, payment_reference: string, payment_method?: string): Promise<BountyClaim> => {
    const response = await apiClient.post<ApiResponse<BountyClaim>>(`/community/claims/${claimId}/paid`, { payment_reference, payment_method })
    return response.data.data
  },

  // -------------------------------------------------------------------------
  // Leaderboard & Heroes
  // -------------------------------------------------------------------------

  // Get leaderboard
  getLeaderboard: async (type: 'reputation' | 'recoveries' = 'reputation', limit: number = 10): Promise<CommunityHero[]> => {
    const response = await apiClient.get<ApiResponse<CommunityHero[]>>(`/community/leaderboard?type=${type}&limit=${limit}`)
    return response.data.data
  },

  // Get my hero profile
  getMyHeroProfile: async (): Promise<CommunityHero> => {
    const response = await apiClient.get<ApiResponse<CommunityHero>>('/community/hero/me')
    return response.data.data
  },

  // -------------------------------------------------------------------------
  // Success Stories
  // -------------------------------------------------------------------------

  // Get success stories
  getSuccessStories: async (filters: SuccessStoryFilters = {}): Promise<PaginatedResponse<SuccessStory>> => {
    const response = await apiClient.get<PaginatedResponse<SuccessStory>>(`/community/stories?${buildParams(filters)}`)
    return response.data
  },

  // Get single story
  getSuccessStory: async (id: number): Promise<SuccessStory> => {
    const response = await apiClient.get<ApiResponse<SuccessStory>>(`/community/stories/${id}`)
    return response.data.data
  },

  // Toggle like
  toggleLike: async (id: number): Promise<{ success: boolean; liked: boolean; likes_count: number }> => {
    const response = await apiClient.post<{ success: boolean; liked: boolean; likes_count: number }>(`/community/stories/${id}/like`)
    return response.data
  },

  // -------------------------------------------------------------------------
  // Statistics
  // -------------------------------------------------------------------------

  // Get community statistics
  getStats: async (): Promise<CommunityStats> => {
    const response = await apiClient.get<ApiResponse<CommunityStats>>('/community/stats')
    return response.data.data
  },
}

export default communityApi
