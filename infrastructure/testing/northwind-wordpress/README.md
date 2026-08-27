# Northwind WordPress integration fixture

This fixture runs a synthetic WordPress site for testing Atlas's real
WordPress connection and publishing path. It binds only to
`127.0.0.1:8090`; ngrok provides the temporary public HTTPS endpoint.

No customer or production data belongs in this environment.

## Start

```bash
docker-compose -f infrastructure/testing/northwind-wordpress/compose.yml up -d
```

Open `http://127.0.0.1:8090` or expose it temporarily:

```bash
ngrok http 8090
```

The ngrok process and the local containers must remain running while Atlas
connects to or publishes to the fixture.

## Build the demo site

After WordPress is installed, create or refresh the realistic Northwind pages,
static homepage, Blog index, and navigation with:

```bash
docker-compose -f infrastructure/testing/northwind-wordpress/compose.yml run --rm cli \
  wp eval-file /opt/northwind-site/bootstrap.php
```

The bootstrap is idempotent and preserves posts published by Atlas.

## Stop

```bash
docker-compose -f infrastructure/testing/northwind-wordpress/compose.yml down
```

Add `--volumes` only when deliberately resetting all WordPress and database
state.

## Atlas connection

Create a dedicated WordPress user with the `Editor` role, then generate a
revocable Application Password for Atlas. Connect with:

- the current ngrok HTTPS URL (it changes when a free tunnel restarts)
- the dedicated Editor username
- the Application Password, not the user's normal WordPress password

Revoke the Application Password when the integration rehearsal is over. Do not
commit it, the ngrok URL, or WordPress login credentials.
