# Private-Beta Incident Response

**Primary incident owner:** Carlo Marini

**Alert routes:** Sentry email alerts (verified); AWS CloudWatch/SNS email alerts
(pending the SCRUM-14 live-stack drill)

**Applies to:** Atlas production at `theclearmove.com`

## Ownership

Carlo Marini is the first responder for production exceptions, availability
alarms, failed-job incidents, and reports that the customer-facing golden path
is blocked. Alerts must route to an inbox Carlo checks during the response
window; a dashboard with no delivered notification is not an alert. Sentry
delivery has been verified. CloudWatch/SNS does not count as active until
SCRUM-14's stack and receipt drill are complete.

There is no backup incident owner during the single-operator private beta. This
is a known operating constraint, not an implied team rotation. Do not expand
the beta beyond closely coordinated customers until a backup owner has been
named, given production access, and completed a supervised response drill.

## First-response expectation

- From **08:00 to 22:00 America/Los_Angeles**, acknowledge a production alert
  within **30 minutes**.
- Alerts received overnight must be reviewed by **08:30 America/Los_Angeles**.
- For an active customer-facing outage, data-integrity risk, suspected security
  incident, or inability to publish an already-approved campaign, begin
  mitigation immediately after acknowledgement.
- Send the affected customer an initial status update within **60 minutes** of
  confirming customer impact, even when the root cause is still unknown.
- Continue updates at least every **60 minutes** until service is restored or a
  specific next-update time has been agreed.

These are private-beta operating targets, not a contractual public SLA.

## Alert routing

| Signal | Route | First check |
|---|---|---|
| New or regressed production exception | Sentry project `atlas` → email alert | Open the Sentry issue; identify environment, release, frequency, and affected component |
| Readiness or availability failure | Route 53 health check → CloudWatch alarm → SNS email (pending SCRUM-14 activation) | Compare `/api/live` and `/api/ready`, then inspect the failing readiness check |
| Failed queue job | Daily failed-job review or related Sentry exception | Open `/admin/failed-jobs`; inspect the exception before choosing Retry or Discard |
| Customer report | Private-beta support channel | Reproduce, establish impact and start time, then correlate with Sentry and health checks |

Routing changes are complete only after the named owner receives a controlled
test notification. Keep provider credentials and alert addresses out of this
repository.

## Response sequence

1. Record the alert time, acknowledgement time, affected surface, and current
   production release.
2. Establish impact: one operation, one customer, multiple customers, or the
   entire service.
3. Check Sentry, `/api/live`, `/api/ready`, Supervisor, `queue:failed`, and disk
   capacity as applicable.
4. Stop additional harm. Pause a scheduled execution, disable the affected
   integration, enter maintenance mode, or roll back only when the observed
   impact justifies it.
5. If a failed job is safe and idempotent, retry it once from
   `/admin/failed-jobs`. Discard only after recording why replay is unsafe or
   unnecessary.
6. Notify affected customers on the schedule above.
7. Verify recovery from the customer's perspective, not only from a green
   process or dashboard.
8. Record root cause, remediation, recovery time, customer impact, and a
   follow-up ticket before closing the incident.

## Escalation

If customer impact is not mitigated within 30 minutes of acknowledgement:

1. stop automated executions for the affected path and prefer a known-safe
   rollback or maintenance state;
2. use the responsible provider's support path when the evidence points to AWS,
   Sentry, the email provider, AI provider, WordPress, or another dependency;
3. tell the affected customer what is unavailable, what remains safe, and when
   the next update will arrive;
4. treat suspected unauthorized access, cross-company exposure, or data loss as
   a security/data incident: preserve evidence, stop the affected access path,
   and do not resume it until scope is understood.

The absence of a backup owner must be visible in launch reviews. Provider
support and customer communication are escalation paths, but neither replaces
a second trained Atlas operator.

## Recurring checks

- Review Sentry and `/admin/failed-jobs` at least once each beta day.
- Confirm the uptime alarm is `OK` and its SNS subscription remains confirmed.
- Re-run controlled alert drills after routing, provider, DNS, TLS, or health
  endpoint changes.
- Review response-target misses weekly and adjust staffing or beta availability
  before adding customers.
