export type CheckoutFormData = {
  asset_id: string
  expected_return_at: string
  deposit_cents: string
}

export type ValidatableAsset = {
  id: number
  status: string
}

export function validateCheckoutForm(form: CheckoutFormData, rows: ValidatableAsset[]): string[] {
  const errors: string[] = []
  if (!form.asset_id) errors.push('Asset ID is required for checkout.')
  if (!form.expected_return_at) errors.push('Expected return date/time is required.')
  if (!form.deposit_cents) errors.push('Deposit in cents is required.')

  const asset = rows.find((r) => r.id === Number(form.asset_id))
  if (asset && asset.status !== 'available') {
    errors.push(
      `Asset is currently "${asset.status}" and cannot be checked out. Only available assets may be rented.`,
    )
  }

  return errors
}
