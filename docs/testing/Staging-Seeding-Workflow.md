# Staging Seeding Workflow — Northwind

**Purpose:** operational guide for seeding the Northwind synthetic business into Atlas staging.

**Related Jira:** SCRUM-66

---

## 1. Seed command

Atlas now includes a staging seed command:

```bash
php artisan atlas:seed-staging
```

### Supported profile
- `northwind`

### Optional flags
```bash
php artisan atlas:seed-staging northwind \
  --owner-email=northwind-owner@atlas.test \
  --owner-name="Northwind Owner" \
  --owner-password='<password-from-your-secret-manager>' \
  --start-discovery
```

The command refuses to run when `APP_ENV=production`. In staging, `--owner-password`
is required; supply it from the staging secret manager and never commit it. The
predictable development password is available only in `local` and `testing`.

---

## 2. What the command seeds

### Company + owner
- owner user
- owner membership
- company row
- default blog channel (via `CompanyService`)

### Onboarding profile
- business goals
- marketing assets
- website asset details
- marketing preferences
- onboarding completion timestamp

### Marketing channels
Declared channels for:
- website
- email
- instagram
- facebook
- google business profile

### Email data
Audiences:
- New leads
- Facial clients
- Membership prospects
- Reactivation candidates

Contacts:
- synthetic contacts from `docs/testing/northwind-skin-studio-seed.json`

---

## 3. Source of truth files

The command is powered by these artifacts:
- `docs/testing/Staging-Dummy-Business-Brief.md`
- `docs/testing/Northwind-Skin-Studio-Seed-Packet.md`
- `docs/testing/northwind-skin-studio-seed.json`

---

## 4. Verification steps

After running the seed command, verify with:

```bash
php artisan atlas:verify-staging
```

Optional stricter checks:

```bash
php artisan atlas:verify-staging --expect-discovery
php artisan atlas:verify-staging --expect-discovery --expect-recommendation
```

The verification command checks:

1. company exists: `Northwind Skin Studio`
2. owner exists with the configured email
3. onboarding profile is complete
4. five marketing channels are declared
5. blog publishing channel exists
6. four email audiences exist
7. four synthetic contacts exist and are attached to the expected audience segments
8. whether WordPress and email are connected yet
9. whether discovery/recommendations exist yet when required

---

## 5. Discovery behavior

If `--start-discovery` is passed, Atlas immediately starts Business Discovery after seeding.

> **External-request boundary:** the Northwind profile declares the controlled
> GitHub Pages test site at `https://cmarini26.github.io/project-atlas/`. Using
> `--start-discovery` schedules a real HTTP crawl of that public synthetic site;
> it never serves files directly from `docs/testing/site-prototype/`.

Use this when you want to test:
- observation orchestration
- discovery progress/status
- first recommendation generation

Skip it when you only want to prepare the staging tenant first.

---

## 6. Current limitations

This command currently seeds:
- company data
- onboarding data
- declared channels
- audience/contact seed data

It does **not** yet automatically:
- connect real WordPress credentials
- connect a real email provider token
- connect Meta/Instagram OAuth
- build the public dummy website on a live host

Those are still part of the later staging setup work.

---

## 7. Recommended use for SCRUM-66

1. confirm `https://cmarini26.github.io/project-atlas/` serves the current synthetic prototype
2. run `php artisan atlas:seed-staging --start-discovery` in staging
3. verify onboarding/discovery state in the app
4. connect the beta-critical real channels next:
   - WordPress
   - email provider
5. use the Northwind website/content package as the external observation target
