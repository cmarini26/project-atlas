import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Input from './Input.vue'

describe('Input', () => {
  it('renders the modelValue', () => {
    const wrapper = mount(Input, { props: { modelValue: 'Acme Comics' } })

    expect((wrapper.element as HTMLInputElement).value).toBe('Acme Comics')
  })

  it('emits update:modelValue on input', async () => {
    const wrapper = mount(Input, { props: { modelValue: '' } })

    await wrapper.setValue('New value')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['New value'])
  })

  it('applies invalid styling when invalid is true', () => {
    const wrapper = mount(Input, { props: { modelValue: '', invalid: true } })

    expect(wrapper.classes().some((c) => c.includes('rose'))).toBe(true)
  })

  it('defaults to type text', () => {
    const wrapper = mount(Input, { props: { modelValue: '' } })

    expect(wrapper.attributes('type')).toBe('text')
  })
})
