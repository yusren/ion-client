# Security Architecture Review: `client_identifier` in Step 1 vs Step 3

## Scope & Limitation

This review is based on:
- The authentication flow described by the product owner.
- The `ptpn/ion-client` package codebase (consumer-side SDK).
- Industry best practices for OAuth 2.0 / OIDC / authorization-code flows.

**Limitation:** The SSO server codebase is **not available** in this working directory, therefore this review **cannot trace actual server-side validation logic**, database schema, or verify the exact storage mechanism of `client_identifier`. Any conclusion that depends on server internals is marked as **assumption**.

---

## 1. Understanding the Current Flow (as Described)

### Step 1 — Front-channel redirect (browser-visible)

```
https://sso.example.com/auth/login
  ?client_key=CLIENT_ID
  &client_identifier=CLIENT_SECRET
  &redirect_uri=https://client.example.com/auth/callback
```

The browser, plus every intermediary (proxy, CDN, router, server access log), sees `client_identifier` in plaintext.

### Step 2 — SSO redirects back with exchange token

```
https://client.example.com/auth/callback?code=EXCHANGE_TOKEN
```

Valid for 10 seconds.

### Step 3 — Back-channel handshake (server-to-server)

Currently in `ptpn/ion-client` the exchange token is sent via `verify($code)`:

```php
POST /auth/verify
Content-Type: application/json
X-Client-ID: CLIENT_ID
X-Client-Secret: CLIENT_SECRET
X-Timestamp: ...

{ "code": "EXCHANGE_TOKEN" }
```

The client SDK **already sends client credentials in Step 3** through `X-Client-ID` / `X-Client-Secret` headers.

### Step 4+ — Local session, heartbeat, logout webhook

Server-to-server only.

---

## 2. Analysis of Current Design (Status Quo)

### What does `client_identifier` in Step 1 protect against?

| Threat | Protection Level | Notes |
|---|---|---|
| Unauthorized client initiates login flow on behalf of victim app | Marginal | `client_key` already identifies the client; `redirect_uri` strict matching is the real protection against token theft. |
| Token exchange by a third party who intercepted the exchange token | None | Step 1 secret does not protect Step 3 if exchange token is leaked. Step 3 already has its own secret (`X-Client-Secret`). |
| Phishing / fake SSO login page | None | `client_identifier` does not help the user verify they are on the real SSO page. |
| Session fixation at SSO side | Marginal | If attacker can force victim to login with attacker's `client_identifier`, the SSO might bind the resulting token to the wrong client context. This is theoretical and depends on server implementation. |

**Verdict on current value:** `client_identifier` in Step 1 provides **very limited** additional security. The strict `redirect_uri` check in Step 1 is doing almost all of the real work.

### Attack surface created by `client_identifier` in Step 1

The secret appears in:
- Browser history / address bar autocomplete
- Browser sync services (Chrome/Firefox history)
- Server access logs (SSO server, reverse proxy, WAF, CDN)
- Referer headers when navigating from SSO page to other sites
- Crash reporting / analytics that capture URL
- Corporate proxies with SSL inspection
- Screenshots / screen recordings / shoulder surfing

Even if the SSO server stores `client_identifier` with Argon2id, the **plaintext** travels through all of the above surfaces.

---

## 3. Analysis of Proposed Design (`client_identifier` ONLY in Step 3)

### What changes in security?

| Property | Current Design | Proposed Design |
|---|---|---|
| Secret in browser URL | Yes | No |
| Secret in access logs | Yes (Step 1) | Only back-channel logs |
| Proof of client identity in Step 3 | `X-Client-Secret` + exchange token | `X-Client-Secret` + exchange token (unchanged) |
| Protection against unauthorized Step 1 initiation | `client_key` + `redirect_uri` strict match | `client_key` + `redirect_uri` strict match (equivalent) |
| Protection against intercepted exchange token | 10s TTL + Step 3 secret | 10s TTL + Step 3 secret (equivalent, arguably better because secret never leaked in Step 1) |

### Is [exchange token + Step 3 secret] sufficient proof of legitimate client?

**Yes**, under these assumptions:
1. The exchange token is **single-use** and short-lived (10 seconds).
2. The exchange token is **cryptographically random** and bound to the client/context that initiated the flow.
3. Step 3 happens over **TLS**.
4. The client secret (`client_identifier`) is stored securely on the client backend and never exposed to the browser.

This is functionally equivalent to the OAuth 2.0 Authorization Code Grant, where:
- `client_id` is public in Step 1.
- `client_secret` is sent only in Step 3 (token exchange).
- `redirect_uri` strict matching prevents token leakage.

### OAuth 2.0 RFC 6749 Alignment

RFC 6749 Section 4.1.1 (Authorization Request) only requires:
- `response_type`
- `client_id`
- `redirect_uri` (if required)
- `scope`, `state`

`client_secret` is **explicitly NOT sent** in the authorization request. It is sent only in the token request (Section 4.1.3):

```
grant_type=authorization_code
&code=...
&redirect_uri=...
&client_id=...
&client_secret=...
```

Therefore, moving `client_identifier` to Step 3 makes this system **more compliant with OAuth 2.0** and follows established security practice.

### New attack surface in the 10-second window

The exchange token is the only transient secret in Step 2. Even if an attacker intercepts it:
- It expires in 10 seconds.
- It should be single-use (consumed on first redemption).
- The attacker still needs `client_identifier` to complete Step 3.

**No new attack surface is introduced** by removing `client_identifier` from Step 1. In fact, the attack surface is reduced because the long-lived secret is no longer exposed in URLs.

---

## 4. Risk of NOT Moving (Status Quo)

The most concrete risk is **secret exposure through logs and browser history**.

If `client_key` + `client_identifier` from a Step 1 URL are stolen:

| Capability of attacker | Depends on server-side validation |
|---|---|
| Initiate a login flow as the victim client | Yes, if server accepts Step 1 with valid credentials. But `redirect_uri` must match, so attacker cannot receive the exchange token. |
| Call SSO admin/API endpoints directly | Only if there are endpoints that accept `client_key` + `client_identifier` outside of the login flow. **Unknown without SSO server code.** |
| Impersonate the client in Step 3 | Yes, if attacker also obtains an exchange token AND the server does not bind the exchange token to the initiating client. |
| Replay old login URLs | If `client_identifier` is static and never rotated, old browser history URLs remain valid for initiating flows. |

The key point: even if the practical exploitability is limited by `redirect_uri` checks, **carrying a long-lived secret in a URL is an anti-pattern** and creates unnecessary exposure.

---

## 5. Verdict

### Can `client_identifier` be safely moved to Step 3 only?

**YES** — with the following conditions:

1. The SSO server **already verifies** `client_identifier` (or equivalent `X-Client-Secret`) in Step 3.
2. The exchange token is **single-use**, **short-lived**, and **cryptographically random**.
3. The exchange token in Step 2 is bound to the `client_key`/`redirect_uri` context, so it cannot be redeemed by a different client.
4. Step 3 is performed server-to-server over TLS.
5. Backward compatibility is maintained during transition.

### Security equivalence assessment

The proposed design is **more secure** than the current design because:
- The long-lived client secret is no longer exposed in browser URLs.
- It reduces the attack surface (browser history, access logs, Referer leakage).
- It aligns with OAuth 2.0 best practice.
- The actual security guarantee of the flow (Step 3 secret + exchange token + redirect_uri matching) remains unchanged or improves.

The only scenario where the current design might be considered "more secure" is if the SSO server uses `client_identifier` in Step 1 to perform some **additional server-side binding** that is not described here. Without access to the SSO server code, this remains speculative.

---

## 6. Implementation Plan (If Verdict is YES)

### 6.1 SSO Server Changes (Required — Cannot Be Verified Without Code)

Assuming the SSO server currently validates `client_identifier` in Step 1, the following changes are likely needed:

| Area | Likely Change |
|---|---|
| Step 1 handler | Make `client_identifier` **optional** or remove it from URL parsing. Continue validating `client_key` and strict `redirect_uri`. |
| Step 3 handler | Ensure `client_identifier` is **required** in the back-channel request body or headers. Verify it against stored credential. |
| Token binding | Ensure exchange token is bound to `client_key` and `redirect_uri`, so it can only be redeemed by the legitimate client. |
| Database / storage | No schema change needed if `client_identifier` is already stored for Step 3 validation. |
| Logging | Ensure Step 1 URLs without secret are safe to log (still avoid logging `code` in Step 2). |

**Specific files/functions cannot be listed without SSO server codebase access.**

### 6.2 Client SDK Changes (`ptpn/ion-client`)

Current `verify($code)` already sends `X-Client-ID` and `X-Client-Secret` in Step 3. **No change is required** if the SSO server treats these headers as the Step 3 client credentials.

If the SSO server requires `client_identifier` in the Step 3 JSON body instead of headers, then:
- Update `verify()` in `src/IonClient.php` to include `client_key` and `client_identifier` in the JSON body.
- Update tests and documentation.

However, based on the current SDK implementation, the header-based authentication in Step 3 is already in place and is the preferred approach.

### 6.3 Backward Compatibility

Recommended approach: **make `client_identifier` optional in Step 1** during a transition period.

1. **Phase 1 (transition):**
   - SSO server accepts Step 1 with or without `client_identifier`.
   - If provided, validate it (for old clients).
   - If omitted, rely on `client_key` + strict `redirect_uri`.

2. **Phase 2 (enforce):**
   - After all clients are updated, reject Step 1 URLs that contain `client_identifier` (or ignore it).
   - Require `client_identifier` only in Step 3.

### 6.4 Client App Migration

For `ptpn/ion-client` consumers and the `dummy-ion-app` example:
- Update login redirect URL generation to **omit** `client_identifier`.
- Ensure `ION_CLIENT_ID` and `ION_CLIENT_SECRET` are still sent in Step 3 (already default behavior).
- Update documentation and examples.

---

## 7. Risks and Caveats

| Risk | Mitigation |
|---|---|
| SSO server currently relies on `client_identifier` in Step 1 for some binding not described here | Audit SSO server code before removing. If used for binding, replace with token-to-client binding in Step 3. |
| Legacy clients break | Maintain backward compatibility during transition. |
| Exchange token not single-use | Ensure SSO server consumes token on first use. |
| Exchange token not bound to client context | Ensure token can only be redeemed by the client identified by `client_key` and matching `redirect_uri`. |
| `client_identifier` still logged in Step 3 request body | Send `client_identifier` in headers (current SDK already does this via `X-Client-Secret`) rather than body. |
| Man-in-the-middle on Step 3 | Enforce TLS and certificate validation. |

---

## 8. Additional Recommendation: Consider PKCE

If this system supports public clients (mobile apps, SPAs without backend), consider adding **PKCE** (Proof Key for Code Exchange, RFC 7636) to Step 1 and Step 3. PKCE is the modern defense against authorization code interception, especially when the exchange token travels through the front channel.

For confidential clients (server-side Laravel apps), PKCE is optional but harmless. The combination of:
- Strict `redirect_uri`
- Short-lived single-use exchange token
- Client secret only in Step 3
- TLS

is already strong for confidential clients.

---

## 9. Summary

| Question | Answer |
|---|---|
| Can `client_identifier` be moved to Step 3 only? | **YES**, conditionally. |
| More secure, less secure, or equivalent? | **More secure** — reduces secret exposure in URLs and aligns with OAuth 2.0. |
| Required client SDK changes? | **None**, if SSO server accepts `X-Client-Secret` header in Step 3 (current behavior). |
| Required SSO server changes? | Make `client_identifier` optional in Step 1; require it in Step 3; ensure token binding to client context. |
| Main caveat? | Need to audit actual SSO server code to confirm Step 3 validation and token binding logic. |
