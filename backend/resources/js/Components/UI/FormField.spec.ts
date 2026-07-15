import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FormField from './FormField.vue'

describe('FormField', () => {
  it('renders the label', () => {
    const wrapper = mount(FormField, { props: { label: 'Company name' } })

    expect(wrapper.find('label').text()).toContain('Company name')
  })

  it('renders "(optional)" only when optional is true', () => {
    const optional = mount(FormField, { props: { label: 'Description', optional: true } })
    expect(optional.text()).toContain('(optional)')

    const required = mount(FormField, { props: { label: 'Description' } })
    expect(required.text()).not.toContain('(optional)')
  })

  it('renders the error message only when provided', () => {
    const withError = mount(FormField, { props: { label: 'Name', error: 'Name is required' } })
    expect(withError.text()).toContain('Name is required')

    const withoutError = mount(FormField, { props: { label: 'Name' } })
    expect(withoutError.text()).not.toContain('is required')
  })

  it('renders the default slot control', () => {
    const wrapper = mount(FormField, {
      props: { label: 'Name' },
      slots: { default: '<input data-testid="control" />' },
    })

    expect(wrapper.find('[data-testid="control"]').exists()).toBe(true)
  })
})
