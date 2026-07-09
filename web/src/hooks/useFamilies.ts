import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import familiesApi, {
  type CreateFamilyData,
  type UpdateFamilyData,
  type InviteMemberData,
  type UpdateMemberData,
} from '@/api/families'

// Query keys
export const familyKeys = {
  all: ['families'] as const,
  detail: (id: number) => ['family', id] as const,
  members: (id: number) => ['family-members', id] as const,
  invitations: ['family-invitations'] as const,
}

// Queries
export function useFamilies() {
  return useQuery({
    queryKey: familyKeys.all,
    queryFn: () => familiesApi.getFamilies(),
  })
}

export function useFamily(id: number) {
  return useQuery({
    queryKey: familyKeys.detail(id),
    queryFn: () => familiesApi.getFamily(id),
    enabled: !!id,
  })
}

export function useFamilyMembers(familyId: number) {
  return useQuery({
    queryKey: familyKeys.members(familyId),
    queryFn: () => familiesApi.getMembers(familyId),
    enabled: !!familyId,
  })
}

export function useFamilyInvitations() {
  return useQuery({
    queryKey: familyKeys.invitations,
    queryFn: () => familiesApi.getPendingInvitations(),
  })
}

// Mutations
export function useCreateFamily() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateFamilyData) => familiesApi.createFamily(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: familyKeys.all })
    },
  })
}

export function useUpdateFamily() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateFamilyData }) =>
      familiesApi.updateFamily(id, data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: familyKeys.all })
      queryClient.invalidateQueries({ queryKey: familyKeys.detail(id) })
    },
  })
}

export function useDeleteFamily() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => familiesApi.deleteFamily(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: familyKeys.all })
    },
  })
}

export function useJoinFamily() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (code: string) => familiesApi.joinFamily(code),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: familyKeys.all })
      queryClient.invalidateQueries({ queryKey: familyKeys.invitations })
    },
  })
}

export function useLeaveFamily() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => familiesApi.leaveFamily(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: familyKeys.all })
    },
  })
}

export function useRegenerateCode() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => familiesApi.regenerateCode(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: familyKeys.detail(id) })
    },
  })
}

export function useRespondToInvitation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ invitationId, accept }: { invitationId: number; accept: boolean }) =>
      familiesApi.respondToInvitation(invitationId, accept),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: familyKeys.all })
      queryClient.invalidateQueries({ queryKey: familyKeys.invitations })
    },
  })
}

export function useInviteMember() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ familyId, data }: { familyId: number; data: InviteMemberData }) =>
      familiesApi.inviteMember(familyId, data),
    onSuccess: (_, { familyId }) => {
      queryClient.invalidateQueries({ queryKey: familyKeys.members(familyId) })
      queryClient.invalidateQueries({ queryKey: familyKeys.detail(familyId) })
    },
  })
}

export function useUpdateMember() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ familyId, memberId, data }: { familyId: number; memberId: number; data: UpdateMemberData }) =>
      familiesApi.updateMember(familyId, memberId, data),
    onSuccess: (_, { familyId }) => {
      queryClient.invalidateQueries({ queryKey: familyKeys.members(familyId) })
      queryClient.invalidateQueries({ queryKey: familyKeys.detail(familyId) })
    },
  })
}

export function useRemoveMember() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ familyId, memberId }: { familyId: number; memberId: number }) =>
      familiesApi.removeMember(familyId, memberId),
    onSuccess: (_, { familyId }) => {
      queryClient.invalidateQueries({ queryKey: familyKeys.members(familyId) })
      queryClient.invalidateQueries({ queryKey: familyKeys.detail(familyId) })
    },
  })
}

export function useApproveMember() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ familyId, memberId }: { familyId: number; memberId: number }) =>
      familiesApi.approveMember(familyId, memberId),
    onSuccess: (_, { familyId }) => {
      queryClient.invalidateQueries({ queryKey: familyKeys.members(familyId) })
      queryClient.invalidateQueries({ queryKey: familyKeys.detail(familyId) })
    },
  })
}

export function useTransferOwnership() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ familyId, memberId }: { familyId: number; memberId: number }) =>
      familiesApi.transferOwnership(familyId, memberId),
    onSuccess: (_, { familyId }) => {
      queryClient.invalidateQueries({ queryKey: familyKeys.all })
      queryClient.invalidateQueries({ queryKey: familyKeys.detail(familyId) })
      queryClient.invalidateQueries({ queryKey: familyKeys.members(familyId) })
    },
  })
}
