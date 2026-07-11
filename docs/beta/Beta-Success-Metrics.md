# Beta Success Metrics

**Purpose:** operationalizes [Version-1.0-Roadmap.md](../plans/Version-1.0-Roadmap.md) §5's Stage A success metric — *"Number of design-partner-caliber businesses that complete onboarding → first recommendation → approve-or-reject, without founder intervention"* — into specific, measurable criteria to check throughout the private beta, on the cadence [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §3 and §5 already define. A metric without a stated measurement method is a hope, not a metric — every criterion below says what to measure, where the data comes from, and what "on track" versus "needs attention" looks like.

**How to use this document:**
- These are **Stage A metrics** — thresholds assume 5–10 hand-picked, founder-watched customers. They are not the bar Stage B (paid beta) will be held to; see `Version-1.0-Roadmap.md` §5 for how expectations rise at each later stage.
- Review these at the cadence `Private-Beta-Execution.md` §5 already defines: daily during the first week per customer, then at the weekly review going forward.
- Where a metric depends on real customer data, the target stated is a plan for the beta, not a claim that it is currently being met — no production environment exists yet as of this writing (see [STATUS.md](../STATUS.md)). Do not backfill this document with invented numbers before real data exists.
- Qualitative signals here are sourced from [Customer-Interview-Guide.md](Customer-Interview-Guide.md) conversations, recorded in [Founder-Learning-Log.md](Founder-Learning-Log.md).

---

## 1. Onboarding Completion Rate

**Definition:** the percentage of invited customers who complete the full onboarding wizard — company profile, website URL, marketing-presence declaration — without abandoning partway.

**How to measure:** count companies whose Digital Twin has progressed past `initializing`, divided by the number of invited customers who started the wizard (a `Company` row exists). Verify in Filament or via a direct database query — don't infer completion from "the UI redirected successfully."

**Target:** 100% for Stage A. A hand-picked, founder-watched beta of 5–10 customers should not lose anyone to onboarding friction alone — any incomplete onboarding is a signal to personally investigate that customer's experience, not a rate to average away.

**Why it matters:** this is the first half of the roadmap's stated Stage A primary metric — a customer who never finishes onboarding never gets the chance to judge the product at all.

---

## 2. Time to First Recommendation

**Definition:** elapsed time from website URL submission to the first Recommendation appearing in the customer's dashboard.

**How to measure:** the timestamp of the onboarding integration's creation versus the `created_at` of the company's first Recommendation. Cross-check this measured value against the customer's own estimate, gathered via [Customer-Interview-Guide.md](Customer-Interview-Guide.md) §2's "how long did the whole thing take, in their own estimate" question — a gap between the measured time and the customer's *felt* time is itself a signal worth logging.

**Target:** under 10 minutes — the founding north-star metric stated in `Version-1.0-Roadmap.md` and `Private-Beta-Execution.md` §5 — measured on the real production environment, never local development.

**Why it matters:** this single number is the concrete test of the product's core pitch to a time-poor small business owner: connect your business, and a real recommendation with a real explanation shows up before you've had time to second-guess signing up.

---

## 3. Recommendation Approval Rate

**Definition:** the percentage of recommendations that receive Approve or Edit & Approve, versus Not This Time — tracked per company and in aggregate across the beta.

**How to measure:** count `Recommendation` status transitions per company over time.

**Target:** no fixed numeric target for Stage A — a low approval rate from one customer is a valid thing to learn from, not automatically a failure. What to track instead is the **trend per company** over the course of the beta. `Version-1.0-Roadmap.md`'s Stage B metric expects this trending stable-or-up as the Learning Engine accumulates evidence; Stage A is where that trend should first become visible at all.

**Why it matters:** this is the most direct quantitative test of whether the four-part rationale (why now / why this / why this channel / why it will work) actually earns trust, and whether "Atlas learns your business" (per [Landing-Page.md](../marketing/Landing-Page.md)'s core messages) is a real, observable behavior rather than a landing-page claim.

---

## 4. Customer Engagement

**Definition:** how often and how meaningfully a customer returns to and uses the product, beyond the onboarding flow and the first mandatory approval decision.

**How to measure:** count logins/dashboard visits per company per week. Cross-check against the customer's own answer to [Customer-Interview-Guide.md](Customer-Interview-Guide.md) §3's "have you looked at Atlas again since the first recommendation" question — a mismatch between actual visits and a customer's *sense* of how often they've engaged is itself worth recording.

**Target:** at least two return visits per company in week one, without a founder-initiated prompt or reminder driving the visit.

**Why it matters:** distinguishes "used it once because we personally asked them to" from a product that's genuinely becoming part of how the business is run — the real bar for advancing toward Stage B (paid beta).

---

## 5. Recommendation Usefulness (Qualitative and Quantitative)

**Quantitative component:** approval rate (§3) and edit frequency/substance (how often Edit & Approve is chosen, and how much of the generated content is actually changed). An expected-vs-actual outcome comparison for a published campaign is **not yet a meaningful metric** — every channel currently only simulates delivery (see [Channel-Publishing-Reality-Audit.md](../reviews/Channel-Publishing-Reality-Audit.md)), so "outcome" for now means engagement with the recommendation itself, not real external reach. This will become a real metric once at least one channel genuinely publishes.

**Qualitative component:** direct answers from [Customer-Interview-Guide.md](Customer-Interview-Guide.md) §2 — "does this sound like it's about *your* business" and the per-quadrant rationale reactions — recorded in [Founder-Learning-Log.md](Founder-Learning-Log.md).

**Target:** no numeric target for Stage A. The goal is a documented, repeatable answer — across most or all beta customers — to "do our customers believe Atlas understands their specific business," not a score.

**Why it matters:** this is the product's actual differentiator, per `Landing-Page.md`'s core messages ("Atlas thinks before it creates," "Atlas explains every recommendation"). If this isn't landing with real customers, no other metric in this document matters.

---

## 6. Weekly Active Companies

**Definition:** the number of distinct companies with at least one meaningful action — login, recommendation view, approval, edit, or rejection — in a trailing 7-day window.

**How to measure:** query across all onboarded companies for the trailing week.

**Target:** for Stage A, this should equal or nearly equal the total number of onboarded companies. In a hand-picked, founder-watched beta of 5–10 customers, a company going inactive is a signal to personally reach out and investigate, not a rate to tolerate as statistical noise.

**Why it matters:** the earliest, cheapest warning sign that the product has stopped earning continued attention from a given customer.

---

## 7. Support Burden

**Definition:** the volume and nature of support interactions per customer per week — messages received, issues raised, time to first response, time to resolution.

**How to measure:** tracked via the designated support channel, per [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §3's "Customer Issue Triage" — track the stated 24-hour response SLA explicitly, not just informally.

**Target:** Stage A explicitly expects founder-time-bound, high-touch support — the metric to watch is not "zero support burden," it's whether the **same root cause repeats** across more than one customer (a signal to fix the underlying issue, not keep answering the same question), per `Private-Beta-Execution.md`'s end-of-week review guidance.

**Why it matters:** Stage B's success metric explicitly requires support that "isn't purely founder-time-bound" (`Version-1.0-Roadmap.md` §2). Stage A is where the team learns what that support actually needs to cover before scaling past personal attention.

---

## 8. Customer Willingness to Continue After Beta

**Definition:** each customer's stated willingness to keep using Atlas — and at what price — once the beta's free, founder-supported framing ends.

**How to measure:** [Customer-Interview-Guide.md](Customer-Interview-Guide.md) §4's direct "would you pay for this?" question at the one-month checkpoint, logged verbatim (not inferred from usage alone — a customer can be actively engaged during a free, high-touch beta and still say no to paying) in [Founder-Learning-Log.md](Founder-Learning-Log.md).

**Target:** no fixed numeric target for Stage A. The deliverable is a documented, individual answer per customer at the one-month mark, aggregated into "how many said yes, and at what rough price range" ahead of Stage B planning.

**Why it matters:** this is the direct bridge to Stage B, whose objective is explicitly to "prove... that customers will pay for what Atlas does today" (`Version-1.0-Roadmap.md` §2). Stage A's job is to produce a real, individually-sourced answer to this question — not an assumed one.

---

## Reporting Cadence

- **Daily** (first week per new customer): time-to-first-recommendation, onboarding completion, and engagement are checked as part of `Private-Beta-Execution.md` §5's daily tasks.
- **Weekly**: all eight metrics above are reviewed together at the end-of-week review `Private-Beta-Execution.md` §5 already calls for, alongside a decision on whether to invite the next customer(s) or pause to fix something first.
- **Monthly**: recommendation usefulness and willingness-to-pay are specifically revisited per customer at the one-month interview checkpoint.

*These metrics should be revisited — not necessarily replaced — once Stage A's own success criteria are met and the roadmap advances to Stage B; see `Version-1.0-Roadmap.md` §5 for how the bar changes at each stage.*
