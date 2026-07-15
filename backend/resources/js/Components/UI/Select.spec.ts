import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Select from './Select.vue'

describe('Select', () => {
  it('renders a placeholder option when provided', () => {
    const wrapper = mount(Select, {
      props: { modelValue: '', placeholder: 'Select a platform' },
      slots: { default: '<option value="wordpress">WordPress</option>' },
    })

    const options = wrapper.findAll('option')
    expect(options[0].text()).toBe('Select a platform')
    expect(options[0].attributes('disabled')).toBeDefined()
  })

  it('omits the placeholder option when not provided', () => {
    const wrapper = mount(Select, {
      props: { modelValue: 'wordpress' },
      slots: { default: '<option value="wordpress">WordPress</option>' },
    })

    expect(wrapper.findAll('option')).toHaveLength(1)
  })

  it('emits update:modelValue on change', async () => {
    const wrapper = mount(Select, {
      props: { modelValue: '' },
      slots: { default: '<option value="a">A</option><option value="b">B</option>' },
    })

    await wrapper.find('select').setValue('b')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['b'])
  })
})
