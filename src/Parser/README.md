# Parser

Folder ini akan berisi kelas-kelas untuk membaca dan menganalisis file Eloquent Model
menggunakan `nikic/php-parser`.

## Rencana Isi (Modul 2 & 3)

- `ModelParser.php`        — Entry point; menerima path file, mengembalikan AST.
- `PropertyExtractor.php`  — NodeVisitor yang mengekstrak `$fillable`, `$casts`, dan docblock.
- `RelationExtractor.php`  — Mendeteksi relasi Eloquent (hasMany, belongsTo, dll.).
