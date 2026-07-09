import apiClient, { type ApiResponse } from './client'
import type { Subscription, SubscriptionPlan, Payment } from '@/types'

export interface SubscribeData {
  plan_id: number
  billing_cycle: 'monthly' | 'yearly'
  payment_method: 'airtel_money' | 'tnm_mpamba' | 'bank_transfer'
  phone_number?: string
}

export const subscriptionsApi = {
  // Get subscription plans
  getPlans: async (): Promise<SubscriptionPlan[]> => {
    const response = await apiClient.get<ApiResponse<SubscriptionPlan[]>>('/subscription-plans')
    return response.data.data
  },

  // Get current user's subscription
  getCurrentSubscription: async (): Promise<Subscription | null> => {
    const response = await apiClient.get<ApiResponse<Subscription | null>>('/subscriptions/current')
    return response.data.data
  },

  // Get subscription details
  getSubscription: async (id: number): Promise<Subscription> => {
    const response = await apiClient.get<ApiResponse<Subscription>>(`/subscriptions/${id}`)
    return response.data.data
  },

  // Subscribe to a plan
  subscribe: async (data: SubscribeData): Promise<{ subscription: Subscription; payment_url?: string }> => {
    const response = await apiClient.post<ApiResponse<{ subscription: Subscription; payment_url?: string }>>(
      '/subscriptions/subscribe',
      data
    )
    return response.data.data
  },

  // Cancel subscription
  cancelSubscription: async (id: number): Promise<Subscription> => {
    const response = await apiClient.post<ApiResponse<Subscription>>(`/subscriptions/${id}/cancel`)
    return response.data.data
  },

  // Change plan
  changePlan: async (id: number, planId: number, billingCycle: 'monthly' | 'yearly'): Promise<Subscription> => {
    const response = await apiClient.post<ApiResponse<Subscription>>(`/subscriptions/${id}/change-plan`, {
      plan_id: planId,
      billing_cycle: billingCycle,
    })
    return response.data.data
  },

  // Toggle auto-renew
  toggleAutoRenew: async (id: number): Promise<Subscription> => {
    const response = await apiClient.post<ApiResponse<Subscription>>(`/subscriptions/${id}/toggle-auto-renew`)
    return response.data.data
  },

  // Get payment history
  getPaymentHistory: async (): Promise<Payment[]> => {
    const response = await apiClient.get<ApiResponse<Payment[]>>('/payments/my-payments')
    return response.data.data
  },
}

export default subscriptionsApi
