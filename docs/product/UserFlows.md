# Atlas — User Flows

**Last updated:** 2026-06-27  
**Milestone:** 10 — Customer Dashboard

These flows define the exact steps a user takes for each primary interaction with the Atlas customer dashboard. They are the source of truth for route design, controller behavior, and Vue page structure.

For the personas these flows serve, see [Personas.md](Personas.md).

---

## Flow 1 — First-Time Setup (Onboarding)

**User:** Marcus or Sofia, first session  
**Trigger:** New account creation or first login with no company membership  
**North-star outcome:** User enters a website URL and sees a pending recommendation within 10 minutes  

### Steps

```
1. Sign up (name, email, password)
   → Account created
   → Redirect to /onboarding

2. Company profile (company name, industry)
   → POST /onboarding/company
   → CompanyService::create() creates Company + Catalog + DigitalTwin (initializing) + CompanyMembership (owner)
   → Redirect to /onboarding (step 2)

3. Connect website (URL input)
   → POST /onboarding/integration
   → IntegrationService::create() creates Integration, dispatches SyncIntegration job
   → Redirect to /onboarding (step 3)

4. Marketing presence ("Where do your customers find you?")
   → Multi-select checklist: Website (preselected), Email Newsletter, Instagram,
     Facebook, LinkedIn, X, YouTube, TikTok, Google Business Profile, Events,
     Print, Other — no handle/URL/API connection prompt anywhere in this step
   → POST /onboarding/marketing-presence
   → MarketingPresenceService::declare() creates one unlinked, unconnected
     MarketingChannel per selection (status: active)
   → Redirect to /onboarding/status

5. Pipeline status page (live progress)
   → Polls GET /api/onboarding/status every 5 seconds
   → Shows progress through:
       [ Crawling your website... ]         ← SyncIntegration running
       [ Extracting facts... ]              ← ProcessObservation running
       [ Building your knowledge base... ]  ← KnowledgeSynthesis running
       [ Detecting opportunities... ]        ← DetectOpportunities running
       [ Preparing your recommendation... ] ← PrepareCampaign + CreateRecommendation running
       [ Your first recommendation is ready! ]

6. When recommendation_count > 0:
   → Stop polling
   → Show "View your recommendation →" button (primary)
   → Button navigates to /app/recommendations/{id}
```

### UX Decisions

- The wizard is 4 steps: company profile → website URL → marketing presence → confirm. Marketing presence was added in Milestone 11 Phase 3 as the final step, so Atlas has a declared marketing presence on record before the pipeline status page's "Atlas is now learning" experience begins — see [marketing-presence.md](../specs/core/marketing-presence.md).
- The marketing presence step asks only "which channels do you use today" — no handles, no URLs, no API connections. It exists to seed a lightweight declaration, not to configure publishing.
- After the final step, the user is on the status page immediately. They are never on a blank dashboard wondering what's happening.
- The status page provides reassurance — each completed step is visually marked done (checkmark) before the next starts.
- If the pipeline takes longer than expected (>5 min), show: "This is taking a moment. Atlas is doing a thorough analysis. You can leave this page — we'll notify you when the first recommendation is ready."
- The first recommendation is not shown until it is complete. No partial states in the UI.

---

## Flow 2 — Reviewing and Approving a Recommendation (Primary Loop)

**User:** Marcus (common) or Sofia (common)  
**Trigger:** User logs in to dashboard; pending recommendation exists  
**North-star outcome:** User reads the full explanation, trusts it, and approves in under 2 minutes  

### Steps

```
1. Login → Redirect to /app (dashboard)

2. Dashboard
   → RecommendationPrompt card is the most prominent element (top of page, highlighted border)
   → Shows: recommendation type, "Why now" sentence, confidence score
   → Primary CTA: "Review Recommendation →"

3. Recommendation detail (/app/recommendations/{id})
   → Shows full rationale in 4 quadrants:
       [ Why now ]           [ Why this ]
       [ Why this channel ]  [ Why it will work ]
   → Shows expected impact (reach estimate, engagement signal, confidence)
   → Shows content preview for each channel (email: subject + body; social: post text)
   → Three actions with distinct visual weight:
       [ Approve ]   (primary)
       [ Edit & Approve ]  (secondary)
       [ Not this time ]   (tertiary, understated)

4. User clicks "Approve"
   → POST /app/recommendations/{id}/approve
   → ApprovalService::approve() is called
   → Approval record created; Recommendation status → 'approved'
   → Campaign status → 'approved'
   → Execution records created (status: 'queued')
   → RecommendationApproved event fires
   → Redirect to /app/recommendations with flash message: "Approved. Atlas will handle the publishing."

5. Dashboard shows:
   → No pending recommendations (or next one if more exist)
   → Recent campaigns card shows the newly approved campaign
```

### UX Decisions

- The rationale is never collapsed or behind a "Show more" toggle. It is the primary content on the page.
- Approve is one click — no intermediate confirmation modal. The flash message on the next page is the acknowledgment.
- After approval, the user sees a brief explanation of what happens next ("Atlas will publish this at the optimal time. You can check the status in Campaigns.").
- If approval fails (server error), show an inline error with a retry option. Never show a raw error message.
- Confidence score is displayed as both a numeric score bar and a plain language label: "High confidence" (≥80), "Moderate confidence" (60–79), "Lower confidence" (<60).

---

## Flow 3 — Editing Before Approving

**User:** Sofia (primary); Marcus (occasional)  
**Trigger:** User wants to modify the generated content before publishing  
**North-star outcome:** User adjusts the content, captures their preference, and approves cleanly  

### Steps

```
1. Recommendation detail page (same as Flow 2, step 3)

2. User clicks "Edit & Approve" (secondary button)
   → Content editor appears inline, replacing the content preview
   → For email: subject line textarea + body textarea
   → For social: post text textarea
   → One asset at a time (tab/accordion per channel if multiple)

3. User edits content
   → No character limits enforced in the editor (the AI respects them; human edits are trusted)
   → "Save & Approve" button becomes active when content has changed

4. User clicks "Save & Approve"
   → POST /app/recommendations/{id}/approve-edit
   → Body includes: { content_asset_id, subject (if email), body }
   → ApprovalService::editAndApprove() is called
   → Approval record created with action: 'edited_and_approved'
   → Approval.edits stores the diff: { content_asset_id, original_body, edited_body }
   → Recommendation status → 'approved'
   → EditPatternDetector detects patterns from the edits
   → Learning record created from the edit signal
   → Redirect to /app/recommendations with flash: "Your changes were saved and the recommendation was approved."

5. "Cancel edit" link reverts the editor to the content preview, no changes saved
```

### UX Decisions

- Editing is optional — the approve button is always the most prominent option.
- The edit UI does not require a rich text editor for MVP. Plain textarea is sufficient. The system content is already formatted.
- The "Save & Approve" button is disabled until the user has actually changed something.
- After editing and approving, the content preview on the recommendation record shows the user's version, not the original.
- The diff captured in `Approval.edits` is internal — it is not shown to the user in MVP.

---

## Flow 4 — Rejecting a Recommendation

**User:** Marcus or Sofia  
**Trigger:** User reviews a recommendation and decides it's not right  
**North-star outcome:** User says no, Atlas acknowledges without friction, and the user feels good about the decision  

### Steps

```
1. Recommendation detail page

2. User clicks "Not this time" (tertiary button — low visual prominence)
   → A small inline form appears below the buttons:
       "Help Atlas learn (optional)"
       [________________________] ← textarea, placeholder: "e.g., wrong timing, wrong channel, content tone"
       [ Confirm rejection ]   [ Cancel ]

3. User optionally adds a note and clicks "Confirm rejection"
   → POST /app/recommendations/{id}/reject
   → Body includes: { notes: string | null }
   → ApprovalService::reject() is called
   → Approval record created (action: 'rejected', notes captured)
   → Recommendation status → 'rejected'
   → Campaign status → 'cancelled'
   → Learning record created (signal: recommendation_rejected)
   → Redirect to /app/recommendations with flash:
       "Got it. Atlas will keep watching and surface a new recommendation soon."

4. Dashboard shows:
   → If another recommendation is pending: show it
   → If none: show empty state for recommendations ("Atlas is looking for the next opportunity")
```

### UX Decisions

- Rejection is never framed as a failure or mistake. The flash message thanks the user implicitly by confirming Atlas will learn from it.
- The rejection note field label is "Help Atlas learn" not "Reason for rejection." The framing matters.
- "Not this time" vs "Reject" — the label is chosen deliberately to avoid sounding like the user is punishing the system.
- The note field is never required. Users who want to explain do; users who don't shouldn't feel penalized.
- Rejection should take under 30 seconds if the user is confident.
- The reject button uses tertiary styling (ghost, muted text) so it is visible but not tempting.

---

## Flow 5 — Checking Campaign Status

**User:** Marcus (verifying last campaign went out) or Sofia (monitoring active campaigns)  
**Trigger:** User wants to know the state of a campaign  

### Steps

```
1. Dashboard → Click "View all campaigns" or navigate to /app/campaigns

2. Campaign timeline (/app/campaigns)
   → List of campaigns ordered by created_at desc
   → Each card shows: title, type, status badge, progress trail
   → Filter tabs: All / Draft / Approved / Active / Completed
   → Paginated: 15 per page

3. Click a campaign → Campaign detail (/app/campaigns/{id})
   → Campaign metadata (type, blueprint summary, status)
   → Content assets per channel (type, preview, status)
   → Execution status per asset (queued / executing / completed / failed)
   → If completed:
       → KPI snapshot if available (actual reach, engagement, vs expected)
       → Channel breakdown

4. If a campaign is in 'failed' status:
   → Show the last_error for each failed execution
   → Explain what Atlas will do next (retry / cancelled)
```

### UX Decisions

- A campaign status badge uses simple language: "In queue", "Publishing", "Sent", "Cancelled" — not raw status enum values.
- The progress trail (Draft → Approved → Queued → Sent → Analytics) shows where in the lifecycle the campaign is, not just the current status.
- For Marcus: the most important answer is "Did it go out?" — make that visible at a glance.
- For Sofia: the most important answer is "How did it perform vs expectation?" — surface the KPI snapshot prominently on the detail page.
- Execution errors are shown in plain language, not stack traces.

---

## Flow 6 — Reading Analytics

**User:** Sofia (primary) or Marcus (occasional)  
**Trigger:** User wants to understand how a campaign performed  

### Steps

```
1. Navigate to /app/analytics

2. Analytics overview
   → Recommendation KPIs at the top:
       - Approval rate (last 30 days)
       - Rejection rate
       - Edit rate
       - Median time-to-decision
   → Campaign KPI snapshots: list of recent finalized campaigns with performance rating
       (Exceeded / Met / Below / Insufficient data)

3. Click a campaign snapshot → Campaign analytics detail (/app/analytics/{campaignId})
   → Campaign context (title, type, channel)
   → Expected impact (from original Decision) vs actual KPIs
   → Channel breakdown: which channel drove the most reach / engagement
   → Performance rating badge

4. No campaign data yet (new company):
   → Empty state: "Analytics will appear here once your first campaign runs.
      Your first recommendation is waiting for your approval."
   → Link to /app/recommendations
```

### UX Decisions

- Analytics pages are read-only. No actions.
- Expected vs actual comparison is always shown side-by-side, never just one or the other.
- A "Performance rating" badge (Exceeded / Met / Below) gives Sofia a one-glance verdict before diving into numbers.
- If a campaign is still executing (window not closed), show "Analytics pending — final data expected within 48 hours."
- Never show raw normalised metric keys. Translate: `normalised_reach` → "Estimated Reach", `normalised_engagement` → "Engagements."
- Approval rate trends over time are visible — Sofia can show a client that Atlas is getting more accurate over months.

---

## Flow 7 — Understanding the Business Brain

**User:** Sofia (regular use); Marcus (occasionally)  
**Trigger:** User wants to understand what Atlas knows about their business  

### Steps

```
1. Navigate to /app/brain

2. Business Brain page
   → Digital Twin status card:
       - Status (Initializing / Active)
       - Health score
       - Last enriched timestamp
   → Facts table: key facts Atlas has learned (key, value, confidence, type)
   → Knowledge cards: synthesized insights (what Atlas knows, not just what it observed)
   → Recent observations: last 5 crawl/sync events with timestamp

3. If Digital Twin is 'initializing':
   → Empty state for Facts and Knowledge
   → Banner: "Atlas is still learning about your business. This page will fill in as observations complete."

4. No integration connected:
   → Empty state: "No data sources connected."
   → CTA: "Connect your website" → /settings
```

### UX Decisions

- Facts are shown in human-readable format: `key: "catalog.item_count"` → display as "Catalog size"; `value: 847` → display as "847 items".
- Fact keys should be translated where possible using a predefined mapping. Unknown keys fall back to the raw key with dots replaced by spaces.
- Knowledge entries are shown as cards, not table rows — they are narrative, not data points.
- This page is informational only. No actions, no editing, no adding facts manually.
- Health score is displayed numerically (0–100) and as a label: "Healthy" (80+), "Building" (50–79), "Learning" (<50).

---

## State Transitions Reference

The following table summarizes status values and the plain-language labels shown in the UI:

| Entity | Status value | UI label |
|--------|-------------|---------|
| DigitalTwin | `initializing` | "Setting up" |
| DigitalTwin | `active` | "Active" |
| Recommendation | `pending` | "Awaiting review" |
| Recommendation | `approved` | "Approved" |
| Recommendation | `rejected` | "Passed" |
| Campaign | `draft` | "Draft" |
| Campaign | `approved` | "Approved" |
| Campaign | `published` | "Published" |
| Campaign | `cancelled` | "Cancelled" |
| Opportunity | `open` | "Active" |
| Opportunity | `selected` | "Selected" |
| Opportunity | `expired` | "Expired" |
| Opportunity | `dismissed` | "Passed" |
| Execution | `queued` | "In queue" |
| Execution | `executing` | "Publishing" |
| Execution | `completed` | "Sent" |
| Execution | `failed` | "Failed" |
| Execution | `cancelled` | "Cancelled" |
| Integration | `active` | "Connected" |
| Integration | `error` | "Error" |
| Integration | `paused` | "Paused" |
