import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ChoiceChip from './ChoiceChip.vue'

describe('ChoiceChip', () => {
  it('renders unchecked by default', () => {
    const wrapper = mount(ChoiceChip, { props: { modelValue: false }, slots: { default: 'Increase sales' } })

    expect((wrapper.find('input').element as HTMLInputElement).checked).toBe(false)
    expect(wrapper.text()).toContain('Increase sales')
  })

  it('applies checked styling when modelValue is true', () => {
    const wrapper = mount(ChoiceChip, { props: { modelValue: true } })

    expect(wrapper.classes().some((c) => c.includes('accent'))).toBe(true)
  })

  it('emits update:modelValue when toggled', async () => {
    const wrapper = mount(ChoiceChip, { props: { modelValue: false } })

    await wrapper.find('input').setValue(true)

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
  })
})
