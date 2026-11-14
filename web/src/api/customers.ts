import apiClient, { type ApiResponse } from './client'
import type { User } from '@/types'

/**
 * Where the signed-in user creates customers.
 *
 * Customer service agents have no access to the admin surface, and admins have
 * no access to the customer-service surface, so the endpoint is a property of
 * the actor rather than of the page. Keeping the choice here stops every page
 * from re-deriving it (and getting it wrong on one of them).
 */
export type CustomerCreateMode = 'customer_service' | 'admin'

export interface NewCustomerInput {
  name: string
  phone_number?: string | null
  email?: string | null
  national_id?: string | null
  password?: string | null
  /** Admin mode only: book the customer into the branch the operator is working in. */
  branch_id?: number | null
}

/** The fields a calling page needs to render the new customer as a choice. */
export type CustomerSummary = Pick<User, 'id' | 'name' | 'email' | 'phone_number'>

export const customerCreateMode = (roles: readonly string[]): CustomerCreateMode =>
  roles.includes('customer_service') ? 'customer_service' : 'admin'

export const customerCreatePath = (mode: CustomerCreateMode): string =>
  mode === 'customer_service' ? '/customer-service/customers' : '/admin/users'

/**
 * Build the list query for a customer search.
 *
 * The admin list is unfiltered by default, so without `type=customer` a cashier
 * can pick an administrator as the buyer. The customer-service list is already
 * role-scoped.
 */
export const customerSearchPath = (mode: CustomerCreateMode, term: string, perPage = 10): string => {
  const params = new URLSearchParams({ search: term.trim(), per_page: String(perPage) })
  if (mode === 'admin') params.set('type', 'customer')
  return `${customerCreatePath(mode)}?${params}`
}

const trimmed = (value?: string | null): string | null => {
  const text = value?.trim()
  return text ? text : null
}

/**
 * Shape the create request.
 *
 * Both endpoints auto-generate a password and derive the branch from the actor,
 * so callers only supply identity details. Only the admin endpoint takes a role.
 */
export const buildCreatePayload = (
  input: NewCustomerInput,
  mode: CustomerCreateMode
): Record<string, string | number | null> => {
  const payload: Record<string, string | number | null> = {
    name: input.name.trim(),
    phone_number: trimmed(input.phone_number),
    email: trimmed(input.email),
    national_id: trimmed(input.national_id),
  }

  if (input.password) {
    payload.password = input.password
    payload.password_confirmation = input.password
  }

  if (mode !== 'admin') return payload

  return {
    ...payload,
    role: 'customer',
    // The customer-service endpoint derives the branch from the actor and
    // rejects nothing extra; only the admin endpoint takes an explicit branch.
    ...(input.branch_id ? { branch_id: input.branch_id } : {}),
  }
}

export const customersApi = {
  create: async (input: NewCustomerInput, mode: CustomerCreateMode): Promise<CustomerSummary> => {
    const response = await apiClient.post<ApiResponse<CustomerSummary>>(
      customerCreatePath(mode),
      buildCreatePayload(input, mode)
    )

    return response.data.data
  },

  search: async (term: string, mode: CustomerCreateMode, perPage = 10): Promise<CustomerSummary[]> => {
    if (!term.trim()) return []

    const response = await apiClient.get<{ data: CustomerSummary[] }>(customerSearchPath(mode, term, perPage))
    return response.data.data
  },
}

export default customersApi