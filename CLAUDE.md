# Governix - ITSM

ระบบจัดการทรัพย์สินและงานบริการ สำหรับบริษัทที่รับงาน MA (Network / PC / Data Center)
Multi-tenant, SaaS instance เดียว, ทีมพัฒนา 1-2 คน

## Stack (ห้ามเปลี่ยนโดยไม่ถาม)
- Laravel 12, PHP 8.3
- Inertia 2 + Vue 3 + TypeScript + Tailwind
- MariaDB 10.11 (เพราะโฮสต์แบบแชร์ของ Plesk มีแค่ MariaDB) — ไม่มี RLS, partial index,
  `ilike`, `FILTER`, `RETURNING`, `::cast` ของ PostgreSQL (โค้ดเดิมที่ใช้ PostgreSQL ยังรันบน
  PostgreSQL ได้ในส่วนของ RLS แต่ test รันบน MariaDB เท่านั้น)
- Redis: cache / session / queue
- Horizon, Pennant, spatie/laravel-permission, spatie/laravel-activitylog,
  spatie/laravel-medialibrary, maatwebsite/excel
- Gotenberg สำหรับสร้าง PDF ทั้งหมด (ห้ามใช้ DomPDF / mPDF / TCPDF)
- Pest สำหรับ test

## กฎเหล็ก

### 1. Multi-tenant — สำคัญที่สุด
- ทุกตารางที่เก็บข้อมูลลูกค้าต้องมี `tenant_id` (foreignId, not null, index)
- ทุก Model ของตารางนั้นต้อง `use BelongsToTenant` — **นี่คือชั้นกันข้อมูลข้ามบริษัทชั้นเดียว**
  (MariaDB ไม่มี RLS): TenantScope กรองทุก query ของ model, `creating` เติม/ตรวจ `tenant_id`
  (สร้างลงบริษัทอื่นไม่ได้) และ `updating` ห้ามย้าย `tenant_id`
- validation `exists` / `unique` ถูกจำกัดให้เห็นแค่บริษัทปัจจุบันอัตโนมัติ (`TenantPresenceVerifier`)
- ยังเรียก `Rls::enable('table')` ต่อจาก `Schema::create` ได้ตามเดิม (บน MariaDB ไม่ทำอะไร)
- ห้ามใช้ `withoutGlobalScope()` นอก `app/Modules/Platform/CrossTenant/`
  และถ้าใช้ ต้องมี comment อธิบายเหตุผล
- Queue job ทุกตัวต้อง `use InteractsWithTenant` เพื่อพา tenant context ไปด้วย
- **ห้ามเขียน raw SQL / `DB::table()` ที่ไม่มีเงื่อนไข `tenant_id`** — ไม่มี RLS คอยกันแล้ว
  query แบบนี้เห็นข้อมูลทุกบริษัท
- tenant ปัจจุบันอยู่ใน `TenantContext` (singleton) — เปลี่ยน tenant ชั่วคราวด้วย
  `app(TenantContext::class)->run($tenant, fn () => ...)`

### 2. โครงสร้างโมดูล
โค้ดอยู่ใน `app/Modules/{Module}/` เท่านั้น ไม่ใช่ `app/Models` หรือ `app/Http/Controllers`

```
app/Modules/{Module}/
  Models/  Actions/  Http/Controllers/  Http/Requests/
  Policies/  Events/  Listeners/  Jobs/
  routes/web.php  database/migrations/
resources/js/Pages/{Module}/
```

`App\Providers\ModuleServiceProvider` โหลด `routes/web.php` และ `database/migrations`
ของทุกโมดูลอัตโนมัติ

โมดูลที่วางไว้: Tenancy, Identity, Asset, Contract, Service, Maintenance,
Inventory, Labeling, Document, Survey, Reporting, Platform

- ห้าม Model ของโมดูล A เรียก Model ของโมดูล B โดยตรง ให้ผ่าน Action หรือ Event

### 3. Business logic
- อยู่ใน `Actions/` เป็นคลาสที่มีเมธอด `handle()` เดียว
- Controller ทำแค่ validate + เรียก Action + return Inertia response
- ห้ามใส่ business logic ใน Model หรือ Controller

### 4. Test
- ทุกฟีเจอร์ต้องมี Pest feature test
- ทุกโมดูลต้องมี test พิสูจน์ว่า tenant A เข้าถึงข้อมูล tenant B ไม่ได้
- ต้องรัน `php artisan test` ผ่านก่อน commit ทุกครั้ง
- test รันบน MariaDB 10.11 ฐาน `autoservice_test` (ไม่ใช้ sqlite — ต้องเหมือน server จริง)

### 5. ฐานข้อมูล
- PK เป็น bigint auto-increment
- สิ่งที่ปรากฏใน URL สาธารณะ (asset, ticket) ต้องมีคอลัมน์ `ulid` แยก และใช้ ulid ใน URL
- ทุกตารางหลักมี soft delete — ห้ามลบข้อมูลธุรกิจจริง
- JSON ใช้กับ dynamic attribute เท่านั้น
  ฟิลด์ที่ต้องค้นหาหรือกรองบ่อย ให้ทำเป็นคอลัมน์จริง
- เงินเก็บเป็น integer หน่วยสตางค์ ห้ามใช้ float
- ข้อควรระวังของ MariaDB:
  - unique ที่ไม่นับแถวที่ลบแล้ว ใช้ `LiveUnique::add(...)` (ไม่มี partial index)
  - ชื่อ index/constraint ยาวไม่เกิน 64 ตัวอักษร (ตั้งชื่อเองถ้ายาว)
  - เลขรันใช้ `Counter::next(...)`, การกันชนกันตอนออกเลขใช้ `Counter::lockTenant(...)`
  - `sum()` คืนค่าเป็น string — cast เป็น `(int)` ก่อนส่งไปหน้าเว็บ
  - collation `utf8mb4_unicode_ci` ไม่สนตัวพิมพ์อยู่แล้ว ใช้ `like` ได้เลย (ไม่มี `ilike`)
- migration ของ spatie (permission, activitylog, medialibrary) ยังไม่ได้ publish
  ให้ publish ในขั้นที่ใช้งาน พร้อมเพิ่ม `tenant_id`

### 6. UI และภาษา
- ข้อความ UI เป็นภาษาไทยทั้งหมด ผ่าน lang file ห้าม hardcode ในคอมโพเนนต์
- timezone `Asia/Bangkok`
- เอกสาร PDF แสดงปี พ.ศ. · UI ภายในแสดง ค.ศ.
- ทุกหน้า list ต้องมี: ค้นหา, กรอง, เรียง, แบ่งหน้า (server-side ทั้งหมด)

### 7. ต้องถามก่อนทำ
- เพิ่ม package ใหม่
- เปลี่ยน schema ของตารางที่มีข้อมูลแล้ว
- แก้ไฟล์เกิน 10 ไฟล์ในงานเดียว
- สร้างโมดูลใหม่ที่ไม่อยู่ในรายการข้างบน

## สภาพแวดล้อม local (Windows, ไม่มี Docker)
- PHP 8.3 อยู่ที่ `C:\php83\php.exe` — ห้ามใช้ `php` ของ XAMPP (8.0) กับโปรเจกต์นี้
- Composer: `C:\php83\php.exe C:\ProgramData\ComposerSetup\bin\composer.phar ...`
  (ต้องใส่ `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` เพราะ Horizon)
- MariaDB 10.11 portable ที่ `C:\mariadb11` (user `root` / pass `root`, port 3307)
  — ไม่ใช่ MySQL ของ XAMPP (10.4 ที่ port 3306)
  - start: `C:\mariadb11\bin\mariadbd.exe --defaults-file=C:\mariadb11\data\my.ini`
  - stop:  `C:\mariadb11\bin\mariadb-admin.exe -u root -proot -P 3307 shutdown`
- PostgreSQL 16 portable ที่ `C:\pgsql16` ยังอยู่ (ข้อมูลเก่าก่อนย้ายมา MariaDB)
- ยังไม่มี Redis / Gotenberg / Mailpit — ตอนนี้ cache/session/queue ใช้ driver `database`
  Horizon รันบน Windows ไม่ได้ (ต้องใช้ pcntl) ใช้ `php artisan queue:listen` แทน

## คำสั่ง
```
C:\php83\php.exe artisan test     # ต้องผ่านก่อน commit
C:\php83\php.exe vendor/bin/pint  # จัดรูปแบบโค้ด
npm run dev                       # frontend
docker compose up -d              # เมื่อมี Docker: mariadb, redis, gotenberg, mailpit
```
