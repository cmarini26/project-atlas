# Northwind Staging Dry-Run Report Template

**Purpose:** capture the first real staging dry run for the Northwind synthetic business in a structured, defect-friendly format.

**Related Jira:** SCRUM-68

---

## Run metadata

- **Date:**
- **Environment URL:**
- **Atlas commit / branch:**
- **Operator:**
- **Seed command used:**
- **Verification command used:**

---

## 1. Preconditions

- [ ] staging app reachable
- [ ] database migrated
- [ ] queue worker running
- [ ] scheduler running
- [ ] Northwind seeded
- [ ] verification command passes baseline
- [ ] WordPress connected
- [ ] email provider connected

Notes:

---

## 2. Seed + verification evidence

### Seed output
```text
[paste output]
```

### Verification output
```text
[paste output]
```

Result:
- [ ] pass
- [ ] fail

Notes:

---

## 3. App/UI validation

Checks:
- [ ] Northwind tenant visible
- [ ] onboarding complete
- [ ] settings page loads
- [ ] declared channels visible
- [ ] connected channel statuses accurate

Evidence:
- screenshot paths/links:

Defects:
- none / list

---

## 4. Discovery run

Checks:
- [ ] discovery started successfully
- [ ] progress/status visible
- [ ] discovery reaches terminal state

Evidence:
- run id:
- screenshots:
- command output:

Defects:
- none / list

---

## 5. Recommendation generation

Checks:
- [ ] at least one recommendation generated
- [ ] recommendation quality is believable
- [ ] recommendation aligns with seeded Northwind weaknesses

Observed recommendations:
1.
2.
3.

Defects:
- none / list

---

## 6. WordPress execution

Checks:
- [ ] WordPress connection saved successfully
- [ ] Atlas can create a publishable or published blog artifact
- [ ] resulting URL/draft is accessible

Evidence:
- post URL:
- draft URL:
- screenshots:

Defects:
- none / list

---

## 7. Email execution

Checks:
- [ ] email provider connected successfully
- [ ] test email succeeded
- [ ] received message content is sane

Evidence:
- recipient inbox:
- message id or provider confirmation:
- screenshots:

Defects:
- none / list

---

## 8. Outcome summary

Overall result:
- [ ] PASS
- [ ] PARTIAL PASS
- [ ] FAIL

### Key blockers
- [ ]
- [ ]
- [ ]

### Recommended next fixes
- [ ]
- [ ]
- [ ]

---

## 9. Defect log

| ID | Area | Severity | Step | Expected | Actual | Evidence | Jira filed? |
|---|---|---|---|---|---|---|---|
| D1 |  |  |  |  |  |  |  |
| D2 |  |  |  |  |  |  |  |
| D3 |  |  |  |  |  |  |  |

---

## 10. Exit decision

Choose one:
- [ ] ready for friendly beta after fixes
- [ ] rerun staging after minor fixes
- [ ] blocked on infrastructure/credentials
- [ ] blocked on product correctness issues
