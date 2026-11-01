import { formatMalawiDate } from '@/lib/datetime'
import { useState } from 'react'
import { Check, Crown, Zap, Shield, Smartphone, MapPin, Bell, Headphones, Loader2, CreditCard, Phone, Building, X, Calendar, RefreshCw } from 'lucide-react'
import { toast } from 'sonner'
import { useSubscriptionPlans, useCurrentSubscription, useSubscribe, useCancelSubscription, useToggleAutoRenew, usePaymentHistory } from '@/hooks/useSubscriptions'
import type { SubscriptionPlan, Payment } from '@/types'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const planIcons: Record<string, typeof Crown> = {
  basic: Shield,
  standard: Zap,
  premium: Crown,
  enterprise: Crown,
}

const paymentMethods = [
  { id: 'airtel_money', name: 'Airtel Money', icon: Phone, color: 'bg-red-500' },
  { id: 'tnm_mpamba', name: 'TNM Mpamba', icon: Phone, color: 'bg-blue-500' },
  { id: 'bank_transfer', name: 'Bank Transfer', icon: Building, color: 'bg-slate-500' },
] as const

export default function SubscriptionsPage() {
  const [selectedPlan, setSelectedPlan] = useState<SubscriptionPlan | null>(null)
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly')
  const [paymentMethod, setPaymentMethod] = useState<'airtel_money' | 'tnm_mpamba' | 'bank_transfer'>('airtel_money')
  const [phoneNumber, setPhoneNumber] = useState('')
  const [showCheckout, setShowCheckout] = useState(false)
  const [showCancelDialog, setShowCancelDialog] = useState(false)
  const [showPaymentHistory, setShowPaymentHistory] = useState(false)

  const { data: plans, isLoading: plansLoading } = useSubscriptionPlans()
  const { data: currentSubscription, isLoading: subscriptionLoading } = useCurrentSubscription()
  const { data: payments } = usePaymentHistory()
  const subscribeMutation = useSubscribe()
  const cancelMutation = useCancelSubscription()
  const toggleAutoRenewMutation = useToggleAutoRenew()

  const handleSelectPlan = (plan: SubscriptionPlan) => {
    if (currentSubscription?.plan_id === plan.id) return
    setSelectedPlan(plan)
    setShowCheckout(true)
  }

  const handleSubscribe = () => {
    if (!selectedPlan) return

    if ((paymentMethod === 'airtel_money' || paymentMethod === 'tnm_mpamba') && !phoneNumber.trim()) {
      toast.error('Please enter your phone number')
      return
    }

    subscribeMutation.mutate(
      {
        plan_id: selectedPlan.id,
        billing_cycle: billingCycle,
        payment_method: paymentMethod,
        phone_number: phoneNumber || undefined,
      },
      {
        onSuccess: (data) => {
          toast.success('Subscription created successfully!')
          setShowCheckout(false)
          setSelectedPlan(null)
          setPhoneNumber('')
          if (data.payment_url) {
            window.open(data.payment_url, '_blank')
          }
        },
        onError: () => {
          toast.error('Failed to create subscription. Please try again.')
        },
      }
    )
  }

  const handleCancelSubscription = () => {
    if (!currentSubscription) return

    cancelMutation.mutate(currentSubscription.id, {
      onSuccess: () => {
        toast.success('Subscription cancelled successfully')
        setShowCancelDialog(false)
      },
      onError: () => {
        toast.error('Failed to cancel subscription')
      },
    })
  }

  const handleToggleAutoRenew = () => {
    if (!currentSubscription) return

    toggleAutoRenewMutation.mutate(currentSubscription.id, {
      onSuccess: (data) => {
        toast.success(data.auto_renew ? 'Auto-renewal enabled' : 'Auto-renewal disabled')
      },
      onError: () => {
        toast.error('Failed to update auto-renewal setting')
      },
    })
  }

  const getPrice = (plan: SubscriptionPlan) => {
    return billingCycle === 'monthly' ? plan.pricing.monthly : plan.pricing.yearly
  }

  const getSavings = (plan: SubscriptionPlan) => {
    const yearlyEquivalent = plan.pricing.monthly * 12
    const actualYearly = plan.pricing.yearly
    return Math.round(((yearlyEquivalent - actualYearly) / yearlyEquivalent) * 100)
  }

  if (plansLoading || subscriptionLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Subscription Plans
          </h1>
          <p className="text-slate-500 text-sm mt-1">
            Choose the plan that best fits your device tracking needs
          </p>
        </div>
        {payments && payments.length > 0 && (
          <button
            onClick={() => setShowPaymentHistory(!showPaymentHistory)}
            className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
          >
            <CreditCard className="h-4 w-4" />
            Payment History
          </button>
        )}
      </div>

      {/* Current Subscription Card */}
      {currentSubscription && (
        <div className="bg-gradient-to-br from-teal-500 to-teal-600 rounded-xl p-6 text-white">
          <div className="flex items-start justify-between">
            <div>
              <p className="text-teal-100 text-sm">Current Plan</p>
              <h2 className="text-2xl font-bold mt-1">{currentSubscription.plan?.name}</h2>
              <div className="flex items-center gap-4 mt-3 text-sm">
                <span className="flex items-center gap-1.5">
                  <Calendar className="h-4 w-4" />
                  Renews {formatMalawiDate(currentSubscription.ends_at)}
                </span>
                <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                  currentSubscription.status === 'active' ? 'bg-white/20' : 'bg-amber-400 text-amber-900'
                }`}>
                  {currentSubscription.status.charAt(0).toUpperCase() + currentSubscription.status.slice(1)}
                </span>
              </div>
            </div>
            <div className="flex flex-col items-end gap-2">
              <button
                onClick={handleToggleAutoRenew}
                disabled={toggleAutoRenewMutation.isPending}
                className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                  currentSubscription.auto_renew
                    ? 'bg-white/20 hover:bg-white/30'
                    : 'bg-white/10 hover:bg-white/20'
                }`}
              >
                <RefreshCw className={`h-4 w-4 ${toggleAutoRenewMutation.isPending ? 'animate-spin' : ''}`} />
                Auto-renew {currentSubscription.auto_renew ? 'On' : 'Off'}
              </button>
              {currentSubscription.status === 'active' && (
                <button
                  onClick={() => setShowCancelDialog(true)}
                  className="text-sm text-teal-200 hover:text-white underline"
                >
                  Cancel subscription
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Billing Cycle Toggle */}
      <div className="flex items-center justify-center gap-4">
        <span className={`text-sm font-medium ${billingCycle === 'monthly' ? 'text-slate-800' : 'text-slate-500'}`}>
          Monthly
        </span>
        <button
          onClick={() => setBillingCycle(billingCycle === 'monthly' ? 'yearly' : 'monthly')}
          className={`relative w-14 h-7 rounded-full transition-colors ${
            billingCycle === 'yearly' ? 'bg-teal-500' : 'bg-slate-300'
          }`}
        >
          <span
            className={`absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-transform ${
              billingCycle === 'yearly' ? 'translate-x-8' : 'translate-x-1'
            }`}
          />
        </button>
        <span className={`text-sm font-medium ${billingCycle === 'yearly' ? 'text-slate-800' : 'text-slate-500'}`}>
          Yearly
        </span>
        {billingCycle === 'yearly' && (
          <span className="ml-2 px-2 py-0.5 bg-emerald-100 text-emerald-700 text-xs font-medium rounded-full">
            Save up to 20%
          </span>
        )}
      </div>

      {/* Plans Grid */}
      {plans && plans.length > 0 ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {plans.sort((a, b) => a.sort_order - b.sort_order).map((plan) => {
            const Icon = planIcons[plan.slug] || Shield
            const isCurrentPlan = currentSubscription?.plan_id === plan.id
            const isPopular = plan.slug === 'standard' || plan.slug === 'premium'

            return (
              <div
                key={plan.id}
                className={`relative bg-white rounded-xl border-2 p-6 transition-all ${
                  isCurrentPlan
                    ? 'border-teal-500 ring-2 ring-teal-100'
                    : isPopular
                    ? 'border-amber-400'
                    : 'border-slate-200 hover:border-slate-300'
                }`}
              >
                {isPopular && !isCurrentPlan && (
                  <span className="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 bg-amber-400 text-amber-900 text-xs font-semibold rounded-full">
                    Popular
                  </span>
                )}
                {isCurrentPlan && (
                  <span className="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 bg-teal-500 text-white text-xs font-semibold rounded-full">
                    Current Plan
                  </span>
                )}

                <div className="flex items-center gap-3 mb-4">
                  <div className={`p-2.5 rounded-xl ${isCurrentPlan ? 'bg-teal-100' : 'bg-slate-100'}`}>
                    <Icon className={`h-6 w-6 ${isCurrentPlan ? 'text-teal-600' : 'text-slate-600'}`} />
                  </div>
                  <div>
                    <h3 className="font-semibold text-slate-800">{plan.name}</h3>
                    {plan.description && (
                      <p className="text-xs text-slate-500">{plan.description}</p>
                    )}
                  </div>
                </div>

                <div className="mb-6">
                  <div className="flex items-baseline gap-1">
                    <span className="text-3xl font-bold text-slate-800">
                      MWK {getPrice(plan).toLocaleString()}
                    </span>
                    <span className="text-slate-500 text-sm">
                      /{billingCycle === 'monthly' ? 'mo' : 'yr'}
                    </span>
                  </div>
                  {billingCycle === 'yearly' && getSavings(plan) > 0 && (
                    <p className="text-xs text-emerald-600 mt-1">
                      Save {getSavings(plan)}% vs monthly
                    </p>
                  )}
                </div>

                <ul className="space-y-3 mb-6">
                  <li className="flex items-center gap-2 text-sm text-slate-600">
                    <Smartphone className="h-4 w-4 text-teal-500" />
                    Up to {plan.limits.max_devices === 'unlimited' ? 'Unlimited' : plan.limits.max_devices} devices
                  </li>
                  {plan.features.real_time_tracking && (
                    <li className="flex items-center gap-2 text-sm text-slate-600">
                      <MapPin className="h-4 w-4 text-teal-500" />
                      Real-time tracking
                    </li>
                  )}
                  {plan.features.geofence_alerts && (
                    <li className="flex items-center gap-2 text-sm text-slate-600">
                      <MapPin className="h-4 w-4 text-teal-500" />
                      Geofencing
                    </li>
                  )}
                  {plan.features.theft_alerts && (
                    <li className="flex items-center gap-2 text-sm text-slate-600">
                      <Bell className="h-4 w-4 text-teal-500" />
                      Theft alerts & recovery
                    </li>
                  )}
                  {plan.features.priority_support && (
                    <li className="flex items-center gap-2 text-sm text-slate-600">
                      <Headphones className="h-4 w-4 text-teal-500" />
                      Priority support
                    </li>
                  )}
                </ul>

                <button
                  onClick={() => handleSelectPlan(plan)}
                  disabled={isCurrentPlan}
                  className={`w-full py-2.5 rounded-lg text-sm font-medium transition-colors ${
                    isCurrentPlan
                      ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                      : 'bg-teal-500 text-white hover:bg-teal-600'
                  }`}
                >
                  {isCurrentPlan ? 'Current Plan' : currentSubscription ? 'Switch Plan' : 'Get Started'}
                </button>
              </div>
            )
          })}
        </div>
      ) : (
        <div className="bg-white rounded-xl border border-slate-200/60 p-12 text-center">
          <div className="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <CreditCard className="h-8 w-8 text-slate-400" />
          </div>
          <h3 className="text-lg font-semibold text-slate-800 mb-2">No Plans Available</h3>
          <p className="text-slate-500">Subscription plans are currently being configured.</p>
        </div>
      )}

      {/* Payment History Panel */}
      {showPaymentHistory && payments && payments.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200/60 p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
              Payment History
            </h2>
            <button onClick={() => setShowPaymentHistory(false)} className="p-1 hover:bg-slate-100 rounded">
              <X className="h-5 w-5 text-slate-400" />
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100">
                  <th className="text-left py-3 px-4 text-slate-500 font-medium">Reference</th>
                  <th className="text-left py-3 px-4 text-slate-500 font-medium">Amount</th>
                  <th className="text-left py-3 px-4 text-slate-500 font-medium">Method</th>
                  <th className="text-left py-3 px-4 text-slate-500 font-medium">Status</th>
                  <th className="text-left py-3 px-4 text-slate-500 font-medium">Date</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((payment: Payment) => (
                  <tr key={payment.id} className="border-b border-slate-50 hover:bg-slate-50">
                    <td className="py-3 px-4 font-mono text-xs">{payment.payment_reference}</td>
                    <td className="py-3 px-4 font-medium">MWK {payment.amount.toLocaleString()}</td>
                    <td className="py-3 px-4 capitalize">{payment.payment_method.replace('_', ' ')}</td>
                    <td className="py-3 px-4">
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                        payment.status === 'completed' ? 'bg-emerald-50 text-emerald-700' :
                        payment.status === 'pending' ? 'bg-amber-50 text-amber-700' :
                        payment.status === 'failed' ? 'bg-red-50 text-red-700' :
                        'bg-slate-100 text-slate-600'
                      }`}>
                        {payment.status.charAt(0).toUpperCase() + payment.status.slice(1)}
                      </span>
                    </td>
                    <td className="py-3 px-4 text-slate-500">
                      {payment.paid_at ? formatMalawiDate(payment.paid_at) : '-'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Checkout Modal */}
      {showCheckout && selectedPlan && (
        <div className="fixed inset-0 z-50 overflow-y-auto">
          <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={() => setShowCheckout(false)} />
          <div className="relative min-h-full flex items-center justify-center p-4">
            <div className="relative bg-white rounded-xl shadow-xl max-w-md w-full">
              <div className="p-6">
                <div className="flex items-center justify-between mb-6">
                  <h2 className="text-lg font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                    Complete Your Subscription
                  </h2>
                  <button onClick={() => setShowCheckout(false)} className="p-1 hover:bg-slate-100 rounded">
                    <X className="h-5 w-5 text-slate-400" />
                  </button>
                </div>

                {/* Selected Plan Summary */}
                <div className="bg-slate-50 rounded-lg p-4 mb-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="font-medium text-slate-800">{selectedPlan.name}</p>
                      <p className="text-sm text-slate-500 capitalize">{billingCycle} billing</p>
                    </div>
                    <p className="text-xl font-bold text-slate-800">
                      MWK {getPrice(selectedPlan).toLocaleString()}
                    </p>
                  </div>
                </div>

                {/* Payment Method Selection */}
                <div className="mb-6">
                  <label className="block text-sm font-medium text-slate-700 mb-3">
                    Payment Method
                  </label>
                  <div className="space-y-2">
                    {paymentMethods.map((method) => {
                      const MethodIcon = method.icon
                      return (
                        <button
                          key={method.id}
                          onClick={() => setPaymentMethod(method.id)}
                          className={`w-full flex items-center gap-3 p-3 rounded-lg border-2 transition-all ${
                            paymentMethod === method.id
                              ? 'border-teal-500 bg-teal-50'
                              : 'border-slate-200 hover:border-slate-300'
                          }`}
                        >
                          <div className={`p-2 ${method.color} rounded-lg`}>
                            <MethodIcon className="h-4 w-4 text-white" />
                          </div>
                          <span className="font-medium text-slate-800">{method.name}</span>
                          {paymentMethod === method.id && (
                            <Check className="h-5 w-5 text-teal-500 ml-auto" />
                          )}
                        </button>
                      )
                    })}
                  </div>
                </div>

                {/* Phone Number Input for Mobile Money */}
                {(paymentMethod === 'airtel_money' || paymentMethod === 'tnm_mpamba') && (
                  <div className="mb-6">
                    <label htmlFor="phone" className="block text-sm font-medium text-slate-700 mb-2">
                      Phone Number
                    </label>
                    <input
                      type="tel"
                      id="phone"
                      value={phoneNumber}
                      onChange={(e) => setPhoneNumber(e.target.value)}
                      placeholder={paymentMethod === 'airtel_money' ? '099XXXXXXX' : '088XXXXXXX'}
                      className="w-full px-4 py-2.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent"
                    />
                    <p className="text-xs text-slate-500 mt-1">
                      You'll receive a payment prompt on this number
                    </p>
                  </div>
                )}

                {paymentMethod === 'bank_transfer' && (
                  <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 text-sm text-blue-800">
                    <p className="font-medium mb-2">Bank Transfer Instructions</p>
                    <p>After submitting, you'll receive bank details via email. Your subscription will be activated once payment is confirmed.</p>
                  </div>
                )}
              </div>

              <div className="flex items-center justify-end gap-3 px-6 py-4 bg-slate-50 rounded-b-xl border-t border-slate-100">
                <button
                  onClick={() => setShowCheckout(false)}
                  disabled={subscribeMutation.isPending}
                  className="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  onClick={handleSubscribe}
                  disabled={subscribeMutation.isPending}
                  className="px-6 py-2 text-sm font-medium text-white bg-teal-500 rounded-lg hover:bg-teal-600 transition-colors disabled:opacity-50 inline-flex items-center gap-2"
                >
                  {subscribeMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                  {currentSubscription ? 'Switch Plan' : 'Subscribe Now'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Cancel Subscription Dialog */}
      <ConfirmDialog
        isOpen={showCancelDialog}
        onClose={() => setShowCancelDialog(false)}
        onConfirm={handleCancelSubscription}
        title="Cancel Subscription"
        message="Are you sure you want to cancel your subscription? You'll continue to have access until the end of your current billing period."
        confirmText="Cancel Subscription"
        variant="danger"
        isLoading={cancelMutation.isPending}
      />
    </div>
  )
}
