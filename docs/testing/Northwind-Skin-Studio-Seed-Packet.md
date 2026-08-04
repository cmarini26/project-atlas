# Northwind Skin Studio — Seed Packet

**Purpose:** concrete staging data and content inputs for the synthetic business defined in `docs/testing/Staging-Dummy-Business-Brief.md`.

**Related Jira:** SCRUM-64

---

## 1. Company profile for current onboarding flow

These values are chosen to align with the **current live onboarding implementation** in `backend/app/Http/Controllers/OnboardingController.php`.

### Step 2 — Company Information
- **Company name:** Northwind Skin Studio
- **Industry:** Med Spa / Skincare Clinic
- **Description:** Northwind Skin Studio is a Scottsdale med spa focused on natural-looking cosmetic treatments, customized skincare plans, and long-term client relationships. The studio emphasizes education, subtle results, and approachable expert guidance.

### Step 3 — Business Goals
Select these Atlas business goals:
- `generate_leads`
- `increase_sales`
- `increase_website_traffic`
- `improve_seo`

### Step 4 — Marketing Assets
Enable these assets:
- `website`
- `email`
- `instagram`
- `facebook`
- `google_business_profile`

Mark these as primary assets:
- `website`
- `email`
- `instagram`

### Step 5 — Asset Details
Current code requires details only for `website`.

#### Website asset details
- **URL:** `https://northwindskinstudio.com`
- **Platform:** `WordPress`

### Step 6 — Marketing Preferences
These values map directly to the current enums:
- **marketing_frequency:** `weekly`
- **marketing_owner:** `me`
- **is_seasonal:** `true`
- **seasonal_months:** `[9, 10, 11]`
- **primary_cta:** `book`

### Notes on currently optional assets
The current onboarding flow does **not** require detail capture for email, Instagram, Facebook, or Google Business Profile during the wizard. Their richer details should be tracked here for later staging setup, but not assumed to be part of the initial wizard payload.

---

## 2. Business identity and contact details

### Public-facing identity
- **Tagline:** Personalized skin treatments for confident, natural results.
- **Short positioning line:** Boutique skincare and aesthetic treatments for busy Scottsdale clients who want visible results without a high-pressure experience.

### Contact information
- **Phone:** `(480) 555-0142`
- **Primary email:** `hello@northwindskinstudio.com`
- **Booking email:** `book@northwindskinstudio.com`
- **Address:** `7142 E. Mercer Lane, Scottsdale, AZ 85254`
- **Hours:**
  - Monday: 9:00 AM – 5:00 PM
  - Tuesday: 9:00 AM – 6:00 PM
  - Wednesday: 10:00 AM – 6:00 PM
  - Thursday: 9:00 AM – 6:00 PM
  - Friday: 9:00 AM – 4:00 PM
  - Saturday: 9:00 AM – 2:00 PM
  - Sunday: Closed

### Founder / practitioner profile
- **Lead practitioner:** Ava Reynolds, RN
- **Background:** 11 years in aesthetic nursing and advanced skincare treatment planning
- **Style:** calm, educational, conservative with injectables, long-term skin-health oriented

---

## 3. Target customer profile

### Primary customer persona
- Women ages 28–55
- Scottsdale / Paradise Valley / North Phoenix
- Busy professionals, mothers, and image-conscious service-industry clients
- Values convenience, expertise, and subtlety

### Common motivations
- Look more rested before events or work travel
- Address acne, texture, or pigmentation issues
- Maintain a polished appearance without dramatic change
- Find a provider they trust for recurring treatments

### Common objections
- Fear of looking unnatural
- Uncertainty about where to start
- Worry about downtime or discomfort
- Concern that premium pricing won’t match results

---

## 4. Service catalog and pricing bands

### Priority services
#### 1. Complimentary consultation
- **Goal:** convert new visitors into appointments
- **Why it matters:** strongest primary CTA and easiest entry point for recommendation generation

#### 2. Custom facial
- **Starting price:** `$145`
- **Audience:** first-time clients, maintenance clients
- **Use case:** a strong introductory service for offers and email campaigns

#### 3. Microneedling
- **Starting price:** `$325`
- **Audience:** skin texture / acne scar / tone improvement
- **Use case:** high-value educational content and consultation campaigns

#### 4. Laser resurfacing consult
- **Starting price:** consultation first
- **Audience:** seasonal skin-reset seekers, pigmentation and texture concerns
- **Use case:** fall promotion focus

#### 5. Membership program
- **Price band:** `$129–$199/month`
- **Includes:** one monthly treatment credit, member pricing, priority booking
- **Use case:** retention and recurring revenue growth

### Additional services
- Botox / wrinkle relaxers
- Dermal fillers
- Chemical peels
- Acne treatment plans
- Skin maintenance add-ons

---

## 5. Offers Atlas should be able to reference

### Offer 1 — New client consultation
- **Headline:** Complimentary 20-minute consultation for first-time clients
- **Goal:** bookings
- **Ideal channel:** homepage CTA, email, Google Business updates, blog CTA

### Offer 2 — First facial offer
- **Headline:** 15% off your first custom facial
- **Goal:** new client conversion
- **Ideal channel:** email and service page CTA

### Offer 3 — Fall skin reset package
- **Headline:** Seasonal facial + peel planning consult
- **Goal:** timely campaign recommendation
- **Ideal channel:** blog, email, homepage banner

### Offer 4 — Membership launch
- **Headline:** Discounted first month for new members
- **Goal:** retention / LTV
- **Ideal channel:** email and membership page

### Offer 5 — Referral credit
- **Headline:** $50 treatment credit when a referred friend books
- **Goal:** word-of-mouth growth
- **Ideal channel:** post-visit email / website proof section

---

## 6. Website information architecture

### Required pages
1. **Home**
2. **About**
3. **Services overview**
4. **Botox / Injectables**
5. **Facials / Skin Treatments**
6. **Memberships**
7. **Blog**
8. **Contact / Book Consultation**

### Homepage content blocks
- hero with consultation CTA
- credibility strip (licensed practitioner, years of experience, Scottsdale location)
- top services preview
- why clients choose Northwind
- featured seasonal offer
- testimonials
- booking CTA
- email signup

### Blog topics to seed
- “How to know if microneedling is right for your skin goals”
- “What to expect from your first med spa consultation”
- “Fall skin reset: why cooler months are ideal for treatment planning”

---

## 7. Intentional content and UX gaps to preserve

These must remain in the dummy website so Atlas has real opportunity signal.

### Gap A — weak hero CTA prominence
- CTA exists, but visual prominence is lower than ideal
- headline strong, but CTA button should feel slightly under-emphasized

### Gap B — stale blog cadence
- 1 recent article
- 2 older articles several months apart
- enough content to look real, but obviously underused

### Gap C — uneven proof distribution
- testimonials on homepage and one service page only
- membership page missing strong social proof

### Gap D — underdeveloped lead capture
- simple email signup form with generic copy like “Stay updated”
- no strong incentive or lead magnet

### Gap E — thin objection handling
- Botox or laser page should not fully answer downtime, comfort, and “will I look overdone?” questions

### Gap F — seasonal offer buried
- fall skin reset exists, but appears only on the blog or low on the homepage rather than prominently above the fold

---

## 8. Optional channel metadata for later staging setup

### Email
- **Provider intent:** staging transactional/provider-aware email path
- **Sender name:** Northwind Skin Studio
- **From address:** `hello@northwindskinstudio.com`
- **Audience segments:**
  - New leads
  - Facial clients
  - Membership prospects
  - Reactivation candidates

### Instagram placeholder
- **Handle:** `@northwindskinstudio`
- **Bio:** Personalized skin treatments and subtle aesthetic care in Scottsdale. Book a consultation below.
- **Content style:** educational reels, before/after-friendly copy, treatment FAQs, practitioner tips

### Facebook placeholder
- **Page name:** Northwind Skin Studio
- **Usage:** local credibility, mirrored promotions, appointment reminders

### Google Business Profile placeholder
- **Business name:** Northwind Skin Studio
- **Primary category:** Medical spa
- **Secondary category:** Skin care clinic

---

## 9. Fake subscribers for staging

Use only synthetic identities. These are suitable for seed data or demos, not real sends.

| Name | Email | Segment | Status |
|---|---|---|---|
| Mia Carter | mia.carter@example.test | New leads | never booked |
| Elena Brooks | elena.brooks@example.test | Facial clients | last visit 90 days ago |
| Jasmine Patel | jasmine.patel@example.test | Membership prospects | clicked prior offer |
| Hannah Flores | hannah.flores@example.test | Reactivation candidates | no visit in 6 months |
| Rachel Kim | rachel.kim@example.test | New leads | requested consultation |
| Olivia Turner | olivia.turner@example.test | Facial clients | purchased intro facial |
| Brooke Sanders | brooke.sanders@example.test | Membership prospects | downloaded pricing info |
| Lauren Diaz | lauren.diaz@example.test | Reactivation candidates | previous peel client |

---

## 10. Draft-friendly FAQs

These should appear across website and blog content.

1. **Will I look overdone after treatment?**
   - Northwind emphasizes subtle, natural-looking results and treatment planning based on your goals.

2. **How do I know where to start?**
   - Start with a consultation so your provider can recommend the right treatment path.

3. **Is there downtime?**
   - Depends on the treatment; some services have little to no downtime, while others may require a few recovery days.

4. **Do you offer plans for ongoing skin maintenance?**
   - Yes, the monthly membership is designed for clients who want consistent treatment and expert guidance.

5. **How often should I come in?**
   - Frequency depends on goals, treatment type, and budget.

---

## 11. Acceptance criteria for SCRUM-64

SCRUM-64 should be considered complete when:
- the high-level business brief exists
- this seed packet exists with concrete onboarding-aligned values
- the selected dummy business can be handed off directly to SCRUM-65 and SCRUM-66 without needing concept clarification
- all content remains fully synthetic and safe for staging use

---

## 12. Hand-off to the next tickets

### For SCRUM-65
Use this packet to build:
- page-by-page site copy
- seeded blog content
- CTA placement and intentional gaps
- testimonial placement decisions

### For SCRUM-66
Use this packet to seed:
- company and onboarding values
- website metadata
- staging audience rows
- channel labels and connection targets
- email sender identity assumptions
