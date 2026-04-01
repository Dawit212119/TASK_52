import { apiRequest } from './http'
import type { LoginPayload, LoginResponse, MeResponse } from '../types/auth'

export function login(payload: LoginPayload) {
  return apiRequest<LoginResponse, LoginPayload>('/auth/login', {
    method: 'POST',
    body: payload,
    // Same-origin deploys send the session cookie with default credentials; backend then requires CSRF. Token login does not need cookies on this request.
    credentials: 'omit',
  })
}

export function logout() {
  return apiRequest<void>('/auth/logout', { method: 'POST' })
}

export function fetchMe() {
  return apiRequest<MeResponse>('/auth/me')
}

export function changePassword(payload: {
  current_password: string
  new_password: string
  new_password_confirmation: string
}) {
  return apiRequest<{ message: string }, typeof payload>('/auth/password/change', {
    method: 'POST',
    body: payload,
  })
}
