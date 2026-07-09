import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import warrantiesApi, { type WarrantyFilters, type CreateWarrantyClaimData } from '@/api/warranties'

export function useWarranties(filters: WarrantyFilters = {}) {
  return useQuery({
    queryKey: ['warranties', filters],
    queryFn: () => warrantiesApi.getWarranties(filters),
  })
}

export function useWarranty(id: number) {
  return useQuery({
    queryKey: ['warranty', id],
    queryFn: () => warrantiesApi.getWarranty(id),
    enabled: !!id,
  })
}

export function useWarrantyClaims(warrantyId: number) {
  return useQuery({
    queryKey: ['warranty-claims', warrantyId],
    queryFn: () => warrantiesApi.getWarrantyClaims(warrantyId),
    enabled: !!warrantyId,
  })
}

export function useSubmitWarrantyClaim() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ warrantyId, data }: { warrantyId: number; data: CreateWarrantyClaimData }) =>
      warrantiesApi.submitClaim(warrantyId, data),
    onSuccess: (_, { warrantyId }) => {
      queryClient.invalidateQueries({ queryKey: ['warranties'] })
      queryClient.invalidateQueries({ queryKey: ['warranty', warrantyId] })
      queryClient.invalidateQueries({ queryKey: ['warranty-claims', warrantyId] })
    },
  })
}
