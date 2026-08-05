# Atlas Team Members MVP

## Outcome

Allow a Company owner or admin to invite colleagues into the existing Atlas
workspace, assign an appropriate role, and manage access without weakening
tenant isolation or the human-approval boundary.

## MVP scope

- A Team page under Settings lists active memberships and pending invitations.
- Owners and admins can invite an email address as `admin`, `member`, or
  `viewer`.
- Invitations use a single-use, hashed token and expire after seven days.
- Existing users sign in; new users register, then return to accept the invite.
- Acceptance verifies that the signed-in user's normalized email matches the
  invitation email before creating a `company_memberships` row.
- Owners can change non-owner roles and remove non-owner members.
- Admins can invite and manage `member`/`viewer` access, but cannot create,
  modify, or remove owners/admins.
- The final owner cannot be removed or demoted.
- All mutations are rate-limited, tenant-scoped, and covered by feature tests.

## Role behavior

| Role | View workspace | Create/edit drafts | Approve/publish | Manage integrations | Manage team |
| --- | --- | --- | --- | --- | --- |
| Owner | Yes | Yes | Yes | Yes | All non-owner access |
| Admin | Yes | Yes | Yes | Yes | Members/viewers only |
| Member | Yes | Yes | No | No | No |
| Viewer | Yes | No | No | No | No |

The MVP will preserve existing authorization behavior and add missing guards
only where the Team workflow requires them. A separate authorization audit will
verify that every existing write route matches this matrix before the feature
is called production-ready.

## Acceptance criteria

1. An owner can invite an unregistered email and the notification contains no
   plaintext token in storage.
2. A matching user can accept once; expired, revoked, reused, or wrong-email
   invitations fail safely.
3. Duplicate active membership and duplicate pending invitation attempts return
   actionable validation errors.
4. An owner can change or remove a non-owner membership.
5. An admin cannot invite another admin or change/remove an owner/admin.
6. A member or viewer cannot view or mutate Team settings.
7. Company A cannot view or mutate Company B memberships or invitations.
8. Invited users enter the invited company directly instead of starting a new
   company onboarding flow.

## Delivery slices

1. Invitation domain, migration, service, notification, and acceptance flow.
2. Team Settings controller/routes and owner/admin authorization.
3. Inertia/Vue Team UI with pending-invite and active-member management.
4. Cross-role authorization audit, browser rehearsal, docs, and release gate.

## Explicitly deferred

- Custom roles or per-permission toggles
- SCIM/SAML/SSO provisioning
- Teams spanning multiple Companies
- Seat billing and plan limits
- Bulk CSV invitations
- Ownership transfer (requires a separate high-risk workflow)
