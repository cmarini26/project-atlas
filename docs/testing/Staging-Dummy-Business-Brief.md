# Staging Dummy Business Brief

**Purpose:** define one realistic synthetic business Atlas can use for staging validation end-to-end without risking a real customer relationship.

**Related Jira:** SCRUM-64

---

## 1. Chosen synthetic business

### Company
- **Name:** Northwind Skin Studio
- **Type:** Local med spa / skincare clinic
- **Location:** Scottsdale, Arizona
- **Service area:** Scottsdale, Paradise Valley, North Phoenix
- **Primary conversion goal:** book consultations and treatment appointments
- **Secondary conversion goals:** grow membership enrollments, drive repeat visits, and increase email list signups

### Why this business was chosen
Northwind Skin Studio is a strong Atlas staging target because it creates clear, testable opportunities across the current golden path:
- **website observation** — service pages, promotions, testimonials, FAQs, contact flows
- **email execution + analytics** — consultation offers, seasonal campaigns, membership promotion, reactivation sequences
- **WordPress execution** — educational blog posts and landing-page-style promotional content

It also gives Atlas enough realistic marketing surface area to produce meaningful recommendations without needing a large catalog or complex inventory sync.

---

## 2. Business profile

### Tagline
Personalized skin treatments for confident, natural results.

### About
Northwind Skin Studio is a boutique Scottsdale med spa focused on skin health, subtle cosmetic treatments, and long-term client relationships. The studio combines medically informed treatment planning with approachable education, emphasizing natural-looking results rather than aggressive transformation messaging.

### Target audience
- Women ages 28–55 in Scottsdale and nearby areas
- Professionals and parents with disposable income and limited time
- Clients interested in preventative skincare, injectables, acne support, and monthly maintenance
- Customers who value trust, cleanliness, and premium-but-approachable service

### Customer pain points
- Unsure which treatment is right for their skin goals
- Nervous about looking overdone
- Inconsistent skincare routines and confusion about options
- Wants visible results without a high-pressure experience
- Needs convenient scheduling and clear pricing expectations

### Positioning
- Premium but not luxury-alienating
- Educational, calm, trustworthy
- Focused on customized treatment plans and subtle results
- More relationship-driven than discount-driven, while still using occasional offers to convert first-time visitors

### Brand voice
- Clean, polished, warm
- Confident without hype
- Helpful and educational
- Avoids sounding overly clinical, aggressive, or salesy

### Words to use
- personalized
- natural-looking
- confidence
- refreshed
- long-term skin health
- consultation
- treatment plan
- expert guidance

### Words to avoid
- miracle
- flawless
- instant transformation
- guaranteed results
- anti-aging panic language
- overly technical jargon without explanation

---

## 3. Core services and offers

### Services
1. Botox / wrinkle relaxers
2. Dermal fillers
3. Custom facials
4. Acne treatment programs
5. Laser resurfacing
6. Chemical peels
7. Microneedling
8. Monthly skin membership

### Priority services for Atlas recommendations
1. Consultations for first-time clients
2. Membership enrollments
3. Seasonal laser and peel promotions
4. Reactivation offers for past facial clients

### Standing offers
- **New client consultation:** complimentary 20-minute consultation
- **First facial offer:** 15% off first custom facial
- **Membership launch:** first month discounted for new members
- **Seasonal promotion:** fall skin reset package (facial + peel consult)
- **Referral incentive:** $50 treatment credit after a referred friend books

---

## 4. Channel inventory for staging

### Required staging channels
#### Website
- Public website with realistic business copy
- Core pages:
  - Home
  - About
  - Services
  - Botox / Injectables
  - Facials / Skin Treatments
  - Memberships
  - Blog
  - Contact / Book Consultation

#### Email
- Sender identity for staging, using the synthetic business brand
- At least one staging audience segment:
  - New leads
  - Existing clients
  - Membership prospects

#### WordPress / blog
- WordPress-backed blog or equivalent publish target suitable for:
  - educational posts
  - seasonal promotion posts
  - treatment FAQs

### Optional placeholder channels
These may exist as realistic placeholders, but must remain honestly labeled unless truly connected:
- Instagram
- Facebook
- Google Business Profile

---

## 5. Intentional weaknesses Atlas should detect

The synthetic business should be realistic, but not overly polished. Atlas needs visible gaps to find.

### Required weaknesses
1. **Stale blog cadence**
   - only 1–2 recent posts
   - older posts clustered months apart

2. **Weak primary CTA on the home page**
   - consultation CTA present but not prominent enough above the fold

3. **Thin service detail on at least one high-value page**
   - one service page should lack strong FAQs, outcome framing, or objection handling

4. **Inconsistent proof elements**
   - testimonials present on some pages but missing from others

5. **Underdeveloped lead capture**
   - email capture exists, but no strong lead magnet or follow-up hook

6. **Seasonal promotion not surfaced well**
   - a timely offer exists but is not clearly promoted on homepage/blog/email signup flow

### Optional weaknesses
- inconsistent internal linking between service pages and blog
- vague membership explanation
- limited before/after proof language
- missing urgency around seasonal timing

---

## 6. Assets/data that should exist for staging

### Business data packet
- company name
- business type
- location and hours
- phone/email/contact details
- service list
- pricing bands or starting prices
- FAQs
- target audience
- brand voice guidance
- current offers

### Seed content
- homepage copy
- about page copy
- 2 detailed service pages
- 2 short blog posts
- 1 stale/older blog post
- 1 consultation offer email draft
- 1 membership promotion email draft

### Seed audience assumptions
- 10–20 fake subscribers in staging
- fake but structured client segments
- no real personal data

---

## 7. Staging success criteria for Atlas

SCRUM-64 is complete only when the synthetic business definition is specific enough to drive meaningful staging validation.

### Definition-complete criteria
- [ ] One repo-tracked source of truth exists for the synthetic business
- [ ] The brief is detailed enough to support website copy, email campaigns, and WordPress content
- [ ] The current Atlas golden path is represented: website observation, email execution, WordPress execution
- [ ] Intentional weaknesses are explicit rather than left to ad hoc interpretation
- [ ] The brief avoids real-customer data and can be safely used in staging

### Downstream validation criteria this brief must enable
The synthetic business must be capable of supporting later verification that:
1. Atlas can onboard the company with realistic business information
2. Atlas can observe the website and produce relevant facts/knowledge
3. Atlas can identify concrete marketing opportunities
4. Atlas can generate believable recommendations in the correct brand voice
5. Atlas can support approval/editing without misleading automation claims
6. Atlas can draft and test at least one email campaign path
7. Atlas can draft and publish at least one WordPress/blog content path

---

## 8. Pass/fail for the first staging dry run

A future staging dry run should count as a pass only if:
- the dummy business can complete onboarding without ambiguous missing business data
- the website crawl yields enough signal for Atlas to produce business-specific recommendations
- at least one recommendation clearly references a seeded weakness from this brief
- Atlas can draft content that matches the brand voice
- staging truth surfaces honestly distinguish connected vs placeholder channels

A future staging dry run should count as a fail if:
- Atlas produces only generic advice not grounded in the seeded business
- the dummy business lacks enough specificity for useful recommendations
- the website/content package is too polished for Atlas to find real opportunities
- unsupported channels are presented as operationally validated

---

## 9. Recommended next steps

1. **SCRUM-65** — build the website and content package from this brief
2. **SCRUM-66** — seed the staging company and connect email/WordPress paths
3. **SCRUM-67** — turn the success criteria above into a step-by-step staging checklist
4. **SCRUM-68** — run the first full dry run and log defects
