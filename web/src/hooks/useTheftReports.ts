import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import theftReportsApi, { type CreateTheftReportData, type UpdateTheftReportData, type TheftReportFilters } from '@/api/theft-reports'

export function useTheftReports(filters: TheftReportFilters = {}) {
  return useQuery({
    queryKey: ['theft-reports', filters],
    queryFn: () => theftReportsApi.getTheftReports(filters),
  })
}

export function useTheftReport(id: number) {
  return useQuery({
    queryKey: ['theft-report', id],
    queryFn: () => theftReportsApi.getTheftReport(id),
    enabled: !!id,
  })
}

export function useCreateTheftReport() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateTheftReportData) => theftReportsApi.createTheftReport(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['theft-reports'] })
      queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}

export function useUpdateTheftReport() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateTheftReportData }) =>
      theftReportsApi.updateTheftReport(id, data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['theft-reports'] })
      queryClient.invalidateQueries({ queryKey: ['theft-report', id] })
    },
  })
}

export function useCancelTheftReport() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => theftReportsApi.cancelTheftReport(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['theft-reports'] })
      queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}

export function useUploadTheftDocuments() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, documents }: { id: number; documents: File[] }) =>
      theftReportsApi.uploadDocuments(id, documents),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['theft-report', id] })
    },
  })
}
