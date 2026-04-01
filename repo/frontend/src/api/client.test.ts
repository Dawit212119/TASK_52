import { describe, expect, it } from 'vitest'
import { API_BASE_PATH } from './http'

describe('API conventions', () => {
  it('uses the versioned API base path', () => {
    expect(API_BASE_PATH.startsWith('/api/v1')).toBe(true)
  })
})
