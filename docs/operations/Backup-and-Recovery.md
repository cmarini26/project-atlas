# Backup and Disaster Recovery

**Purpose:** define Atlas's backup strategy and give an operator the actual tooling (scripts, not just prose) to back up, verify, and restore the production database — plus document what's still required before backups are genuinely operational. See [Critical-Production-Blockers.md](../plans/Critical-Production-Blockers.md) Blocker 8 and [Production-Deployment-Audit.md](../reviews/Production-Deployment-Audit.md).

---

## Code-complete vs. operator-complete

**Read this section first.** Nothing below means backups exist in production today.

| | Status |
|---|---|
| Backup script exists, tested, fails loudly | ✅ Code-complete (this document, `infrastructure/backup/`) |
| Verify script exists, tested | ✅ Code-complete |
| Restore script exists, tested, requires explicit confirmation | ✅ Code-complete |
| A local restore drill has been run and round-trips data correctly | ✅ Code-complete (`tests/Feature/Backup/BackupRestoreDrillTest.php` — a real drill against scratch PostgreSQL databases, not a mock) |
| Backups are scheduled and running against the real production database | ⬜ **Operator-executed — not done.** No production database exists yet (Blocker 7 is still infrastructure-pending). |
| A backup has been restored from the *actual* production backup destination | ⬜ **Operator-executed — not done.** |
| Off-site storage is provisioned and receiving backups | ⬜ **Operator-executed — not done.** |
| Encryption keys/recipients are provisioned | ⬜ **Operator-executed — not done.** |

A script existing does not mean a backup exists. A cron entry existing does not mean a backup has run. A backup file existing does not mean it restores correctly. Each of those is a distinct claim, and only the ones marked ✅ above are true today. The remaining rows are exactly [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md)'s "Backups" checklist and this plan's Blocker 8 acceptance criteria — verify each one for real before Customer 1, per that document's Go/No-Go gate.

---

## Backup strategy

### PostgreSQL database

The database (`DB_CONNECTION=pgsql`, per [Production-Topology.md](../deployment/Production-Topology.md)) is the only stateful store Atlas's own code manages today, and the only one this blocker addresses in depth.

- **Mechanism:** `pg_dump` (logical, schema + data, plain SQL format, gzip-compressed) via [`infrastructure/backup/atlas-db-backup.sh`](../../infrastructure/backup/atlas-db-backup.sh). Logical dumps were chosen over physical/WAL-archiving because they're provider-neutral — they work identically against any managed PostgreSQL offering, whereas WAL archiving setup is provider-specific and, on many managed providers, already handled by the provider's own automated backup feature as a configuration toggle (see "What a managed provider may already give you" below).
- **Frequency:** at minimum, daily. Increase frequency (e.g., every 6 hours) once real customer data volume makes a day of data loss unacceptable — this is a judgment call for whoever operates the beta, not a fixed rule.
- **Scope:** the entire `DB_DATABASE` database — every tenant's data, since Atlas is single-database multi-tenant (see `App\Domain\Shared\Scopes\CompanyScope`). There is no per-tenant backup granularity today, nor any plan to add one — a restore recovers every company at once.

### Application-managed uploaded files

**None exist today.** `grep -rn "Storage::" app/` returns nothing — no code path in this application uploads, generates, or stores a file on disk or object storage (a High Priority audit finding: "File storage defaults to local disk with no object storage populated"). There is currently nothing to back up beyond the database.

If this changes — e.g., a future `ContentAsset` gains a generated image, or Filament file-upload fields are used — that data must be added to this strategy at that time: either migrate `FILESYSTEM_DISK` to S3-compatible object storage (which typically has its own provider-level versioning/replication, separate from this document's script-based approach) or extend the backup script to archive the relevant disk path. Don't assume this section is still accurate without re-checking `app/` for `Storage::` usage first.

### Environment/secrets recovery

**Never stored in this repository, and this document does not change that.** `.env.example` ships placeholders only, and every real credential (`DB_PASSWORD`, `POSTMARK_API_KEY`, `APP_KEY`, etc.) lives solely in the real, uncommitted `.env` — recovering from a total server loss requires recovering the *credentials* separately from the *data*. The credentials themselves are not this document's concern to store (this is a backup-and-recovery doc, not a secrets vault), but recovery is impossible without a plan for both:

- Store the production `.env`'s contents (or the secrets that populate it) in a password manager or secrets vault the founding team already uses for other credentials — not in git, not in a plain-text file on the same server being backed up.
- `APP_KEY` deserves special mention: losing it makes every encrypted value (sessions, any `encrypted` cast model attribute) permanently unreadable, independent of whether the database itself is recovered. Back it up wherever the rest of the production `.env` is escrowed.
- This document's job is only to say **that** this must happen and **why** — not to prescribe a specific vault product, which is an operational choice outside this repository's scope.

### What a managed provider may already give you

Most managed PostgreSQL offerings (the stack's documented preference — see `CLAUDE.md`) provide automated WAL-based backups/point-in-time recovery as a dashboard toggle, often satisfying "automated backups are configured and running" with zero code. Where available, treat that as the primary backup mechanism and this repository's `pg_dump`-based scripts as the **portable, provider-independent fallback** — useful for the local/disposable restore drill (below), for a provider that doesn't offer managed backups, or for exporting a copy to a genuinely separate off-site location the primary provider doesn't control (see "Off-site storage requirements").

---

## Operational artifacts

Three scripts exist in [`infrastructure/backup/`](../../infrastructure/backup/), mirroring the style already established by `infrastructure/supervisor/atlas-worker.conf` (Blocker 4) and `infrastructure/cron/atlas-scheduler` (Blocker 4):

| Script | Purpose |
|---|---|
| [`atlas-db-backup.sh`](../../infrastructure/backup/atlas-db-backup.sh) | Dumps the database (via `pg_dump`), gzip-compresses it, optionally encrypts it (GPG) and/or uploads it off-site, optionally prunes old local dumps by retention age. |
| [`atlas-db-verify.sh`](../../infrastructure/backup/atlas-db-verify.sh) | A lightweight integrity check on a dump — confirms it isn't truncated/corrupt and contains schema. **Not** a substitute for the full restore drill below. |
| [`atlas-db-restore.sh`](../../infrastructure/backup/atlas-db-restore.sh) | Restores a dump into a target database. Destructive; requires explicit confirmation (see "Safety" below). |

All three read connection info from the same `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` environment variables Laravel itself uses — no separate credential configuration to maintain.

### Backup usage

```bash
DB_HOST=... DB_PORT=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
  ./infrastructure/backup/atlas-db-backup.sh /path/to/backup/destination
```

Optional environment variables:

- `BACKUP_RETENTION_DAYS` — delete local dumps older than N days after a successful backup. Unset means no pruning (safe default; an operator can prune manually until a retention policy is decided — see "Retention guidance").
- `BACKUP_GPG_RECIPIENT` — if set, encrypts the dump with `gpg --encrypt -r <recipient>` before it's written to its final path. See "Encryption requirements."
- `BACKUP_OFFSITE_COMMAND` — if set, a shell command template run after a successful local backup, with `{file}` replaced by the backup's path (e.g. `aws s3 cp {file} s3://atlas-backups/`, `rclone copy {file} remote:atlas-backups/`, or a `b2 upload-file` invocation). Deliberately not a specific vendor — see "Off-site storage requirements." A failure here is logged loudly but does **not** delete the still-valid local backup.

### Verify usage

```bash
./infrastructure/backup/atlas-db-verify.sh /path/to/backup.sql.gz
```

### Restore usage — DESTRUCTIVE, see Safety below

```bash
# Interactive (prompts for confirmation):
DB_HOST=... DB_PORT=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
  ./infrastructure/backup/atlas-db-restore.sh /path/to/backup.sql.gz

# Non-interactive (for scripted restore drills — see below):
./infrastructure/backup/atlas-db-restore.sh /path/to/backup.sql.gz \
  --yes --confirm-database=<the-exact-target-database-name>
```

---

## Safety

- **Fail loudly.** Every script runs under `set -euo pipefail`, checks required environment variables explicitly before doing anything, and refuses to treat a truncated/empty dump as a successful backup. Any failure exits non-zero with a clear, timestamped log line — see `tests/Feature/Backup/BackupScriptSafetyTest.php` for the exact behaviors verified (missing config, unreachable host, missing/empty files, and every restore-confirmation path below).
- **No destructive restore without explicit confirmation.** `atlas-db-restore.sh` never proceeds without one of:
  - Interactively typing the exact target database name at a prompt, or
  - Passing both `--yes` and `--confirm-database=<name>`, where `<name>` must match `DB_DATABASE` exactly (a typo or a stale value refuses, rather than restoring into the wrong database).
  - A gpg-encrypted (`.gpg`) dump is refused outright — decrypt it first, so the operator has explicitly looked at what they're about to restore.
- **No credentials committed.** The scripts read credentials from environment variables at invocation time only; nothing is written to disk by these scripts except the dump itself (which is data, not credentials) and log lines (which explicitly never include `DB_PASSWORD` or any secret — verified by `Backup-and-Recovery`'s test coverage asserting log output never contains the password value).
- **Environment-configured, not hardcoded.** Connection details and destinations are 100% environment-driven, consistent with every other credential in `.env.example` (`ProductionMailerGuard`/`TrustedProxyResolver` from Blockers 6–7 established this same pattern).
- **Outcomes are logged, secrets are not.** Every script emits a single-line, timestamped `[...] atlas-db-{backup,verify,restore}: <outcome>` message per step — success, failure, and (for backup) off-site upload/retention-pruning outcomes — to stdout, safe to redirect to a log file or forward to whatever log aggregation exists.

---

## Retention guidance

No fixed retention period is prescribed here — it's a cost/risk tradeoff for whoever operates the beta, not a technical constraint. As a starting point:

- Keep at least 7 daily backups locally (or at the primary destination) and 4 weekly backups off-site, discarding anything older — a common, reasonable default absent a specific compliance requirement.
- `BACKUP_RETENTION_DAYS` on `atlas-db-backup.sh` only prunes **local** dumps after each run; off-site retention (e.g., an S3 lifecycle policy) is configured on the off-site destination itself, not by this script.
- Whatever period is chosen, write it down here once it's decided operationally, so this document stays the single source of truth.

## Encryption requirements

- **At rest:** any backup leaving the application server (off-site copy) must be encrypted. `BACKUP_GPG_RECIPIENT` wires this in at the script level; the GPG keypair itself (private key for decryption) must be generated and escrowed by an operator — same principle as the `.env` secrets above: this document says a key must exist, not what the key is.
- **In transit:** whatever `BACKUP_OFFSITE_COMMAND` is configured to run (an `aws s3 cp`, `rclone`, etc.) must use a provider that transports over TLS by default — true for every mainstream object storage provider, but worth confirming explicitly during setup rather than assuming.
- **Local dumps** (before any off-site upload) are not encrypted by default — they're gzip-compressed only. If the application server's local disk itself is a threat surface worth defending against, always set `BACKUP_GPG_RECIPIENT`.

## Off-site storage requirements

- Backups must not live *only* on the same server (or even the same hosting account/region) as the production database — a provider-level outage or account issue must not be able to take out both the database and its only backup simultaneously.
- `BACKUP_OFFSITE_COMMAND` is intentionally provider-agnostic (a shell command template, not a hardcoded SDK dependency) so this repository doesn't need to pick a cloud vendor before one is chosen for real. Whatever destination is chosen, it should be a **different** provider/account/region than the primary database, not just a different bucket in the same account.
- Provisioning the actual off-site destination (an S3 bucket, a Backblaze B2 account, etc.) is explicitly **not** done by this blocker — it's infrastructure provisioning, out of scope here the same way Blocker 7's actual server/domain provisioning was.

---

## Scheduling backups in production

Backups are **not** run through Laravel's own scheduler (`routes/console.php`) — they're an infrastructure-level concern that should keep working even if the application itself is broken (the whole point of a backup is surviving the application being in a bad state). Instead, schedule the backup script directly via the server's own cron, the same pattern as [`infrastructure/cron/atlas-scheduler`](../../infrastructure/cron/atlas-scheduler) established for `schedule:run`:

```cron
# Nightly at 02:30, before ApplyLearnings' 02:00 scheduled job would plausibly
# still be running — adjust to avoid overlapping with any heavy scheduled job.
30 2 * * * DB_HOST=... DB_PORT=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
  BACKUP_RETENTION_DAYS=14 BACKUP_OFFSITE_COMMAND="..." \
  /var/www/atlas/infrastructure/backup/atlas-db-backup.sh /var/backups/atlas \
  >> /var/log/atlas/backup.log 2>&1
```

**Installing this cron entry does not, by itself, mean backups are operational.** Per the "Code-complete vs. operator-complete" table above, an operator must still: confirm the first scheduled run actually completed (check the log, not just that cron fired), confirm the off-site copy actually landed at its destination, and perform at least one real restore drill against the actual backup destination — not just this repository's local drill — before this is a true, verified backup system.

---

## Restore testing checklist

This is the drill an operator runs against real infrastructure once it exists — see [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md)'s "Backups" and "Backup Verification" sections for the full operational checklist this feeds into.

- [ ] A recent production backup file is retrieved from its actual storage location (local, or the off-site destination — prefer the off-site copy, since that's the one that has to work in a real disaster).
- [ ] `atlas-db-verify.sh` confirms the retrieved file is intact.
- [ ] `atlas-db-restore.sh` is run against a genuinely separate, scratch database — never the live production database.
- [ ] The restored data is spot-checked for correctness: row counts on a few key tables, and at least one specific record's content, not just "the restore command exited 0."
- [ ] The scratch database is dropped afterward — a restore drill should leave no lasting artifact.
- [ ] The whole drill is repeatable by a second person following only this document, without asking whoever performed the first restore (a direct requirement from both this plan's Blocker 8 and the Private Beta Execution Checklist's Go/No-Go gate).
- [ ] This checklist is repeated on a regular cadence during the beta (at minimum weekly, per Private-Beta-Execution.md's daily/weekly operational cadence) — not just once before launch.

### Local/disposable-database drill (already automated)

`tests/Feature/Backup/BackupRestoreDrillTest.php` runs a real version of the drill above against two throwaway local PostgreSQL databases (created and dropped within the test itself) every time the test suite runs — proving the scripts round-trip real data, not merely that they parse arguments correctly. It requires a reachable local PostgreSQL server and a `pg_dump`/`psql` client version compatible with that server (client must not be newer than the server it's dumping from, and a dump taken by a newer client than the restore target's server can include settings the older server doesn't recognize — both encountered and worked around while building this drill). It skips gracefully (mirroring `RedisConnectionTest`'s existing pattern) rather than failing the build when that local environment isn't available — this is an environment property, not a defect in the scripts.

This automated drill is a **substitute for manually re-verifying the scripts work at all**, not a substitute for the real, above, against-actual-infrastructure restore drill required before Customer 1.
