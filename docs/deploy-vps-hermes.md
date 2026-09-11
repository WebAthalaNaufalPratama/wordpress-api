# Tutorial: WordPress + Hermes Agent di satu VPS (alamat IP, tanpa domain)

Hasil akhir:

```
Pembeli ──► Next.js @ Vercel ──GraphQL (HTTPS)──► WordPress @ VPS
                                                        ▲
                     Kamu ──Telegram──► Hermes @ VPS ──MCP─┘
```

Hermes dan WordPress berada di mesin yang sama, jadi masalah sertifikat lokal
Herd yang ada di laptop **hilang sama sekali** — Hermes memakai sertifikat
Let's Encrypt yang sama dengan yang dipakai Next.js.

**Asumsi**: Ubuntu 24.04 LTS, IPv4 publik, akses `root` lewat SSH. Semua
perintah dijalankan di VPS kecuali disebutkan lain.

> **`IP_VPS` di seluruh tutorial ini adalah placeholder.** Ganti dengan IP
> asli di setiap perintah dan file config — certbot, nginx, URL MCP, env var
> Vercel. Cari IP-mu dengan `curl -4 ifconfig.me`. Kalau tidak diganti,
> certbot menolak dengan *"'IP_VPS' does not appear to be an IPv4 or IPv6
> address"*.

Yang sudah diverifikasi dari dokumentasi resmi (Sep 2026):
- Let's Encrypt menerbitkan sertifikat IP; umur **6 hari**; butuh Certbot **≥ 5.4**
- Hermes: `install.sh` per-user, gateway Telegram via `hermes gateway install`
- `hermes config set` menyimpan rahasia ke `~/.hermes/.env`, dan `${VAR}` di
  `config.yaml` di-resolve dari sana

---

## 0. Amankan VPS dulu

```bash
# user kerja, bukan root
adduser deploy && usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy   # bawa kunci SSH
su - deploy

# firewall: hanya SSH + web
sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443
sudo ufw enable

# lindungi SSH & wp-login dari brute force
sudo apt update && sudo apt install -y fail2ban
sudo systemctl enable --now fail2ban
```

Matikan login password SSH di `/etc/ssh/sshd_config`
(`PasswordAuthentication no`), lalu `sudo systemctl restart ssh`.

---

## 1. LEMP

```bash
sudo apt install -y nginx mariadb-server \
  php8.3-fpm php8.3-mysql php8.3-xml php8.3-curl php8.3-gd php8.3-mbstring \
  php8.3-zip php8.3-intl php8.3-imagick unzip git curl
sudo mysql_secure_installation
```

Database:

```sql
sudo mysql
CREATE DATABASE wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wp'@'localhost' IDENTIFIED BY 'PASSWORD_KUAT';
GRANT ALL ON wordpress.* TO 'wp'@'localhost';
FLUSH PRIVILEGES; EXIT;
```

Naikkan batas upload di `/etc/php/8.3/fpm/php.ini`:
`upload_max_filesize = 64M`, `post_max_size = 64M`, `memory_limit = 256M`.

---

## 2. WordPress

```bash
cd /var/www
sudo curl -LO https://wordpress.org/latest.zip && sudo unzip -q latest.zip
sudo mv wordpress wordpress-api && sudo rm latest.zip
sudo chown -R www-data:www-data wordpress-api
```

nginx — `/etc/nginx/sites-available/wordpress-api` (sementara HTTP saja;
HTTPS ditambah di langkah 3):

```nginx
server {
    listen 80;
    server_name IP_VPS;
    root /var/www/wordpress-api;
    index index.php;
    client_max_body_size 64M;

    location / { try_files $uri $uri/ /index.php?$args; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location = /xmlrpc.php { deny all; }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/wordpress-api /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

> nginx meneruskan header `Authorization` ke PHP apa adanya. Ini penting untuk
> JWT dan Application Password. (Apache membuangnya — salah satu alasan memilih
> nginx.)

**Jangan buka wizard instalasi dulu.** Selesaikan HTTPS lebih dahulu supaya
`siteurl` langsung tercatat sebagai `https://IP_VPS`, bukan `http://`.

---

## 3. HTTPS untuk alamat IP

Certbot dari `apt` terlalu tua. Pakai snap:

```bash
sudo snap install --classic certbot
sudo ln -sf /snap/bin/certbot /usr/bin/certbot
certbot --version    # harus >= 5.4
```

Minta sertifikat. Installer `--nginx` **belum mendukung IP**, jadi pakai
`certonly` + webroot, dan reload nginx lewat deploy-hook:

```bash
sudo certbot certonly \
  --preferred-profile shortlived \
  --webroot --webroot-path /var/www/wordpress-api \
  --ip-address IP_VPS \
  --deploy-hook "systemctl reload nginx"
```

Sertifikat ada di `/etc/letsencrypt/live/<IP>/` — cek nama persisnya dengan
`ls /etc/letsencrypt/live/`. Ganti seluruh isi config nginx dengan versi ini
(pengaturan TLS ditulis langsung, bukan `include options-ssl-nginx.conf`,
karena file itu hanya dibuat oleh plugin `--nginx` yang tidak kita pakai):

```nginx
server {
    listen 80;
    server_name IP_VPS;
    location /.well-known/acme-challenge/ { root /var/www/wordpress-api; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl http2;
    server_name IP_VPS;
    ssl_certificate     /etc/letsencrypt/live/IP_VPS/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/IP_VPS/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    root /var/www/wordpress-api;
    index index.php;
    client_max_body_size 64M;

    location / { try_files $uri $uri/ /index.php?$args; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location = /xmlrpc.php { deny all; }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl list-timers | grep certbot   # timer renewal harus ada
sudo certbot renew --dry-run
```

> **Sertifikat ini hanya berumur 6 hari.** Timer certbot memeriksa 2× sehari
> dan memperbarui otomatis. Kalau timer mati atau port 80 tertutup, toko mati
> dalam seminggu. Pasang pengingat untuk mengecek `sudo certbot certificates`
> minggu pertama.

Sekarang buka `https://IP_VPS` dan selesaikan wizard WordPress.

---

## 4. Plugin dan konfigurasi

Dua plugin pertama ada di WordPress.org; tiga sisanya hanya dirilis di GitHub
dan **tidak muncul** di pencarian wp-admin. Cara paling rapi: WP-CLI.

```bash
# pasang WP-CLI sekali
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar && sudo mv wp-cli.phar /usr/local/bin/wp

cd /var/www/wordpress-api

# urutan penting — WooGraphQL mengecek WPGraphQL saat boot
sudo -u www-data wp plugin install woocommerce wp-graphql --activate
sudo -u www-data wp plugin install https://github.com/wp-graphql/wp-graphql-jwt-authentication/releases/download/v0.7.2/wp-graphql-jwt-authentication.zip --activate
sudo -u www-data wp plugin install https://github.com/wp-graphql/wp-graphql-woocommerce/releases/download/v1.0.3/wp-graphql-woocommerce.zip --activate
sudo -u www-data wp plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip --activate

sudo -u www-data wp plugin list      # kelimanya harus `active`
```

Kalau lebih suka lewat wp-admin → Plugins → Add New → *Upload Plugin*, unduh
zip dari halaman *Releases* masing-masing repo GitHub. Ambil zip rilis
(`wp-graphql-woocommerce.zip`, `mcp-adapter.zip`, dst.), **bukan** "Source
code (zip)" — zip rilis membawa `vendor/`, source zip tidak, dan plugin akan
gagal tanpa pesan.

`wp-config.php`, sebelum `/* That's all, stop editing! */`:

```php
define( 'GRAPHQL_JWT_AUTH_SECRET_KEY', 'string-acak-minimal-50-karakter' );
define( 'WP_DEBUG', false );
define( 'DISALLOW_FILE_EDIT', true );
```

Ambil kode ability (satu-satunya kode buatan sendiri) lewat git. Repo ini
memang dirancang untuk itu: `.gitignore`-nya menyisakan hanya `mu-plugins/`
dan `docs/`. Clone ke `/srv`, **bukan** ke web root, supaya `docs/` tidak
tersaji publik oleh nginx — lalu symlink folder `mu-plugins`-nya:

Kalau repo-nya **private**, pakai *Deploy Key* — kunci SSH read-only khusus
untuk satu repo. Jangan pakai kunci pribadimu atau token Hermes. Sebagai
`deploy`, **tanpa sudo** (sudo membuat git mencari kunci milik root):

```bash
ssh-keygen -t ed25519 -C "deploy@vps wordpress-api" -f ~/.ssh/github_wordpress_api -N ""
cat ~/.ssh/github_wordpress_api.pub
# tempel outputnya di GitHub → repo wordpress-api → Settings → Deploy keys
# → Add deploy key. JANGAN centang "Allow write access".

cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/github_wordpress_api
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
ssh -T git@github.com    # harus "successfully authenticated"
```

Lalu clone:

```bash
sudo mkdir -p /srv/wordpress-api && sudo chown deploy:deploy /srv/wordpress-api
git clone git@github.com:<username>/wordpress-api.git /srv/wordpress-api

sudo rmdir /var/www/wordpress-api/wp-content/mu-plugins 2>/dev/null   # kalau sudah ada & kosong
sudo ln -s /srv/wordpress-api/wp-content/mu-plugins /var/www/wordpress-api/wp-content/mu-plugins

sudo -u www-data wp plugin list --status=must-use   # harus muncul headless-mcp-abilities
sudo -u www-data wp rewrite structure '/%postname%/' # endpoint /wp-json butuh pretty permalink
```

Memperbarui nanti cukup `cd /srv/wordpress-api && git pull`.

Pengaturan WooCommerce yang wajib sama dengan lokal:
- **General** → basis toko `ID` (harus sama dengan `STORE_COUNTRY` di frontend)
- **Shipping** → minimal satu zona + satu metode
- **Payments** → aktifkan COD

Cek: `https://IP_VPS/wp-json/mcp/mcp-adapter-default-server` harus balas
**401**, bukan 404.

---

## 5. Akun untuk Hermes

wp-admin → Users → Add New:
- username `hermes-bot`, role **Shop Manager**
- buka profilnya → **Application Passwords** → nama `Hermes Agent` → *Add* →
  **salin password-nya sekarang, hanya tampil sekali**

Bentuk header yang dipakai Hermes:

```bash
echo -n "hermes-bot:xxxx xxxx xxxx xxxx xxxx xxxx" | base64
# hasilnya dipakai sebagai:  Basic <hasil-base64>
```

Verifikasi endpoint **sebelum** menyentuh Hermes, supaya kalau gagal jelas
salahnya di mana:

```bash
export WP_MCP_AUTH="Basic <hasil-base64>"
export MCP_URL="https://IP_VPS/wp-json/mcp/mcp-adapter-default-server"
bash verifikasi-mcp.sh     # skrip ada di repo, folder docs/
```

Yang harus terlihat: `HTTP 401` tanpa auth, lalu `tools/list` memuat
`woo/list-orders`, `woo/get-order`, `woo/complete-order`, `woo/create-product`.

---

## 6. Hermes Agent

Jalankan sebagai user terpisah yang tidak punya sudo:

```bash
sudo adduser --disabled-password --gecos "" hermes
sudo loginctl enable-linger hermes     # service user tetap hidup tanpa login
sudo su - hermes
```

Sebagai `hermes`:

```bash
curl -fsSL https://hermes-agent.nousresearch.com/install.sh | bash
source ~/.bashrc
hermes doctor

hermes config set OPENROUTER_API_KEY sk-or-...
hermes config set WP_MCP_AUTH "Basic <hasil-base64>"    # masuk ke ~/.hermes/.env
hermes model                                            # pilih model
```

`~/.hermes/config.yaml` — tambahkan:

```yaml
mcp_servers:
  woocommerce:
    url: "https://IP_VPS/wp-json/mcp/mcp-adapter-default-server"
    headers:
      Authorization: "${WP_MCP_AUTH}"
```

Uji dari terminal:

```bash
hermes
> tampilkan pesanan yang masih diproses
> selesaikan pesanan #31
```

Lalu cek wp-admin → Pesanan #31: status **Selesai** dan ada catatan
*"Diselesaikan oleh Hermes Agent lewat MCP"*.

---

## 7. Telegram supaya tidak perlu SSH

Buat bot lewat **@BotFather** di Telegram (`/newbot`), simpan token-nya. Cari
ID Telegram-mu lewat **@userinfobot**.

Sebagai user `hermes`:

```bash
hermes gateway setup          # pilih Telegram, masukkan token
hermes config set TELEGRAM_ALLOWED_USERS 123456789   # ID-mu; selain ini ditolak
hermes gateway install        # jadi systemd user service
hermes gateway start
hermes gateway status
```

Sekarang chat ke bot-mu: *"ada pesanan baru?"* → Hermes memanggil
`woo/list-orders`.

> Gateway **menolak semua orang** yang tidak ada di allowlist. Jangan kosongkan
> `TELEGRAM_ALLOWED_USERS` — bot ini bisa mengubah status pesanan sungguhan.

---

## 8. Satu Hermes, dua peran: buat produk & perbaiki kode

Tidak perlu dua agent. Satu Hermes dengan dua **skill** (`~/.hermes/skills/`),
masing-masing dipanggil sebagai slash command dari Telegram/Discord:

| Perintah di chat | Skill | Alat yang dipakai |
|---|---|---|
| `/produk tambah kaos hitam 95rb stok 20` | `produk` | MCP tool `woo/create-product` |
| `/perbaiki-kode tombol cart tidak update` | `perbaiki-kode` | `terminal` + `git` + `gh` → **Pull Request** |

Keduanya sudah ada di repo: `docs/hermes-skills/produk/SKILL.md` dan
`docs/hermes-skills/perbaiki-kode/SKILL.md`.

### 8a. Skill produk

Tool `woo/create-product` sudah ada di `headless-mcp-abilities.php` (langkah
4). Tinggal salin skill-nya, sebagai user `hermes`:

```bash
mkdir -p ~/.hermes/skills
cp -r /srv/wordpress-api/docs/hermes-skills/produk ~/.hermes/skills/
```

Uji dari Telegram: `/produk tambah botol minum 750ml harga 45rb stok 15`.
Produk langsung tampil di `/products` storefront — tidak perlu deploy ulang.

### 8b. Skill perbaiki kode

Ini bagian yang **mengubah repo GitHub**, jadi pagarnya lebih ketat: agent
hanya boleh membuka PR, tidak pernah push ke `main`.

**Di GitHub**, buat token untuk Hermes:
Settings → Developer settings → Fine-grained tokens → *Generate new token*
- Repository access: **Only select** → `my-headless-store`, `wordpress-api`
- Permissions: Contents **Read and write**, Pull requests **Read and write**,
  Metadata **Read**. Tidak lebih.

**Di repo GitHub `my-headless-store`**, Settings → Branches → *Add rule* untuk
`main`: centang *Require a pull request before merging*. Ini pagar terakhir
kalau skill-nya salah — token Hermes tetap tidak bisa menulis ke `main`.

**Di VPS**, sebagai user `hermes`:

```bash
# alat yang dibutuhkan skill
sudo apt install -y gh                       # (sebagai deploy, sekali)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs

# sebagai hermes:
hermes config set GITHUB_TOKEN github_pat_...
echo "github_pat_..." | gh auth login --with-token
git config --global user.name  "Hermes Agent"
git config --global user.email "hermes-bot@users.noreply.github.com"

mkdir -p ~/repos && cd ~/repos
gh repo clone <username>/my-headless-store
gh repo clone <username>/wordpress-api
cd my-headless-store && npm ci               # supaya lint/build bisa jalan

# skill
cp -r /srv/wordpress-api/docs/hermes-skills/perbaiki-kode ~/.hermes/skills/
```

Uji dari Telegram: `/perbaiki-kode ganti judul halaman /products jadi "Katalog"`.
Yang benar terjadi: Hermes membuat branch, mengubah file, menjalankan
`npm run lint && npx tsc --noEmit && npm run build`, push, lalu membalas
dengan URL PR. Kamu buka PR di HP, lihat preview Vercel, lalu *Merge*.

> Kalau Vercel tersambung ke repo, setiap PR otomatis dapat **preview URL**.
> Jadi kamu bisa melihat hasil perubahan Hermes sebelum menyetujuinya —
> tanpa membuka laptop.

### Kenapa dua skill, bukan dua agent

Kedua peran berbagi model, memori, dan gateway yang sama. Yang membedakan hanya
*instruksi* dan *alat*. Skill adalah cara Hermes memuat instruksi itu hanya
saat dibutuhkan, sehingga percakapan `/produk` tidak terbebani aturan git, dan
sebaliknya.

---

## 9. Frontend di Vercel

Vercel → Settings → Environment Variables:

```
NEXT_PUBLIC_WORDPRESS_URL=https://IP_VPS
NEXT_PUBLIC_WORDPRESS_API_URL=https://IP_VPS/graphql
WORDPRESS_API_URL=https://IP_VPS/graphql
```

Dan di `package.json`, hapus `--use-system-ca` dari `build` dan `start`.

---

## Kalau ada masalah

| Gejala | Penyebab paling mungkin |
|---|---|
| Endpoint MCP balas 404 | mcp-adapter belum aktif, atau permalink masih "Plain" — set ke *Post name* |
| 401 padahal auth benar | Application Password ditolak karena `is_ssl()` false — pastikan akses lewat `https://`, bukan `http://` |
| `tools/list` tidak memuat `woo/*` | mu-plugin belum tersalin, atau WooCommerce belum aktif (ability hanya didaftarkan kalau `wc_get_orders` ada) |
| Hermes: `SSL: CERTIFICATE_VERIFY_FAILED` | Sertifikat kedaluwarsa (6 hari!) — `sudo certbot certificates` |
| Sertifikat tidak diperbarui | Port 80 tertutup di ufw, atau blok `acme-challenge` hilang dari nginx |
| Gambar produk tidak tampil di Vercel | `NEXT_PUBLIC_WORDPRESS_URL` tidak ada saat build |
| Gateway mati setelah logout SSH | `loginctl enable-linger hermes` belum dijalankan |
| PR Hermes gagal build | Skill mewajibkan lint + tsc + build sebelum commit; cek balasan Hermes — ia harus melaporkan mana yang gagal |

---

## Tool MCP yang tersedia

Semua butuh capability `manage_woocommerce`. Definisinya di
`wp-content/mu-plugins/headless-mcp-abilities.php`; menambah tool baru cukup
mendaftarkan ability dan menambahkan namanya ke `HEADLESS_MCP_ABILITIES`.

| Tool | Jenis | Fungsi |
|---|---|---|
| `woo/list-orders` | baca | Daftar pesanan, bisa disaring per status |
| `woo/get-order` | baca | Detail satu pesanan beserta item |
| `woo/complete-order` | **tulis** | Tandai `completed`. Hanya dari `processing`/`on-hold`. Meninggalkan order note |
| `woo/create-product` | **tulis** | Produk sederhana baru: nama, harga, deskripsi, SKU, stok, gambar dari URL |

---

## Nanti kalau pindah ke domain

Cukup tiga hal: A record → `IP_VPS`; `certbot --nginx -d domain` (sertifikat
normal 90 hari, tidak perlu profil shortlived); search-replace `https://IP_VPS`
→ `https://domain` di database dengan alat yang sadar data terserialisasi
(mis. plugin *Better Search Replace*). Kode ability dan config Hermes cukup
diganti URL-nya.
