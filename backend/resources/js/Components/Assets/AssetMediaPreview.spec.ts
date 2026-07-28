import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AssetMediaPreview from './AssetMediaPreview.vue'

describe('AssetMediaPreview', () => {
  it('renders image uploads as images', () => {
    const wrapper = mount(AssetMediaPreview, {
      props: { url: '/storage/hero.jpg', title: 'Campaign hero', mimeType: 'image/jpeg' },
    })

    expect(wrapper.find('img').attributes('alt')).toBe('Campaign hero')
    expect(wrapper.find('video').exists()).toBe(false)
  })

  it('renders video uploads with controls', () => {
    const wrapper = mount(AssetMediaPreview, {
      props: { url: '/storage/launch.mp4', title: 'Launch', mimeType: 'video/mp4' },
    })

    expect(wrapper.find('video').attributes('controls')).toBeDefined()
    expect(wrapper.find('video').attributes('aria-label')).toBe('Launch video')
  })

  it('renders documents as accessible links', () => {
    const wrapper = mount(AssetMediaPreview, {
      props: { url: '/storage/story.pdf', title: 'Customer story', mimeType: 'application/pdf' },
    })

    expect(wrapper.find('a').attributes('href')).toBe('/storage/story.pdf')
    expect(wrapper.text()).toContain('Open PDF')
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('falls back safely for legacy records without MIME metadata', () => {
    const video = mount(AssetMediaPreview, {
      props: { url: '/storage/legacy.mov?download=1', title: 'Legacy video' },
    })
    const unknown = mount(AssetMediaPreview, {
      props: { url: '/storage/legacy.bin', title: 'Legacy file' },
    })

    expect(video.find('video').exists()).toBe(true)
    expect(unknown.find('a').exists()).toBe(true)
  })
})
