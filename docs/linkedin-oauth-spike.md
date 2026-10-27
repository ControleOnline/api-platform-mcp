# Spike: LinkedIn OAuth multi-tenant + architecture decision

**Issue:** ControleOnline/app-community#1184 (parent epic #1182)  
**Date:** 2026-10-27  
**Status:** Spike complete — recommendation for Manager/product

## 1. Goal of this spike

Answer before product implementation:

1. What does the **official LinkedIn API** allow for a multi-tenant SaaS (Controle Online) where each client connects their own LinkedIn?
2. Is an **unofficial** (browser/session) path acceptable given ToS and account risk on the **client's** LinkedIn?
3. How should LinkedIn fit `api-platform-mcp` and existing OAuth multi-tenancy?

## 2. Official API map (October 2026)

Sources: LinkedIn Getting Access, Share on LinkedIn, Community Management overview, Microsoft Learn (Marketing versioning).

### 2.1 Self-serve (no LinkedIn review)

| Product | Scopes | Capability |
|---------|--------|------------|
| Sign In with LinkedIn (OpenID Connect) | `openid`, `profile`, `email` | Auth; name, headline, photo, email of the authorizing member |
| Share on LinkedIn | `w_member_social` | Create / comment / react **as the member** on their **personal** profile |

- Token lifetime ~60 days; refresh via standard OAuth.
- Rate (Share on LinkedIn): ~150 requests/member/day, ~100k/app/day (UTC).
- Posts API: `POST https://api.linkedin.com/rest/posts` with member URN as author.

**This is enough for V1 personal-profile posting** without partner approval.

### 2.2 Vetted (application + review)

| Product | Key scopes | Capability |
|---------|------------|------------|
| Community Management API | `w_organization_social`, `r_organization_social`, `rw_organization_admin`, analytics scopes | Post/comment/react **as company page**; list pages the member administers; page analytics |

- Development tier then Standard tier (screencast of the product).
- Requires registered legal organization, verified domain/email, and often Page super-admin verification of the app.
- Use case must be commercial page management — fits Controle Online if the product is “clients manage their company pages from our ERP/agents”.

### 2.3 Not available (official) for this product

| Need from #1182 | Official status |
|-----------------|-----------------|
| **Personal inbox DMs** (read/send) | **No** public messaging write API for member inbox. Page Messaging is for company pages only and still gated. Compliance / Messages partner programs are closed or partner-only with heavy restrictions (no bulk/auto-send). |
| Edit arbitrary profile fields | Not covered by open scopes; profile write is not part of Share on LinkedIn. |
| People search / enrichment / Sales Navigator | Partner programs (SNAP etc.); not for generic SaaS multi-tenant without approval. |
| Read another member’s full profile by URL | Restricted; member IDs are app-scoped. |

## 3. Matrix: official vs unofficial

| Criterion | Official only | Unofficial (session/browser MCP-style) |
|-----------|---------------|----------------------------------------|
| ToS | Compliant | Violates User Agreement; LinkedIn actively restricts automation |
| Account risk | Low (token revoke) | High — restriction/ban of **client** LinkedIn account |
| Personal post | Yes (`w_member_social`) | Yes |
| Company page post | After Community Management approval | Yes (fragile) |
| Personal DMs | No | Often yes in third-party tools — highest ban risk |
| Maintenance | Stable Posts API + version headers | Breaks when LinkedIn changes Voyager/UI |
| Multi-tenant isolation | OAuth tokens per tenant — fits `api-platform-mcp` | Session cookies per tenant — harder to isolate and audit |
| Recommendation for Controle Online | **Default path** | **Only with explicit product risk acceptance** documented per client |

## 4. Fit with existing MCP stack

Already in ecosystem:

- `api-platform-mcp` — `/mcp`, OAuth code+PKCE, tenant via `/mcp/{appDomain}`, security filters (`docs/oauth-multitenancy.md`).
- Deploy-line work on MCP OAuth/tenant (#39, #197, #218, #264, #973).

**Proposed integration (official path):**

1. **LinkedIn as an external identity provider + action provider**, not as a replacement for MCP auth.
2. Store per-tenant (people/company) LinkedIn tokens encrypted, with refresh, revoke, and audit log of every write.
3. MCP tools (when implemented) call internal API that uses the stored LinkedIn token — same tenant resolution as existing MCP.
4. Never put LinkedIn session cookies in the MCP server process as the primary model.

## 5. Recommended phasing (replaces monolithic #1182)

| Phase | Delivery | Depends on |
|-------|----------|------------|
| **A – This spike** | Architecture + decision | Done |
| **B – Connection** | LinkedIn app (OpenID + Share on LinkedIn); multi-tenant connect/disconnect; store tokens; show connected status in Manager | Product creates LinkedIn Developer app |
| **C – Personal post** | MCP + API: create text (and image if media upload path is ready) post as member | Phase B |
| **D – Company page** | Apply Community Management API; list administered pages; post as organization | Phase B + LinkedIn approval |
| **E – Messaging** | **Out of V1** unless product accepts unofficial risk or obtains partner Messages access | Explicit go/no-go |

**Edit profile** (headline, about, etc.): treat as out of V1; not in open write scopes.

## 6. PoC notes (no production code in this spike)

OAuth authorize URL shape (self-serve):

```
https://www.linkedin.com/oauth/v2/authorization
  ?response_type=code
  &client_id={LINKEDIN_CLIENT_ID}
  &redirect_uri={HTTPS_CALLBACK}
  &state={tenant_bound_state}
  &scope=openid%20profile%20email%20w_member_social
```

Token exchange and `POST /rest/posts` with `Linkedin-Version` header are documented under Share on LinkedIn / Posts API.  
A live PoC requires a LinkedIn Developer application (client id/secret) owned by Controle Online — not committed here.

## 7. Decision request (Manager / product)

Please choose one:

1. **Official-only V1** — Phase B+C now; Phase D after Community Management approval; no DMs.  
2. **Official + apply for Community Management** — same as (1) with parallel LinkedIn access request for pages.  
3. **Accept unofficial risk for DMs/full automation** — requires written risk acceptance; not recommended as default for multi-tenant clients.

**Developer recommendation:** **(2)** — ship personal posting on official API; apply for Community Management for company pages; keep messaging out of scope until a compliant path exists.

## 8. Acceptance checklist (spike)

- [x] Inventory of official endpoints/scopes
- [x] Official vs unofficial matrix and risk
- [x] Fit with `api-platform-mcp` / multi-tenant OAuth
- [x] Phased plan replacing monolithic #1182
- [x] Decision options for Manager/product
- [ ] Live OAuth PoC — blocked on LinkedIn app credentials (next step after decision)

## References

- https://learn.microsoft.com/en-us/linkedin/shared/authentication/getting-access
- https://learn.microsoft.com/en-us/linkedin/marketing/community-management/community-management-overview
- https://learn.microsoft.com/en-us/linkedin/marketing/increasing-access
- Share on LinkedIn / Posts API (LinkedIn Developer docs)
