import { apiRequest } from './http'

export function fetchHealth() {
  return apiRequest<{
    data: {
      service: string
      status: string
      timestamp_utc: string
      currency: { code: string; amount_format: string }
    }
    request_id: string
  }>('/health')
}

export function fetchInventoryLowStock() {
  return apiRequest<{ data: Array<Record<string, unknown>> }>('/analytics/inventory/low-stock')
}

export function fetchReviewSummary() {
  return apiRequest<{
    data: {
      average_score: number
      negative_review_rate: number
      median_response_time_minutes: number
    }
  }>('/analytics/reviews/summary')
}

export function fetchAuditLogs() {
  return apiRequest<{ data: Array<Record<string, unknown>> }>('/audit/logs')
}

export function fetchOverdueRentals() {
  return apiRequest<{ data: Array<Record<string, unknown>> }>('/analytics/rentals/overdue')
}

export type RentalAssetFilters = {
  q?: string
  status?: string
  facility_id?: number
  category?: string
  scan_code?: string
}

export function fetchRentalAssets(filters: RentalAssetFilters) {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '' && value !== null) {
      query.set(key, String(value))
    }
  })
  const suffix = query.toString() ? `?${query.toString()}` : ''
  return apiRequest<{ data: Array<Record<string, unknown>> }>(`/rentals/assets${suffix}`)
}

export function checkoutRental(payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>('/rentals/checkouts', {
    method: 'POST',
    body: payload,
  })
}

export function returnRental(checkoutId: number) {
  return apiRequest<void>(`/rentals/checkouts/${checkoutId}/return`, { method: 'POST' })
}

export function requestAssetTransfer(assetId: number, payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>(`/rentals/assets/${assetId}/transfer`, {
    method: 'POST',
    body: payload,
  })
}

export function approveAssetTransfer(transferId: number) {
  return apiRequest<{ data: Record<string, unknown> }>(`/rentals/transfers/${transferId}/approve`, {
    method: 'POST',
  })
}

export function fetchInventoryItems(params: Record<string, string | number | undefined> = {}) {
  const query = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') {
      query.set(key, String(value))
    }
  })
  const suffix = query.toString() ? `?${query.toString()}` : ''
  return apiRequest<{ data: Array<Record<string, unknown>> }>(`/inventory/items${suffix}`)
}

export function createInventoryReceipt(payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>('/inventory/receipts', {
    method: 'POST',
    body: payload,
  })
}

export function createInventoryIssue(payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>('/inventory/issues', {
    method: 'POST',
    body: payload,
  })
}

export function createInventoryTransfer(payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>('/inventory/transfers', {
    method: 'POST',
    body: payload,
  })
}

export function createStocktake(payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>('/inventory/stocktakes', {
    method: 'POST',
    body: payload,
  })
}

export function addStocktakeLines(stocktakeId: number, payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>(`/inventory/stocktakes/${stocktakeId}/lines`, {
    method: 'POST',
    body: payload,
  })
}

export function approveStocktakeVariance(stocktakeId: number, reason: string) {
  return apiRequest<{ data: Record<string, unknown> }, { reason: string }>(`/inventory/stocktakes/${stocktakeId}/approve-variance`, {
    method: 'POST',
    body: { reason },
  })
}

export function setReservationStrategy(payload: { service_id: number; strategy: string }) {
  return apiRequest<{ data: Record<string, unknown> }, typeof payload>('/inventory/reservation-strategy', {
    method: 'PUT',
    body: payload,
  })
}

export function reserveServiceOrder(serviceOrderId: number, payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>(`/inventory/service-orders/${serviceOrderId}/reserve`, {
    method: 'POST',
    body: payload,
  })
}

export function closeServiceOrder(serviceOrderId: number) {
  return apiRequest<{ data: Record<string, unknown> }>(`/inventory/service-orders/${serviceOrderId}/close`, {
    method: 'POST',
  })
}

export function fetchContentItems(params: Record<string, string | number | undefined> = {}) {
  const query = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  const suffix = query.toString() ? `?${query.toString()}` : ''
  return apiRequest<{ data: Array<Record<string, unknown>> }>(`/content/items${suffix}`)
}

export function createContentItem(payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>('/content/items', {
    method: 'POST',
    body: payload,
  })
}

export function updateContentItem(contentId: number, payload: Record<string, unknown>) {
  return apiRequest<{ data: Record<string, unknown> }, Record<string, unknown>>(`/content/items/${contentId}`, {
    method: 'PATCH',
    body: payload,
  })
}

export function submitContentApproval(contentId: number) {
  return apiRequest<{ data: Record<string, unknown> }>(`/content/items/${contentId}/submit-approval`, { method: 'POST' })
}

export function approveContent(contentId: number) {
  return apiRequest<{ data: Record<string, unknown> }>(`/content/items/${contentId}/approve`, { method: 'POST' })
}

export function rejectContent(contentId: number) {
  return apiRequest<{ data: Record<string, unknown> }>(`/content/items/${contentId}/reject`, { method: 'POST' })
}

export function rollbackContent(contentId: number, version: number) {
  return apiRequest<{ data: Record<string, unknown> }, { version: number }>(`/content/items/${contentId}/rollback`, {
    method: 'POST',
    body: { version },
  })
}

export function fetchContentVersions(contentId: number) {
  return apiRequest<{ data: Array<Record<string, unknown>> }>(`/content/items/${contentId}/versions`)
}

export function fetchReviews(filters: Record<string, string | number | undefined> = {}) {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') {
      query.set(key, String(value))
    }
  })
  const suffix = query.toString() ? `?${query.toString()}` : ''
  return apiRequest<{ data: Array<Record<string, unknown>> }>(`/reviews${suffix}`)
}

export function respondReview(reviewId: number, response_text: string) {
  return apiRequest<{ data: Record<string, unknown> }, { response_text: string }>(`/reviews/${reviewId}/responses`, {
    method: 'POST',
    body: { response_text },
  })
}

export function appealReview(reviewId: number, reason_category: string) {
  return apiRequest<{ data: Record<string, unknown> }, { reason_category: string }>(`/reviews/${reviewId}/appeal`, {
    method: 'POST',
    body: { reason_category },
  })
}

export function hideReview(reviewId: number) {
  return apiRequest<{ data: Record<string, unknown> }>(`/reviews/${reviewId}/hide`, {
    method: 'POST',
  })
}

export function createReview(payload: {
  visit_order_id: number
  rating: number
  tags: string[]
  text: string
  images?: File[]
}) {
  const form = new FormData()
  form.append('visit_order_id', String(payload.visit_order_id))
  form.append('rating', String(payload.rating))
  payload.tags.forEach((t) => form.append('tags[]', t))
  form.append('text', payload.text)
  if (payload.images) {
    payload.images.slice(0, 5).forEach((f) => form.append('images[]', f))
  }
  return apiRequest<{ data: Record<string, unknown> }, FormData>('/reviews', {
    method: 'POST',
    body: form,
    multipart: true,
  })
}

export function fetchPublishedCarousel() {
  return apiRequest<{ data: Array<Record<string, unknown>> }>('/content/public?content_type=homepage_carousel')
}
