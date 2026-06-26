# Tenancy Strategy

## Production-Readiness Requirement

Multi-tenancy is partially implemented. The `CompanyScope` global scope isolates data by `company_id` automatically for Eloquent queries. However, customer-facing routes do not yet bind a `current_company_id` from a session or JWT claim. This must be implemented before any customer-facing UI or API is exposed.

---

## How CompanyScope Works

Every domain model uses two shared concerns:

- `BelongsToCompany` — adds `company_id` to `$fillable` and applies `CompanyScope` as a global scope.
- `CompanyScope` — adds a `WHERE company_id = ?` constraint on every Eloquent query for the model.

The scope reads from a singleton `CurrentCompany` resolver (or equivalent) that must be bound to the active company for the current request.

### Internal services and jobs

Services, jobs, and listeners call `withoutGlobalScopes()` explicitly on every query that crosses company boundaries. This is deliberate: background jobs operate on specific records already fetched with a known `company_id`, so the global scope adds no safety and would break cross-company admin operations.

---

## Required: Route-Level Binding

For customer-facing routes, the active company must be resolved and bound before any model query runs. The recommended approach:

```php
// In a middleware: ResolveCurrentCompany
public function handle(Request $request, Closure $next): Response
{
    $companyId = $this->resolveFromRequest($request);
    // e.g., from subdomain, JWT claim, session, or route parameter

    app()->instance(CurrentCompany::class, new CurrentCompany($companyId));

    return $next($request);
}
```

Without this middleware, `CompanyScope` has no company to filter by and will either return all records or throw.

---

## Subdomain / Route Parameter Strategy

Two viable approaches for binding `current_company_id`:

**Subdomain routing** (preferred for SaaS):
- Route: `{company}.atlas.app/*`
- Middleware resolves company from `$request->route('company')` or `$request->getHost()`

**Route parameter** (simpler for MVP):
- Route: `/companies/{company}/dashboard`
- Middleware resolves from `$request->route('company')`

Both require that the company slug or ID be validated and the resolved company stored via `app()->instance()` or a request-scoped singleton.

---

## Not Yet Implemented

- `ResolveCurrentCompany` middleware does not exist yet
- `CurrentCompany` singleton binding is not wired in any route group
- The admin panel (Filament) currently operates without company scoping — this is acceptable for internal admin use but must never be exposed to customers

**Before any customer-facing feature ships**, this binding must be in place, reviewed, and tested with a test that asserts cross-company data isolation.
