import { QueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getApiErrorMessage, requiresApiConfirmation } from '@/lib/utils'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5,
      retry: 1,
      refetchOnWindowFocus: false,
    },
    mutations: {
      onError: (error) => {
        if (requiresApiConfirmation(error)) return
        toast.error(getApiErrorMessage(error, 'Something went wrong. Please try again.'))
      },
    },
  },
})

export function clearSessionCache() {
  // Removing queries also cancels their pending fetches and retries. Only
  // explicitly public queries may survive an authentication change.
  queryClient.removeQueries({ predicate: (query) => query.meta?.public !== true })
  queryClient.getMutationCache().clear()
}
