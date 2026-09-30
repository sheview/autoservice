# AutoService

ระบบจัดการทรัพย์สินและงานบริการ MA แบบ multi-tenant
Laravel 12 · Inertia 2 + Vue 3 + TypeScript · PostgreSQL 16

กฎการพัฒนาทั้งหมดอยู่ใน [CLAUDE.md](CLAUDE.md)

## ติดตั้งครั้งแรก

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate

# สร้าง role และฐานข้อมูล (รันด้วย superuser ของ PostgreSQL ครั้งเดียว)
psql -U postgres -h 127.0.0.1 -f database/setup/pgsql-roles.sql

php artisan migrate --database=pgsql_migrate
php artisan test
```

## ข้อมูลตัวอย่างและการรันบนเครื่อง dev

```bash
# ล้างฐานแล้วใส่ข้อมูลตัวอย่าง (DemoSeeder ไม่ทำงานบน production)
php artisan migrate:fresh --database=pgsql_migrate --seed

php artisan serve          # แอป
npm run dev                # frontend
php artisan queue:listen   # งานเบื้องหลัง เช่น นำเข้า Excel, อีเมลสัญญาใกล้หมดอายุ
php artisan schedule:work  # งานตามเวลา: pm:notify-upcoming (07:30), contracts:notify-expiring (08:00), inventory:notify-low-stock (08:00), tickets:notify-sla-breaches (ทุก 15 นาที)
```

รหัสผ่านทุกบัญชีคือ `password`

| บัญชี | บทบาท |
|---|---|
| `admin@platform.test` | superadmin ของแพลตฟอร์ม (เข้าดูในนามบริษัทลูกค้าและทำได้ทุกอย่าง, เปิด/ปิดโมดูล) |
| `helpdesk@platform.test`, `tech@platform.test` | Helpdesk / ช่างส่วนกลาง: เข้าได้ทุกบริษัท เห็นทุกสาขา ทำงานประจำวันได้ แต่ตั้งค่าไม่ได้ |
| `admin@itsol.test`, `admin@netpro.test` | ผู้ดูแลระบบบริษัท (เห็นทุกสาขาของบริษัท) |
| `helpdesk@…`, `tech1@…`, `tech2@…`, `user@…` | บทบาทอื่นของแต่ละบริษัท เห็นเฉพาะสาขาของตัวเอง |
| `customer@itsol.test`, `customer@netpro.test` | บัญชีลูกค้า (CUST001) เห็นเฉพาะใบงานและทรัพย์สินของตัวเอง |

บน production ต้องตั้ง cron `* * * * * php artisan schedule:run` และรัน queue worker (Horizon)

## ฐานข้อมูล: สอง role และ Row Level Security

การแยกข้อมูลระหว่าง tenant มีสองชั้น

1. **Eloquent global scope** (`BelongsToTenant`) เติม `where tenant_id = ?` ให้ทุก query ของ Model
2. **PostgreSQL RLS** ที่ฐานข้อมูล ซึ่งยังกันได้แม้ query ไม่ผ่าน Eloquent (`DB::table`, raw SQL)

RLS จะมีผลจริงได้ต้องตั้งค่าสามอย่างนี้ครบ

| ต้องมี | เพราะ |
|---|---|
| app ต่อฐานด้วย role ที่**ไม่ใช่ superuser และไม่มี `BYPASSRLS`** | superuser และ `BYPASSRLS` ข้าม policy ทุกตัว |
| app ต่อด้วย role ที่**ไม่ใช่เจ้าของตาราง** | เจ้าของตารางสั่ง `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` ได้ |
| ทุกตาราง tenant ใช้ `ENABLE` + `FORCE ROW LEVEL SECURITY` | `FORCE` ทำให้ policy ใช้กับเจ้าของตารางด้วย (เช่น ตอนรัน migration) |

จึงมีสอง role (สร้างโดย [database/setup/pgsql-roles.sql](database/setup/pgsql-roles.sql))

| role | connection | ใช้ทำอะไร | สิทธิ์ |
|---|---|---|---|
| `autoservice_owner` | `pgsql_migrate` | รัน migration เท่านั้น | เจ้าของ database และทุกตาราง |
| `autoservice_app` | `pgsql` (default) | app, queue worker, test | `SELECT/INSERT/UPDATE/DELETE` ผ่าน default privileges |

ผลที่ตามมา:

- **migration ต้องรันด้วย `--database=pgsql_migrate` เสมอ** ถ้าลืม จะได้ error เรื่องสิทธิ์ `CREATE`
- test สร้าง schema ด้วย role owner ครั้งเดียว (ดู `tests/TestCase.php`) แล้วรันทุก test ด้วย role app
- ตารางใหม่ที่ owner สร้าง role app จะได้สิทธิ์อัตโนมัติ ไม่ต้อง `GRANT` เอง

Policy อ่านค่าจาก setting `app.tenant_id` ของ session ฐานข้อมูล ซึ่ง `TenantContext::set()` เป็นคนตั้ง
ถ้าไม่มี tenant ค่าจะเป็น `''` แล้ว policy จะไม่คืนแถวใดเลย

บน production ให้เปลี่ยนรหัสผ่านของทั้งสอง role และใส่ใน `.env`
(`DB_USERNAME/DB_PASSWORD` และ `DB_MIGRATE_USERNAME/DB_MIGRATE_PASSWORD`)

## Tenant ของ request

`ResolveTenant` (อยู่ใน web middleware group) หา tenant ตามลำดับนี้

1. subdomain: `{subdomain}.{TENANCY_CENTRAL_DOMAINS}` ถ้าไม่พบให้ 404 ถ้าถูกระงับให้ 403
   ถ้า user ที่ล็อกอินอยู่เป็นของ tenant อื่นให้ 403
2. `tenant_id` ของ user ที่ล็อกอิน
3. ไม่มี tenant: ตาราง tenant จะว่างทั้งหมด
