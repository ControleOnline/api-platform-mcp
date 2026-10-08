# OAuth and tenant isolation

The MCP server is a protected resource at `/mcp`. OAuth endpoints and the consent UI live in the reusable `users` and `ui-login` modules so other clients can share the same login flow.

## Authorization flow

1. The MCP client registers a public client and declares callback URLs. HTTPS callbacks and loopback HTTP callbacks are accepted.
2. The client starts Authorization Code flow with PKCE S256 and a `state` value.
3. The API validates the request and sends the browser to the manager application. The UI resolves the final screen origin from `MANAGER_APP` in `app-community/config/env.local.js`.
4. The user signs in and approves or denies the requested `mcp:read` scope. The callback host and data scope are shown on the consent screen.
5. The API returns a short-lived, single-use code. Code exchange creates a 15-minute Bearer token with user and tenant claims.

The API resolves the tenant from the signed authorization code before the multi-tenancy database switch. For `/mcp`, a domain in `/mcp/{domain}` selects the tenant; bare `/mcp` uses the API host's main domain. This runs before the database listener and ignores caller-supplied `app-domain`, `Origin`, and `Referer`. Missing and invalid credentials stop request propagation before a tenant database can be selected. OPTIONS preflight remains unauthenticated and does not select a tenant database.

## Data boundaries

The `users` OAuth authenticator reloads the user in the selected tenant database and derives current roles from `PeopleRoleService`. `list_my_companies` uses `getAccessibleCompaniesForPeople()`, the same scope represented by the front-end company switcher.

`sales` and `invoices` invoke their existing `securityFilter` methods after the Bearer user is loaded. `products` have an explicit company filter because the current product service does not enforce one in its `securityFilter`. Results are selected into fixed scalar projections; they do not serialize entities. Documents, free-form invoice/order descriptions, addresses, and contact data are excluded.

The sales aggregate includes closed sale orders and sums root order-product totals. Date boundaries use the configured `APP_TIMEZONE`. Query rows are capped at 100; aggregation is performed in the database and is not capped by the row limit.

## Local checks

```sh
php -d error_reporting=1 vendor/bin/phpunit --fail-on-notice tests/Service/McpServerServiceTest.php
php -d error_reporting=1 vendor/bin/phpunit --fail-on-notice --bootstrap tests/bootstrap.php tests/Service/OAuthServiceTest.php
php -d error_reporting=1 bin/console lint:container --env=test
```

For multiple API nodes, configure `cache.app` and `lock.factory` to use shared backends so authorization-code replay prevention is consistent across nodes.
# MCP tenant selection

The MCP module selects the tenant from `/mcp/{domain}` before the multi-tenancy
request listener switches databases. Bare `/mcp` uses the API host's main domain.
The URL value overrides `app-domain`, `Origin`, and `Referer`, so a client cannot
switch the MCP request to an unrelated tenant by supplying headers. The subscriber
runs before routing because database selection happens earlier than route matching.
