import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CampaignTrail from './CampaignTrail.vue'

describe('CampaignTrail', () => {
  it('renders all 5 lifecycle steps', () => {
    const wrapper = mount(CampaignTrail, { props: { status: 'draft' } })

    expect(wrapper.text()).toContain('Draft')
    expect(wrapper.text()).toContain('Approved')
    expect(wrapper.text()).toContain('Active')
    expect(wrapper.text()).toContain('Published')
    expect(wrapper.text()).toContain('Completed')
  })

  it('marks the current step with aria-current="step"', () => {
    const wrapper = mount(CampaignTrail, { props: { status: 'active' } })

    expect(wrapper.find('[aria-current="step"]').exists()).toBe(true)
  })

  it('does not render anything for a cancelled campaign', () => {
    const wrapper = mount(CampaignTrail, { props: { status: 'cancelled' } })

    expect(wrapper.find('ol').exists()).toBe(false)
  })
})
