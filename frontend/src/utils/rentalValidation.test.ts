import { describe, expect, it } from 'vitest'
import { validateCheckoutForm } from './rentalValidation'
import type { CheckoutFormData, ValidatableAsset } from './rentalValidation'

const availableAsset: ValidatableAsset = { id: 10, status: 'available' }
const rentedAsset: ValidatableAsset = { id: 20, status: 'rented' }

describe('validateCheckoutForm', () => {
  it('validates successfully when all fields are present and asset is available', () => {
    const form: CheckoutFormData = {
      asset_id: '10',
      expected_return_at: '2026-04-01T12:00',
      deposit_cents: '5000',
    }
    const errors = validateCheckoutForm(form, [availableAsset])
    expect(errors).toHaveLength(0)
  })

  it('returns an error when expected_return_at is missing', () => {
    const form: CheckoutFormData = {
      asset_id: '10',
      expected_return_at: '',
      deposit_cents: '5000',
    }
    const errors = validateCheckoutForm(form, [availableAsset])
    expect(errors.some((e) => e.includes('Expected return date/time is required'))).toBe(true)
  })

  it('returns a double-booking error when asset status is rented', () => {
    const form: CheckoutFormData = {
      asset_id: '20',
      expected_return_at: '2026-04-01T12:00',
      deposit_cents: '5000',
    }
    const errors = validateCheckoutForm(form, [rentedAsset])
    expect(errors.some((e) => e.includes('"rented"') && e.includes('cannot be checked out'))).toBe(true)
  })
})
