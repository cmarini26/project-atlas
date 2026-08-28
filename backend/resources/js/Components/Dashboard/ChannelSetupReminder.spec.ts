import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ChannelSetupReminder from './ChannelSetupReminder.vue'

const postMock = vi.fn((_url: string, _data?: unknown, options?: { onFinish?: () => void }) => {
  options?.onFinish?.()
})

vi.mock('@inertiajs/vue3', () => ({
  Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
  router: {
    post: (url: string, data?: unknown, options?: { onFinish?: () => void }) => postMock(url, data, options),
  },
}))

const channels = [
  { type: 'instagram', label: 'Instagram', requirement: 'oauth' as const, summary: 'Connect your Instagram account in Marketing Presence.' },
  { type: 'x', label: 'X', requirement: 'handle' as const, summary: 'Add your X handle in Marketing Presence.' },
]

describe('ChannelSetupReminder', () => {
  afterEach(() => postMock.mockClear())

  it('lists each pending channel with its plain-language summary and a link to Marketing Presence', () => {
    const wrapper = mount(ChannelSetupReminder, { props: { channels } })

    expect(wrapper.text()).toContain('Finish connecting your channels')
    expect(wrapper.text()).toContain('Instagram')
    expect(wrapper.text()).toContain('Connect your Instagram account in Marketing Presence.')
    expect(wrapper.text()).toContain('Add your X handle in Marketing Presence.')

    const links = wrapper.findAll('a')
    expect(links.length).toBe(channels.length)
    links.forEach((l) => expect(l.attributes('href')).toBe('/app/settings/marketing-presence'))
  })

  it('posts to the dismiss route when the close button is clicked', async () => {
    const wrapper = mount(ChannelSetupReminder, { props: { channels } })

    await wrapper.find('button[aria-label="Dismiss channel setup reminder"]').trigger('click')

    expect(postMock).toHaveBeenCalledWith('/channel-setup/dismiss', {}, expect.anything())
  })
})
