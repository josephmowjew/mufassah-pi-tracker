import { formatMalawiDate, formatMalawiDateTime } from '@/lib/datetime'
import { useState } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  MapPin,
  Loader2,
  Eye,
  MessageSquare,
  DollarSign,
  Clock,
  Smartphone,
  Share2,
  ChevronLeft,
  Send,
  Check,
  User,
  Camera,
} from 'lucide-react'
import {
  useCommunityAlert,
  useAlertTips,
  useSubmitTip,
  useRecordShare,
} from '@/hooks/useCommunity'
import type { CommunityTip, TipStatusValue } from '@/types'

const statusConfig = {
  active: { bg: 'bg-red-50', text: 'text-red-700', label: 'Active' },
  paused: { bg: 'bg-amber-50', text: 'text-amber-700', label: 'Paused' },
  resolved: { bg: 'bg-emerald-50', text: 'text-emerald-700', label: 'Resolved' },
  expired: { bg: 'bg-slate-100', text: 'text-slate-600', label: 'Expired' },
}

const tipStatusConfig: Record<TipStatusValue, { bg: string; text: string; label: string }> = {
  pending: { bg: 'bg-amber-50', text: 'text-amber-700', label: 'Pending' },
  reviewing: { bg: 'bg-blue-50', text: 'text-blue-700', label: 'Reviewing' },
  verified: { bg: 'bg-emerald-50', text: 'text-emerald-700', label: 'Verified' },
  false_lead: { bg: 'bg-slate-100', text: 'text-slate-600', label: 'False Lead' },
}

export default function CommunityAlertDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const alertId = Number(id)

  const { data: alert, isLoading, error } = useCommunityAlert(alertId)
  const { data: tipsData } = useAlertTips(alertId)
  const submitTip = useSubmitTip()
  const recordShare = useRecordShare()

  const [showTipForm, setShowTipForm] = useState(false)
  const [tipForm, setTipForm] = useState({
    tip_type: 'sighting' as 'sighting' | 'location' | 'information' | 'recovery',
    description: '',
    spotted_address: '',
    is_anonymous: false,
    contact_preference: 'none' as 'phone' | 'email' | 'whatsapp' | 'none',
    contact_info: '',
  })

  const tips = tipsData?.data || []
  const device = alert?.theft_report?.device
  const status = alert ? statusConfig[alert.status.value] : null

  const handleShare = async () => {
    if (!alert) return

    try {
      await navigator.share({
        title: `Help find stolen ${device?.brand} ${device?.model}`,
        text: alert.theft_report?.description || 'Help recover this stolen device',
        url: window.location.href,
      })
      recordShare.mutate(alertId)
    } catch {
      // Fallback: copy to clipboard
      navigator.clipboard.writeText(window.location.href)
      recordShare.mutate(alertId)
    }
  }

  const handleSubmitTip = async (e: React.FormEvent) => {
    e.preventDefault()

    try {
      await submitTip.mutateAsync({
        alertId,
        data: tipForm,
      })
      setShowTipForm(false)
      setTipForm({
        tip_type: 'sighting',
        description: '',
        spotted_address: '',
        is_anonymous: false,
        contact_preference: 'none',
        contact_info: '',
      })
    } catch {
      // Error handled by mutation
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
      </div>
    )
  }

  if (error || !alert) {
    return (
      <div className="bg-white rounded-xl border border-slate-200/60 flex flex-col items-center justify-center py-20 text-center">
        <AlertTriangle className="h-10 w-10 text-red-400 mb-3" />
        <p className="text-slate-600">Alert not found</p>
        <button
          onClick={() => navigate(-1)}
          className="mt-4 text-teal-600 hover:text-teal-700 text-sm font-medium"
        >
          Go back
        </button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <button
          onClick={() => navigate(-1)}
          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
        >
          <ChevronLeft className="h-5 w-5 text-slate-600" />
        </button>
        <div className="flex-1">
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Alert Details
          </h1>
          <p className="text-sm text-slate-500">
            Report #{alert.theft_report?.report_number}
          </p>
        </div>
        <button
          onClick={handleShare}
          className="inline-flex items-center gap-2 px-4 py-2.5 border border-slate-200 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50 transition-colors"
        >
          <Share2 className="h-4 w-4" />
          Share
        </button>
      </div>

      <div className="grid lg:grid-cols-3 gap-6">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-6">
          {/* Alert Card */}
          <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
            {/* Status Bar */}
            {status && (
              <div className={`px-4 py-3 ${status.bg} flex items-center justify-between`}>
                <span className={`text-sm font-medium ${status.text}`}>{status.label}</span>
                {alert.has_bounty && (
                  <span className="inline-flex items-center gap-1 px-3 py-1 bg-emerald-500 text-white rounded-full text-sm font-medium">
                    <DollarSign className="h-4 w-4" />
                    MWK {Number(alert.bounty_amount).toLocaleString()} Bounty
                  </span>
                )}
              </div>
            )}

            <div className="p-6">
              {/* Device Info */}
              {device && (
                <div className="flex items-start gap-4 mb-6">
                  <div className="p-3 bg-slate-100 rounded-xl">
                    <Smartphone className="h-8 w-8 text-slate-600" />
                  </div>
                  <div>
                    <h2 className="text-xl font-semibold text-slate-800">
                      {device.brand} {device.model}
                    </h2>
                    {device.color && (
                      <p className="text-sm text-slate-500">Color: {device.color}</p>
                    )}
                    <p className="text-xs text-slate-400 mt-1">
                      Serial: {device.serial_number}
                    </p>
                  </div>
                </div>
              )}

              {/* Description */}
              {alert.theft_report?.description && (
                <div className="mb-6">
                  <h3 className="text-sm font-medium text-slate-700 mb-2">Description</h3>
                  <p className="text-slate-600">{alert.theft_report.description}</p>
                </div>
              )}

              {/* Theft Location */}
              {alert.theft_report?.theft_location && (
                <div className="flex items-start gap-2 mb-4">
                  <MapPin className="h-4 w-4 text-slate-400 mt-0.5" />
                  <div>
                    <p className="text-sm text-slate-600">
                      Last seen: {alert.theft_report.theft_location}
                    </p>
                    <p className="text-xs text-slate-400">
                      {formatMalawiDate(alert.theft_report.theft_date)}
                    </p>
                  </div>
                </div>
              )}

              {/* Stats */}
              <div className="flex flex-wrap items-center gap-4 pt-4 border-t border-slate-100">
                <span className="flex items-center gap-1.5 text-sm text-slate-500">
                  <Eye className="h-4 w-4" />
                  {alert.view_count} views
                </span>
                <span className="flex items-center gap-1.5 text-sm text-slate-500">
                  <MessageSquare className="h-4 w-4" />
                  {alert.tips_count} tips
                </span>
                <span className="flex items-center gap-1.5 text-sm text-slate-500">
                  <Share2 className="h-4 w-4" />
                  {alert.share_count} shares
                </span>
                <span className="flex items-center gap-1.5 text-sm text-slate-400">
                  <Clock className="h-4 w-4" />
                  Posted {formatMalawiDate(alert.created_at)}
                </span>
              </div>
            </div>
          </div>

          {/* Tips Section */}
          <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
            <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
              <h3 className="font-semibold text-slate-800">Community Tips</h3>
              {alert.can_receive_tips && (
                <button
                  onClick={() => setShowTipForm(!showTipForm)}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors"
                >
                  <Send className="h-3.5 w-3.5" />
                  Submit Tip
                </button>
              )}
            </div>

            {/* Tip Form */}
            {showTipForm && (
              <div className="p-6 bg-slate-50 border-b border-slate-100">
                <form onSubmit={handleSubmitTip} className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">
                      Tip Type
                    </label>
                    <select
                      value={tipForm.tip_type}
                      onChange={(e) => setTipForm({ ...tipForm, tip_type: e.target.value as typeof tipForm.tip_type })}
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                    >
                      <option value="sighting">I saw this device</option>
                      <option value="location">I know where it might be</option>
                      <option value="information">I have information</option>
                      <option value="recovery">I can help recover it</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">
                      Description *
                    </label>
                    <textarea
                      value={tipForm.description}
                      onChange={(e) => setTipForm({ ...tipForm, description: e.target.value })}
                      rows={3}
                      placeholder="Provide as much detail as possible..."
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">
                      Location (if applicable)
                    </label>
                    <input
                      type="text"
                      value={tipForm.spotted_address}
                      onChange={(e) => setTipForm({ ...tipForm, spotted_address: e.target.value })}
                      placeholder="Where did you see it?"
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                    />
                  </div>

                  <div className="flex items-center gap-6">
                    <label className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={tipForm.is_anonymous}
                        onChange={(e) => setTipForm({ ...tipForm, is_anonymous: e.target.checked })}
                        className="rounded border-slate-300 text-teal-500 focus:ring-teal-500"
                      />
                      <span className="text-sm text-slate-600">Submit anonymously</span>
                    </label>
                  </div>

                  {!tipForm.is_anonymous && (
                    <div className="grid sm:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-slate-700 mb-1.5">
                          Contact Preference
                        </label>
                        <select
                          value={tipForm.contact_preference}
                          onChange={(e) => setTipForm({ ...tipForm, contact_preference: e.target.value as typeof tipForm.contact_preference })}
                          className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                        >
                          <option value="none">Don't contact me</option>
                          <option value="phone">Phone call</option>
                          <option value="whatsapp">WhatsApp</option>
                          <option value="email">Email</option>
                        </select>
                      </div>
                      {tipForm.contact_preference !== 'none' && (
                        <div>
                          <label className="block text-sm font-medium text-slate-700 mb-1.5">
                            Contact Info
                          </label>
                          <input
                            type="text"
                            value={tipForm.contact_info}
                            onChange={(e) => setTipForm({ ...tipForm, contact_info: e.target.value })}
                            placeholder={tipForm.contact_preference === 'email' ? 'Email address' : 'Phone number'}
                            className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                          />
                        </div>
                      )}
                    </div>
                  )}

                  <div className="flex items-center justify-end gap-3 pt-2">
                    <button
                      type="button"
                      onClick={() => setShowTipForm(false)}
                      className="px-4 py-2 border border-slate-200 text-slate-600 rounded-lg text-sm font-medium hover:bg-slate-50 transition-colors"
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      disabled={submitTip.isPending}
                      className="inline-flex items-center gap-2 px-4 py-2 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors disabled:opacity-50"
                    >
                      {submitTip.isPending ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        <Send className="h-4 w-4" />
                      )}
                      Submit Tip
                    </button>
                  </div>
                </form>
              </div>
            )}

            {/* Tips List */}
            <div className="divide-y divide-slate-100">
              {tips.length === 0 ? (
                <div className="p-8 text-center">
                  <MessageSquare className="h-10 w-10 text-slate-300 mx-auto mb-3" />
                  <p className="text-slate-500">No tips yet. Be the first to help!</p>
                </div>
              ) : (
                tips.map((tip) => <TipCard key={tip.id} tip={tip} />)
              )}
            </div>
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          {/* Bounty Card */}
          {alert.has_bounty && (
            <div className="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl p-6 text-white">
              <div className="flex items-center gap-2 mb-3">
                <DollarSign className="h-5 w-5" />
                <span className="font-semibold">Recovery Bounty</span>
              </div>
              <p className="text-3xl font-bold mb-2">
                MWK {Number(alert.bounty_amount).toLocaleString()}
              </p>
              <p className="text-sm text-emerald-100">
                Earn this reward by helping recover this device
              </p>
            </div>
          )}

          {/* How to Help */}
          <div className="bg-white rounded-xl border border-slate-200/60 p-6">
            <h3 className="font-semibold text-slate-800 mb-4">How You Can Help</h3>
            <ul className="space-y-3 text-sm text-slate-600">
              <li className="flex items-start gap-2">
                <Eye className="h-4 w-4 text-teal-500 mt-0.5" />
                <span>Keep an eye out for this device in your area</span>
              </li>
              <li className="flex items-start gap-2">
                <Share2 className="h-4 w-4 text-teal-500 mt-0.5" />
                <span>Share this alert with friends and family</span>
              </li>
              <li className="flex items-start gap-2">
                <MessageSquare className="h-4 w-4 text-teal-500 mt-0.5" />
                <span>Submit a tip if you have any information</span>
              </li>
              <li className="flex items-start gap-2">
                <Camera className="h-4 w-4 text-teal-500 mt-0.5" />
                <span>Take photos if you spot the device</span>
              </li>
            </ul>
          </div>

          {/* Safety Notice */}
          <div className="bg-amber-50 rounded-xl border border-amber-200 p-6">
            <h3 className="font-semibold text-amber-800 mb-2">Safety First</h3>
            <p className="text-sm text-amber-700">
              Never confront suspected thieves. If you spot this device, report its location
              through the tip system and let authorities handle recovery.
            </p>
          </div>

          {/* Back Link */}
          <Link
            to="/community"
            className="block text-center text-sm text-teal-600 hover:text-teal-700 font-medium"
          >
            View all alerts
          </Link>
        </div>
      </div>
    </div>
  )
}

function TipCard({ tip }: { tip: CommunityTip }) {
  const status = tipStatusConfig[tip.status.value]

  return (
    <div className="p-4">
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <div className="p-2 bg-slate-100 rounded-full">
            <User className="h-4 w-4 text-slate-500" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="font-medium text-slate-800">
                {tip.tipster_display_name}
              </span>
              <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${status.bg} ${status.text}`}>
                {status.label}
              </span>
            </div>
            <p className="text-sm text-slate-600 mt-1">{tip.description}</p>
            {tip.spotted_address && (
              <p className="flex items-center gap-1 text-xs text-slate-400 mt-2">
                <MapPin className="h-3 w-3" />
                {tip.spotted_address}
              </p>
            )}
            <p className="text-xs text-slate-400 mt-1">
              {formatMalawiDateTime(tip.created_at)}
            </p>
          </div>
        </div>
        {tip.is_verified && (
          <div className="flex items-center gap-1 px-2 py-1 bg-emerald-50 text-emerald-700 rounded-lg text-xs font-medium">
            <Check className="h-3 w-3" />
            Verified
          </div>
        )}
      </div>
    </div>
  )
}
