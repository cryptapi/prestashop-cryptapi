# PrestaShop CryptAPI Plugin — Issue Tracker

Audit date: 2026-05-22
Scope: cryptapi.php, controllers/front/*, lib/CryptAPIHelper.php

---

## 🔴 Critical

### [x] #1 — Unauthenticated, unsigned payment callback ✅ FIXED
**File:** `controllers/front/callback.php:25`, `lib/CryptAPIHelper.php`

`$callback = $_REQUEST;` was consumed verbatim. The only checks were `coin` and `nonce` — but the nonce shipped in the callback URL itself, so anyone who learned the URL could replay.

**Fix shipped:** Verify CryptAPI's RSA-SHA256 webhook signature on every callback before any state mutation.
Reference: <https://docs.cryptapi.io/webhooks/verify-webhook-signature>

- Added `CryptAPIHelper::get_pubkey($force_refresh = false)` — fetches `https://api.cryptapi.io/pubkey/` with SSL verification explicitly enabled, caches PEM in `Configuration` with 24h TTL, refetches on rotation.
- Added `CryptAPIHelper::verify_callback_signature()` — reads `HTTP_X_CA_SIGNATURE`, base64-decodes, signs full URL for GET (or `php://input` for POST) with `openssl_verify(..., OPENSSL_ALGO_SHA256)`. Tries multiple URL reconstructions (forwarded-proto/host, then HTTPS/HTTP_HOST) for reverse-proxy compatibility. Force-refreshes the pubkey once on failure to handle key rotation.
- `controllers/front/callback.php` now returns 401 + `*invalid signature*` if verification fails, before any DB read or state change.

---

### [x] #2 — Payment amount taken from callback query string, never verified ✅ FIXED
**File:** `controllers/front/callback.php:47`

`$paid = (float) $callback['value_coin'];` was persisted and subtracted from the total without any authenticity check.

**Fix shipped:** Fundamentally resolved by #1 — once the RSA signature verifies, `value_coin` / `pending` / `uuid` / `txid_in` are authentic. Additional defensive tightening applied:

- Switched callback source from `$_REQUEST` → `$_GET` so cookies/POST body cannot shadow signed query parameters.
- Required-field check for `order`, `coin`, `nonce`, `uuid`, `value_coin`, `pending`, `txid_in` — returns 400 with descriptive marker if missing.
- `value_coin` validated as numeric and `> 0`.
- `pending` validated as exactly `'0'` or `'1'`.

400 (not `*ok*`) on validation failures so CryptAPI retries rather than silently dropping legitimate-but-malformed payloads.

---

### [x] #3 — IDOR on order state endpoint (no ownership check) ✅ FIXED
**Files:** `controllers/front/state.php`, `controllers/front/success.php`, `controllers/front/validation.php`, `views/js/cryptapi.js`, `cryptapi.php`

`$orderId = $_REQUEST['order_id'];` was used to load order metadata with no customer or token check. Any visitor could enumerate `order_id` and read every CryptAPI order's payment data.

**Fix shipped:**
- Added `cryptapi::authorizeOrderAccess($order, $providedKey)` in `cryptapi.php` — returns true if the logged-in customer owns the order, OR if `$providedKey` matches `$order->secure_key` (compared with `hash_equals`).
- `state.php` and `success.php` now call the helper and return **403 Forbidden** on failure, before any metadata read.
- `validation.php` includes `&key=<secure_key>` in the redirect URL to `success.php` (so guests and emailed payment links work).
- `success.php::setMedia` exposes the verified `secure_key` to JS via `Media::addJsDef` (only when authorized).
- `cryptapi.js` forwards the key as `key` in the state poll.
- `fee.php` does **not** take `order_id` — it operates on the current cart from session — so it is not vulnerable to this IDOR. Left unchanged.

Note: `secure_key` is PrestaShop's standard per-order capability token (already passed to `validateOrder`). Using it preserves guest-checkout while closing the IDOR.

---

## 🟠 High

### [x] #4 — Non-atomic read-modify-write on payment history (race condition) ✅ FIXED
**File:** `controllers/front/callback.php`

Sequence was `getPaymentResponse → mutate JSON → updatePaymentResponse`, with `updatePaymentResponse` itself doing another RMW. Two concurrent callbacks could both observe `empty($historyDb[$uuid])` and both insert, or stomp each other's `pending` flag. No transaction, no row-level lock.

**Fix shipped:**
- Callback handler now wraps the entire RMW in `START TRANSACTION` + `SELECT response FROM cryptapi_order WHERE order_id = X FOR UPDATE` + single `UPDATE` + `COMMIT`.
- Try/catch with `ROLLBACK` on any exception path.
- Single coalesced write: `cryptapi_history` mutation and `cryptapi_qr_code_value` refresh are persisted in one `UPDATE` (was two separate RMWs).
- `OrderHistory` state transition and `addOrderPayment` run inside the same transaction so the "is the order paid?" check and the state change can't race a parallel callback.
- **Bonus fix (related defect):** `$order->addOrderPayment(...)` was being called on every callback, including retries for the same `uuid`. It now runs only when the UUID is new, so PrestaShop's `order_payment` table no longer accumulates duplicate rows on CryptAPI retries.
- **Bonus fix (side effect):** `Configuration::get('cryptapi_disable_conversion') === 1` strict-compare bug from #7 is fixed in `callback.php` as a side effect (cronjob site still pending under #7).

**Known tradeoffs not addressed here:**
- The QR-code fetch and the `addWithemail` mail send run inside the lock window. They hold the row lock during outbound HTTP / SMTP. Acceptable given callbacks are per-order serialized and infrequent, but worth knowing if throughput becomes a concern.
- `cryptapi_order` has no index on `order_id`. With InnoDB the lock is still per-row in practice, but explicit `UNIQUE KEY (order_id)` would tighten locking and is a no-cost schema improvement (defer to a separate migration).

---

### [x] #5 — SSL verification disabled on outbound CryptAPI calls ✅ FIXED
**File:** `lib/CryptAPIHelper.php:319-320`

Conversion rate and QR-generation calls were MITM-able due to disabled cert verification.

**Fix shipped:** `CURLOPT_SSL_VERIFYHOST = 2` and `CURLOPT_SSL_VERIFYPEER = 1` (explicit, not default-via-omission, to make intent obvious to future readers and to harden against curl configurations that might invert the default).

> If a merchant's PHP install has an outdated CA bundle and starts seeing curl errors against `api.cryptapi.io` / `api.blockbee.io` after this, the fix is to update the CA bundle (`curl.cainfo` in `php.ini`), not to re-disable verification.

---

### [x] #6 — Weak nonce generation (mt_rand) + per-order nonce now redundant ✅ FIXED
**Files:** `cryptapi.php` (generateNonce), `controllers/front/callback.php`, `controllers/front/validation.php`, `controllers/front/cronjob.php`

Two distinct nonces were in play:
1. **Per-order callback nonce** — was the sole defense against forged callbacks. Now fully redundant: CryptAPI's RSA signature (added in #1) covers the entire URL, so an attacker who can fake the nonce would also need CryptAPI's private key.
2. **Cronjob nonce** — different beast: shared secret authenticating the merchant's own scheduled cron hits. Not covered by CryptAPI signature, still needed.

**Fixes shipped:**
- `cryptapi::generateNonce()` switched from `mt_rand` to `random_int` (still used for the cronjob nonce).
- Removed the per-order callback nonce entirely:
  - `validation.php` no longer calls `generateNonce()` for callbacks.
  - Callback URL is now `module/cryptapi/callback?order=<id>` (no `&nonce=`).
  - `cryptapi_nonce` is no longer written to payment metadata.
  - `callback.php` dropped `'nonce'` from required fields and dropped the `nonce !== metaData.cryptapi_nonce` guard.
- `controllers/front/cronjob.php`: nonce comparison switched to `hash_equals` (constant-time) and now rejects empty/unset saved nonce so a misconfigured install doesn't accept an empty-nonce request.

**In-flight orders:** Existing orders' callback URLs registered with CryptAPI still carry `&nonce=…`. The callback handler simply ignores the now-extraneous query param. No migration needed.

---

### [x] #7 — `disable_conversion` predicate inconsistency ✅ FIXED
**Files:** `cryptapi.php`, `controllers/front/callback.php`, `controllers/front/validation.php`

Three call sites, three different comparisons:
- `callback.php` — `=== 1` (strict, always false against the stored string).
- `validation.php` — `=== '0' ? false : true` (defaulted to **disable** when the setting was unset/missing — wrong direction).
- `cronjob` (in `cryptapi.php`) — passed the raw `Configuration::get` value straight to `get_conversion`, which worked only because PHP treats the string `'0'` as falsy.

**Fix shipped:** Added `cryptapi::isConversionDisabled()` — single source of truth returning `(int) Configuration::get('cryptapi_disable_conversion') === 1`. All three call sites now use the helper. Also fixes the validation.php default-direction bug as a side effect: a never-configured install now defaults to **conversion enabled** (the safer default for a brand-new shop).

---

## 🟡 Medium

### [x] #8 — `getPaymentResponse` doesn't handle a missing row ✅ FIXED
**Files:** `cryptapi.php`, `controllers/front/state.php`, `controllers/front/success.php`

`getRow(...)['response']` emitted a PHP notice and returned `null` when the row was missing. Downstream `json_decode(null)` returned null and callers happily array-accessed on null, producing more notices and silent failures.

**Fix shipped:**
- `getPaymentResponse` now explicitly checks the row exists and returns `null` on miss. Also tightened the SELECT to only fetch `response` (was `SELECT *`).
- Every caller now does `$responseJson === null` followed by `is_array($metaData)` after `json_decode`, with a clear short-circuit:
  - **`state.php`** / **`success.php`** — return `404 Not Found` (was a soft "Order not found" message with a 200 status; now matches the resource semantics).
  - **`cryptapi.php::cryptapiCronjob`** — `continue` the loop for that order; the cron run keeps going for other orders.
  - **`cryptapi.php::sendMail`** — early return (was wrapped in a useless try/catch since `json_decode` never throws; that's gone now).
  - **`cryptapi.php::hookDisplayAdminOrderTabContent`** — early return (no panel rendered for an order with no CryptAPI metadata, instead of a notice-laden empty render).
- Defensive: the second `getPaymentResponse` call inside the cronjob (after `updatePaymentResponse('cryptapi_total', …)`) is also null-checked, even though logically it should always succeed.

---

### [x] #9 — Float precision loss in crypto accounting ✅ FIXED
**Files:** `cryptapi.php`, `controllers/front/callback.php`, `controllers/front/state.php`

`calcOrder` was doing bcmath-subtraction on strings, then casting to `(float)` at the return boundary — defeating the whole point. Downstream `<= 0` / `=== ` comparisons on floats could miss satoshi-level precision (BTC's 8 decimals exceed IEEE 754 float's ~15-digit total precision once amounts get into normal payment ranges).

**Fix shipped:**
- `calcOrder` now returns strings (`already_paid`, `already_paid_fiat`, `remaining`, `remaining_pending`, `remaining_fiat`).
- All decision-driving comparisons switched to `bccomp(…, 8)`:
  - `callback.php` — finalize-order check, QR-amount minimum check.
  - `cryptapi.php` cronjob — price-refresh "no payment yet" check (`remaining === remaining_pending`), min-tx QR check, and order-timeout "nothing paid" cancellation check.
  - `state.php` — pending check, min-tx QR-display check.
- `state.php` JSON output: `crypto_total`, `remaining`, `fiat_remaining` are now strings rather than floats so JS strict-equality `data.remaining !== data.crypto_total` keeps working (string-vs-string instead of mismatched types). Display via `.html(...)` is unaffected.
- `min_tx` reads in `callback.php`, `cryptapi.php`, and `state.php` are now `(string)` to keep the bccomp inputs consistent.

**Behavior note for in-flight orders:** orders whose history was written before this fix have `value_paid` already stored as bcmath-style strings (sig_fig output), so calcOrder still works correctly against existing data. No migration needed.

---

### [x] #10 — No validation of admin-entered wallet addresses ✅ FIXED
**File:** `cryptapi.php`

`Validate::isString` accepts any string, so the previous check was effectively no-op. A typo in an admin-entered wallet meant customer crypto was sent to an unrecoverable address (CryptAPI would still derive a forwarder, then forward to the bad target).

**Fix shipped:** Added `cryptapi::validateCryptoAddress($ticker, $address)`. Two layers:

1. **Generic sanity** (applies to all coins, including unknown tickers): trim, reject whitespace anywhere, length 20–130, printable ASCII only. Catches the common paste-with-extra-text failure mode.
2. **Per-coin format** (when the ticker matches a known family): regex for BTC (legacy + bech32), BCH (legacy + cashaddr), LTC (legacy + bech32), DOGE, ETH-family (incl. polygon/bsc/arbitrum/optimism/avax via `_`-suffix detection), TRX, XMR, IOTA/MIOTA, XRP.

Admin save error now names the offending coin: *"Invalid BTC address: please double-check the wallet address before saving."*

**Known limits:** This is structural validation, not full checksum validation (no EIP-55, no base58check verification). A single-character typo that preserves length/charset still passes. A future improvement is to call CryptAPI's API for authoritative validation at save time, but that requires a live network call from the admin page and a fallback path when CryptAPI is down. Out of scope here.

**Unknown coins:** if CryptAPI adds a new ticker tomorrow that this validator doesn't know about, the generic sanity check still applies and the address is accepted. No code change needed for new coin support.

---

### [x] #11 — State endpoint triggers full cronjob from any unauthenticated request ✅ FIXED
**Files:** `cryptapi.php`, `controllers/front/state.php`

The state endpoint, when its counter rolled over, called `cryptapi::cryptapiCronjob()` — which sweeps **all** in-flight CryptAPI orders, doing outbound CryptAPI API calls and potentially cancelling expired orders. A single hit could amplify into N units of work.

**Fix shipped:**
- Extracted the cron's per-order inner body into `cryptapi::processOrderTick($order, $order_timeout = null, $value_refresh = null)`. `cryptapiCronjob()` now just iterates `getAllOrders()` and delegates.
- `state.php` now calls `processOrderTick(...)` for **only the current order**, passing a minimal row (`id_order`, `total_paid`, `current_state`).
- Added a `current_state === CRYPTAPI_WAITING` guard inside `processOrderTick` so a poll on an already-paid/cancelled order can't accidentally re-run state transitions.
- Auth context from #3 still applies: only customers who own the order (or hold its `secure_key`) can hit `state.php` in the first place, so the amplification surface is now bounded to one's own order.
- Replaced a stray `exit;` inside `cryptapiCronjob` with `return;` — the previous version would terminate the calling response (state.php) prematurely if both timeout and refresh were configured to 0.

**Behavior unchanged for the legit cron path:** `module/cryptapi/cronjob?nonce=…` still runs the full sweep via `cryptapiCronjob()`.

---

## 🟢 Low

### [x] #12 — Manual SQL string concatenation ✅ FIXED
**Files:** `cryptapi.php`, `controllers/front/callback.php`

The raw SQL was safe today (everything was int-cast or pSQL'd) but fragile under future edits. Converted to PrestaShop's `Db::insert` / `Db::update` helpers where possible.

**Fix shipped:**
- `cryptapi.php`
  - Admin-save coin list: INSERT/UPDATE on `cryptapi_coins` now uses `Db::insert` / `Db::update`. The existence check switched to `SELECT id` (was `SELECT *`).
  - `addPaymentResponse` → `Db::insert('cryptapi_order', […])`.
  - `updatePaymentResponse` → `Db::update('cryptapi_order', […], 'order_id=…')`.
  - `getAllOrders`: added explicit `(int)` cast around `Configuration::get(self::CRYPTAPI_WAITING)` in the `WHERE` clause (defensive — the value should always be an int, but the cast makes intent obvious and prevents drift if the underlying type ever changes).
- `controllers/front/callback.php`
  - The UPDATE inside the `START TRANSACTION` block now uses `Db::update`. Still participates in the active transaction (PrestaShop's `Db` is a singleton, so the connection is the same).
  - The `SELECT … FOR UPDATE` stays raw — `Db` has no helper for explicit row locking.
  - `START TRANSACTION` / `COMMIT` / `ROLLBACK` stay raw — they're not SQL queries in the data-DML sense.
- Schema-creation `CREATE TABLE IF NOT EXISTS` statements left as raw SQL (run once at install).

---

### [x] #13 — Loose-comparison style in color scheme check ✅ FIXED
**File:** `cryptapi.php:152`

The chained `!=` comparison worked but was loose-typed and verbose. Replaced with `!in_array($color_scheme, ['light', 'dark', 'auto'], true)` — strict, single-statement, easier to extend if more schemes are added.

---

## Suggested fix order

1. ~~#1 — Sign callbacks~~ ✅
2. ~~#2 — Validate signed fields~~ ✅
3. ~~#3 — Ownership check on state/success endpoints~~ ✅
4. ~~#4 — Transaction-wrap the callback handler~~ ✅
5. ~~#5 — Re-enable SSL verification on the existing `_request`~~ ✅
6. ~~#6 — `random_int` for nonces + drop redundant per-order nonce~~ ✅
7. ~~#7 — Normalize `disable_conversion` comparison~~ ✅
8. ~~#8 — Handle missing-row case in getPaymentResponse~~ ✅
9. ~~#9 — Crypto accounting precision (strings + bccomp)~~ ✅
10. ~~#10 — Admin wallet-address validation~~ ✅
11. ~~#11 — Scope state-endpoint refresh to one order~~ ✅
12. ~~#12 — SQL hardening (Db::insert/update)~~ ✅
13. ~~#13 — Loose-comparison cleanup~~ ✅

**All 13 issues resolved.**

---

## Post-audit pass (A — broader scope review)

Reviewed `mails/`, `views/templates/`, install/uninstall lifecycle, and `composer.json`.

### Fixed

- **Uninstall hygiene** — `cryptapi.php::uninstall()` now also `Configuration::deleteByName`s the two keys I added in #1 (`cryptapi_pubkey`, `cryptapi_pubkey_fetched_at`).
- **Admin tab XSS defense-in-depth** — `views/templates/admin/payment_tab_content.tpl` now `|escape:'htmlall':'UTF-8'`s every variable output (history keys/values, meta_data keys/values, QR base64 src). Risk was low post-#1 (all metadata is signature-verified), but the admin panel is the highest-impact XSS surface; cheap escape pass.

### Flagged, not fixed (rationale per item)

- **Front template (`payment_success.tpl`)** has unescaped output in `data-*` attrs and base64 `src=`. Customer-self-XSS only (a customer can only see their own order via secure-key). Very low risk; fix would touch a lot of lines for negligible benefit.
- **Email template** uses unescaped `{firstname}`/`{order}`/etc. via PrestaShop's `Mail::Send` raw substitution. Customer names already constrained by `Validate::isCustomerName`; email clients block JS anyway. Standard PrestaShop pattern.
- **Pre-existing uninstall bug** — `parent::uninstall() &&` chain discards its result; `uninstall()` returns `true` unconditionally even on partial failure. Not introduced by this work, out of scope here. Worth a future cleanup.
- **No test infrastructure.** Out of scope.
- **Composer**: no dependencies declared; `vendor/` only contains the autoloader. Clean.

---

## Post-audit pass (D — admin CSRF)

**Verified protected, no fix needed.**

The admin save form (`cryptapi.php::getContent()`) is gated by PrestaShop's standard admin token mechanism:

1. Form is rendered via `HelperForm` with `token = Tools::getAdminTokenLite('AdminModules')` and posts to `AdminController::$currentIndex&configure=cryptapi&token=…`.
2. `AdminModulesController` validates the token at dispatch time *before* `Tools::isSubmit('submit'.$this->name)` is checked.

An attacker without the admin's per-employee, per-controller token can't reach `getContent()` at all — no module-side CSRF check is required.

**Platform-level caveats** (out of scope):
- PrestaShop admin tokens travel in the URL, so they can leak via `Referer` to external sites. Known PS platform limitation, not module-fixable.
- Token persists for the session, so admin-side XSS elsewhere could capture it.

---

## Post-audit pass (E — logging on rejection paths)

**File:** `controllers/front/callback.php`

Before this pass, every rejection path in the callback handler exited silently. A signature change on CryptAPI's side, a misrouted callback, or a malformed payload would manifest as "orders never confirm" with no breadcrumbs.

**Fixed:** added `PrestaShopLogger::addLog(...)` at each rejection / notable transition, tagged with `Order` object type and `id_order` so log entries surface in the order's audit view.

| Path | Severity | What it tells you |
|---|---|---|
| Signature verification fail | 3 (error) | Suspicious — invalid/forged callback or CryptAPI rotated keys faster than the 24h cache |
| Missing required field | 2 (warning) | Probably a CryptAPI API change or proxy stripping params |
| `value_coin` not numeric / ≤ 0 | 2 (warning) | Malformed payload |
| `pending` not 0/1 | 2 (warning) | Malformed payload |
| `cryptapi_order` row missing | 2 (warning) | Orphan callback (order deleted? wrong tenant?) |
| `response` JSON malformed | 3 (error) | DB corruption — needs investigation |
| Coin mismatch vs stored metadata | 2 (warning) | Different coin than was registered — suspicious |
| Order already paid / canceled | (no log) | Expected on CryptAPI retries after finalization; would just spam |
| Order finalized as paid | 1 (info) | Business event — useful for audit |
| Caught exception | 3 (error) | Runtime fault; includes `$e->getMessage()` |

All entries pass `allow_duplicate=true` so PrestaShop's logger doesn't dedupe across orders — each order's failure stands on its own.

**What's not logged:** the actual signature, the full `value_coin` value beyond 32 chars, or the txid beyond 80 chars — bounded substrings only, to keep the log table from filling up on a flooded endpoint.

---

## Post-audit pass (C — schema `PRIMARY KEY (order_id)` on cryptapi_order)

Originally deferred under #4 — the transaction-based locking from #4 works without a unique index, but row locking degrades to gap locks in InnoDB when no suitable index exists. A natural primary key tightens that and also formally prevents duplicate rows from buggy retries.

**Fix shipped:**
- `cryptapi.php::install()` — `CREATE TABLE` now includes `PRIMARY KEY (order_id)`. Order ID is the natural key: one row per order.

**Migration deliberately skipped:** No upgrade script for existing installs, since this codebase has no production users yet (per project owner). If/when there are deployed installs of the prior version, a future release should add `upgrade/Upgrade-X.Y.Z.php` to apply `ALTER TABLE … ADD PRIMARY KEY (order_id)` idempotently (and refuse if duplicate `order_id` rows exist).

**What this enables:**
- `SELECT … FOR UPDATE WHERE order_id = X` in the callback handler (#4) now hits an index → row lock only, no gap locks.
- A second concurrent `INSERT` for the same `order_id` (e.g., from a bug in order creation) would fail with a unique-constraint error rather than silently double-row the metadata.

**Decision deferred:** the previous "schema improvement" item also implied changing `cryptapi_history` to a relational table with `UNIQUE (order_id, uuid)` instead of a JSON blob. That's a bigger schema redesign and a bigger payoff (proper indexing, no JSON RMW), but out of scope here.
