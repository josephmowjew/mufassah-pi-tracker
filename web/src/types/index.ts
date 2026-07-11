// User types
export interface User {
  id: number
  name: string
  email: string | null
  phone_number: string | null
  avatar: string | null
  address: string | null
  city: string | null
  national_id: string | null
  branch_id: number | null
  email_verified_at: string | null
  created_at: string
  updated_at: string
  roles?: Role[] | string[]
  permissions?: string[]
  branch?: Branch
}

export interface Role {
  id: number
  name: string
  guard_name: string
}

// Branch types
export interface Branch {
  id: number
  name: string
  code: string
  address: string | null
  city: string | null
  phone: string | null
  email: string | null
  manager_id: number | null
  is_active: boolean
  devices_count?: number
  users_count?: number
  sales_count?: number
  manager?: User
  created_at: string
  updated_at: string
}

// Device types
export type DeviceStatusValue = 'active' | 'inactive' | 'stolen' | 'recovered'

export interface DeviceStatusObject {
  value: DeviceStatusValue
  label: string
  color: string
}

// Status can be either a string or an object depending on API response
export type DeviceStatus = DeviceStatusValue | DeviceStatusObject

export interface Device {
  id: number
  owner_id: number | null
  category_id: number
  branch_id: number | null
  inventory_batch_id?: number | null
  iot_module_id: number | null
  unique_device_code?: string  // API returns this
  device_code?: string         // For backwards compatibility
  serial_number: string
  name: string                 // Computed from brand + model by API
  brand: string
  model: string
  color: string | null
  purchase_date: string | null
  purchase_price: number | string | null  // API might return string
  purchase_location: string | null
  status: DeviceStatus
  inventory_status?: InventoryStatus
  is_in_stock?: boolean
  is_sold?: boolean
  sold_at?: string | null
  sale_id?: number | null
  sold_by_id?: number | null
  description: string | null
  notes: string | null
  photos: string[] | null
  is_stolen: boolean
  has_iot_module: boolean
  last_location_id: number | null
  last_seen_at: string | null
  created_at: string
  updated_at: string
  category?: DeviceCategory
  owner?: User
  sold_by?: User
  branch?: Branch
  inventory_batch?: InventoryBatch
  last_location?: DeviceLocation
  iot_module?: IotModule
}

export interface DeviceCategory {
  id: number
  name: string
  slug: string
  description: string | null
  icon: string | null
  is_active: boolean
  devices_count?: number
  available_count?: number  // Count of in-stock devices available for sale
}

// Brand types for product catalog
export interface Brand {
  id: number
  category_id: number
  name: string
  slug: string
  logo: string | null
  description: string | null
  is_active: boolean
  sort_order: number
  created_at: string
  updated_at: string
  category?: DeviceCategory
  product_models?: ProductModel[]
  product_models_count?: number
  devices_count?: number
}

// Product Model types for product catalog
export interface ProductModel {
  id: number
  brand_id: number
  name: string
  slug: string
  description: string | null
  image: string | null
  base_price: number | null
  specifications: Record<string, unknown> | null
  available_colors: string[] | null
  is_active: boolean
  sort_order: number
  created_at: string
  updated_at: string
  brand?: Brand
  devices_count?: number
}

// Product options for cascading selection
export interface ProductOption {
  name: string | null
  count: number
}

export interface ProductOptions {
  brands: ProductOption[]
  models: ProductOption[]
  colors: ProductOption[]
  has_colors: boolean
  available_count: number
  selling_price: number | null
  price_tiers?: Array<{ batch_id: number | null; batch_number: string | null; available_quantity: number; selling_price: number | string | null }>
}

// Location types
export interface DeviceLocation {
  id: number
  device_id: number
  iot_module_id: number | null
  latitude: number
  longitude: number
  altitude: number | null
  accuracy: number | null
  speed: number | null
  heading: number | null
  address: string | null
  is_manual: boolean
  recorded_at: string
  created_at: string
}

// Geofence types
export type GeofenceType = 'circle' | 'polygon'

export interface Geofence {
  id: number
  device_id: number
  name: string
  type: GeofenceType
  center_latitude: number
  center_longitude: number
  radius_meters: number
  coordinates: { lat: number; lng: number }[] | null
  is_active: boolean
  notify_on_entry: boolean
  notify_on_exit: boolean
  created_at: string
  updated_at: string
  device?: Device
}

export interface GeofenceEvent {
  id: number
  geofence_id: number
  device_location_id: number
  event_type: 'entry' | 'exit'
  latitude: number
  longitude: number
  created_at: string
  geofence?: Geofence
  location?: DeviceLocation
}

// Theft Report types
export type TheftReportStatusValue = 'reported' | 'investigating' | 'recovered' | 'closed' | 'false_alarm'

export interface TheftReportStatusObject {
  value: TheftReportStatusValue
  label: string
  color: string
}

// Keep the old type for backwards compatibility
export type TheftReportStatus = TheftReportStatusValue | TheftReportStatusObject

export interface TheftReport {
  id: number
  device_id: number
  reporter_id: number
  report_number: string
  status: TheftReportStatusObject
  theft_date: string
  theft_location: string | null
  theft_location_latitude: number | null
  theft_location_longitude: number | null
  description: string
  police_report_number: string | null
  police_station: string | null
  documents: string[]
  investigation_notes: string | null
  assigned_to: number | null
  recovery_date: string | null
  recovery_location: string | null
  recovery_notes: string | null
  created_at: string
  updated_at: string
  device?: Device
  reporter?: User
  assignee?: User
}

// IoT Module types
export type IotModuleStatus = 'active' | 'inactive' | 'maintenance'
export type ModemType = 'gsm' | '4g_lte' | 'nb_iot' | 'lora'

export interface IotModule {
  id: number
  module_id: string
  sim_number: string | null
  imei: string | null
  modem_type: ModemType
  status: IotModuleStatus
  firmware_version: string | null
  battery_level: number | null
  signal_strength: number | null
  last_ping: string | null
  last_location_update: string | null
  activated_at: string | null
  configuration: Record<string, unknown> | null
  created_at: string
  updated_at: string
  device?: Device
}

// Subscription types
export type SubscriptionStatus = 'active' | 'cancelled' | 'expired' | 'suspended' | 'pending'
export type BillingCycle = 'monthly' | 'yearly'

export interface SubscriptionPlan {
  id: number
  name: string
  slug: string
  description: string | null
  pricing: {
    monthly: number
    yearly: number
    currency: string
    yearly_savings: number
  }
  limits: {
    max_devices: number | 'unlimited'
    max_geofences_per_device: number | 'unlimited'
    location_history_days: number
  }
  features: {
    real_time_tracking: boolean
    theft_alerts: boolean
    geofence_alerts: boolean
    priority_support: boolean
    feature_list: string[]
  }
  is_active: boolean
  sort_order: number
}

export interface Subscription {
  id: number
  user_id: number
  plan_id: number
  status: SubscriptionStatus
  billing_cycle: BillingCycle
  starts_at: string
  ends_at: string
  cancelled_at: string | null
  auto_renew: boolean
  created_at: string
  updated_at: string
  user?: User
  plan?: SubscriptionPlan
}

// Payment types
export type PaymentStatus = 'pending' | 'completed' | 'failed' | 'refunded' | 'cancelled'
export type PaymentMethod = 'airtel_money' | 'tnm_mpamba' | 'bank_transfer' | 'cash'

export interface Payment {
  id: number
  subscription_id: number
  user_id: number
  payment_reference: string
  amount: number
  currency: string
  payment_method: PaymentMethod
  status: PaymentStatus
  provider_reference: string | null
  paid_at: string | null
  metadata: Record<string, unknown> | null
  created_at: string
  updated_at: string
  subscription?: Subscription
  user?: User
}

// Warranty types
export type WarrantyStatus = 'active' | 'expired' | 'voided'
export type WarrantyClaimStatus = 'pending' | 'approved' | 'rejected' | 'completed'

export interface Warranty {
  id: number
  device_id: number
  user_id: number
  warranty_number: string
  starts_at: string
  ends_at: string
  status: WarrantyStatus
  terms: string | null
  coverage_details: Record<string, unknown> | null
  created_at: string
  updated_at: string
  device?: Device
  user?: User
}

export interface WarrantyClaim {
  id: number
  warranty_id: number
  claim_number: string
  issue_description: string
  status: WarrantyClaimStatus
  documents: string[]
  admin_notes: string | null
  resolution: string | null
  resolved_at: string | null
  created_at: string
  updated_at: string
  warranty?: Warranty
}

// Notification types
export interface Notification {
  id: string
  type: string
  title?: string
  message?: string
  notifiable_type: string
  notifiable_id: number
  data: Record<string, unknown>
  read_at: string | null
  created_at: string
  updated_at: string
}

// Inventory types
export type InventoryStatusValue = 'in_stock' | 'reserved' | 'sold' | 'returned' | 'faulty'

export interface InventoryStatusObject {
  value: InventoryStatusValue
  label: string
  color: string
}

export type InventoryStatus = InventoryStatusValue | InventoryStatusObject

export interface GroupedInventoryItem {
  category_id: number
  category_name: string
  brand: string
  model: string
  color: string | null
  in_stock: number
  reserved: number
  sold: number
  returned: number
  faulty: number
  total: number
  avg_price: number
  min_price: number | null
  max_price: number | null
}

export interface BulkAddDeviceData {
  category_id: number
  branch_id: number
  iot_module_id?: number
  brand: string
  model: string
  color?: string
  description?: string
  quantity: number
  purchase_date?: string
  buying_price?: number
  target_selling_price?: number
  purchase_price?: number
  purchase_location?: string
  notes?: string
  auto_generate_serials?: boolean
  serial_numbers?: string[]
}

export interface InventoryReceiptLineData {
  category_id: number
  brand: string
  model: string
  color?: string
  description?: string
  quantity: number
  buying_price?: number
  target_selling_price?: number
  notes?: string
  auto_generate_serials?: boolean
  serial_numbers?: string[] | string
}

export interface InventoryReceiptData {
  branch_id: number
  supplier?: string
  reference_number?: string
  purchase_date?: string
  purchase_location?: string
  notes?: string
  lines: InventoryReceiptLineData[]
}

export interface InventoryBatchSummary {
  in_stock: number
  reserved: number
  sold: number
  returned: number
  faulty: number
  total_cost: number
  realized_revenue: number
  realized_gross_profit: number
  expected_gross_profit: number
  margin: number | null
}

export interface InventoryBatch {
  id: number
  batch_number: string
  inventory_receipt_id?: number | null
  branch_id: number
  category_id: number
  brand: string
  model: string
  color: string | null
  quantity_received: number
  buying_price: number | string
  target_selling_price: number | string
  purchase_date: string | null
  purchase_location: string | null
  notes: string | null
  summary: InventoryBatchSummary
  branch?: Branch
  category?: DeviceCategory
  devices?: Device[]
  receipt?: InventoryReceipt
  created_at: string
  updated_at: string
}

export interface InventoryReceipt {
  id: number
  receipt_number: string
  branch_id: number
  supplier: string | null
  reference_number: string | null
  purchase_date: string | null
  purchase_location: string | null
  status: string
  notes: string | null
  received_by_id: number | null
  received_at: string | null
  summary: {
    lines: number
    units: number
    total_cost: number
    expected_revenue: number
    expected_gross_profit: number
    realized_revenue: number
    realized_gross_profit: number
    margin: number | null
  }
  branch?: Branch
  received_by?: User
  batches?: InventoryBatch[]
  created_at: string
  updated_at: string
}

export type InventoryMovementType =
  | 'receipt'
  | 'reservation'
  | 'reservation_cancelled'
  | 'sale'
  | 'sale_cancelled'
  | 'refund'
  | 'transfer'
  | 'adjustment_in'
  | 'adjustment_out'
  | 'faulty'
  | 'returned'
  | 'write_off'

export type StockTransferRequestStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'
export interface StockTransferRequest {
  id: number
  request_number: string
  status: StockTransferRequestStatus
  from_branch_id: number
  to_branch_id: number
  requested_by_id: number
  decided_by_id: number | null
  reason: string
  notes: string | null
  decision_reason: string | null
  decided_at: string | null
  item_count?: number
  from_branch?: Branch
  to_branch?: Branch
  requested_by?: User
  decided_by?: User
  items?: { id: number; device_id: number; device?: Device }[]
  created_at: string
  updated_at: string
}

export interface InventoryMovement {
  id: number
  movement_number: string
  movement_type: InventoryMovementType
  device_id: number | null
  inventory_batch_id: number | null
  inventory_receipt_id: number | null
  sale_id: number | null
  sale_item_id: number | null
  category_id: number | null
  branch_id: number | null
  from_branch_id: number | null
  to_branch_id: number | null
  brand: string | null
  model: string | null
  color: string | null
  quantity: number
  previous_status: InventoryStatusValue | string | null
  new_status: InventoryStatusValue | string | null
  previous_owner_id: number | null
  new_owner_id: number | null
  reference_type: string | null
  reference_id: number | null
  reason: string | null
  notes: string | null
  created_by_id: number | null
  is_backfilled: boolean
  metadata?: Record<string, unknown> | null
  device?: Device
  batch?: InventoryBatch
  receipt?: InventoryReceipt
  sale?: Sale
  category?: DeviceCategory
  branch?: Branch
  from_branch?: Branch
  to_branch?: Branch
  created_by?: User
  created_at: string
  updated_at: string
}

export interface InventoryMovementSummary {
  total_movements: number
  total_quantity: number
  by_type: Partial<Record<InventoryMovementType, number>>
  by_branch: Record<string, number>
  by_product: Array<{ product: string; quantity: number }>
}

// Sale types
export type SaleStatusValue = 'draft' | 'completed' | 'cancelled' | 'refunded'
export type SalePaymentStatusValue = 'pending' | 'completed' | 'partial' | 'failed' | 'refunded' | 'cancelled'
export type SalePaymentMethodValue = 'cash' | 'mobile_money' | 'card' | 'bank_transfer' | 'credit'

export interface StatusObject<T = string> {
  value: T
  label: string
  color?: string
}

export interface Sale {
  id: number
  sale_number: string
  customer_id: number
  branch_id: number | null
  sold_by_id: number
  subtotal: number | string
  tax_amount: number | string
  discount_amount: number | string
  total_amount: number | string
  payment_method: StatusObject<SalePaymentMethodValue>
  payment_status: StatusObject<SalePaymentStatusValue>
  payment_reference: string | null
  payment_amount?: number | string | null
  change_due?: number | string | null
  status: StatusObject<SaleStatusValue>
  notes: string | null
  sale_date: string
  is_completed: boolean
  is_paid: boolean
  items_count?: number
  vat_applicable?: boolean
  refund_reason?: string | null
  customer?: User
  sold_by?: User
  branch?: Branch
  items?: SaleItem[]
  created_at: string
  updated_at: string
}

export interface SaleItem {
  id: number
  sale_id: number
  device_id: number
  subscription_plan_id: number | null
  item_description: string
  unit_price: number | string
  discount: number | string
  tax: number | string
  total_price: number | string
  cost_price?: number | string | null
  selling_price?: number | string | null
  profit_amount?: number | string | null
  device?: Device
  subscription_plan?: SubscriptionPlan
  created_at: string
  updated_at: string
}

// Family types
export type FamilyRoleValue = 'admin' | 'guardian' | 'member' | 'child'
export type FamilyMemberStatusValue = 'pending' | 'active' | 'removed'

export interface FamilyRole {
  value: FamilyRoleValue
  label: string
  color: string
}

export interface FamilyMemberStatus {
  value: FamilyMemberStatusValue
  label: string
}

export interface Family {
  id: number
  name: string
  code?: string // Only visible to guardians+
  avatar: string | null
  settings?: Record<string, unknown> // Only visible to admins
  owner: User
  is_owner: boolean
  my_membership?: {
    role: FamilyRole
    nickname: string | null
    joined_at: string | null
    status: FamilyMemberStatus
    location_sharing_enabled: boolean
    receive_family_alerts: boolean
    can_manage_members: boolean
    can_manage_settings: boolean
  }
  members?: FamilyMember[]
  members_count?: number
  created_at: string
  updated_at: string
}

export interface FamilyMember {
  id: number
  user_id: number
  user?: User
  role: FamilyRole
  nickname: string | null
  display_name: string
  status: FamilyMemberStatus
  is_active: boolean
  is_pending: boolean
  can_manage_members: boolean
  can_manage_settings: boolean
  must_share_location: boolean
  location_sharing_enabled: boolean
  receive_family_alerts: boolean
  inviter?: User
  joined_at: string | null
  created_at: string
  updated_at: string
}

export interface FamilyInvitation {
  id: number
  family: {
    id: number
    name: string
    avatar: string | null
    owner: {
      id: number
      name: string
    }
  }
  role: FamilyRole
  invited_by: {
    id: number
    name: string
  } | null
  created_at: string
}

// Auth types
export interface LoginCredentials {
  email: string
  password: string
  remember?: boolean
}

export interface RegisterData {
  name: string
  email: string
  password: string
  password_confirmation: string
  phone_number?: string
}

export interface AuthResponse {
  user: User
  token: string
  token_type: string
  expires_at: string
}

// Community Recovery Network types
export type CommunityAlertStatusValue = 'active' | 'paused' | 'resolved' | 'expired'
export type BountyStatusValue = 'none' | 'offered' | 'claimed' | 'paid'
export type TipStatusValue = 'pending' | 'reviewing' | 'verified' | 'false_lead'
export type ClaimStatusValue = 'pending' | 'approved' | 'rejected' | 'paid'

export interface CommunityAlertStatus {
  value: CommunityAlertStatusValue
  label: string
  color: string
}

export interface BountyStatus {
  value: BountyStatusValue
  label: string
  color: string
}

export interface TipStatus {
  value: TipStatusValue
  label: string
  color: string
}

export interface ClaimStatus {
  value: ClaimStatusValue
  label: string
  color: string
}

export interface CommunityAlert {
  id: number
  status: CommunityAlertStatus
  broadcast_latitude: number
  broadcast_longitude: number
  alert_radius_km: number
  opt_in_notifications: boolean
  bounty_amount: number | null
  bounty_currency: string
  bounty_status: BountyStatus | null
  has_bounty: boolean
  view_count: number
  share_count: number
  tips_count: number
  is_active: boolean
  is_expired: boolean
  can_receive_tips: boolean
  expires_at: string | null
  resolved_at: string | null
  distance_km?: number
  theft_report?: TheftReport
  creator?: User
  tips?: CommunityTip[]
  claims?: BountyClaim[]
  success_story?: SuccessStory
  created_at: string
  updated_at: string
}

export interface CommunityTip {
  id: number
  tip_type: string
  description: string
  spotted_latitude: number | null
  spotted_longitude: number | null
  spotted_address: string | null
  spotted_at: string | null
  has_location: boolean
  is_anonymous: boolean
  contact_preference?: string
  contact_info?: string
  photos: string[] | null
  status: TipStatus
  is_helpful: boolean
  is_pending: boolean
  is_verified: boolean
  admin_notes?: string
  tipster_display_name: string
  alert?: CommunityAlert
  tipster?: User
  reviewer?: User
  reviewed_at: string | null
  created_at: string
  updated_at: string
}

export interface BountyClaim {
  id: number
  claim_type: string
  description: string
  evidence_photos: string[]
  recovery_latitude: number
  recovery_longitude: number
  recovery_address: string | null
  recovery_date: string
  status: ClaimStatus
  is_pending: boolean
  is_approved: boolean
  is_paid: boolean
  claim_amount: number
  approved_amount: number | null
  payment_method: string
  payment_account?: string
  payment_reference: string | null
  paid_at: string | null
  rejection_reason: string | null
  reviewed_at: string | null
  owner_rating: number | null
  owner_feedback: string | null
  alert?: CommunityAlert
  claimant?: User
  reviewer?: User
  created_at: string
  updated_at: string
}

export interface CommunityHero {
  id: number
  display_name: string
  successful_recoveries: number
  tips_submitted: number
  tips_verified: number
  claims_approved: number
  total_bounties_earned: number
  reputation_score: number
  monthly_recoveries: number
  monthly_tips: number
  badges: string[]
  rank: number | null
  is_featured: boolean
  is_public: boolean
  user?: User
  stats_reset_at: string | null
  created_at: string
  updated_at: string
}

export interface SuccessStory {
  id: number
  title: string
  story: string
  photos: string[] | null
  device_brand: string | null
  device_model: string | null
  device_type: string | null
  device_description: string
  days_to_recover: number | null
  bounty_paid: number | null
  recovery_city: string | null
  is_published: boolean
  is_featured: boolean
  published_at: string | null
  is_pending: boolean
  is_rejected: boolean
  view_count: number
  likes_count: number
  shares_count: number
  is_liked?: boolean
  rejection_reason?: string
  approved_at: string | null
  alert?: CommunityAlert
  author?: User
  approver?: User
  created_at: string
  updated_at: string
}

export interface CommunityStats {
  total_stories: number
  total_recoveries: number
  recovery_rate: number
  avg_days_to_recover: number | null
  total_bounties_paid: number
  active_alerts: number
  total_tips: number
  verified_tips: number
  total_heroes: number
}

export interface AlertSubscription {
  id: number
  is_active: boolean
  home_location?: {
    latitude: number
    longitude: number
    radius_km: number
    label: string
  }
  work_location?: {
    latitude: number
    longitude: number
    radius_km: number
    label: string
  }
  custom_location?: {
    latitude: number
    longitude: number
    radius_km: number
    label: string
  }
  notify_via: string[]
  notify_with_bounty_only: boolean
  min_bounty_amount: number | null
  quiet_hours?: {
    start: string
    end: string
  }
  alerts_received: number
  last_alert_at: string | null
  configured_locations: Record<string, {
    latitude: number
    longitude: number
    radius_km: number
    label: string
  }>
  is_in_quiet_hours: boolean
  user?: User
  created_at: string
  updated_at: string
}

// Shift types
export type ShiftStatus = 'active' | 'completed' | 'cancelled'

export interface Shift {
  id: number
  user_id: number
  branch_id: number
  branch?: Branch
  started_at: string
  ended_at: string | null
  status: ShiftStatus
  notes: string | null
  opening_cash: number
  closing_cash: number | null
  duration_minutes: number | null
  revenue: number
  sales_count: number
  cash_sales: number
  created_at: string
  updated_at: string
  user?: User
  sales?: Sale[]
}
