# Private Beta Plan

**Goal:** Safely onboard the first 10 paying customers
**Timeline:** ~4 weeks to launch readiness
**Scope:** Strictly limited to what 10 paying customers need — nothing else
**Reference:** `docs/reviews/Beta-Readiness-Audit.md`

---

## Who Are These 10 Customers?

Before planning, define who the first 10 are. This shapes every decision.

**Target profile:**
- Businesses with an active website (the crawler needs something to work with)
- Owners who are comfortable with early software ("beta" is not a surprise)
- In a vertical Atlas already understands: comic book auction houses, exotic car dealers, or similar dynamic-inventory businesses
- English-language businesses with public-facing websites
- Not enterprise — no procurement, no legal review, no SLA requirements

**CBB Auctions** is the primary design partner and should be Customer 1. Formalize this before any code ships.

The goal for each customer: they connect their website, Atlas builds their Business Brain, and they receive and approve their first recommendation — ideally within 10 minutes of setup.

---

## What the Beta Delivers (and Doesn't)

### Delivers
- Website crawl + Business Brain construction
- Opportunity detection and Decision Engine
- AI-generated recommendations with four-part rationale
- Approve / Edit & Approve / Reject workflow
- Email publishing via Postmark (one real channel)
- Learning from approvals and rejections
- Customer dashboard: all 10 pages
- Filament admin panel for support and debugging

### Does Not Deliver (and customers will be told)
- Social media publishing (Instagram, Facebook) — "coming in the next release"
- Real analytics (requires published content first) — "will activate once email campaigns run"
- Mobile app — web only
- Multi-seat team collaboration — single owner/admin per account
- Billing — beta customers are invoiced manually or receive free access

Be honest in onboarding communications. Early adopters respect transparency.

---

## Sprint Plan

### Week 1 — Production Infrastructure

**Goal:** A server exists, the application runs, a domain points to it.

| Task | Owner | Effort |
|------|-------|--------|
| Provision DigitalOcean Droplet (4 vCPU, 8GB RAM) via Laravel Forge | Eng | 4h |
| Provision DigitalOcean Managed PostgreSQL 16 cluster | Eng | 2h |
| Provision DigitalOcean Spaces bucket for object storage | Eng | 1h |
| Register domain (e.g., `getatlas.app`) | Founder | 30min |
| Configure DNS, Forge site, Nginx, SSL (Let's Encrypt auto) | Eng | 2h |
| Configure Redis (Forge managed or self-hosted on same server) | Eng | 1h |
| Deploy application — first successful deploy | Eng | 2h |
| Run migrations against production database | Eng | 1h |
| Configure Supervisor for queue workers (5 worker groups) | Eng | 2h |
| Configure cron for `php artisan schedule:run` every minute | Eng | 30min |
| Configure Postmark account + verify sending domain | Founder/Eng | 2h |
| Set `APP_DEBUG=false`, configure `.env` for production | Eng | 1h |
| Set up UptimeRobot to monitor `/health/live` every 60s | Eng | 30min |
| Trigger GitHub Actions CI pipeline at least once | Eng | 1h |
| **Milestone check:** Application accessible at `https://getatlas.app` | | |

**Risks:**
- Forge provisioning is straightforward but SSL depends on DNS propagation (1–48 hours)
- The first deploy will likely reveal environment config gaps — budget 4h debugging buffer
- CI has never run before — the first run may fail and require fixes

---

### Week 2 — Security, Tenancy, Compliance

**Goal:** Multi-tenancy is verified safe. Basic auth protections are in place. Legal framework exists.

| Task | Owner | Effort |
|------|-------|--------|
| Audit `CompanyScope` — verify it reads from per-request binding, not a singleton | Eng | 4h |
| Implement `ResolveCurrentCompany` middleware if needed; wire to all `/app/*` routes | Eng | 4h |
| Add cross-company isolation test at service layer (not just controller 404) | Eng | 3h |
| Add rate limiting to `POST /login` and `POST /register` (`throttle:5,1`) | Eng | 1h |
| Add HTTP security headers middleware (X-Frame-Options, X-Content-Type-Options, HSTS) | Eng | 2h |
| Implement email verification (Laravel built-in `MustVerifyEmail`) | Eng | 3h |
| Implement password reset flow (Laravel built-in `ResetPassword`) | Eng | 2h |
| Write privacy policy (Termly or equivalent generator + custom review) | Founder | 1 day |
| Write terms of service (Termly or equivalent) | Founder | 1 day |
| Publish privacy policy and ToS at `getatlas.app/privacy` and `/terms` | Eng | 1h |
| Add checkbox to registration form: "I agree to the Terms of Service and Privacy Policy" | Eng | 1h |
| Configure database backup (Managed PostgreSQL handles WAL archiving automatically) | Eng | 1h |
| Test backup restore to a fresh database | Eng | 2h |
| Document restore procedure | Eng | 1h |
| **Milestone check:** Two test companies onboarded; neither can see the other's data | | |

**Key decision:** If `CompanyScope` relies on a singleton that is reset per-HTTP-request but not per-queue-job, a job processing Company A could read Company B's data. The safest approach is to pass `company_id` explicitly as a job constructor argument and call `Company::withoutGlobalScopes()->find($this->companyId)` inside each job — never relying on the global scope in background jobs. Review the existing job implementations against this pattern.

---

### Week 3 — Reliability & Monitoring

**Goal:** If something breaks, we know about it before the customer tells us.

| Task | Owner | Effort |
|------|-------|--------|
| Install and configure Flare (Laravel-native error reporting) | Eng | 2h |
| Configure `Queue::failing()` hook to alert via email or Slack | Eng | 2h |
| Configure log rotation (daily, 14-day retention) | Eng | 30min |
| Install Laravel Pulse for queue depth visibility | Eng | 2h |
| Write operational runbook (5 most common failure scenarios) | Eng | 1 day |
| Manual end-to-end pipeline test on production (new company → recommendation) | Eng | 4h |
| Implement `BusinessBrainService` Redis cache (5-min TTL) | Eng | 2h |
| Add "Re-trigger sync" Filament action to Integration resource | Eng | 2h |
| Reconcile Learning spec/code drift (update spec: `value` not `payload`) | Eng | 30min |
| Move `ApplyLearnings` to `maintenance` queue (align with Architecture.md) | Eng | 1h |
| **Milestone check:** Fire a `queue:failed` and verify alert fires within 5 minutes | | |

---

### Week 4 — Onboarding Polish & Beta Prep

**Goal:** A real customer can sign up, complete onboarding, and have a good experience.

| Task | Owner | Effort |
|------|-------|--------|
| Write customer Getting Started guide (onboarding, how to read a recommendation, what to expect) | Founder | 1 day |
| Configure welcome email sent on registration completion | Eng | 2h |
| Add "Beta Limitations" section to onboarding flow (honest list of what's missing) | Eng | 2h |
| Add error recovery UI for failed integrations (Settings page: "Your crawl failed. Retry?") | Eng | 3h |
| Define and document support channel (email or Slack) | Founder | 1h |
| Finalize pricing / invoice template for 10 beta customers | Founder | 2h |
| Run through complete onboarding flow as a test customer | Founder | 1h |
| Send invite to CBB Auctions (Customer 1) | Founder | — |
| **Milestone check:** CBB Auctions is onboarded and has received their first recommendation | | |

---

## Infrastructure Architecture (Minimal)

```
Internet → Cloudflare (DNS + DDoS protection, free tier)
         → Nginx (via Forge) on DigitalOcean Droplet
           ├── PHP-FPM → Laravel application
           ├── Supervisor → Queue workers (5 groups)
           └── Cron → Laravel scheduler (every minute)
         → DigitalOcean Managed PostgreSQL (primary + automatic replica)
         → Redis (same droplet, or DigitalOcean Managed Redis)
         → DigitalOcean Spaces (object storage)
         → Postmark (transactional email)
```

**Cost estimate at 10 customers:**
| Service | Monthly cost |
|---------|-------------|
| DigitalOcean Droplet (4 vCPU, 8GB) | ~$48 |
| DigitalOcean Managed PostgreSQL (1GB) | ~$15 |
| DigitalOcean Spaces (250GB) | ~$5 |
| Postmark (10k emails/month) | Free tier |
| Cloudflare (DNS) | Free |
| UptimeRobot | Free tier |
| **Total** | **~$68/month** |

---

## Customer Onboarding Flow

```
Customer receives invite link (via email)
  ↓
Register at getatlas.app/register
  → Verify email (magic link)
  ↓
Onboarding wizard:
  Step 1: Company name + industry
  Step 2: Website URL
  Step 3: Confirmation
  ↓
Status page (polls every 5 seconds):
  "Atlas is reviewing your website..."
  "Your Business Brain is activating..."
  → Timeout message after 5 minutes if delayed
  ↓
Auto-redirect to first recommendation
  ↓
Customer reviews rationale and approves
  ↓
First content asset queued for email publishing
```

---

## Support Plan

**Support channel:** Dedicated Slack workspace or email alias (`beta@getatlas.app`)

**Response time SLA:** Within 24 hours (communicated in onboarding)

**Common issues and responses:**

| Issue | Response |
|-------|----------|
| No recommendations after 30 minutes | Check Filament admin: integration status, failed_jobs queue. Re-trigger sync if needed. |
| Recommendation content is wrong | Ask for feedback (which part?). Note the company's preferences. This feeds the Learning Engine. |
| "Edit & Approve" saved but nothing happened | Check Execution status in Filament. The content is queued — email delivery may be delayed. |
| Customer wants to add a second website | Manually create a second Integration in Filament. (Self-serve UI is post-beta.) |
| Customer wants to cancel | Cancel manually. Soft-delete their Company record. |

**Weekly check-in:** Brief async update to each beta customer (2–3 sentences): what Atlas found about their business this week, what recommendation it prepared.

---

## Beta Communications

### Invite Email

> Subject: You're in — your Atlas beta access is ready
>
> Hi [Name],
>
> Your private beta access to Atlas is ready. Atlas is an autonomous marketing assistant that learns about your business and prepares campaigns for your approval — before you even know you need them.
>
> To get started: [CTA Button: Connect Your Business]
>
> Beta note: You'll be among the first 10 businesses on Atlas. A few things are still in progress — social media publishing is not yet live, and analytics data will populate once your first email campaign runs. Everything else is real.
>
> Reply to this email any time. I read every message.
>
> [Founder name]

### Getting Started Guide (one-pager)

Sections:
1. What Atlas does (the loop in one paragraph)
2. Your Business Brain (what it is and why it gets better over time)
3. How to read a recommendation (rationale, confidence score, content preview)
4. What "Approve" does (queues for email delivery — you'll see it in Publishing)
5. What "Edit & Approve" does
6. What to do if nothing happens (contact support)
7. Beta limitations (publishing, analytics, what's coming)

---

## Success Criteria

The beta is a success if:

1. All 10 customers complete onboarding without engineering intervention
2. Each customer receives at least 3 recommendations in the first 30 days
3. At least 70% of recommendations are approved (not rejected)
4. No cross-company data exposure incidents
5. Uptime > 99% for the first 30 days
6. At least 5 of 10 customers provide qualitative feedback on the recommendation quality
7. Net Promoter Score > 40 from the beta cohort

---

## What Happens After Beta

The private beta is time-limited. After 30 days:
1. Collect structured feedback from all 10 customers
2. Identify the top 3 improvements that would make Atlas more valuable
3. Use real approval/rejection signals to evaluate prompt performance
4. Decide whether to expand the beta (25 customers?) or move toward public launch
5. Begin M11–M19 milestones based on what the beta reveals

The Learning Engine will have 30+ days of real signals at this point. The Business Brain for each company will have evolved. Approval rates should be measurably higher in week 4 than week 1 — this is the key product validation.

---

## Go-Checklist

Before inviting Customer 1:

- [ ] Application live at `https://getatlas.app`
- [ ] SSL active (green padlock)
- [ ] Registration and email verification working
- [ ] Password reset working
- [ ] Cross-company isolation test passing
- [ ] Database backup configured and restore tested
- [ ] Transactional email delivering (send a test email from Postmark)
- [ ] Uptime monitoring active and alerting tested
- [ ] Error reporting active (Flare or Sentry)
- [ ] Privacy policy published at `/privacy`
- [ ] Terms of service published at `/terms`
- [ ] ToS checkbox on registration form
- [ ] Operational runbook written and accessible
- [ ] End-to-end pipeline test passed on production server
- [ ] Support channel documented and staffed
- [ ] Getting Started guide written
- [ ] Beta invite email drafted and approved
- [ ] Pricing / billing plan defined
