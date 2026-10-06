# Governix - ITSM

ระบบจัดการทรัพย์สินและงานบริการ MA แบบ multi-tenant
Laravel 12 · Inertia 2 + Vue 3 + TypeScript · MariaDB 10.11

กฎการพัฒนาทั้งหมดอยู่ใน [CLAUDE.md](CLAUDE.md)

## ติดตั้งครั้งแรก

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate

# สร้างฐานข้อมูล (utf8mb4_unicode_ci) แล้วใส่ชื่อฐาน/ผู้ใช้/รหัสผ่านใน .env
mariadb -u root -e "CREATE DATABASE autoservice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE autoservice_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate
php artisan test
```

ต้องใช้ MariaDB **10.11** ขึ้นไป (หรือเวอร์ชันที่ `explicit_defaults_for_timestamp = ON`)
ถ้าเป็น OFF คอลัมน์ timestamp ตัวแรกของตารางจะกลายเป็น "อัปเดตเป็นเวลาปัจจุบันเอง" แบบเงียบ ๆ

## ข้อมูลตัวอย่างและการรันบนเครื่อง dev

```bash
# ล้างฐานแล้วใส่ข้อมูลตัวอย่าง (DemoSeeder ไม่ทำงานบน production)
php artisan migrate:fresh --seed

php artisan serve          # แอป
npm run dev                # frontend
php artisan queue:listen   # งานเบื้องหลัง เช่น นำเข้า Excel, อีเมลสัญญาใกล้หมดอายุ
php artisan schedule:work  # งานตามเวลา: pm:notify-upcoming (07:30), contracts:notify-expiring (08:00), inventory:notify-low-stock (08:00), tickets:notify-sla-breaches (ทุก 15 นาที)
docker compose up -d gotenberg  # สำหรับปุ่ม PDF (ถ้าไม่มี ปุ่ม PDF จะแจ้งว่ายังไม่พร้อม และยังใช้หน้า "พิมพ์" ได้)
```

รหัสผ่านทุกบัญชีคือ `password`

| บัญชี | บทบาท |
|---|---|
| `admin@platform.test` | superadmin ของแพลตฟอร์ม (เข้าดูในนามบริษัทลูกค้าและทำได้ทุกอย่าง, เปิด/ปิดโมดูล) |
| `helpdesk@platform.test`, `tech@platform.test` | Helpdesk / ช่างส่วนกลาง: เข้าได้ทุกบริษัท เห็นทุกสาขา ทำงานประจำวันได้ แต่ตั้งค่าไม่ได้ |
| `admin@itsol.test`, `admin@netpro.test` | ผู้ดูแลระบบบริษัท (เห็นทุกสาขาของบริษัท) |
| `helpdesk@…`, `tech1@…`, `tech2@…`, `user@…` | บทบาทอื่นของแต่ละบริษัท เห็นเฉพาะสาขาของตัวเอง |
| `customer@itsol.test`, `customer@netpro.test` | บัญชีลูกค้า (CUST001) เห็นเฉพาะใบงานและทรัพย์สินของตัวเอง |

## Deploy (production)

ต้องมี: PHP 8.3 (ext: pdo_mysql, intl, mbstring, gd, zip, exif, fileinfo), MariaDB 10.11,
Node 20+ (เฉพาะตอน build), SMTP สำหรับอีเมล
และ DNS แบบ wildcard `*.<โดเมนของระบบ>` ชี้มาที่เซิร์ฟเวอร์ (แต่ละบริษัทเข้าทาง subdomain ของตัวเอง)
ถ้ามี: Redis (cache/session/queue), Gotenberg 8 (PDF), pcntl/posix (Horizon)

php.ini (และ `client_max_body_size` ของ nginx) ต้องรับไฟล์แนบได้ครบ: ไฟล์ละไม่เกิน 2 MB ครั้งละไม่เกิน 10 ไฟล์
และรูปถ่ายไม่เกิน 10 MB

```ini
upload_max_filesize = 10M
post_max_size = 32M
max_file_uploads = 20
```

### บน Plesk แบบโฮสต์แชร์ (ไม่มี SSH / Docker)

- สร้างโดเมน → document root เป็น `<โดเมน>/public`, PHP 8.3
- Git → deploy ลง `<โดเมน>` (ไม่ใช่ `<โดเมน>/public`)
- Databases → สร้างฐาน MariaDB และผู้ใช้ แล้วใส่ใน `.env` (`DB_CONNECTION=mariadb`)
- ไม่มี Redis: ใช้ `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`
- คำสั่ง `artisan` / `composer` รันผ่าน Laravel Toolkit ของ Plesk (หรือ Scheduled Tasks แบบรันครั้งเดียว)
- Scheduled Tasks ทุกนาที: `php artisan schedule:run` และ `php artisan queue:work --stop-when-empty --max-time=55`
- ไม่มี Gotenberg: ปุ่ม PDF จะแจ้งว่ายังไม่พร้อม ใช้หน้า "พิมพ์" ของเบราว์เซอร์แทน

### ครั้งแรก

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
# แก้ .env: APP_ENV=production, APP_DEBUG=false, APP_URL, TENANCY_CENTRAL_DOMAINS, SESSION_DOMAIN,
#            DB_*, cache/session/queue, MAIL_*, GOTENBERG_URL (ถ้ามี)

php artisan migrate --force
php artisan storage:link

# บริษัทแพลตฟอร์มและ superadmin คนแรก (ถามชื่อ อีเมล รหัสผ่าน)
php artisan platform:install

php artisan config:cache && php artisan route:cache && php artisan view:cache
```

จากนั้น superadmin เข้าระบบที่ `admin.<โดเมน>` แล้วไปที่ "บริษัทลูกค้า" → "เพิ่มบริษัท"
เพื่อสร้างบริษัทลูกค้า บัญชีผู้ดูแลคนแรก และกำหนดวันเริ่ม/สิ้นสุดการใช้งาน

### บริการที่ต้องรันตลอด

| บริการ | คำสั่ง |
|---|---|
| Queue worker | `php artisan horizon` (ใช้ supervisor/systemd ดูแล) หรือ `queue:work` ตามด้านบนบน Plesk |
| งานตามเวลา | cron `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` |
| Gotenberg | `docker run -d -p 3000:3000 gotenberg/gotenberg:8` (หรือดู docker-compose.yml) |

### ทุกครั้งที่อัปเดตโค้ด

```bash
php artisan down
git pull && composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan migrate --force
php artisan platform:sync-permissions      # เพิ่มสิทธิ์ใหม่ (ไม่ลบสิ่งที่บริษัทปรับเอง)
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

### อายุการใช้งานของบริษัทลูกค้า

superadmin กำหนดวันเริ่มและวันสิ้นสุดได้ในหน้าแก้ไขบริษัท (เว้นว่าง = ไม่จำกัด)

| ช่วงเวลา | ผลกับผู้ใช้ของบริษัท |
|---|---|
| ก่อนวันเริ่ม | ดูข้อมูลได้อย่างเดียว |
| เหลือไม่เกิน 30 วัน | ใช้งานได้ปกติ มีแถบเตือนและ popup วันละครั้ง |
| หมดอายุไม่เกิน 30 วัน | ดูข้อมูลได้อย่างเดียว ทุกการบันทึกถูกปฏิเสธพร้อมข้อความ |
| หมดอายุเกิน 30 วัน | เข้าใช้งานไม่ได้ เห็นหน้า "หมดอายุการใช้งาน" และไม่มีอีเมลแจ้งเตือนจากระบบ |

superadmin ยังเข้าไปในบริษัทที่หมดอายุและแก้ไขได้เสมอ ส่วน helpdesk/ช่างส่วนกลางดูได้อย่างเดียว

## ฐานข้อมูล: การแยกข้อมูลระหว่างบริษัท

MariaDB ไม่มี Row Level Security การแยกข้อมูลระหว่าง tenant จึงอยู่ในโค้ดทั้งหมด

| ชั้น | ทำอะไร |
|---|---|
| `BelongsToTenant` → `TenantScope` | เติม `where tenant_id = ?` ให้ทุก query ของ Model (ไม่มี tenant = ไม่มีแถว) |
| `BelongsToTenant` ตอน `creating` / `updating` | เติม `tenant_id` จาก tenant ปัจจุบัน, ห้ามสร้างลงบริษัทอื่น, ห้ามย้ายแถวไปบริษัทอื่น |
| `TenantPresenceVerifier` | validation `exists` / `unique` เห็นเฉพาะแถวของบริษัทปัจจุบัน |
| ทุก raw SQL / `DB::table()` | **ต้องใส่ `tenant_id` เอง** — ไม่มีอะไรกันให้แล้ว |

`Rls::enable()` ใน migration ยังเรียกได้ (เปิด RLS ให้เมื่อรันบน PostgreSQL บน MariaDB ไม่ทำอะไร)

## Tenant ของ request

`ResolveTenant` (อยู่ใน web middleware group) หา tenant ตามลำดับนี้

1. subdomain: `{subdomain}.{TENANCY_CENTRAL_DOMAINS}` ถ้าไม่พบให้ 404 ถ้าถูกระงับให้ 403
   ถ้า user ที่ล็อกอินอยู่เป็นของ tenant อื่นให้ 403
2. `tenant_id` ของ user ที่ล็อกอิน
3. ไม่มี tenant: ตาราง tenant จะว่างทั้งหมด
