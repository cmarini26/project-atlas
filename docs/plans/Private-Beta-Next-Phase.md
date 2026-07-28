# Private Beta: Next Phase

**Status:** In progress  
**Started:** 2026-07-28  
**Objective:** move Atlas from a healthy deployed application to an operationally defensible private beta.

## Verified baseline

- `theclearmove.com` is live on AWS EC2 with valid HTTPS.
- Production reports `APP_ENV=production`, `APP_DEBUG=false`, and the correct application URL.
- `/api/live`, `/api/ready`, and `/api/health` are healthy.
- GitHub Actions has completed repeat deployments from `main`.
- Supervisor runs workers for `high`, `ai`, `default`, `observations`, and `maintenance`.
- The Laravel scheduler runs every minute and the configured recurring commands fire.
- Queue depth and `failed_jobs` were empty at the verification point.
- Disk usage was 26%, leaving approximately 14 GB free.

These are point-in-time operational checks, not permanent guarantees. Monitoring and recurring verification are still required.

## Delivery sequence

### 1. Close deployment verification

- Run `php artisan atlas:verify-queues` in production and retain the result as SCRUM-43 evidence.
- Close SCRUM-43 and its parent SCRUM-10 only after all five acknowledgements arrive.
- Reconcile already-proven scheduler and repeat-deployment tickets with production evidence.

### 2. Protect customer data

Work SCRUM-17, SCRUM-18, and SCRUM-19 as one release gate:

- schedule encrypted database and uploaded-asset backups;
- copy backups off the EC2 instance;
- prove a current backup completes;
- restore it into an isolated scratch target and spot-check records and files;
- document retention, ownership, and the recurring restore-drill cadence.

**Exit criterion:** a server loss does not imply an unrecoverable customer-data loss, and another operator can follow the recovery procedure.

### 3. Make failures visible

Work SCRUM-13 through SCRUM-16:

- install/configure real production error tracking and prove receipt of a test exception;
- monitor `/api/ready` externally and prove an alert is received;
- assign a named incident owner and escalation path;
- exercise failed-job inspection, retry, and discard procedures.

**Exit criterion:** a production failure is detected and owned without waiting for a customer report.

### 4. Validate the narrow golden path

Work SCRUM-20 through SCRUM-26 and SCRUM-52 with real provider accounts:

- observe a real website or Shopify store;
- import a real Mailchimp audience;
- send and receive a real provider-backed email and collect analytics;
- publish to a real WordPress site and collect reporting;
- confirm every customer-facing capability label matches the observed result.

**Exit criterion:** Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn completes for a controlled real business.

### 5. Pass the customer trust gate

Finish SCRUM-27 through SCRUM-31:

- publish privacy and terms pages and require acceptance;
- establish the support channel, runbook, owner, and response expectation;
- complete an end-to-end production dry run;
- prove live tenant isolation with two companies;
- have a second person independently execute the go/no-go checklist.

**Exit criterion:** every item in `Private-Beta-Execution.md` section 4 is checked with evidence before Customer 1 is invited.

## Explicitly deferred

Do not widen the beta promise with more channel implementations until the gates above pass. New connectors can stay in the backlog unless they are required by the first customer's actual golden path. Depth, recoverability, and truthful operation take precedence over breadth.
