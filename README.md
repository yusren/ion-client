# PTPN ION Client

Official PHP/Laravel client SDK untuk mengonsumsi API **ION SSO v2**.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ptpn/ion-client.svg?style=flat-square)](https://packagist.org/packages/ptpn/ion-client)
[![License](https://img.shields.io/packagist/l/ptpn/ion-client.svg?style=flat-square)](LICENSE)

## Fitur

- Dukungan Laravel 8, 9, 10, 11, dan 12.
- PHP 7.3+ dan PHP 8.x.
- Menggunakan GuzzleHTTP sebagai HTTP client.
- Laravel package auto-discovery.
- Facade `IonClient` siap pakai.
- Config publishable.
- Custom exception `IonClientException`.

## Instalasi

Install package melalui Composer:

```bash
composer require ptpn/ion-client
```

## Konfigurasi

Publish file konfigurasi ke project Laravel:

```bash
php artisan vendor:publish --tag=ion-client-config
```

Setelah dipublish, file `config/ion-client.php` akan tersedia. Tambahkan environment variables berikut di `.env`:

```env
# Aktifkan/nonaktifkan ION SSO client
ION_ENABLED=true

# Konfigurasi API ION
ION_BASE_URL=https://ion.palmco.id/api/v2
ION_CLIENT_ID=your-client-id
ION_CLIENT_SECRET=your-client-secret
ION_TIMEOUT=30
ION_VERIFY_SSL=true

# URL frontend yang menjadi tujuan redirect setelah SSO callback
ION_FRONTEND_URL=http://localhost:9000

# Pengaturan cookie session setelah callback SSO
ION_COOKIE_NAME=ion_session
ION_COOKIE_LIFETIME=1440
ION_COOKIE_DOMAIN=localhost
ION_COOKIE_SECURE=false
ION_COOKIE_HTTP_ONLY=true
ION_COOKIE_SAMESITE=Lax
```

## Penggunaan

### SSO Callback

Setelah user berhasil login di ION SSO, SSO server akan redirect ke callback URL aplikasi client dengan membawa query parameter `code`. Package ini menyediakan method `callback()` yang menangani seluruh alur tersebut.

> **ION_ENABLED**: Jika `ION_ENABLED=false`, method `callback()` akan melewati seluruh proses SSO dan langsung redirect ke frontend. Ini memungkinkan aplikasi consumer untuk menggunakan auth Laravel/default atau provider autentikasi lainnya. Gunakan `IonClient::isEnabled()` untuk mengecek status integrasi SSO di kode aplikasi.

1. Menukar `code` dengan `session_id` dari SSO.
2. Membuat session lokal Laravel dengan **ID yang sama persis dengan SSO session ID**.
3. Mengambil data lengkap user dari SSO.
4. Menyimpan data user ke session.
5. Set cookie session ke browser.
6. Redirect browser ke frontend.

Buat route callback di aplikasi Laravel:

```php
use Illuminate\Support\Facades\Route;
use Ptpn\IonClient\IonClient;

Route::get('/auth/callback', function (\Illuminate\Http\Request $request) {
    return app(IonClient::class)->callback($request);
});
```

Atau menggunakan Facade:

```php
use Illuminate\Support\Facades\Route;
use IonClient;

Route::get('/auth/callback', function (\Illuminate\Http\Request $request) {
    return IonClient::callback($request);
});
```

> **Penting:** session lokal dibuat dengan ID yang sama dengan SSO session ID. Hal ini diperlukan agar webhook logout dari SSO dapat menghapus session lokal berdasarkan ID tersebut.

### Menggunakan Facade

```php
use IonClient;

// Cek session
$session = IonClient::checkSession($sessionId);

// Verifikasi auth code
$user = IonClient::verify('AUTH_CODE_HERE');

// Ambil data session lengkap
$fullInfo = IonClient::getSessionFullInfo($sessionId);

// Ambil role user untuk aplikasi tertentu
$roles = IonClient::getUserRoles($sessionId, 'hris');

// Heartbeat agar session tetap aktif
IonClient::heartbeat($sessionId);

// Logout user (trigger pemutusan session ke SSO)
IonClient::logout($sessionId);
```

### Menggunakan Dependency Injection

```php
use Illuminate\Http\Request;
use Ptpn\IonClient\IonClient;

class AuthController extends Controller
{
    protected $ion;

    public function __construct(IonClient $ion)
    {
        $this->ion = $ion;
    }

    public function logout(Request $request)
    {
        try {
            $data = $this->ion->logout($request->input('session_id'));

            return response()->json($data);
        } catch (\Ptpn\IonClient\Exceptions\IonClientException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
```

## Daftar Method

| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| `checkSession($sessionId)` | `GET /auth/check-session` | Cek apakah session SSO masih aktif. |
| `verify($code)` | `POST /auth/verify` | Tukar auth code menjadi session ID + data user. |
| `getSessionFullInfo($sessionId)` | `POST /client/session/full-info` | Ambil data session lengkap. |
| `getUserRoles($sessionId, $application)` | `POST /client/user/roles` | Ambil daftar role user untuk app tertentu. |
| `heartbeat($sessionId)` | `POST /client/heartbeat` | Pertahankan session tetap aktif. |
| `logout($sessionId)` | `POST /client/logout` | Logout user session (trigger putus ke SSO). |
| `callback($request)` | `POST /auth/verify` + `POST /client/session/full-info` | Handle SSO callback, buat session lokal, set cookie, redirect ke frontend. |

Setiap request akan otomatis menyertakan header wajib:

- `X-Client-ID`
- `X-Client-Secret`
- `X-Timestamp`

## Struktur Data SSO

Berikut adalah struktur data yang dikembalikan oleh ION SSO dan cara menggunakannya di aplikasi client.

### 1. Response Verifikasi (`POST /auth/verify`)

Method `verify($code)` mengembalikan response dengan struktur:

| Field | JSON Key | Keterangan |
|---|---|---|
| `User.SessionID` | `user.session_id` | Session ID dari SSO |
| `User.Email` | `user.email` | Email user |
| `Error` | `error` | Pesan error jika gagal |

```json
{
    "user": {
        "session_id": "sso-session-abc123",
        "email": "user@example.com"
    },
    "error": ""
}
```

### 2. Data User Lengkap (`POST /client/session/full-info`)

Method `getSessionFullInfo($sessionId)` mengembalikan data user lengkap:

| Field | JSON Key | Tipe | Keterangan |
|---|---|---|---|
| `Username` | `username` | string | Username / NIK SAP |
| `SessionID` | `session_id` | string | SSO Session ID |
| `HashUserID` | `hash_user_id` | string | Hash ID user |
| `NikSAP` | `nik_sap` | string | NIK SAP karyawan |
| `UserRole` | `user_role` | string | Role user, delimiter `;` |
| `ExpiresAt` | `expires_at` | datetime | Waktu expired session |
| `Locale` | `locale` | string | Bahasa, contoh: `id`, `en` |
| `Name` | `name` | string | Nama lengkap |
| `PositionName` | `position_name` | string | Nama jabatan |
| `DepartmentName` | `department_name` | string | Nama departemen |
| `UnitName` | `unit_name` | string | Nama unit |
| `UnitCode` | `unit_code` | string | Kode unit |
| `CompanyName` | `company_name` | string | Nama perusahaan |
| `Gender` | `gender` | bool | Jenis kelamin |
| `TelegramID` | `telegram_id` | string | ID Telegram |
| `CellphoneNumber` | `cellphone_number` | string | Nomor HP |
| `HashRecipientId` | `recipient_id` | string | Hash recipient ID |
| `CompanyID` | `company_id` | int64 | ID perusahaan asli |
| `UnitID` | `unit_id` | int64 | ID unit asli |
| `DepartmentID` | `department_id` | int64 | ID departemen asli |
| `PositionID` | `position_id` | int64 | ID jabatan asli |
| `LevelElemen` | `level_elemen` | uint8 | Level elemen user |

```json
{
    "message": "success",
    "data": {
        "session_id": "sso-session-abc123",
        "hash_user_id": "abc123",
        "nik_sap": "88888888",
        "username": "88888888",
        "user_role": "GENERAL_USER;PROTON_CREATOR;MEMO_CREATOR",
        "expires_at": "2026-06-12T09:27:21Z",
        "locale": "id",
        "name": "Karyawan Teladan",
        "position_name": "Officer Data Support",
        "department_name": "Divisi Teknologi Informasi & Sistem Manajemen",
        "unit_name": "Head Office",
        "unit_code": "HO",
        "company_name": "PT Perkebunan Nusantara IV",
        "gender": true,
        "telegram_id": "",
        "cellphone_number": "081234567890",
        "recipient_id": "xyz789",
        "company_id": 2,
        "unit_id": 2,
        "department_id": 53,
        "position_id": 1631,
        "level_elemen": 1
    }
}
```

### 3. Data User untuk Frontend

Saat mengirim data user ke frontend, sebaiknya:

- **Sembunyikan ID integer asli** (`company_id`, `unit_id`, `department_id`, `position_id`). Gunakan versi hash-nya.
- **Masking data sensitif** seperti `username`, `nik_sap`, `telegram_id`, `cellphone_number` sebelum dikirim.
- **Field `recipient_id`** tetap dikirim sebagai hash.

Contoh response ke frontend:

```json
{
    "message": "Authenticated",
    "authenticated": true,
    "data": {
        "session_id": "sso-session-abc123",
        "hash_user_id": "abc123",
        "nik_sap": "88888888",
        "username": "88888888",
        "user_role": "GENERAL_USER;PROTON_CREATOR;MEMO_CREATOR",
        "expires_at": "2026-06-12T09:27:21Z",
        "locale": "id",
        "name": "Karyawan Teladan",
        "position_name": "Officer Data Support",
        "department_name": "Divisi Teknologi Informasi & Sistem Manajemen",
        "unit_name": "Head Office",
        "unit_code": "HO",
        "company_name": "PT Perkebunan Nusantara IV",
        "gender": true,
        "telegram_id": "",
        "cellphone_number": "08*****7890",
        "recipient_id": "xyz789",
        "company_id": "hash-company-1",
        "unit_id": "hash-unit-1",
        "department_id": "hash-dept-1",
        "position_id": "hash-position-1",
        "level_elemen": 1
    }
}
```

### 4. User Roles (`POST /client/user/roles`)

Method `getUserRoles($sessionId, $application)` mengembalikan:

| Field | JSON Key | Keterangan |
|---|---|---|
| `ApplicationCode` | `application_code` | Kode aplikasi |
| `UserRoles` | `user_roles` | Role user, delimiter `;` |

```json
{
    "message": "success",
    "data": {
        "application_code": "elemen",
        "user_roles": "GENERAL_USER;ADMIN"
    }
}
```

Di PHP, role bisa diubah menjadi array dengan:

```php
$roles = explode(';', $response['data']['user_roles']);
```

## Catatan Tentang Login

Package client ini **tidak menyediakan fitur login**. Proses autentikasi pengguna (memasukkan kredensial) berjalan di sisi ION SSO melalui redirect/resmi SSO. Client hanya menerima session melalui:

- `verify($code)` — menukar authorization code hasil redirect SSO menjadi session ID dan data user.
- `checkSession($sessionId)` — memvalidasi session yang sudah ada.

Logout tetap disediakan agar aplikasi client dapat memberitahu ION SSO bahwa session pengguna harus dihapus.

## Penanganan Error

Semua error HTTP maupun error dari response ION akan dilempar sebagai `Ptpn\IonClient\Exceptions\IonClientException`. Pesan error akan diambil dari field `message` atau `error` pada response JSON ION jika tersedia.

```php
use Ptpn\IonClient\Exceptions\IonClientException;

try {
    $user = IonClient::verify('AUTH_CODE_HERE');
} catch (IonClientException $e) {
    // $e->getMessage() berisi pesan error dari ION
    logger()->error($e->getMessage());
}
```

## Testing

Jalankan PHPUnit setelah menginstall dependency:

```bash
composer install
vendor/bin/phpunit
```

## License

Package ini dirilis di bawah lisensi [MIT](LICENSE).
