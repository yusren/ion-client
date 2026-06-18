# Agent Summary: ptpn/ion-client

**Package:** `ptpn/ion-client` — Laravel SDK untuk ION SSO v2 PTPN. Support Laravel 8–12, PHP 7.3+/8.x.

## Struktur

```
src/
  Config/ion-client.php      # base_url, client_id, client_secret, timeout, verify_ssl, frontend_url, cookie
  Exceptions/IonClientException.php  # extend RuntimeException, ada factory methods & getType()
  Facades/IonClient.php      # Laravel Facade
  IonClient.php              # class utama
  IonClientServiceProvider.php
tests/IonClientTest.php
```

## API Methods

| Method | Endpoint | Keterangan |
|--------|----------|------------|
| `checkSession($id)` | `GET /auth/check-session` | Validasi session aktif |
| `verify($code)` | `POST /auth/verify` | Tukar auth code → session ID + user |
| `getSessionFullInfo($id)` | `POST /client/session/full-info` | Data session lengkap |
| `getUserRoles($id, $app?)` | `POST /client/user/roles` | Role user per aplikasi |
| `heartbeat($id)` | `POST /client/heartbeat` | Perpanjang session |
| `logout($id)` | `POST /client/logout` | Putus session di SSO |
| `isEnabled()` | — | Cek apakah ION SSO aktif (config `enabled`) |
| `callback($request)` | verify + full-info | Handle redirect SSO, buat session lokal, set cookie |

Headers otomatis tiap request: `X-Client-ID`, `X-Client-Secret`, `X-Timestamp`.

## Callback Flow (URUTAN PENTING — jangan diubah)

`IonClient::isEnabled()` membaca config `enabled` (env `ION_ENABLED`). Jika `false`, `callback()` langsung redirect ke frontend tanpa proses SSO — consumer bisa pakai auth Laravel/default.

```
1. (skip jika enabled=false)
2. verify($code)            → ssoSessionId
2. Validasi format ID       regex /^[a-zA-Z0-9\-_]{20,256}$/
3. getSessionFullInfo()     → userData  (gagal → redirect, TIDAK buat session)
4. session()->invalidate() + setId($ssoSessionId) + start()
5. session()->put(status, sso_session_id, user_data) + save()
6. isSafeRedirectUrl(return_url cookie) → redirect ke frontend
```

Session ID lokal **harus sama** dengan SSO session ID agar logout webhook bisa hapus session lokal.

Route contoh:
```php
Route::get('/auth/callback', fn(Request $r) => app(IonClient::class)->callback($r));
```

## Env Variables

```env
ION_ENABLED=true
ION_BASE_URL=https://ion.palmco.id/api/v2
ION_CLIENT_ID=your-client-id
ION_CLIENT_SECRET=your-client-secret
ION_TIMEOUT=30
ION_VERIFY_SSL=true
ION_FRONTEND_URL=http://localhost:9000
ION_COOKIE_NAME=ion_session
ION_COOKIE_LIFETIME=1440
ION_COOKIE_DOMAIN=localhost
ION_COOKIE_SECURE=false
ION_COOKIE_HTTP_ONLY=true
ION_COOKIE_SAMESITE=Lax
```

Publish config: `php artisan vendor:publish --tag=ion-client-config`

## Response Shape SSO

`verify()` → `{ user: { session_id, email }, error }` atau `{ session_id }` di root/data/user.

`getSessionFullInfo()` → `{ message, data: { session_id, hash_user_id, nik_sap, username, user_role (;-delimited), expires_at, name, position_name, department_name, unit_name, unit_code, company_name, gender, telegram_id, cellphone_number, recipient_id, company_id, unit_id, department_id, position_id, level_elemen } }`

> **Keamanan data frontend:** Jangan kirim ID integer asli (`*_id`), gunakan hash. Mask data sensitif (`nik_sap`, `username`, `cellphone_number`, `telegram_id`). `user_role` diparse dengan split `;`.

## Aturan untuk Agent

- ❌ Jangan tambah `login()` — auth user bukan tanggung jawab client ini.
- ✏️ Jika ubah method: update `IonClient.php` + `Facades/IonClient.php` (PHPDoc) + `README.md` + `agent.md`.
- ✅ Selalu jalankan `vendor/bin/phpunit` setelah perubahan. (13 tests, 34 assertions)
- `IonClientException` punya factory methods: `networkError()`, `authFailed()`, `invalidResponse()`, `configError()` + `getType()`.
- Constructor `IonClient(array $config, ?Client $http)` — inject mock HTTP client untuk testing, tanpa ReflectionClass.

## Keamanan (Audit 2026-06-18 — JANGAN MUNDURKAN)

| ID | Masalah | Solusi |
|----|---------|--------|
| C1 | Open Redirect via `return_url` cookie | `isSafeRedirectUrl()`: validasi host+scheme vs `frontend_url` config |
| C2 | `X-Client-Secret` bocor di Guzzle exception message | `extractErrorMessage()` ambil dari response body saja; `IonClientException` tidak terima `$previous` |
| C3 | Session Fixation: session ID dari SSO tidak divalidasi | Regex `/^[a-zA-Z0-9\-_]{20,256}$/` sebelum `setId()` |
| H1 | `json_decode` gagal silent (→ `[]`) | Cek `json_last_error()`, lempar `invalidResponse()` |
| H2+H3 | Race condition: session dibuat sebelum data user siap | `getSessionFullInfo()` dipanggil SEBELUM `session()->setId()` |
| L1 | Exception kosong, sulit di-handle consumer | Factory methods + type constants di `IonClientException` |
| L2 | `extractSessionId` cari key `id` yang ambigu | Hanya cari `session_id` di root/`data`/`user` |
| L3 | Fallback silent ke `http://localhost` | `getFrontendUrl()` lempar `configError()` jika kosong |
| L4 | Tidak ada retry saat network error | Guzzle `Middleware::retry()`: 2x, 500ms, pada ConnectException + 5xx |
| M1+M4 | ReflectionClass di test (rapuh) | Injectable `?Client $http` di constructor |
| M2 | `same_site` tidak divalidasi | Whitelist `['Strict','Lax','None']`, fallback ke `Lax` |
| M3 | `timeout` tidak di-cast di config | `(int) env('ION_TIMEOUT', 30)` |
