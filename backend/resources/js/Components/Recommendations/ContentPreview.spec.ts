import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ContentPreview from './ContentPreview.vue'
import type { ContentAsset } from '@/types'

function makeAsset(overrides: Partial<ContentAsset> = {}): ContentAsset {
  return {
    id: 'a1',
    type: 'social_post',
    body: 'Bid now.',
    title: null,
    status: 'draft',
    media: null,
    metadata: {},
    ...overrides,
  }
}

describe('ContentPreview', () => {
  it('labels the image as AI-generated when the media entry is ai_generated', () => {
    const wrapper = mount(ContentPreview, {
      props: { asset: makeAsset({ media: [{ url: 'https://cdn.test/x.png', source: 'ai_generated' }] }) },
    })

    expect(wrapper.text()).toContain('AI-generated')
  })

  it('does not label a real sourced image', () => {
    const wrapper = mount(ContentPreview, {
      props: { asset: makeAsset({ media: [{ url: 'https://example.com/hero.jpg' }] }) },
    })

    expect(wrapper.find('img').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('AI-generated')
  })

  it('renders nothing image-related when there is no media', () => {
    const wrapper = mount(ContentPreview, { props: { asset: makeAsset() } })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('AI-generated')
  })
})
