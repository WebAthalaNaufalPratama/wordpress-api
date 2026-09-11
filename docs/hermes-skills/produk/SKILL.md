---
name: produk
description: Membuat produk baru di toko WooCommerce lewat MCP tool woo-create-product. Dipakai saat pengguna minta "tambah produk", "buat produk", "posting barang baru".
version: 1.0.0
---
# Membuat produk toko

## Kapan dipakai
Pengguna ingin menambahkan barang baru ke toko. Contoh pemicu: "tambah produk
kaos hitam harga 95rb", "posting botol minum baru stok 20".

## Prosedur
1. Kumpulkan minimal **nama** dan **harga**. Kalau salah satunya tidak ada,
   tanya sekali — jangan menebak harga.
2. Angka rupiah: "95rb" = 95000, "1,2jt" = 1200000. Kirim tanpa titik/koma.
3. Kalau pengguna tidak menyebut deskripsi, tulis `short_description` satu
   kalimat yang wajar dari nama produk. Jangan mengarang spesifikasi teknis.
4. Kalau ada URL gambar, teruskan ke `image_url`. Kalau gambar dikirim sebagai
   lampiran chat, katakan gambar harus berupa URL publik — tool tidak bisa
   menerima file.
5. Panggil tool **`woo-create-product`** (MCP server `woocommerce`).
6. Balas ringkas: nama, harga, ID, dan tautan storefront dari `store_path`.
   Kalau `image_set` false padahal URL diberikan, sebutkan gambarnya gagal
   diunduh dan produk tetap dibuat tanpa gambar.

## Jangan
- Jangan memanggil `woo-create-product` lebih dari sekali untuk satu
  permintaan. Kalau tool sudah mengembalikan `id`, produk sudah jadi — jangan
  diulang meski balasan terasa lambat.
- Jangan mengubah harga yang disebut pengguna "supaya bulat".
- Jangan membuat produk *variable* (ukuran/warna) — tool hanya mendukung
  produk sederhana. Sarankan pengguna membuat satu produk per varian.

## Verifikasi
Tool mengembalikan `id` dan `store_path`. Produk dengan status `publish`
langsung muncul di `/products` storefront tanpa deploy ulang.
