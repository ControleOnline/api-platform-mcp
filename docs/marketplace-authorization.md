# Marketplace authorization contract

Marketplace adapters are registered as services tagged
`controleonline.mcp.marketplace_provider`. Each provider uses a stable
lowercase marketplace slug and names tools with the prefix
`marketplace_<slug>_` to prevent collisions.

## Authorization rules

- The authenticated user ID comes from the server-side security context, never
  from tool arguments.
- Every operation requires a positive `company_id` present in the current
  user's server-resolved company scope.
- `McpMarketplaceAuthorizationProvider` derives the user from Symfony's server-side
  security token and checks the current company scope on every authorization call.
- The adapter must call `assertAllowed()` again immediately before every external
  write; a prior authorization result is not a durable grant.
- Connection and marketplace resource IDs from the client are selectors only.
  Each adapter must resolve them in the selected tenant, confirm ownership, and
  enforce operation-specific permissions before reading or changing a resource.
- Marketplace tools are not exposed if provider, authorization, company scope,
  or audit logging is unavailable. Missing security dependencies fail closed.

## Auditing

The dispatcher records an operation start and final outcome, including UTC
timestamp, server-derived user ID, company, marketplace, connection, tool,
resource identifiers and outcome. Successful results may include an external
resource ID. Arguments, tokens, credentials and exception messages are never
logged.

## Adapter requirements

Adapters validate typed schemas, distinguish reads from writes, enforce
connection/resource ownership, re-check authorization immediately before writes,
and do not report unsupported operations as successful. Pagination, rate limits,
token renewal, retries and idempotency remain explicit per-marketplace concerns
because external APIs differ.

Concrete authorization providers and marketplace clients must be registered by
the application that owns tenant-aware services and credentials.
