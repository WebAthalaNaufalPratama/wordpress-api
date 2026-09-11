---
name: perbaiki-kode
description: Memperbaiki bug atau menambah fitur di repo GitHub my-headless-store (Next.js) atau wordpress-api, lalu membuka Pull Request. Dipakai saat pengguna minta "perbaiki", "tambahkan fitur", "ubah tampilan", "ada bug di ...".
version: 1.0.0
---
# Memperbaiki kode lewat Pull Request

## Kapan dipakai
Pengguna minta perubahan kode. Hasil akhirnya **selalu Pull Request**, tidak
pernah push langsung ke `main`. Pengguna yang me-merge, bukan agent.

## Repo
- Storefront Next.js: `~/repos/my-headless-store` (remote `origin`)
- Backend WordPress: `~/repos/wordpress-api` — hanya `wp-content/mu-plugins/`
  dan `docs/` yang berisi kode; sisanya tidak ada di git.

## Prosedur
1. Pastikan mulai dari kode terbaru:
   `cd ~/repos/<repo> && git checkout main && git pull --ff-only`
2. Buat branch dengan nama jelas: `git checkout -b fix/<ringkas>` atau
   `feat/<ringkas>`.
3. Pahami dulu sebelum mengubah: `read_file` pada file terkait. Untuk Next.js,
   pola yang ada: Server Component untuk halaman, Server Action di `lib/*/
   actions.ts`, query GraphQL di `lib/*/queries.ts`. Ikuti pola itu.
4. Lakukan perubahan sekecil mungkin yang menyelesaikan permintaan. Jangan
   merapikan kode lain yang tidak diminta.
5. **Wajib** sebelum commit (Next.js):
   `npm run lint && npx tsc --noEmit && npm run build`
   Kalau salah satu gagal, perbaiki dulu. Jangan commit kode yang gagal build.
   Untuk mu-plugin PHP: `php -l <file>`.
6. Commit dengan pesan yang menjelaskan *kenapa*, bukan hanya *apa*.
7. `git push -u origin <branch>` lalu
   `gh pr create --fill --base main`
8. Balas ke pengguna dengan **URL PR** dan ringkasan 2–3 kalimat: apa yang
   diubah, file mana, dan apakah lint/build lolos. Kalau Vercel terpasang,
   sebutkan preview deploy akan muncul di PR.

## Jangan
- Jangan `git push` ke `main`. Jangan `--force`. Jangan `gh pr merge`.
- Jangan menyentuh `.env*`, `wp-config.php`, atau file berisi rahasia.
- Jangan menjalankan `npm install <paket-baru>` tanpa menyebutkannya jelas di
  PR — pengguna perlu tahu ada dependensi baru.
- Jangan mengubah `next.config.ts` atau `package.json` scripts kecuali itu
  memang inti permintaannya.
- Kalau permintaan ambigu ("bikin lebih bagus"), tanya satu pertanyaan
  spesifik dulu. Jangan menebak lalu membuat PR besar.

## Verifikasi
PR terbuka di GitHub, CI/preview hijau, dan `git log main..<branch>` hanya
berisi commit untuk permintaan ini.
