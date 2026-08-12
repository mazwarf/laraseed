# Laraseed 🤖🚀

[![Latest Version on Packagist](https://img.shields.io/packagist/v/azwar/laraseed.svg?style=flat-square)](https://packagist.org/packages/azwar/laraseed)
[![Total Downloads](https://img.shields.io/packagist/dt/azwar/laraseed.svg?style=flat-square)](https://packagist.org/packages/azwar/laraseed)
[![License](https://img.shields.io/packagist/l/azwar/laraseed.svg?style=flat-square)](https://packagist.org/packages/azwar/laraseed)

**The Ultimate Laravel Auto Generator for Factories and Seeders powered by AI (Gemini 1.5 Flash).**

Laraseed adalah *tools* otomatisasi revolusioner untuk Laravel yang menggunakan kecerdasan buatan untuk membaca struktur *Database Migration* Anda dan secara otomatis membuatkan kode **Factory** dan **Seeder** yang sangat akurat, lengkap dengan relasi *Foreign Key* dan pemetaan tipe data Faker yang cerdas.

Berhenti membuang waktu menulis data *dummy* secara manual! Biarkan AI yang melakukannya untuk Anda.

---

## 🌟 Fitur Utama
- **Zero-Configuration:** Tidak perlu menulis anotasi JSON/YAML di Model. Cukup miliki file *Migration* bawaan Laravel.
- **Smart Data Mapping:** AI otomatis mengenali tipe kolom (email, nama, password, tanggal) dan mencocokannya dengan method Faker yang paling relevan.
- **Foreign Key Relationship:** Ekstraksi relasi antar tabel (`foreignId`) secara otomatis menjadi kode `Model::factory()`.
- **Topological Sorting:** Menjamin urutan eksekusi Seeder pada `DatabaseSeeder.php` terurut berdasarkan dependensi relasional agar tidak terjadi *Foreign Key Constraint Error*.
- **Batching Mode:** Eksekusi banyak tabel secara simultan (multi-tabel) untuk mempercepat respons API.

---

## 📦 Instalasi

Anda dapat menginstal package ini melalui Composer:

```bash
composer require azwar/laraseed
```

---

## ⚙️ Konfigurasi (Wajib)

Karena Laraseed ditenagai oleh Google Gemini AI, Anda wajib menyediakan API Key. Dapatkan API Key secara gratis di [Google AI Studio](https://aistudio.google.com/).

Tambahkan baris berikut ke dalam file `.env` Laravel Anda:

```env
GEMINI_API_KEY=AIzaSyxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

*(Opsional) Anda juga bisa mengubah model AI yang digunakan dengan mempublikasikan file config.*

---

## 🚀 Cara Penggunaan

Laraseed beroperasi melalui perintah Artisan bawaan Laravel.

### 1. Men-generate Semua Tabel
Untuk mengeksekusi semua tabel (kecuali tabel sistem Laravel dan tabel yang sudah memiliki Factory):
```bash
php artisan make:laraseed
```

### 2. Spesifik Tabel Tertentu
Anda bisa membatasi *generate* hanya pada satu atau beberapa tabel saja:
```bash
php artisan make:laraseed barangs stock_ins
```

### 3. Mengatur Jumlah Data
Gunakan opsi `--count` untuk menentukan jumlah baris data yang ingin dibuat di dalam file Seeder (Default: 10):
```bash
php artisan make:laraseed --count=50
```

### 4. Memaksa Penimpaan (Overwrite)
Jika Anda sudah pernah membuat file sebelumnya dan ingin membuat ulang (meresetnya), gunakan opsi `--force`:
```bash
php artisan make:laraseed --force
```

### 5. Langkah Terakhir: Tanam Data!
Setelah Laraseed selesai membuat kode Factory, Seeder, dan mendaftarkannya ke `DatabaseSeeder.php`, Anda cukup menjalankan perintah bawaan Laravel:
```bash
php artisan db:seed
```

BAM! 💥 Database Anda kini sudah terisi penuh dengan data *dummy* relasional yang relevan secara kontekstual.

---

## 🛡️ Lisensi

Laraseed adalah perangkat lunak *open-sourced* yang dilisensikan di bawah [MIT license](LICENSE.md). Dikembangkan sebagai Tugas Akhir oleh **Azwar**.
