# DEVELOPMENT_SETUP.md

# Asia Dental Lab Management System

Version: V1
Stack: Laravel 12 + Livewire 3 + PostgreSQL
Architecture: Modular Monolith

---

# 1. Tujuan

Dokumen ini menjelaskan cara menyiapkan environment development untuk aplikasi Asia Dental Lab Management System.

---

# 2. Requirement

## Wajib

```text
PHP 8.4
Composer
Node.js LTS
NPM
PostgreSQL 16
Git
VS Code
```

## Rekomendasi Windows

```text
Laravel Herd
PostgreSQL
TablePlus / DBeaver
VS Code
Git Bash
```

---

# 3. VS Code Extensions

```text
PHP Intelephense
Laravel Extra Intellisense
Laravel Blade Snippets
Livewire Language Support
Tailwind CSS IntelliSense
DotENV
PostgreSQL
GitLens
Prettier
EditorConfig
```

---

# 4. Clone Project

```bash
git clone https://github.com/your-org/asia-dental-lab.git
cd asia-dental-lab
```

---

# 5. Install Dependency

```bash
composer install
npm install
```

---

# 6. Environment File

```bash
cp .env.example .env
```

Isi `.env`:

```env
APP_NAME="Asia Dental Lab"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://asia-dental-lab.test

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=asia_dental_lab
DB_USERNAME=postgres
DB_PASSWORD=postgres

CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_DRIVER=database
FILESYSTEM_DISK=public
```

Generate key:

```bash
php artisan key:generate
```

---

# 7. Database Setup

Buat database PostgreSQL:

```sql
CREATE DATABASE asia_dental_lab;
```

Test koneksi:

```bash
php artisan migrate:status
```

---

# 8. Install Laravel Breeze

```bash
composer require laravel/breeze --dev
php artisan breeze:install blade
npm install
npm run build
```

---

# 9. Install Spatie Permission

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

---

# 10. Queue Setup

Karena V1 memakai database queue:

```bash
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

Jalankan worker:

```bash
php artisan queue:work
```

---

# 11. Storage Setup

```bash
php artisan storage:link
```

Folder storage:

```text
storage/app/public/
├── lab-orders/
├── order-attachments/
├── signatures/
├── delivery-photos/
├── invoices/
└── exports/
```

---

# 12. Testing Setup

Install Pest:

```bash
composer require pestphp/pest --dev
php artisan pest:install
```

Jalankan test:

```bash
php artisan test
```

---

# 13. Struktur Project

```text
app/
└── Modules/
    ├── Auth/
    ├── Clinic/
    ├── Doctor/
    ├── Patient/
    ├── LabService/
    ├── Technician/
    ├── LabOrder/
    ├── QualityControl/
    ├── Delivery/
    ├── Invoice/
    ├── Payment/
    └── Report/
```

Setiap module:

```text
Controllers/
Requests/
Services/
Repositories/
Interfaces/
Models/
Resources/
Policies/
Tests/
```

---

# 14. Perintah Development Harian

Terminal 1:

```bash
php artisan serve
```

Terminal 2:

```bash
npm run dev
```

Terminal 3:

```bash
php artisan queue:work
```

---

# 15. Git Branching

```text
main
develop
feature/*
```

Contoh:

```bash
git checkout -b feature/sprint-0-foundation
git checkout -b feature/user-management
git checkout -b feature/lab-order
```

---

# 16. Migration Rules

Jalankan migration:

```bash
php artisan migrate
```

Rollback:

```bash
php artisan migrate:rollback
```

Refresh local:

```bash
php artisan migrate:fresh --seed
```

---

# 17. Seeder Awal

Wajib seed:

```text
Super Admin
Admin Lab
Technician
Quality Control
Courier
Finance
Doctor
```

Command:

```bash
php artisan db:seed
```

---

# 18. Local Login Default

```text
Email: admin@asiadentallab.com
Password: password
```

Hanya untuk local dan staging.

---

# 19. Code Quality

Sebelum commit:

```bash
php artisan test
npm run build
```

Opsional:

```bash
./vendor/bin/pint
```

---

# 20. Dokumentasi Project

Folder dokumentasi:

```text
docs/
├── PRD.md
├── DATABASE_SCHEMA_V1.md
├── SYSTEM_ARCHITECTURE.md
├── PROJECT_RULES.md
├── SPRINT_PLAN.md
├── ERD.md
├── API_SPEC.md
├── UI_FLOW.md
└── DEVELOPMENT_SETUP.md
```

---

# 21. Rule Penting

```text
1. Jangan coding sebelum membaca docs.
2. Jangan mengubah database tanpa update DATABASE_SCHEMA_V1.md.
3. Jangan menambah fitur tanpa update PRD.md.
4. Jangan bypass Service Layer.
5. Jangan simpan file sebagai base64 di database.
6. Jangan hard delete data transaksi.
7. Semua perubahan status wajib masuk status log.
8. Semua aksi penting wajib masuk audit log.
```

---

# 22. Sprint 0 Checklist

```text
Laravel 12 installed
PostgreSQL connected
Breeze installed
Spatie Permission installed
Database Queue ready
Storage linked
Module structure ready
Default admin user ready
Test command working
Git branch ready
```
