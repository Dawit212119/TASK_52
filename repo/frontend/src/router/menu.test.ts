import { describe, expect, it } from 'vitest'
import { firstAllowedPath } from './menu'
import { toActionableMessage, ApiClientError } from '../api/http'

describe('menu and messaging helpers', () => {
  it('resolves first allowed workspace by role', () => {
    expect(firstAllowedPath(['clinic_manager'])).toBe('/workspace/clinic-manager')
    expect(firstAllowedPath(['content_approver'])).toBe('/workspace/content')
  })

  it('maps session expiration to actionable message', () => {
    const error = new ApiClientError(401, {
      error: { code: 'SESSION_EXPIRED', message: 'Session expired', details: [] },
      request_id: 'r1',
    })

    expect(toActionableMessage(error)).toContain('Please sign in again')
  })
})
