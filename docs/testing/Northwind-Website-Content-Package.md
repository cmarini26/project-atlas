# Northwind Skin Studio — Website & Content Package

**Purpose:** page-by-page website/content package for the synthetic Northwind Skin Studio business, suitable for staging implementation and deliberate Atlas validation.

**Related Jira:** SCRUM-65
**Depends on:** `docs/testing/Staging-Dummy-Business-Brief.md`, `docs/testing/Northwind-Skin-Studio-Seed-Packet.md`

---

## 1. Content strategy for the dummy site

This site should feel real enough that a crawler, marketer, or potential customer would believe it, but it must preserve specific weaknesses so Atlas has meaningful opportunities to detect.

### What the site should do well
- clearly explain what the business is
- establish trust and local credibility
- present several real-looking services and offers
- include enough language variety for Atlas to infer audience, positioning, and goals
- create clear website observation signal for recommendations

### What the site should do imperfectly on purpose
- the primary CTA should be present but under-emphasized on the homepage
- blog activity should feel inconsistent
- one high-value service page should feel thinner than it should
- membership value proposition should be understandable but not compelling enough
- lead capture should exist without a strong incentive

---

## 2. Sitemap

1. Home
2. About
3. Services
4. Botox / Injectables
5. Facials / Skin Treatments
6. Memberships
7. Blog Index
8. Blog Post: Microneedling for skin goals
9. Blog Post: What to expect from your first consultation
10. Blog Post: Fall skin reset (older / somewhat buried)
11. Contact / Book Consultation

---

## 3. Global website settings

### Primary CTA
- **Label:** Book a Consultation
- **Destination:** Contact / booking form section

### Secondary CTA
- **Label:** Explore Treatments
- **Destination:** Services page

### Global header nav
- Home
- About
- Services
- Memberships
- Blog
- Contact

### Global footer details
- Scottsdale address
- business hours
- phone number
- hello@northwindskinstudio.com
- short email signup form

---

## 4. Home page

### Goal
Introduce the business, establish trust, create consultation intent, and surface enough marketing clues for Atlas to analyze.

### Intentional weakness
The consultation CTA should exist, but it should not dominate the hero as strongly as it should for a lead-driven local service business.

### Hero
**Headline:**
Feel like yourself — refreshed, confident, and cared for.

**Subheadline:**
Northwind Skin Studio offers personalized skin treatments and subtle aesthetic care in Scottsdale, with treatment plans designed around your goals, comfort, and long-term confidence.

**Primary CTA:**
Book a Consultation

**Secondary CTA:**
Explore Treatments

### Credibility strip
- 11+ years of aesthetic nursing experience
- Scottsdale-based care
- personalized treatment planning
- natural-looking results

### Section: Why clients choose Northwind
**Heading:**
Modern skincare guidance without the pressure.

**Body copy:**
We believe great results start with trust. Every treatment plan at Northwind is designed around your goals, comfort level, timeline, and lifestyle. Whether you want to address texture, acne, dullness, or early signs of aging, we guide you toward the right next step — not the biggest possible treatment.

### Section: Featured services
Cards for:
- Complimentary consultation
- Custom facials
- Microneedling
- Botox / wrinkle relaxers
- Laser resurfacing consults
- Monthly skin membership

### Section: Seasonal highlight
**Heading:**
Planning a skin reset this season?

**Body copy:**
Cooler months can be a great time to revisit your skincare routine and plan treatments that support tone, texture, and long-term skin health.

> Keep this section a little too low on the page so the seasonal offer feels under-promoted.

### Section: Testimonials
Use 3 short testimonials.

**Testimonial 1:**
“I never felt rushed or pressured. Ava walked me through every option and helped me choose a treatment plan that felt right for me.”

**Testimonial 2:**
“My skin looked healthier and brighter after just a few visits. The whole experience feels calm, clean, and really personalized.”

**Testimonial 3:**
“I wanted subtle results, and that’s exactly what I got. I look more rested, not different.”

### Section: Email signup
**Heading:**
Stay updated

**Body copy:**
Occasional skincare tips, treatment updates, and studio news.

> Keep this generic. No lead magnet.

### Final CTA
**Heading:**
Not sure where to begin?

**Body copy:**
Start with a consultation and we’ll help you understand which treatments fit your goals and comfort level.

---

## 5. About page

### Goal
Humanize the business and make the lead practitioner credible.

### Page heading
About Northwind Skin Studio

### Intro copy
Northwind Skin Studio was created for clients who want thoughtful, personalized care — not a one-size-fits-all treatment menu. We focus on skin health, subtle aesthetic treatments, and honest guidance so you can make informed decisions with confidence.

### Practitioner profile
**Ava Reynolds, RN**
Ava has spent more than a decade helping clients navigate skincare and aesthetic treatment options with a calm, conservative approach. Her philosophy is simple: understand the client first, educate clearly, and aim for results that feel natural, balanced, and sustainable.

### Values section
- personalization over pressure
- education before treatment
- subtle, natural-looking results
- long-term relationships over one-time transactions

### Closing CTA
Book a consultation to talk through your goals, concerns, and ideal treatment pace.

---

## 6. Services overview page

### Goal
Summarize available treatments and create multiple pathways into detail pages and booking.

### Heading
Services designed around your skin, schedule, and goals.

### Intro copy
Whether you’re looking for a simple refresh, a treatment plan for specific concerns, or support maintaining results over time, Northwind offers personalized options for every stage.

### Service categories
#### Injectables
Wrinkle relaxers and filler consultations focused on subtle, natural-looking enhancement.

#### Skin treatments
Custom facials, peels, microneedling, and skin-renewal services tailored to your needs.

#### Long-term care
Membership and maintenance options for clients who want consistency and expert guidance over time.

### Internal links
- Learn about Botox / Injectables
- Explore Facials / Skin Treatments
- View Memberships
- Book a Consultation

---

## 7. Botox / Injectables page

### Goal
High-intent page with enough value to be believable, but still intentionally missing some objection-handling depth.

### Heading
Subtle injectable treatments with a personalized approach.

### Intro copy
Injectables can help soften expression lines and support a more refreshed appearance when they’re approached thoughtfully. At Northwind, treatment planning starts with your goals, comfort level, and preference for natural-looking results.

### Sections
#### What this treatment can help with
- forehead lines
- crow’s feet
- frown lines
- facial balance and subtle volume support

#### What to expect
We begin with a consultation, discuss your goals, review timing and comfort considerations, and create a treatment plan that fits your preferences.

#### Why clients choose Northwind
Clients come to Northwind for a calmer, more personalized experience — especially if they want guidance without feeling pushed into a larger treatment plan.

### Intentional weakness
Do **not** fully answer:
- downtime expectations
- longevity expectations
- “how do I avoid looking overdone?”

That omission should create opportunity signal for Atlas.

---

## 8. Facials / Skin Treatments page

### Goal
Show treatment breadth and create a strong consultation/facial entry point.

### Heading
Targeted skin treatments for texture, tone, clarity, and confidence.

### Intro copy
From first-time facials to advanced treatment planning, Northwind helps clients build routines and treatment paths that support healthier, more radiant skin over time.

### Featured treatments
- custom facials
- chemical peels
- microneedling
- acne treatment support
- laser resurfacing consults

### Stronger educational section
This page should be more complete than the injectables page.

**Section heading:**
A treatment plan built for where your skin is now.

**Body copy:**
Not every client needs the same intensity, frequency, or treatment sequence. Some start with facials and home-care adjustments. Others are ready for more advanced treatments like microneedling or resurfacing planning. We help you start where it makes sense and build from there.

### CTA
Book a Consultation

---

## 9. Memberships page

### Goal
Present recurring revenue offer, but intentionally under-explain it.

### Heading
Simple monthly care for clients who want consistency.

### Intro copy
Our membership is designed for clients who want a more structured way to stay on top of skincare, maintenance treatments, and long-term results.

### Included benefits
- monthly treatment credit
- preferred member pricing
- priority booking access
- guidance on long-term planning

### Intentional weakness
Keep this page a little too vague on:
- exact value comparison
- who it is best for
- why now
- clear testimonial / proof

Atlas should reasonably recommend stronger membership positioning.

---

## 10. Blog index

### Goal
Show enough content to prove the business tries to educate, but not enough to feel consistently active.

### Intro
Insights, treatment guidance, and practical skincare education from Northwind Skin Studio.

### Posts shown
1. How to know if microneedling is right for your skin goals
2. What to expect from your first med spa consultation
3. Fall skin reset: why cooler months are ideal for treatment planning

### Intentional weakness
Use publication timing that makes the blog cadence look inconsistent.

Suggested spacing:
- Post 1: 3 weeks ago
- Post 2: 4 months ago
- Post 3: 9 months ago

---

## 11. Blog post 1 — Microneedling

### Title
How to know if microneedling is right for your skin goals

### Purpose
A strong, useful educational post that supports consultation conversion.

### Outline
- common reasons clients consider microneedling
- texture, scarring, and tone concerns
- why consultation matters before advanced treatment
- when a gentler approach may make more sense first
- CTA to book a consultation

### Sample intro
Microneedling is often one of the first treatments clients ask about when they want to improve skin texture, soften acne scarring, or support overall skin renewal. But like any advanced treatment, it works best when it’s part of a plan — not just a trend-driven decision.

---

## 12. Blog post 2 — First consultation

### Title
What to expect from your first med spa consultation

### Purpose
Reduce hesitation and answer beginner concerns.

### Outline
- understanding your goals
- discussing comfort and timing
- reviewing treatment options
- deciding whether to start now or later
- setting expectations clearly

### Sample intro
If you’ve never visited a med spa before, it’s normal to have questions. Your first consultation should feel informative, calm, and collaborative — not rushed or sales-driven.

---

## 13. Blog post 3 — Fall skin reset

### Title
Fall skin reset: why cooler months are ideal for treatment planning

### Purpose
Seasonal recommendation signal.

### Outline
- why seasonal timing matters
- planning for texture and tone concerns
- pairing consultation with treatment roadmap
- mention facial + peel planning offer

### Sample intro
As temperatures cool and routines settle back into place, fall can be a great time to step back, reassess your skincare goals, and build a treatment plan for the months ahead.

### Intentional weakness
This is a strong promotional hook, but it should feel under-leveraged across the rest of the site.

---

## 14. Contact / Book Consultation page

### Goal
Provide enough booking/contact info for a realistic local lead-gen site.

### Heading
Book your consultation

### Intro copy
Whether you’re ready to schedule or just want help understanding your options, we’d love to hear from you.

### Contact fields
- first name
- last name
- email
- phone
- preferred appointment day
- primary concern
- how did you hear about us?

### Contact details block
- phone number
- hello@northwindskinstudio.com
- Scottsdale address
- studio hours

---

## 15. Reusable testimonial snippets

Use these snippets sparingly — not on every page.

1. “I appreciated how personalized everything felt from the first consultation.”
2. “The results were subtle, natural, and exactly what I was hoping for.”
3. “I finally felt like I had a long-term plan for my skin instead of guessing.”
4. “The studio feels elevated without being intimidating.”

---

## 16. Email capture copy variants

### Weak version for the site
Stay updated with occasional skincare tips and studio news.

### Stronger version Atlas should ideally recommend later
Get practical skincare guidance, seasonal treatment updates, and first access to studio offers.

---

## 17. Acceptance criteria for SCRUM-65

SCRUM-65 should be considered complete when:
- every required page has a content direction and enough copy to implement
- the site clearly reflects the Northwind brand and target audience
- seeded strengths and weaknesses are both intentional and documented
- blog and offer content support later email/WordPress testing
- the package is specific enough to build a staging site without further concept work

---

## 18. Hand-off to implementation

### Build-critical details
- use realistic local-business formatting and trust signals
- keep the site credible, not overly polished
- preserve the weak CTA / stale blog / thin page / buried offer weaknesses
- ensure the site still contains enough content depth for Atlas to generate grounded recommendations

### What SCRUM-66 will need from this
- final page URLs
- whether WordPress is the actual staging implementation target
- final blog slugs
- contact form field expectations
