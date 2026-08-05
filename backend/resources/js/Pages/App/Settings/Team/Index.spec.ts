import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Index from './Index.vue'

const postMock = vi.fn()
const patchMock = vi.fn()
const deleteMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>', props: ['href'] },
  router: {
    patch: (...args: unknown[]) => patchMock(...args),
    delete: (...args: unknown[]) => deleteMock(...args),
  },
  useForm: (initial: Record<string, unknown>) => reactive({
    ...initial,
    processing: false,
    errors: {},
    post: (...args: unknown[]) => postMock(...args),
    reset: vi.fn(),
  }),
}))

const baseProps = {
  members: [
    { id: 'owner-1', name: 'Owner', email: 'owner@example.com', role: 'owner', joined_at: null, can_manage: false },
    { id: 'member-1', name: 'Member', email: 'member@example.com', role: 'member', joined_at: null, can_manage: true },
  ],
  pending_invitations: [
    { id: 'invite-1', email: 'pending@example.com', role: 'viewer', expires_at: '2026-08-12T00:00:00Z', can_manage: true },
  ],
  actor_role: 'owner',
  allowed_invitation_roles: ['admin', 'member', 'viewer'],
}

describe('Settings/Team/Index', () => {
  afterEach(() => {
    postMock.mockClear()
    patchMock.mockClear()
    deleteMock.mockClear()
  })

  it('lists active members and pending invitations', () => {
    const wrapper = mount(Index, { props: baseProps })

    expect(wrapper.text()).toContain('Members (2)')
    expect(wrapper.text()).toContain('owner@example.com')
    expect(wrapper.text()).toContain('pending@example.com')
  })

  it('submits a team invitation', async () => {
    const wrapper = mount(Index, { props: baseProps })

    await wrapper.find('#team-email').setValue('new@example.com')
    await wrapper.find('#team-role').setValue('viewer')
    await wrapper.find('form').trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/app/settings/team/invitations', expect.anything())
  })

  it('updates a manageable member role', async () => {
    const wrapper = mount(Index, { props: baseProps })
    const roleSelect = wrapper.findAll('select').find((select) => select.element.id !== 'team-role')

    await roleSelect?.setValue('viewer')

    expect(patchMock).toHaveBeenCalledWith(
      '/app/settings/team/members/member-1',
      { role: 'viewer' },
      expect.anything(),
    )
  })

  it('revokes a manageable pending invitation', async () => {
    const wrapper = mount(Index, { props: baseProps })
    const revoke = wrapper.findAll('button').find((button) => button.text() === 'Revoke')

    await revoke?.trigger('click')

    expect(deleteMock).toHaveBeenCalledWith('/app/settings/team/invitations/invite-1', expect.anything())
  })
})
