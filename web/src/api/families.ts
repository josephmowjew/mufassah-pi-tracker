import apiClient, { type ApiResponse } from './client'
import type { Family, FamilyMember, FamilyInvitation, FamilyRoleValue } from '@/types'

export interface CreateFamilyData {
  name: string
  avatar?: File
}

export interface UpdateFamilyData {
  name?: string
  avatar?: File
  settings?: Record<string, unknown>
}

export interface InviteMemberData {
  email: string
  role: FamilyRoleValue
  nickname?: string
}

export interface UpdateMemberData {
  role?: FamilyRoleValue
  nickname?: string
  location_sharing_enabled?: boolean
  receive_family_alerts?: boolean
}

export const familiesApi = {
  // Get all families for the current user
  getFamilies: async (): Promise<Family[]> => {
    const response = await apiClient.get<ApiResponse<Family[]>>('/families')
    return response.data.data
  },

  // Get single family
  getFamily: async (id: number): Promise<Family> => {
    const response = await apiClient.get<ApiResponse<Family>>(`/families/${id}`)
    return response.data.data
  },

  // Create family
  createFamily: async (data: CreateFamilyData): Promise<Family> => {
    const formData = new FormData()
    formData.append('name', data.name)
    if (data.avatar) {
      formData.append('avatar', data.avatar)
    }
    const response = await apiClient.post<ApiResponse<Family>>('/families', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data.data
  },

  // Update family
  updateFamily: async (id: number, data: UpdateFamilyData): Promise<Family> => {
    const formData = new FormData()
    if (data.name) formData.append('name', data.name)
    if (data.avatar) formData.append('avatar', data.avatar)
    if (data.settings) formData.append('settings', JSON.stringify(data.settings))
    formData.append('_method', 'PUT')
    const response = await apiClient.post<ApiResponse<Family>>(`/families/${id}`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data.data
  },

  // Delete family
  deleteFamily: async (id: number): Promise<void> => {
    await apiClient.delete(`/families/${id}`)
  },

  // Join family with invite code
  joinFamily: async (code: string): Promise<Family> => {
    const response = await apiClient.post<ApiResponse<Family>>('/families/join', { code })
    return response.data.data
  },

  // Leave family
  leaveFamily: async (id: number): Promise<void> => {
    await apiClient.post(`/families/${id}/leave`)
  },

  // Regenerate invite code
  regenerateCode: async (id: number): Promise<{ code: string }> => {
    const response = await apiClient.post<ApiResponse<{ code: string }>>(`/families/${id}/regenerate-code`)
    return response.data.data
  },

  // Get pending invitations
  getPendingInvitations: async (): Promise<FamilyInvitation[]> => {
    const response = await apiClient.get<ApiResponse<FamilyInvitation[]>>('/families/invitations')
    return response.data.data
  },

  // Respond to invitation
  respondToInvitation: async (invitationId: number, accept: boolean): Promise<void> => {
    await apiClient.post(`/families/invitations/${invitationId}/respond`, { accept })
  },

  // Get family members
  getMembers: async (familyId: number): Promise<FamilyMember[]> => {
    const response = await apiClient.get<ApiResponse<FamilyMember[]>>(`/families/${familyId}/members`)
    return response.data.data
  },

  // Invite member
  inviteMember: async (familyId: number, data: InviteMemberData): Promise<FamilyMember> => {
    const response = await apiClient.post<ApiResponse<FamilyMember>>(`/families/${familyId}/members/invite`, data)
    return response.data.data
  },

  // Update member
  updateMember: async (familyId: number, memberId: number, data: UpdateMemberData): Promise<FamilyMember> => {
    const response = await apiClient.put<ApiResponse<FamilyMember>>(`/families/${familyId}/members/${memberId}`, data)
    return response.data.data
  },

  // Remove member
  removeMember: async (familyId: number, memberId: number): Promise<void> => {
    await apiClient.delete(`/families/${familyId}/members/${memberId}`)
  },

  // Approve pending member
  approveMember: async (familyId: number, memberId: number): Promise<FamilyMember> => {
    const response = await apiClient.post<ApiResponse<FamilyMember>>(`/families/${familyId}/members/${memberId}/approve`)
    return response.data.data
  },

  // Transfer ownership
  transferOwnership: async (familyId: number, memberId: number): Promise<void> => {
    await apiClient.post(`/families/${familyId}/members/${memberId}/transfer-ownership`)
  },
}

export default familiesApi
