import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/api/client'
import { toast } from 'sonner'
import {
  Mail,
  Plus,
  X,
  Bell,
  Save,
  TestTube,
  Check,
  MailCheck,
} from 'lucide-react'

interface CommunicationSettings {
  id: number
  admin_emails: string[]
  sale_notifications_enabled: boolean
  shift_summary_notifications_enabled: boolean
}

export default function AdminCommunicationsPage() {
  console.log('=== AdminCommunicationsPage MOUNTED ===')

  const queryClient = useQueryClient()
  const [localSettings, setLocalSettings] = useState<CommunicationSettings | null>(null)
  const [newEmail, setNewEmail] = useState('')

  console.log('Current localSettings:', localSettings)

  const { data: settings, isLoading, error, isError } = useQuery({
    queryKey: ['communication-settings'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: CommunicationSettings }>(
        '/admin/communications'
      )
      return response.data.data
    },
  })

  useEffect(() => {
    if (settings) {

     
      setLocalSettings(settings)
    }
  }, [settings])

  const updateMutation = useMutation({
    mutationFn: async (data: CommunicationSettings) => {
      const response = await apiClient.put('/admin/communications', {
        admin_emails: data.admin_emails,
        sale_notifications_enabled: data.sale_notifications_enabled,
        shift_summary_notifications_enabled: data.shift_summary_notifications_enabled,
      })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['communication-settings'] })
      toast.success('Communication settings updated successfully')
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Failed to update communication settings'
      toast.error(message)
    },
  })

  const testEmailMutation = useMutation({
    mutationFn: async () => {
      const response = await apiClient.post('/admin/communications/test-email')
      return response.data
    },
    onSuccess: (data: any) => {
      toast.success(data.message || 'Test email sent successfully')
    },
    onError: () => {
      toast.error('Failed to send test email')
    },
  })

  const handleAddEmail = () => {
    if (!newEmail) return

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    if (!emailRegex.test(newEmail)) {
      toast.error('Please enter a valid email address')
      return
    }

    if (!localSettings) return

    if (localSettings.admin_emails.includes(newEmail)) {
      toast.error('This email is already in the list')
      return
    }

    setLocalSettings({
      ...localSettings,
      admin_emails: [...localSettings.admin_emails, newEmail],
    })
    setNewEmail('')
  }

  const handleRemoveEmail = (email: string) => {
    if (!localSettings) return

    setLocalSettings({
      ...localSettings,
      admin_emails: localSettings.admin_emails.filter((e) => e !== email),
    })
  }

  const handleSave = () => {
    console.log('=== handleSave called ===')
    console.log('localSettings:', localSettings)

    if (!localSettings) {
      console.error('No localSettings')
      toast.error('Settings not loaded')
      return
    }

    if (localSettings.admin_emails.length === 0) {
      console.error('No admin emails')
      toast.error('Please add at least one admin email')
      return
    }

    console.log('Calling updateMutation.mutate with:', localSettings)
    updateMutation.mutate(localSettings)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-4">
        <div className="text-red-600 text-center">
          <p className="font-semibold">Error loading communication settings</p>
          <p className="text-sm mt-2">{error instanceof Error ? error.message : 'Unknown error'}</p>
        </div>
      </div>
    )
  }

  if (!localSettings) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-slate-600">No settings found. Please try refreshing the page.</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Communication Settings</h1>
          <p className="text-slate-600 mt-1">
            Configure email notifications for important system events
          </p>
        </div>
        <div className="flex gap-3">
          <button
            onClick={() => testEmailMutation.mutate()}
            disabled={testEmailMutation.isPending || localSettings.admin_emails.length === 0}
            className="flex items-center gap-2 px-4 py-2 border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            <TestTube className="h-4 w-4" />
            {testEmailMutation.isPending ? 'Sending...' : 'Send Test Email'}
          </button>
          <button
            onClick={(e) => {
              console.log('=== BUTTON CLICKED ===', e)
              console.log('Event target:', e.target)
              console.log('Current target:', e.currentTarget)
              alert('Save button clicked! Check console for details.')
              handleSave()
            }}
            disabled={updateMutation.isPending}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            <Save className="h-4 w-4" />
            {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Admin Emails */}
        <div className="lg:col-span-2 space-y-6">
          <div className="bg-white rounded-xl border border-slate-200 p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="p-2 bg-blue-100 rounded-lg">
                <Mail className="h-5 w-5 text-blue-600" />
              </div>
              <div>
                <h2 className="text-lg font-semibold text-slate-900">Admin Email Addresses</h2>
                <p className="text-sm text-slate-500">
                  Add email addresses that should receive notifications
                </p>
              </div>
            </div>

            {/* Add Email Input */}
            <div className="flex gap-2 mb-4">
              <input
                type="email"
                value={newEmail}
                onChange={(e) => setNewEmail(e.target.value)}
                onKeyPress={(e) => e.key === 'Enter' && handleAddEmail()}
                placeholder="Enter email address (e.g., admin@example.com)"
                className="flex-1 px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <button
                onClick={handleAddEmail}
                className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
              >
                <Plus className="h-4 w-4" />
                Add
              </button>
            </div>

            {/* Email List */}
            {localSettings.admin_emails.length === 0 ? (
              <div className="text-center py-8 bg-slate-50 rounded-lg border border-dashed border-slate-300">
                <Mail className="h-12 w-12 text-slate-400 mx-auto mb-2" />
                <p className="text-slate-600">No admin emails configured yet</p>
                <p className="text-sm text-slate-500 mt-1">
                  Add at least one email to receive notifications
                </p>
              </div>
            ) : (
              <div className="space-y-2">
                {localSettings.admin_emails.map((email) => (
                  <div
                    key={email}
                    className="flex items-center justify-between p-3 bg-slate-50 rounded-lg group"
                  >
                    <div className="flex items-center gap-3">
                      <MailCheck className="h-4 w-4 text-blue-600" />
                      <span className="text-sm font-medium text-slate-900">{email}</span>
                    </div>
                    <button
                      onClick={() => handleRemoveEmail(email)}
                      className="p-1 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                    >
                      <X className="h-4 w-4" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Notification Toggles */}
        <div className="space-y-6">
          <div className="bg-white rounded-xl border border-slate-200 p-6">
            <div className="flex items-center gap-3 mb-6">
              <div className="p-2 bg-purple-100 rounded-lg">
                <Bell className="h-5 w-5 text-purple-600" />
              </div>
              <div>
                <h2 className="text-lg font-semibold text-slate-900">Notification Types</h2>
                <p className="text-sm text-slate-500">Choose which notifications to receive</p>
              </div>
            </div>

            <div className="space-y-4">
              {/* Sale Notifications */}
              <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg">
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <Check className="h-4 w-4 text-green-600" />
                    <h3 className="font-medium text-slate-900">New Sale Notifications</h3>
                  </div>
                  <p className="text-sm text-slate-600">
                    Receive an email whenever a sale is made
                  </p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={localSettings.sale_notifications_enabled}
                    onChange={(e) =>
                      setLocalSettings({
                        ...localSettings,
                        sale_notifications_enabled: e.target.checked,
                      })
                    }
                    disabled={updateMutation.isPending}
                    className="sr-only peer"
                  />
                  <div className="w-11 h-6 bg-slate-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
              </div>

              {/* Shift Summary Notifications */}
              <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg">
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <Check className="h-4 w-4 text-green-600" />
                    <h3 className="font-medium text-slate-900">Shift End Summaries</h3>
                  </div>
                  <p className="text-sm text-slate-600">
                    Receive a summary when staff end their shifts
                  </p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={localSettings.shift_summary_notifications_enabled}
                    onChange={(e) =>
                      setLocalSettings({
                        ...localSettings,
                        shift_summary_notifications_enabled: e.target.checked,
                      })
                    }
                    disabled={updateMutation.isPending}
                    className="sr-only peer"
                  />
                  <div className="w-11 h-6 bg-slate-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
              </div>
            </div>

            {/* Info Box */}
            <div className="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
              <div className="flex gap-3">
                <MailCheck className="h-5 w-5 text-blue-600 flex-shrink-0 mt-0.5" />
                <div className="text-sm">
                  <p className="font-medium text-blue-900">How Notifications Work</p>
                  <p className="text-blue-700 mt-1">
                    When enabled, notifications will be sent to all configured admin emails
                    instantly when events occur.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
