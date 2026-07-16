# Customer 1 Launch — Ownership & Access

**Purpose:** who owns what, before Customer 1. This is an ownership/access roster, not a procedure — for the actual provisioning/verification steps, see [Customer-1-Launch-Runbook.md](Customer-1-Launch-Runbook.md); for the Go/No-Go criteria, see [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §4. Fill in every placeholder below before launch day — a row with no named owner is not a completed row.

## ⚠️ Never paste real secrets into this document

This file (and every doc under `docs/`) is checked into git. **Never write an actual API key, password, token, or DSN here** — not even "temporarily." Record only:
- *where* the credential lives (a password manager entry name, a secrets-manager path, an env var name in the real production `.env`)
- *who* can retrieve it

If a real secret is ever accidentally committed anywhere in this repo, treat it as compromised: rotate it immediately at the provider, then remove it from git history — don't just delete the line in a new commit.

---

## External accounts & services

| Service | Purpose | Named Owner | Backup Owner | Access Location | Billing Owner | Verification Status |
|---|---|---|---|---|---|---|
| Domain registrar | Owns the production domain | _________ | _________ | _________ (e.g. password manager entry name) | _________ | ⬜ Not verified |
| DNS provider | A/CNAME records, resolves domain to server | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Hosting provider (server) | Runs app server, DB, Redis | _________ | _________ | _________ | _________ | ⬜ Not verified |
| SSL/TLS certificate | HTTPS for the domain | _________ | _________ | _________ (e.g. Certbot on server / platform dashboard) | _________ | ⬜ Not verified |
| PostgreSQL (production database) | Primary datastore | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Redis (production) | Cache/session/queue backing | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Postmark | Transactional email (password reset, campaign sends) | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Error-tracking vendor (Sentry or equivalent) | Production exception visibility | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Uptime monitor | External health-check polling | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Anthropic (AI provider) | Powers observation/recommendation pipeline — already in use pre-launch, included here since production traffic changes its spend profile | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Meta developer app (if used) | Facebook/Instagram OAuth publishing | _________ | _________ | _________ | _________ | ⬜ Not verified — only applicable if Customer 1 connects Meta |
| Backup storage (off-site) | Off-site copy of database backups | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Secrets manager / production `.env` | Where every real credential above actually lives | _________ | _________ | _________ | _________ | ⬜ Not verified |
| GitHub (this repository) | Source of truth, deploy source | _________ | _________ | _________ | _________ | ⬜ Not verified |
| Support channel (email alias or Slack) | Customer issue intake | _________ | _________ | _________ | _________ | ⬜ Not verified |

**"Verification Status" values:** ⬜ Not verified · 🟡 Access confirmed, not yet used for a real action · ✅ Verified (owner has actually logged in / performed a real action against it, per the corresponding step in [Customer-1-Launch-Runbook.md](Customer-1-Launch-Runbook.md))

---

## Launch-day contact list

Fill in before the invite goes out. Every role below must be a real name and a real, checked-daily contact method — not "the team."

| Role | Name | Contact method | Availability during launch week |
|---|---|---|---|
| Engineering / on-call lead | _________ | _________ | _________ |
| Backup engineering contact | _________ | _________ | _________ |
| Support channel owner (24h SLA) | _________ | _________ | _________ |
| Error-tracking alert recipient | _________ | _________ | _________ |
| Uptime-monitor alert recipient | _________ | _________ | _________ |
| Database/backup owner | _________ | _________ | _________ |
| Business/customer contact for CBB Auctions | _________ | _________ | _________ |
| Legal/compliance contact (privacy policy, terms) | _________ | _________ | _________ |

---

## Final sign-off

Distinct from the Runbook's technical Go/No-Go gate — this confirms every account above has a real, accountable human attached before the invite is sent.

| Confirmation | Signed off by | Date |
|---|---|---|
| Every row above has a named Owner and Backup Owner (no blanks) | _________ | _________ |
| Every credential's Access Location has been personally confirmed reachable by both the Owner and Backup Owner | _________ | _________ |
| No real secret value appears anywhere in this document or its edit history | _________ | _________ |
| Billing is confirmed active (no trial expiring mid-beta) for every paid service above | _________ | _________ |
| The Launch-day contact list has been shared with everyone on it, and each has confirmed receipt | _________ | _________ |
| [Customer-1-Launch-Runbook.md](Customer-1-Launch-Runbook.md)'s Final Go/No-Go Gate is fully checked | _________ | _________ |

**If any row above is unsigned, do not send the Customer 1 invite.**
