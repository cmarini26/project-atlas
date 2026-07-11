import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import PageHeader from './PageHeader.vue'

const FakeIcon = { render: () => h('svg', { 'data-testid': 'fake-icon' }) }

describe('PageHeader', () => {
  it('renders the title', () => {
    const wrapper = mount(PageHeader, { props: { title: 'Campaigns' } })

    expect(wrapper.find('h1').text()).toBe('Campaigns')
  })

  it('renders the description only when provided', () => {
    const withDescription = mount(PageHeader, {
      props: { title: 'Campaigns', description: 'Track every campaign from draft to completion in one place.' },
    })
    expect(withDescription.text()).toContain('Track every campaign from draft to completion in one place.')

    const withoutDescription = mount(PageHeader, { props: { title: 'Campaigns' } })
    expect(withoutDescription.find('p').exists()).toBe(false)
  })

  it('renders the icon only when provided', () => {
    const withIcon = mount(PageHeader, { props: { title: 'Campaigns', icon: FakeIcon } })
    expect(withIcon.find('[data-testid="fake-icon"]').exists()).toBe(true)

    const withoutIcon = mount(PageHeader, { props: { title: 'Campaigns' } })
    expect(withoutIcon.find('[data-testid="fake-icon"]').exists()).toBe(false)
  })

  it('renders the actions slot when provided', () => {
    const wrapper = mount(PageHeader, {
      props: { title: 'Campaigns' },
      slots: { actions: '<button>New Campaign</button>' },
    })

    expect(wrapper.text()).toContain('New Campaign')
  })
})
