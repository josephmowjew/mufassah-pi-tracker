/**
 * Add the selected branch scope to an admin read request.
 *
 * An empty branch id is sent explicitly so a super-admin's "All branches"
 * choice cannot be confused with an omitted filter or an implicit home branch.
 */
export const appendBranchScope = (params: URLSearchParams, branchId: string | number | null | undefined) => {
  if (branchId) {
    params.set('branch_id', String(branchId))
    params.delete('all_branches')
    return
  }

  params.delete('branch_id')
  params.set('all_branches', '1')
}
