import type { RoleCode } from '../types/auth'

export type AppRouteMeta = {
  label?: string
  requiresAuth?: boolean
  permissions?: string[]
  roles?: RoleCode[]
  showInMenu?: boolean
}

export type MenuItem = {
  label: string
  path: string
  permissions: string[]
  roles: RoleCode[]
}

export const menuItems: MenuItem[] = [
  {
    label: 'System Administration',
    path: '/workspace/system-admin',
    permissions: ['audit.read'],
    roles: ['system_admin'],
  },
  {
    label: 'Clinic Manager',
    path: '/workspace/clinic-manager',
    permissions: ['analytics.read'],
    roles: ['clinic_manager', 'system_admin'],
  },
  {
    label: 'Inventory Clerk',
    path: '/workspace/inventory-clerk',
    permissions: ['inventory.read'],
    roles: ['inventory_clerk', 'clinic_manager', 'system_admin'],
  },
  {
    label: 'Technician / Doctor',
    path: '/workspace/technician-doctor',
    permissions: ['rentals.checkout'],
    roles: ['technician_doctor', 'clinic_manager', 'system_admin'],
  },
  {
    label: 'Content Workspace',
    path: '/workspace/content',
    permissions: ['content.read'],
    roles: ['content_editor', 'content_approver', 'system_admin'],
  },
]

export function firstAllowedPath(roleCodes: RoleCode[]): string {
  const roles = new Set(roleCodes)
  const found = menuItems.find((item) => item.roles.some((role) => roles.has(role)))
  return found?.path ?? '/unauthorized'
}
