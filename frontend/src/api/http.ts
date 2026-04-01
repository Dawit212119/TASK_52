export const API_BASE_PATH = import.meta.env.VITE_API_BASE_PATH ?? '/api/v1'

export type ApiErrorDetail = {
  field: string
  rule: string
  message: string
}

export type ApiErrorEnvelope = {
  error: {
    code: string
    message: string
    details: ApiErrorDetail[]
  }
  request_id: string
}

export class ApiClientError extends Error {
  status: number
  code: string
  details: ApiErrorDetail[]
  requestId: string

  constructor(status: number, envelope: ApiErrorEnvelope) {
    super(envelope.error.message)
    this.name = 'ApiClientError'
    this.status = status
    this.code = envelope.error.code
    this.details = envelope.error.details
    this.requestId = envelope.request_id
  }
}

type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

type RequestOptions<TBody> = {
  method?: HttpMethod
  body?: TBody
  csrfToken?: string
  signal?: AbortSignal
  authMode?: 'token' | 'cookie'
  /** Override fetch credentials; use `omit` for login so no session cookie is sent (avoids CSRF without X-CSRF-TOKEN). */
  credentials?: RequestCredentials
  /** When true, skip Content-Type header and JSON.stringify — lets the browser set multipart/form-data with boundary. */
  multipart?: boolean
}

type ApiListener = (error: ApiClientError) => void

let authToken = ''
const listeners = new Set<ApiListener>()

export function setAuthToken(token: string): void {
  authToken = token
}

export function subscribeApiErrors(listener: ApiListener): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function notifyApiError(error: ApiClientError): void {
  listeners.forEach((listener) => listener(error))
}

export async function apiRequest<TResponse, TBody = unknown>(
  path: string,
  options: RequestOptions<TBody> = {},
): Promise<TResponse> {
  const method = options.method ?? 'GET'
  const requestId = crypto.randomUUID()

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Request-Id': requestId,
    'X-Workstation-Id': (import.meta.env.VITE_WORKSTATION_ID as string) || 'ws-unknown',
  }

  if (options.authMode === 'cookie') {
    headers['X-Auth-Mode'] = 'cookie'
  }

  if (authToken !== '') {
    headers.Authorization = `Bearer ${authToken}`
  }

  if (options.csrfToken) {
    headers['X-CSRF-TOKEN'] = options.csrfToken
  }

  const init: RequestInit = {
    method,
    headers,
    credentials:
      options.credentials ??
      (options.authMode === 'cookie' ? 'include' : 'same-origin'),
    signal: options.signal,
  }

  if (options.body !== undefined) {
    if (options.multipart) {
      init.body = options.body as unknown as BodyInit
    } else {
      headers['Content-Type'] = 'application/json'
      init.body = JSON.stringify(options.body)
    }
  }

  const response = await fetch(`${API_BASE_PATH}${path}`, init)

  if (response.status === 204) {
    return undefined as TResponse
  }

  const payload = (await response.json()) as TResponse | ApiErrorEnvelope

  if (!response.ok) {
    const envelope = payload as ApiErrorEnvelope
    const error = new ApiClientError(response.status, envelope)
    notifyApiError(error)
    throw error
  }

  return payload as TResponse
}

export function toActionableMessage(error: unknown): string {
  if (!(error instanceof ApiClientError)) {
    return 'Unexpected client error. Please retry the action.'
  }

  if (error.code === 'SESSION_EXPIRED') {
    return 'Your session expired due to inactivity. Please sign in again.'
  }

  if (error.code === 'CAPTCHA_REQUIRED') {
    return 'Please complete CAPTCHA verification before signing in.'
  }

  if (error.code === 'FORBIDDEN') {
    return 'You do not have permission to perform this action.'
  }

  if (error.details.length > 0) {
    return `${error.message} (${error.details[0].message})`
  }

  return error.message
}
