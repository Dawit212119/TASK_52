export type RoleCode =
  | 'system_admin'
  | 'clinic_manager'
  | 'inventory_clerk'
  | 'technician_doctor'
  | 'content_editor'
  | 'content_approver'

export type AuthUser = {
  id: number
  username: string
  display_name: string
  roles: RoleCode[]
  permissions: string[]
  facility_ids: number[]
}

export type LoginPayload = {
  username: string
  password: string
  captcha_token?: string
}

export type LoginResponse = {
  token: string
  expires_in_seconds: number
  user: Omit<AuthUser, 'permissions'>
  security: {
    captcha_required: boolean
  }
}

export type MeResponse = {
  user: AuthUser
  request_id: string
}
