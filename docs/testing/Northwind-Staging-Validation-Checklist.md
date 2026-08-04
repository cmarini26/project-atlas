# Northwind Staging Validation Checklist

**Purpose:** repeatable end-to-end checklist for validating Atlas against the Northwind synthetic business on staging.

**Related Jira:** SCRUM-67

---

## 0. Preconditions

Before starting, confirm all of the following are true:

- Atlas staging app is deployed and reachable
- database migrations are current
- queue worker is running
- scheduler is running
- `php artisan atlas:seed-staging` is available on staging
- Northwind seed artifacts are present in `docs/testing/`

Recommended baseline check:

```bash
php artisan atlas:seed-staging
php artisan atlas:verify-staging
```

Expected result:
- seed command succeeds
- verification passes
- WordPress/email may still show `pending`

---

## 1. Seed the synthetic business

### Action
Run:

```bash
php artisan atlas:seed-staging
```

### Pass criteria
- command exits 0
- output includes `Seeded staging profile [northwind]`
- company slug is `northwind-skin-studio`

### Evidence to capture
- terminal output
- timestamp of run

---

## 2. Verify seeded state

### Action
Run:

```bash
php artisan atlas:verify-staging
```

### Pass criteria
- command exits 0
- verification summary shows:
  - company `ok`
  - owner `ok`
  - onboarding `ok`
  - declared channels `ok`
  - blog channel `ok`
  - audiences `ok`
  - contacts `ok`

### Evidence to capture
- verification table output

---

## 3. Confirm Northwind appears correctly in the app

### Action
Log into staging and inspect the seeded Northwind tenant.

### Pass criteria
- company name displays as `Northwind Skin Studio`
- onboarding is marked complete
- marketing presence shows:
  - website
  - email
  - instagram
  - facebook
  - google business profile
- Settings page loads without error

### Evidence to capture
- screenshots of company view
- screenshot of settings page

---

## 4. Connect WordPress

### Action
In Settings, connect the staging Northwind WordPress site.

Required inputs:
- site URL
- username
- WordPress application password

### Pass criteria
- Atlas returns `WordPress connected.`
- rerunning:
  ```bash
  php artisan atlas:verify-staging
  ```
  shows `wordpress_connection = connected`

### Failure signs
- invalid credentials
- wrong site URL
- REST API not reachable

### Evidence to capture
- connection success message
- updated verification output

---

## 5. Connect email provider

### Action
In Settings, connect Postmark or SendGrid.

Required inputs:
- provider type
- API token
- from email
- optional from name

### Pass criteria
- Atlas returns provider connected message
- email test send succeeds
- rerunning:
  ```bash
  php artisan atlas:verify-staging
  ```
  shows `email_connection = connected`

### Evidence to capture
- connection success message
- test email receipt
- updated verification output

---

## 6. Start first discovery run

### Action
Use one of:

```bash
php artisan atlas:seed-staging --start-discovery
```

or, if already seeded, trigger discovery via app flow.

### Pass criteria
- a discovery run exists for Northwind
- rerunning:
  ```bash
  php artisan atlas:verify-staging --expect-discovery
  ```
  exits 0

### Evidence to capture
- discovery status screen
- verification output with discovery present

---

## 7. Validate first recommendation generation

### Action
Wait for the initial discovery/orchestration flow to complete.

### Pass criteria
- at least one recommendation is created
- rerunning:
  ```bash
  php artisan atlas:verify-staging --expect-discovery --expect-recommendation
  ```
  exits 0
- recommendation is coherent for seeded Northwind weaknesses

### Good recommendation examples
- stronger homepage CTA
- improved membership offer framing
- fall seasonal campaign opportunity
- refreshed blog cadence recommendation
- missing testimonial/social proof recommendation

### Evidence to capture
- recommendation UI screenshot
- verification output

---

## 8. Validate channel-backed execution path

### WordPress execution
Pass if Atlas can produce publishable blog content and send it to the connected WordPress destination.

### Email execution
Pass if Atlas can produce an email draft or test send against the connected provider without credential errors.

### Evidence to capture
- WordPress post URL or draft URL
- email test-send confirmation or received test email

---

## 9. Final checklist outcome

Mark the run:

- **PASS** if all sections 1–8 succeed
- **PARTIAL PASS** if seeding/discovery succeed but real channel connections or execution fail
- **FAIL** if seed, verification, onboarding state, or discovery orchestration breaks

---

## 10. Defect logging template

For each failure, capture:

- step number
- command or UI action
- expected result
- actual result
- screenshot or terminal output
- probable area:
  - seeding
  - onboarding
  - settings connection
  - discovery orchestration
  - recommendation generation
  - publishing/execution

---

## 11. Fast rerun loop

For repeated staging checks, use this minimum loop:

```bash
php artisan atlas:seed-staging
php artisan atlas:verify-staging
```

Then continue with:
- Settings connection checks
- discovery run
- recommendation verification
