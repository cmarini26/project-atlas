# Northwind Staging Channel Connection Checklist

**Purpose:** define the exact remaining inputs and steps needed to finish the beta-critical staging path for Northwind.

**Related Jira:** SCRUM-66

---

## 1. Remaining blocking items

The synthetic business is seeded and verifiable, but the end-to-end staging path is still blocked on two real connections:

1. **WordPress blog connection**
2. **Email provider connection**

Without those, Atlas can still:
- complete onboarding
- run synthetic seeding
- verify staging data shape

But it cannot yet fully validate:
- WordPress publishing
- email test-send
- live channel-backed recommendation execution

---

## 2. WordPress connection requirements

Atlas Settings expects:
- `site_url`
- `username`
- `app_password`

### Expected staging target
Use the public or staging WordPress site that will represent Northwind.

### Atlas behavior
Atlas verifies the connection before saving it by calling the WordPress REST endpoint:
- `GET /wp-json/wp/v2/users/me`

### Ready-to-run validation path
Once credentials exist:
1. open company settings in Atlas
2. connect WordPress
3. confirm success message
4. run:
   ```bash
   php artisan atlas:verify-staging
   ```
5. verify `wordpress_connection` changes from `pending` to `connected`

---

## 3. Email connection requirements

Atlas Settings expects:
- `provider_type` (`postmark` or `sendgrid`)
- `api_token`
- `from_email`
- `from_name` (optional)

### Recommended staging choice
- **Postmark** if you want the most direct match to the current happy path
- **SendGrid** if that is already available in your environment

### Ready-to-run validation path
Once credentials exist:
1. open company settings in Atlas
2. connect the email provider
3. send a test email from settings
4. run:
   ```bash
   php artisan atlas:verify-staging
   ```
5. verify `email_connection` changes from `pending` to `connected`

---

## 4. Recommended execution order

1. Deploy Atlas to staging
2. Run:
   ```bash
   php artisan atlas:seed-staging
   php artisan atlas:verify-staging
   ```
3. Connect WordPress
4. Connect email provider
5. Run:
   ```bash
   php artisan atlas:verify-staging --expect-discovery
   ```
   after first discovery start
6. Run:
   ```bash
   php artisan atlas:verify-staging --expect-discovery --expect-recommendation
   ```
   after first recommendation appears

---

## 5. What I can do next without more input

Without credentials from you, I can still continue by:
- tightening the staging verification workflow further
- adding a post-seed discovery helper command
- adding a short operator runbook for SCRUM-67

## 6. What will require your input later

I will need your help only when we reach the real connection step for:
- WordPress site URL + app password
- email provider token/details
- staging server/domain access if we are deploying this live
