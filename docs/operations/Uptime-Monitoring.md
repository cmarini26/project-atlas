# Production Uptime Monitoring

**Owner:** Carlo Marini  
**Service:** AWS Route 53 health checks + CloudWatch alarm + SNS email  
**Target:** `https://theclearmove.com/api/ready`  
**Infrastructure source:** `infrastructure/cloudformation/atlas-uptime-monitor.yml`

**Response target:** [Private-Beta Incident Response](Incident-Response.md)

## What the monitor proves

The Route 53 check runs outside the Atlas EC2 server every 30 seconds from three AWS regions. A check is healthy only when:

- HTTPS connects successfully;
- `/api/ready` returns a successful response; and
- the response contains `"status":"ok"`.

The readiness endpoint checks the application, PostgreSQL, Redis/cache, and queue connectivity. This is deliberately stronger than `/api/live`, which proves only that the web process can answer.

The CloudWatch alarm enters `ALARM` after two consecutive one-minute periods report an unhealthy check. Missing metrics count as unhealthy. The named operator receives both alarm and recovery notifications through SNS.

## Provisioning

CloudWatch stores Route 53 health-check metrics in `us-east-1`, so create the stack in **US East (N. Virginia)** even though the EC2 server runs in `us-west-2`.

1. Open CloudFormation in `us-east-1`.
2. Create a stack using `infrastructure/cloudformation/atlas-uptime-monitor.yml`.
3. Name it `atlas-production-uptime`.
4. Enter the named operator's email for `AlertEmail`.
5. Keep the host, path, and expected-content defaults.
6. Create the stack and wait for `CREATE_COMPLETE`.
7. Open the SNS confirmation email and select **Confirm subscription**.
8. Confirm the subscription status is `Confirmed`, the Route 53 health check is healthy, and the CloudWatch alarm is `OK`.

An unconfirmed SNS subscription does not deliver alerts and does not satisfy SCRUM-14.

## Non-disruptive alert drill

Do not stop PHP-FPM or take the customer site down merely to test an alert.

1. Update the same CloudFormation stack.
2. Change `ExpectedContent` from `"status":"ok"` to `atlas-uptime-alert-drill`.
3. Wait for the health check to become unhealthy and the alarm to enter `ALARM`.
4. Confirm the named operator receives the alarm email.
5. Immediately update the stack and restore `ExpectedContent` to `"status":"ok"`.
6. Confirm the health check returns healthy, the alarm returns to `OK`, and the recovery email arrives.
7. Record the UTC timestamps of both messages in SCRUM-14.

The application remains online throughout this drill; only the monitor's expected response changes.

## Incident response

When the alarm fires:

1. Open `https://theclearmove.com/api/live`.
   - If unavailable, inspect Nginx/PHP-FPM and EC2 reachability first.
2. Open `https://theclearmove.com/api/ready`.
   - Its `checks` object identifies database, cache, or queue connectivity failures.
3. Check `supervisorctl status` and `php artisan queue:failed`.
4. Check disk usage with `df -h`.
5. Record the start time, observed failure, remediation, recovery time, and affected customers.

Do not treat planned deployments as incidents unless the alarm remains unhealthy after the deployment workflow completes.

## Ongoing verification

- Review the alarm state daily during private beta.
- Repeat the non-disruptive alert drill after changes to DNS, TLS, health endpoints, or monitoring infrastructure.
- Reconfirm the SNS subscription whenever the alert address changes.
- Keep a named human owner; a topic with no confirmed recipient is not monitoring.
