# wordpress-api — Backend Headless

Backend WordPress + WooCommerce yang melayani storefront Next.js lewat GraphQL.
Repo ini **hanya menyimpan konfigurasi dan kode buatan sendiri**, bukan snapshot
instalasi WordPress.

Frontend-nya ada di repo terpisah: `my-headless-store`.

---

## Kenapa repo ini nyaris kosong?

Isinya cuma 4 file. Ini disengaja:

| Tidak ikut git | Alasan |
|---|---|
| `wp-admin/`, `wp-includes/`, `wp-*.php` | Core di-update lewat wp-admin, bukan git |
| `wp-content/plugins/*`, `themes/*` | Plugin pihak ketiga, install dari wp-admin |
| `wp-content/uploads/` | Media & data pembeli |
| `wp-config.php` | **Berisi password database dan JWT secret** |
| `*.sql`, `*.log`, cache, backup | Data runtime |

Yang **akan** dilacak begitu kamu menulisnya: `wp-content/mu-plugins/`,
`wp-content/plugins/custom-*/`, dan `wp-content/themes/custom-*/`.

> Repo ini bukan alat migrasi. Untuk memindahkan situs ke server, pakai
> `rsync` + dump database, atau plugin migrasi — bukan `git clone`.

---

## Kebutuhan

- PHP **8.1+** (minimum absolut 7.4)
- MySQL 5.7+ / MariaDB 10.4+
- WordPress **7.0.4**

---

## Menyiapkan dari nol

### 1. Install WordPress

Install WordPress seperti biasa, lalu salin `wp-config-sample.php` menjadi
`wp-config.php` dan isi kredensial database.

### 2. Install plugin

Semua lewat **wp-admin → Plugins → Add New**. Versi yang dipakai saat ini:

| Plugin | Versi | Catatan |
|---|---|---|
| WooCommerce | 11.1.0 | Mesin dagang: produk, stok, keranjang, pesanan |
| WPGraphQL | 2.22.3 | Membuka WordPress core sebagai GraphQL |
| WPGraphQL JWT Authentication | 0.7.0 | Login & refresh token |
| WooGraphQL (*GraphQL for WooCommerce*) | 1.0.3 | Jembatan WooCommerce ke skema GraphQL |

**Urutan aktivasi penting.** WooGraphQL mengecek `class_exists('\WPGraphQL')`
saat boot, jadi aktifkan WooCommerce dan WPGraphQL lebih dulu. Kalau terbalik,
WooGraphQL diam saja tanpa pesan error.

**WooGraphQL harus dari release zip resmi**, bukan "Download ZIP" GitHub.
Source zip tidak membawa folder `vendor/` sehingga autoloader-nya gagal.

### 3. Tambahkan konstanta ke `wp-config.php`

Sisipkan **sebelum** baris `/* That's all, stop editing! */`:

```php
// Wajib. Tanpa ini plugin JWT tidak akan menerbitkan token.
define( 'GRAPHQL_JWT_AUTH_SECRET_KEY', 'ganti-dengan-string-acak-min-50-karakter' );

// Hanya untuk development — matikan di produksi.
define( 'WP_DEBUG',         true  );
define( 'WP_DEBUG_LOG',     true  );
define( 'WP_DEBUG_DISPLAY', false );
define( 'GRAPHQL_DEBUG',    true  );
```

Ambil string acak dari <https://api.wordpress.org/secret-key/1.1/salt/>.

> Kalau secret ini bocor, siapa pun bisa membuat JWT palsu dan masuk sebagai
> admin. Jangan pernah commit `wp-config.php`.

### 4. Konfigurasi WooCommerce

Frontend mengunci checkout ke satu negara lewat `STORE_COUNTRY` di
`lib/checkout/config.ts`. Nilainya **harus sama** dengan basis toko di sini.

- **WooCommerce → Settings → General** — set negara/wilayah basis (saat ini `ID`)
- **Shipping** — buat minimal satu zona pengiriman dengan satu metode.
  Tanpa ini checkout gagal dengan *"No shipping method has been selected"*
- **Payments** — aktifkan minimal satu gateway (saat ini *Cash on delivery*)
- **Products → Add New** — produk **simple**. Produk *variable* belum didukung
  frontend karena form keranjang belum mengirim `variationId`

---

## Verifikasi

Buka **wp-admin → GraphQL → GraphiQL IDE**, jalankan berurutan:

```graphql
# 1. WPGraphQL core hidup?
{ generalSettings { title url } }

# 2. WooGraphQL hidup?
{ products(first: 3) { nodes { id name } } }

# 3. JWT hidup?
mutation {
  login(input: { username: "USERNAME", password: "PASSWORD" }) {
    authToken
    refreshToken
    sessionToken
    user { id name }
  }
}
```

Kalau ketiganya menjawab, backend siap.

Cek juga dari luar bahwa header `Authorization` tidak dibuang web server:

```bash
curl -s -X POST https://HOST/graphql \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <authToken>" \
  -d '{"query":"{ viewer { id name } }"}'
```

Kalau `viewer` balas `null` padahal token valid, berarti Apache/Nginx men-strip
header tersebut. Plugin JWT membacanya dari `$_SERVER['HTTP_AUTHORIZATION']`.
Untuk Apache, tambahkan di `.htaccess`:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

---

## Umur token

| Token | Umur | Disimpan di |
|---|---|---|
| `authToken` | **300 detik** | cookie httpOnly di Next.js |
| `refreshToken` | 365 hari | cookie httpOnly di Next.js |
| `sessionToken` (keranjang Woo) | — | cookie httpOnly di Next.js |

authToken sengaja berumur sangat pendek. Frontend menukarnya otomatis dengan
`refreshJwtAuthToken` saat kedaluwarsa. Token tidak pernah dikirim ke browser
dalam body JSON.

---

## Development lokal

Situs lokal berjalan di **Laravel Herd** sebagai `https://wordpress-api.test`.

Dua jebakan yang sudah pernah menggigit:

**Node menolak sertifikat Herd.** Sertifikat Herd ditandatangani CA lokal yang
tidak dipercaya Node, sehingga fetch dari Next.js gagal dengan
`UNABLE_TO_VERIFY_LEAF_SIGNATURE`. Frontend mengatasinya dengan
`NODE_OPTIONS=--use-system-ca`. Di server produksi dengan sertifikat asli,
flag ini harus dihapus.

**Bootstrap `wp-load.php` dari PHP CLI menggantung.** Jetpack dan MailPoet
melakukan HTTP keluar saat boot. Untuk script CLI, define ini dulu:

```php
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
require '/path/ke/wp-load.php';
```

---

## Deploy ke VPS

WordPress tidak bisa dijalankan di Vercel — butuh PHP, MySQL, dan filesystem
persisten untuk `wp-content/uploads`. Hanya frontend yang ke Vercel.

> **Panduan langkah demi langkah, termasuk memasang Hermes Agent di VPS yang
> sama:** [`docs/deploy-vps-hermes.md`](docs/deploy-vps-hermes.md).
> Skrip uji endpoint MCP: [`docs/verifikasi-mcp.sh`](docs/verifikasi-mcp.sh).

```
Pembeli ──► Next.js @ Vercel ──GraphQL──► WordPress @ VPS
```

Langkah di VPS: install LEMP/LAMP → install WordPress → install 4 plugin di atas
→ isi `wp-config.php` → import database → salin `wp-content/uploads/`.

### Catatan kalau memakai alamat IP

Setup ini menggunakan IP VPS langsung, tanpa domain. Konsekuensinya:

- **HTTPS tetap wajib.** Halaman Vercel disajikan lewat HTTPS, jadi gambar dari
  `http://IP/...` akan diblokir browser sebagai mixed content — dan JWT akan
  melintas internet tanpa enkripsi. Let's Encrypt bisa menerbitkan sertifikat
  untuk IP sejak Januari 2026, tapi umurnya hanya **160 jam (~6 hari)**.
  Pastikan auto-renew benar-benar jalan; kalau macet, toko mati dalam seminggu.
  Butuh Certbot 5.3+ dengan flag `--ip-address`, dan hanya challenge `http-01`
  atau `tls-alpn-01` (DNS-01 tidak bisa untuk IP).
- **`siteurl` dan `home` akan berisi IP**, dan IP itu ikut tertanam di setiap
  URL media yang di-upload. Kalau nanti pindah ke domain, perlu search-replace
  database yang sadar data terserialisasi WooCommerce.
- **Firewall tidak bisa dibatasi ke Vercel saja** — IP egress Vercel tidak
  statis kecuali berlangganan Secure Compute. Amankan dengan Fail2ban,
  rate limit pada `/wp-login.php`, dan matikan XML-RPC.

### Env var yang dibutuhkan frontend

Isi di Vercel → Settings → Environment Variables:

```
NEXT_PUBLIC_WORDPRESS_URL=https://IP_VPS
NEXT_PUBLIC_WORDPRESS_API_URL=https://IP_VPS/graphql
WORDPRESS_API_URL=https://IP_VPS/graphql
```

`NEXT_PUBLIC_WORDPRESS_URL` **wajib ada saat build** — `next.config.ts`
memakainya untuk `images.remotePatterns`. Kalau kosong, build tetap sukses tapi
semua gambar produk gagal tampil.

---

## MCP untuk agent AI

Selain GraphQL untuk pembeli, WordPress ini juga jadi **MCP server** supaya agent
(Hermes Agent, Claude Code, dsb.) bisa mengurus toko dari sisi admin. MCP
**melengkapi**, bukan mengganti, WPGraphQL — GraphQL tetap yang melayani
storefront.

```
Pembeli ──► Next.js ──GraphQL──► WordPress ◄──MCP── Hermes Agent
```

| Komponen | Peran |
|---|---|
| Abilities API | Sudah ada di core sejak WP 6.9 |
| `mcp-adapter` (v0.6.1, plugin) | Menerjemahkan ability jadi MCP tool |
| `wp-content/mu-plugins/headless-mcp-abilities.php` | **Kode sendiri, masuk git.** Mendefinisikan tool WooCommerce |

Endpoint: `POST /wp-json/mcp/mcp-adapter-default-server`
Auth: REST standar — **Application Password** dengan Basic auth.

### Tool yang tersedia

| Tool | Jenis | Fungsi |
|---|---|---|
| `woo-list-orders` | baca | Daftar pesanan, bisa disaring per status |
| `woo-get-order` | baca | Detail satu pesanan |
| `woo-complete-order` | **tulis** | Tandai pesanan `completed`. Hanya dari `processing`/`on-hold`. Meninggalkan order note di wp-admin |
| `woo-create-product` | **tulis** | Buat produk sederhana baru (nama, harga, deskripsi, stok, gambar dari URL) |

> Nama ability di PHP memakai garis miring (`woo/list-orders`), tapi MCP
> Adapter mengubahnya jadi strip saat diekspos sebagai tool
> (`woo-list-orders`). Di Hermes dan di `tools/call`, pakai bentuk **strip**.

Semua tool butuh capability `manage_woocommerce`. Role **Shop Manager** cukup;
tidak perlu Administrator.

### Setup

1. Install `mcp-adapter` dari release zip resmi
   (`github.com/WordPress/mcp-adapter/releases`), aktifkan.
2. Buat user khusus, mis. `hermes-bot`, role Shop Manager.
3. **Users → Profile → Application Passwords** → buat satu, catat nilainya.
4. Auth header = `Basic base64("hermes-bot:<app-password>")`.

Kalau ingin menambah tool: daftarkan ability baru di file mu-plugin di atas
dan tambahkan namanya ke `HEADLESS_MCP_ABILITIES`. Ability **privat secara
default** — wajib `meta.public => true` agar terlihat oleh MCP.

### Menyambungkan Hermes Agent

`~/.hermes/config.yaml`:

```yaml
mcp_servers:
  woocommerce:
    url: "https://HOST/wp-json/mcp/mcp-adapter-default-server"
    headers:
      Authorization: "${WP_MCP_AUTH}"
```

`${WP_MCP_AUTH}` diambil dari env var — jangan tulis kredensial mentah di YAML.

> **Development lokal dengan Herd:** Hermes berbasis Python dan tidak mempercayai
> CA lokal Herd. Buat bundle gabungan (CA publik + `LaravelValetCASelfSigned.crt`)
> dan arahkan `SSL_CERT_FILE` ke sana. Harus gabungan — kalau hanya CA Herd,
> Hermes justru tidak bisa menghubungi penyedia LLM-nya. Di server dengan
> sertifikat asli, langkah ini tidak perlu.

---

## Batasan yang diketahui

- Hanya produk **simple**; produk variable belum didukung frontend
- Checkout terkunci ke satu negara
- Baru satu gateway pembayaran (COD)
- Belum ada caching di sisi frontend — setiap kunjungan menembak WordPress
- Belum ada rate limiting pada route login
- Belum ada tes otomatis
