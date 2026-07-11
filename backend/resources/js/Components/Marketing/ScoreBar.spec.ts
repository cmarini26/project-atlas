import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ScoreBar from './ScoreBar.vue'

describe('ScoreBar', () => {
  it('exposes the score as an accessible progressbar with a visible numeric label', () => {
    const wrapper = mount(ScoreBar, { props: { label: 'Confidence score', value: 84 } })

    const bar = wrapper.find('[role="progressbar"]')
    expect(bar.attributes('aria-label')).toBe('Confidence score')
    expect(bar.attributes('aria-valuenow')).toBe('84')
    expect(bar.attributes('aria-valuemin')).toBe('0')
    expect(bar.attributes('aria-valuemax')).toBe('100')

    // Score value is never conveyed by color alone — the number is always
    // rendered as text (docs/marketing/Landing-Page.md §22).
    expect(wrapper.text()).toContain('84')
  })

  it('does not render a fill until revealed', () => {
    const wrapper = mount(ScoreBar, { props: { label: 'Confidence score', value: 84, reveal: false } })

    const fill = wrapper.find('[role="progressbar"] > div > div')
    expect(fill.attributes('style')).toContain('width: 0%')
  })

  it('renders the final width once revealed', () => {
    const wrapper = mount(ScoreBar, { props: { label: 'Confidence score', value: 84, reveal: true } })

    const fill = wrapper.find('[role="progressbar"] > div > div')
    expect(fill.attributes('style')).toContain('width: 84%')
  })
})
