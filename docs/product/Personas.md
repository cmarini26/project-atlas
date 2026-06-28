# Atlas — User Personas

**Last updated:** 2026-06-27  
**Milestone:** 10 — Customer Dashboard

These personas are the design target for every customer-facing interface decision in Atlas. When two design options exist, choose the one Marcus would prefer.

---

## Persona 1 — Marcus

**Name:** Marcus  
**Role:** Owner, CBB Auctions  
**Business type:** Comic book auction house and online marketplace  

### Who Marcus Is

Marcus has run CBB Auctions for 11 years. He knows the comic book market deeply — which runs are undervalued, which graders to trust, which auction windows attract the serious buyers. He is not a marketer. He doesn't think of himself as a marketer, and he would resist the label.

He runs weekly auctions, manages a team of two, photographs and lists hundreds of items per month, and handles customer service when things go sideways. Marketing gets whatever time and attention are left over — which is rarely much.

**Technical level:** Low-to-moderate. Uses Gmail, Facebook, Instagram, and a browser. Has used Mailchimp once, found it confusing, and stopped. Can navigate software if it behaves sensibly. Will not read a tutorial.

**Time available for marketing:** 30–60 minutes per week, in fragments. A Sunday evening before the next auction cycle. A Thursday morning before the weekend preview opens.

### What Marcus Wants

- To know which auction or item deserves promotion *this week* — the one that will actually drive bids
- To have the content already written, not drafted, when he gets to it
- To publish without second-guessing whether he's making a mistake
- For Atlas to get better over time without him having to configure it

### What Atlas Means to Marcus

> "Someone who noticed the Silver Age auction closes in 48 hours and already wrote the email before I even thought to check."

Atlas is not a tool Marcus uses. Atlas is a colleague who does the marketing thinking so Marcus can focus on running the auction.

### Pain Points Atlas Solves

| Pain | How Atlas addresses it |
|------|----------------------|
| Writes the same promotional posts over and over | Atlas generates campaign content based on what's in the auction right now |
| Doesn't know which items to feature | Atlas identifies the highest-signal opportunity from the current catalog |
| Misses promotion windows because he's busy | Atlas detects urgency (auction closes in 48h) and surfaces a recommendation before Marcus thinks to ask |
| Can't remember what worked last time | Atlas records every approval, edit, and outcome — and learns from it |

### Key Behaviors

- Checks the dashboard **once or twice a week**, not daily
- Wants to see **exactly one pending recommendation** — not a list of ten
- Will read the explanation if it is **short and in plain language**
- **Will not approve** something he doesn't understand
- Expects Atlas to **remember his preferences** and get better over time
- If a recommendation doesn't feel right, he wants to say no quickly and move on

### Primary Interactions with the Dashboard

1. **Review and approve** the pending recommendation (primary loop)
2. **Check that last week's campaign went out** (campaign status)
3. **Occasionally read** what Atlas knows about his business (Business Brain)
4. **Onboarding** — enter his website URL once, let Atlas do the rest

### How Marcus Fails with Bad Design

- Long lists of recommendations he didn't ask for → ignores the dashboard entirely
- Jargon or marketing terminology → feels out of his depth, stops trusting Atlas
- Unclear approval consequences ("what happens when I click this?") → doesn't click
- Empty state with no explanation → concludes Atlas isn't working yet, disengages

---

## Persona 2 — Sofia

**Name:** Sofia  
**Role:** Part-time marketing contractor, managing 2–3 clients simultaneously  
**Business type:** Contract marketing manager; one of her clients is an exotic used car dealership  

### Who Sofia Is

Sofia has 8 years of marketing experience. She has managed email campaigns in Klaviyo, scheduled social posts in Buffer, analyzed performance in Google Analytics, and run A/B tests in Optimizely. She knows what a conversion funnel looks like and doesn't need it explained to her.

She manages 2–3 clients at a time. For each, she is responsible for content strategy, execution, and reporting. She bills by the hour — efficiency is money. She can't afford to babysit a tool that requires constant correction.

**Technical level:** High. Comfortable with dashboards, analytics, and moderately complex software. Will explore features Marcus would never find.

**Time available:** Sofia has dedicated marketing hours — unlike Marcus, she is not doing this in the margins. She may log in daily during active campaign periods.

### What Sofia Wants

- To **produce high-quality, on-brand content efficiently** — Atlas's first draft is a starting point she refines
- To **catch anything Atlas gets wrong** before it reaches the client or goes public
- To **validate decisions with analytics** — did the recommended channel actually outperform?
- To understand **how Atlas is improving** — the learning feed helps her explain Atlas's value to her clients
- To have a **clear record of what she approved and why**, in case a client asks

### What Atlas Means to Sofia

> "The first draft is already there when I sit down. I review it, adjust the tone, and it's out the door in 15 minutes instead of an hour."

Atlas removes the rote work — channel selection, content generation, timing judgment — so Sofia can focus on brand judgment and client relationships.

### Pain Points Atlas Solves

| Pain | How Atlas addresses it |
|------|----------------------|
| Client feedback loops are slow | Atlas surfaces the recommendation before the client window closes — Sofia can act proactively |
| Has to manually remember what worked last time per client | Atlas's Learning Engine accumulates per-company history automatically |
| Generic scheduling tools don't understand context | Atlas knows the catalog, the opportunity type, and the channel history |
| Reporting what worked requires manual data pulls | Atlas compares actual vs expected KPIs after each campaign |

### Key Behaviors

- Logs in **daily or near-daily** during active campaign cycles
- **Always reads the full rationale** before approving — she wants all four quadrants (why now, why this, why this channel, why it will work)
- **Edits content before approving** in many cases — Atlas's draft is a starting point, not a final output
- Reviews **analytics after publishing** to validate that the decision made sense
- Reads the **Learning insights** to track how Atlas is improving per client
- Will **reject a recommendation** and add a note if the rationale doesn't hold up

### Primary Interactions with the Dashboard

1. **Review recommendation in full** (rationale + content preview) and decide: approve, edit & approve, or reject
2. **Edit content** inline before approving — adjust tone, remove jargon, add client-specific details
3. **Monitor campaign status** and execution across active campaigns
4. **Read analytics** and compare to expected impact
5. **Review Learning insights** to explain Atlas's improvement trajectory to clients

### How Sofia Fails with Bad Design

- Truncated rationale or hidden explanation → has to guess at Atlas's reasoning; doesn't trust it
- No edit capability → forced to approve imperfect content or reject everything; value collapses
- Analytics that don't compare expected vs actual → can't assess decision quality
- No audit trail of what she changed → can't defend her edits to the client

---

## Design Implications

| Decision | Marcus's need | Sofia's need | Resolution |
|----------|--------------|--------------|-----------|
| How much rationale to show? | Short, plain language | Full detail, all four quadrants | Show full rationale. Sofia reads it. Marcus can skim after the first line tells him what he needs. |
| Edit before approve? | Rarely needed | Used frequently | Available but optional. Not the primary CTA. |
| Analytics visibility | Just "did it go out?" | Full KPI breakdown, expected vs actual | Full analytics available; dashboard summary is simple |
| How many pending items? | One at a time | All pending, organized | Dashboard shows the most urgent one prominently; full list accessible |
| Learning feed | Curiosity at best | Professional tool for client reporting | Present as a readable feed, not a debug log |
| Action labels | Plain English: "Approve", "Not this time" | Familiar: same, with notes capability | Plain English throughout |
