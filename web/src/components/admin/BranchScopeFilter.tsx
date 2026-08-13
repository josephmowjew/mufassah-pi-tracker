import { Building2, ChevronDown } from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'

export interface BranchScopeOption {
  id: number
  name: string
  code?: string | null
  is_active?: boolean
}

interface BranchScopeFilterProps {
  value: string
  branches?: BranchScopeOption[]
  onChange: (value: string) => void
  isLoading?: boolean
  className?: string
}

/** A consistent, super-admin-only branch scope control for admin list pages. */
export default function BranchScopeFilter({
  value,
  branches = [],
  onChange,
  isLoading = false,
  className = '',
}: BranchScopeFilterProps) {
  const isSuperAdmin = useAuthStore((state) => state.isSuperAdmin())

  if (!isSuperAdmin) return null

  return (
    <div className={`relative min-w-[220px] ${className}`}>
      <label className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
        <Building2 className="h-3.5 w-3.5 text-blue-500" aria-hidden="true" />
        Branch scope
      </label>
      <div className="relative">
        <select
          aria-label="Filter by branch"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          disabled={isLoading}
          className="w-full appearance-none rounded-lg border border-slate-200 bg-slate-50 py-2.5 pl-3 pr-9 text-sm font-medium text-slate-700 transition-colors focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 disabled:cursor-wait disabled:opacity-60"
        >
          <option value="">All branches</option>
          {branches.map((branch) => (
            <option key={branch.id} value={branch.id.toString()}>
              {branch.name}{branch.code ? ` (${branch.code})` : ''}{branch.is_active === false ? ' — Inactive' : ''}
            </option>
          ))}
        </select>
        <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
      </div>
    </div>
  )
}
