import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import subscriptionsApi, { type SubscribeData } from '@/api/subscriptions'

export function useSubscriptionPlans() {
  return useQuery({
    queryKey: ['subscription-plans'],
    queryFn: () => subscriptionsApi.getPlans(),
    staleTime: 1000 * 60 * 10, // 10 minutes
  })
}

export function useCurrentSubscription() {
  return useQuery({
    queryKey: ['current-subscription'],
    queryFn: () => subscriptionsApi.getCurrentSubscription(),
  })
}

export function useSubscription(id: number) {
  return useQuery({
    queryKey: ['subscription', id],
    queryFn: () => subscriptionsApi.getSubscription(id),
    enabled: !!id,
  })
}

export function useSubscribe() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: SubscribeData) => subscriptionsApi.subscribe(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['current-subscription'] })
      queryClient.invalidateQueries({ queryKey: ['subscription-plans'] })
    },
  })
}

export function useCancelSubscription() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => subscriptionsApi.cancelSubscription(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['current-subscription'] })
    },
  })
}

export function useChangePlan() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, planId, billingCycle }: { id: number; planId: number; billingCycle: 'monthly' | 'yearly' }) =>
      subscriptionsApi.changePlan(id, planId, billingCycle),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['current-subscription'] })
    },
  })
}

export function useToggleAutoRenew() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => subscriptionsApi.toggleAutoRenew(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['current-subscription'] })
    },
  })
}

export function usePaymentHistory() {
  return useQuery({
    queryKey: ['payment-history'],
    queryFn: () => subscriptionsApi.getPaymentHistory(),
  })
}
