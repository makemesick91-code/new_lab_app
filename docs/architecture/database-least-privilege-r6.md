---
kb_status: current
kb_verified_against: 3a231c28
kb_updated: 2026-11-20
---

# Arsitektur least privilege PostgreSQL (R6) — RME-LAB-FEE-APPROVAL-1

Kembali ke [00-INDEX](../00-INDEX.md). Keputusan:
[Owner Design Lock](../decisions/rme-lab-fee-approval-1-owner-design-lock.md) §9. Rule kanonik:
`.cursor/rules/245-database-least-privilege.mdc`. Indeks domain: `.claude/rules/database-security.md`.

**Status:** model empat role disetujui Owner sebagai **baseline perencanaan saja**, bukan izin mengubah role (DL-R6).
`SET ROLE` eksplisit disetujui dengan syarat verifikasi CI (A-17); perubahan identitas backup ditunda (A-18). Seluruh
desain di bawah ini **PROPOSED / NOT IMPLEMENTED**. **Hak produksi aktual: NOT VERIFIED.** Gate 1: NO-GO (R6-01, R6-02).

## 1. Kondisi sekarang (diverifikasi di `3a231c28`, repositori saja)

- Satu identitas database melakukan migrasi (`scripts/deploy-vps.sh` menjalankan `migrate --force` sebagai user runtime)
  sekaligus melayani web, queue, dan scheduler. Identitas itu hampir pasti menjadi owner semua objek.
- Repositori tidak memuat `CREATE ROLE`, `GRANT`, `REVOKE`, `OWNER TO`, RLS, maupun `SECURITY DEFINER`.
- Trigger penjaga hanya ada di tiga tabel klinis. Test repositori menunjukkan bahwa owner tabel bisa menjalankan
  `DISABLE TRIGGER USER`.
- `foundation:postgres-runtime-check` memeriksa performa dan PgBouncer, bukan privilege.

Kondisi ini adalah drift implementasi terhadap rule 245. Selama rule 245 belum diterapkan, kontrol database melindungi
dari bug aplikasi, tetapi tidak dari runtime yang terkompromi.

## 2. Role, keanggotaan, dan hak efektif

| | `dms_owner` | `dms_migrator` | `dms_runtime` | `dms_readonly` |
|---|---|---|---|---|
| LOGIN | Tidak | Ya (deploy saja) | Ya (role login aplikasi yang sekarang dipertahankan) | Ya |
| SUPERUSER / CREATEDB / CREATEROLE / BYPASSRLS / REPLICATION | Tidak | Tidak | Tidak | Tidak |
| Keanggotaan | — | `dms_owner` WITH INHERIT FALSE, SET TRUE | Tidak ada | Opsional `pg_read_all_data` |
| Owner objek | Skema `public` dan semua objek aplikasi | Tidak memiliki objek | Tidak memiliki objek | Tidak memiliki objek |
| CREATE di skema | Ya | Hanya setelah `SET ROLE dms_owner` | **Tidak** | Tidak |
| DML | Penuh | Lewat `SET ROLE` | Sesuai kelas tabel (§4) | SELECT saja |
| ALTER / DISABLE TRIGGER / DROP / TRUNCATE | Ya | Lewat `SET ROLE` | **Tidak** | Tidak |

Hak runtime tidak pernah mencakup hak owner, karena runtime tidak punya keanggotaan yang memungkinkan `SET ROLE`.

## 3. Hubungan migrasi dan owner (A-17)

- Dengan `INHERIT FALSE`, migrator tidak otomatis memakai hak owner. Koneksi `pgsql_migrate` menjalankan
  `SET ROLE dms_owner` **secara eksplisit** saat terhubung, sehingga setiap objek baru dimiliki `dms_owner` dan
  `ALTER DEFAULT PRIVILEGES FOR ROLE dms_owner` berlaku untuknya.
- Perilaku ini wajib dibuktikan oleh job CI PostgreSQL 16 yang memeriksa owner setiap objek baru.
- Migrasi tersambung langsung ke PostgreSQL, tidak lewat PgBouncer (transaction pooling tidak aman untuk state sesi).
- Perubahan role, keanggotaan, dan ownership adalah provisioning yang disetujui Owner, **bukan** migrasi Laravel (CG-09).
- Saklar transisi di konfigurasi host:
  - `1` → migrasi hanya lewat migrator, fail-closed bila migrator tidak tersedia;
  - `0` → perilaku sekarang.

## 4. Kelas tabel untuk runtime

| Kelas | Hak runtime | Contoh (final setelah inventaris R6-T02) |
|---|---|---|
| FULL_DML | SELECT, INSERT, UPDATE, DELETE | `sessions`, `cache`, `jobs`, `failed_jobs`, `trx_rme_invoices`, `trx_rme_invoice_items` (koreksi menghapus item) |
| APPEND_ONLY | SELECT, INSERT | `sys_audit_logs`, tabel `*_events`, versi dokumen invoice, snapshot kuitansi, `trx_rme_record_versions`, `trx_inventory_movements`, `trx_lab_order_status_logs` |
| APPEND_ONLY_WITH_COLUMNS | SELECT, INSERT, UPDATE pada kolom tertentu | `trx_rme_payments`: `UPDATE(patient_id, updated_at)` untuk merge |
| READ_ONLY_RUNTIME | SELECT | `migrations` |

**Hak row-lock.** PostgreSQL mensyaratkan hak UPDATE (minimal satu kolom) untuk `FOR UPDATE`, `FOR NO KEY UPDATE`,
`FOR SHARE`, dan `FOR KEY SHARE`. Konsekuensinya:
- Inventaris R6-T02 wajib memetakan setiap row lock runtime per tabel.
- Tabel append-only yang dikunci runtime mempertahankan UPDATE pada satu kolom, dijaga trigger.
- Dibuktikan di job CI.

## 5. SECURITY DEFINER dan RLS

- **SECURITY DEFINER:** dihindari. Bila terpaksa, fungsi wajib:
  - dimiliki `dms_owner`;
  - memakai `SET search_path = pg_catalog, pg_temp` dan nama objek ber-skema;
  - `REVOKE EXECUTE FROM PUBLIC`, lalu `GRANT EXECUTE TO dms_runtime`;
  - tanpa SQL dinamis, dan punya test negatif.

  Fungsi trigger yang sudah ada diberi `SET search_path` yang sama.
- **RLS:** belum diadopsi; otoritas cabang tetap di aplikasi (CG-05). Bila kelak diadopsi:
  - `FORCE ROW LEVEL SECURITY`;
  - variabel cabang diset per transaksi;
  - role backup butuh `BYPASSRLS` atau `pg_dump --enable-row-security`.

## 6. Proteksi tabel keuangan dan audit

- Trigger penjaga dimiliki `dms_owner` lewat tabelnya.
- Runtime tidak bisa `DISABLE TRIGGER`, `CREATE OR REPLACE FUNCTION`, atau mengubah `session_replication_role` (GUC
  superuser).
- Tabel audit dan event: lapis trigger (rule 248) ditambah hak runtime SELECT+INSERT saja (rule 245).

## 7. Kompatibilitas backup (A-18: perubahan identitas ditunda)

**`dms_readonly` tidak otomatis cukup untuk `pg_dump`.** Yang dibutuhkan:
- `CONNECT` ke database dan `USAGE` di skema;
- SELECT di **semua** tabel dan sequence, termasuk yang dibuat kelak (default privileges), atau `pg_read_all_data`;
- tidak ada tabel ber-RLS.

Dump yang dibuat oleh identitas baru wajib di-restore di rehearsal sebelum dipakai.

Identitas yang dipakai sekarang:
- Dump pra-deploy dan timer db-backup memakai kredensial runtime. Setelah rule 245 diterapkan, runtime tetap punya
  SELECT penuh, jadi keduanya tetap berjalan.
- Identitas database pembuat paket BK-2 (host-only) **NOT VERIFIED**; wajib diverifikasi sebelum perubahan role
  produksi.
- BK-2 tidak dijalankan, diubah, maupun dijadikan gate (rule 232).

## 8. Fase rollout dan rollback

| Fase | Isi | Izin |
|---|---|---|
| R6-A | Bukti: Owner menjalankan paket query §9 | PERM-01 |
| R6-B | Repositori: koneksi `pgsql_migrate`, perintah read-only `foundation:db-privilege-check`, job CI role-separation, saklar deploy | Implementasi |
| R6-C | Rehearsal di salinan restore: provisioning, pemeriksaan privilege, rollback, ukur durasi | Owner |
| R6-D | Produksi: jendela pemeliharaan setelah dump terverifikasi, oleh admin dengan sudo ber-password Owner (rule 235) | PERM-06 |

**Rollback (tanpa restore data):**
1. Saklar = 0.
2. `REASSIGN OWNED BY dms_owner TO <role runtime>`, dengan kepemilikan database ditetapkan eksplisit.
3. Role baru dijadikan NOLOGIN; tidak dihapus sampai ada keputusan Owner.
4. Verifikasi ulang dengan paket query.

Langkah provisioning ditulis sebagai runbook bersama implementasinya, bukan di dokumen ini.

## 9. Paket query read-only untuk Owner (belum dijalankan)

Jalankan dengan `php artisan db` sebagai user runtime. Langkahnya:
1. Ketik `\pset pager off` **di barisnya sendiri**.
2. Bila prompt menampilkan `->`, tekan Ctrl+C.
3. Tempel **hanya** SQL di bawah.
4. Pastikan keluaran memuat `BEGIN` dan `ROLLBACK`. Bila `BEGIN` gagal, hasilnya tidak terjamin read-only dan harus
   dilaporkan begitu.

Query ini tidak membaca kolom pasien, payload audit, maupun password.

```sql
BEGIN READ ONLY;
-- Q1 identitas dan atribut role runtime
SELECT current_user, session_user, current_database(), current_setting('server_version') AS pg;
SELECT rolname, rolsuper, rolinherit, rolcreaterole, rolcreatedb, rolcanlogin, rolreplication, rolbypassrls
FROM pg_roles WHERE rolname = current_user;
-- Q2 keanggotaan (warisan dan kelayakan SET ROLE, PG16)
SELECT r.rolname AS member_of, m.admin_option, m.inherit_option, m.set_option
FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.roleid
WHERE m.member = (SELECT oid FROM pg_roles WHERE rolname = current_user);
-- Q3 owner database dan skema, serta hak CREATE
SELECT datname, pg_get_userbyid(datdba) AS db_owner FROM pg_database WHERE datname = current_database();
SELECT nspname, pg_get_userbyid(nspowner) AS owner, has_schema_privilege(current_user, nspname, 'CREATE') AS can_create
FROM pg_namespace WHERE nspname NOT LIKE 'pg\_%' AND nspname <> 'information_schema';
-- Q4 ringkasan ownership objek di public
SELECT pg_get_userbyid(c.relowner) AS owner, c.relkind, count(*)
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r','p','S','v','m') GROUP BY 1,2 ORDER BY 1,2;
-- Q5 tabel sensitif: owner, RLS, hak efektif
SELECT c.relname, pg_get_userbyid(c.relowner) AS owner, c.relrowsecurity, c.relforcerowsecurity,
       pg_has_role(current_user, c.relowner, 'USAGE') AS has_owner_rights,
       has_table_privilege(current_user, c.oid, 'UPDATE') AS can_update,
       has_table_privilege(current_user, c.oid, 'DELETE') AS can_delete,
       has_table_privilege(current_user, c.oid, 'TRUNCATE') AS can_truncate
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind = 'r'
  AND (c.relname LIKE 'trx\_rme\_%' OR c.relname IN ('sys_audit_logs','trx_lab_case_candidates','mst_treatments','trx_invoices','trx_payments','trx_patient_rm_paper_card_registrations'))
ORDER BY 1;
-- Q6 trigger non-internal dan status aktifnya
SELECT t.tgrelid::regclass AS tbl, t.tgname, t.tgenabled, pg_get_userbyid(p.proowner) AS fn_owner, p.prosecdef, p.proconfig
FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE NOT t.tgisinternal ORDER BY 1,2;
-- Q7 fungsi SECURITY DEFINER atau trigger: owner, search_path, EXECUTE
SELECT n.nspname, p.proname, pg_get_userbyid(p.proowner) AS owner, p.prosecdef, p.proconfig,
       has_function_privilege(current_user, p.oid, 'EXECUTE') AS can_exec
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND (p.prosecdef OR p.prorettype = 'trigger'::regtype)
ORDER BY 1,2;
-- Q8 default privileges dan ACL skema public
SELECT defaclrole::regrole, defaclnamespace::regnamespace, defaclobjtype, defaclacl FROM pg_default_acl;
SELECT nspacl FROM pg_namespace WHERE nspname = 'public';
-- Q9 daftar role (tanpa password)
SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolcanlogin FROM pg_roles WHERE rolname !~ '^pg_' ORDER BY 1;
ROLLBACK;
```

**Kriteria R6 CLEAR (produksi):**
- Q1: atribut runtime semuanya false, kecuali login dan inherit.
- Q2: tidak ada keanggotaan ke role owner.
- Q4/Q5: objek milik runtime = 0, dan hak UPDATE/DELETE/TRUNCATE runtime pada tabel APPEND_ONLY = 0.
- Q6: semua trigger penjaga `tgenabled = 'O'`.
- `foundation:db-privilege-check` exit 0.

Sampai kriteria ini terpenuhi: NOT VERIFIED.
