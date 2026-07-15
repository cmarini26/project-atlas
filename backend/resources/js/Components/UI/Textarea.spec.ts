import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Textarea from './Textarea.vue'

describe('Textarea', () => {
  it('renders the modelValue', () => {
    const wrapper = mount(Textarea, { props: { modelValue: 'A few sentences.' } })

    expect((wrapper.element as HTMLTextAreaElement).value).toBe('A few sentences.')
  })

  it('defaults to 3 rows', () => {
    const wrapper = mount(Textarea, { props: { modelValue: '' } })

    expect(wrapper.attributes('rows')).toBe('3')
  })

  it('emits update:modelValue on input', async () => {
    const wrapper = mount(Textarea, { props: { modelValue: '' } })

    await wrapper.setValue('New body text')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['New body text'])
  })
})
