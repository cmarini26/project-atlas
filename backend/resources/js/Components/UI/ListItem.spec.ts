import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ListItem from './ListItem.vue'

describe('ListItem', () => {
  it('renders the default slot', () => {
    const wrapper = mount(ListItem, { slots: { default: '<p>business.name</p>' } })

    expect(wrapper.text()).toContain('business.name')
  })

  it('renders the trailing slot only when provided', () => {
    const withTrailing = mount(ListItem, { slots: { trailing: '<span>92%</span>' } })
    expect(withTrailing.text()).toContain('92%')

    const withoutTrailing = mount(ListItem)
    expect(withoutTrailing.text()).not.toContain('92%')
  })

  it('renders as a div by default and li when requested', () => {
    const asDiv = mount(ListItem)
    expect(asDiv.element.tagName).toBe('DIV')

    const asLi = mount(ListItem, { props: { as: 'li' } })
    expect(asLi.element.tagName).toBe('LI')
  })
})
