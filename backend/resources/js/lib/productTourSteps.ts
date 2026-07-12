/**
 * The first-time-user product tour, anchored to Dashboard.vue's four
 * data-tour="..." sections. Static config rather than data-attribute
 * discovery — all four anchors live on a single page, so there's no
 * cross-page step-discovery problem to solve.
 */

export interface ProductTourStep {
  target: string
  title: string
  body: string
}

export const PRODUCT_TOUR_STEPS: ProductTourStep[] = [
  {
    target: '[data-tour="recommendation-prompt"]',
    title: 'Your next recommendation',
    body: 'This is where Atlas surfaces its next suggestion for your business — review and approve it here.',
  },
  {
    target: '[data-tour="summary-cards"]',
    title: 'At a glance',
    body: 'A quick count of what\'s pending, open, active, or waiting on you across the app.',
  },
  {
    target: '[data-tour="health-card"]',
    title: 'Your brand twin',
    body: 'Your brand twin\'s health score and your most recent campaigns, at a glance.',
  },
  {
    target: '[data-tour="recent-executions"]',
    title: 'Publishing activity',
    body: 'A running log of everything that\'s actually gone out.',
  },
]
