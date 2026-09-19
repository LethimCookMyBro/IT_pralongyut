# นำ Bangsaen Waste Vision ขึ้น Railway

เอกสารนี้อธิบายการนำ **ส่วนเว็บ (PHP + MySQL)** ขึ้น Railway

สิ่งที่ **ไม่ได้** ขึ้น Railway ในรอบนี้: ตัวตรวจจับด้วย AI (pLitter + PyTorch)
ยังเป็น **Local Demo Worker** ที่รันบนเครื่องตัวเอง เพราะโมเดลและ PyTorch ใหญ่
ใช้ทรัพยากรสูง และไม่จำเป็นต่อการเปิดเว็บให้คนอื่นดู

---

## ก่อนเริ่ม

ตรวจว่าไม่มีรหัสผ่านจริงอยู่ในโค้ด:

- `afternoon/lib/db.php` อ่านค่าจาก environment variables ไม่มีรหัสผ่านเขียนไว้
- `.env` จริงอยู่ใน `.gitignore` แล้ว — มีแต่ `.env.example` ที่ขึ้น Git ได้
- `references/pLitter/` (โมเดล 107MB) ไม่ถูก track ใน Git แล้ว ยังอยู่บนเครื่องตามเดิม

---

## ขั้นตอน

### 1. Push repository ขึ้น GitHub

สร้าง repository แล้ว push branch `main`

### 2. Railway → New Project

เข้า [railway.app](https://railway.app) → **New Project**

### 3. เพิ่ม Web Service จาก GitHub repo

**Deploy from GitHub repo** → เลือก repository นี้

### 4. ตั้ง Root Directory = `/afternoon`

Service → **Settings** → **Root Directory** → ใส่ `afternoon`

สำคัญ: Railpack ดูว่าเป็น PHP app จาก `index.php` หรือ `composer.json`
โปรเจกต์นี้มี `afternoon/index.php` ไว้ให้แล้ว (ไฟล์เล็ก ๆ ที่ส่ง `index.html` ออกไป
ไม่ได้ทำ UI ซ้ำ และ URL หน้าเดิมทุกหน้ายังใช้ได้เหมือนเดิม)

### 5. เพิ่ม MySQL service

ในโปรเจกต์เดียวกัน → **New** → **Database** → **Add MySQL**

### 6. ตั้งตัวแปรให้ Web Service

ไปที่ Web Service → **Variables** → เพิ่มโดย reference จาก MySQL service:

```
MYSQLHOST      = ${{MySQL.MYSQLHOST}}
MYSQLPORT      = ${{MySQL.MYSQLPORT}}
MYSQLUSER      = ${{MySQL.MYSQLUSER}}
MYSQLPASSWORD  = ${{MySQL.MYSQLPASSWORD}}
MYSQLDATABASE  = ${{MySQL.MYSQLDATABASE}}
```

อย่าพิมพ์รหัสผ่านลงไปตรง ๆ และอย่า commit ค่าเหล่านี้

ถ้าไม่ตั้งตัวแปรเหล่านี้ ระบบจะถอยไปใช้ค่า XAMPP บนเครื่อง (`127.0.0.1`, `root`,
รหัสผ่านว่าง, `bangsaen_waste`) ซึ่งบน Railway จะต่อฐานข้อมูลไม่ได้

### 7. สร้างตารางด้วย `railway_init.sql`

**ห้ามรัน `afternoon/sql/schema.sql` บน Railway** — ไฟล์นั้นมี `DROP TABLE` ข้อมูลจะหายหมด
ใช้สำหรับ reset เครื่อง local ตอน workshop เท่านั้น

ใช้ `afternoon/sql/railway_init.sql` แทน — ไม่มี `DROP` / `TRUNCATE` / `DELETE`
รันซ้ำได้ ตารางที่มีอยู่แล้วไม่ถูกแตะ และข้อมูล `waste_stats` จะใส่ให้เฉพาะตอนตารางยังว่าง

รันจากเครื่องตัวเอง โดยเอาค่าการเชื่อมต่อจากแท็บ **Variables** ของ MySQL service
(ค่า public host/port อยู่ในตัวแปรที่ขึ้นต้นด้วย `MYSQL_PUBLIC_URL` หรือดูที่ **Connect**):

```
C:\xampp\mysql\bin\mysql.exe -h HOST -P PORT -u USER -p DBNAME ^
  --default-character-set=utf8mb4 < afternoon\sql\railway_init.sql
```

`railway_init.sql` ไม่สร้าง database และไม่ `USE` ชื่อฐานแบบ hardcode แล้ว
ดังนั้น `DBNAME` ในคำสั่งต้องใช้ค่าจาก `MYSQLDATABASE` ของ Railway โดยตรง
เพื่อให้ตารางถูกสร้างในฐานที่ Railway จัดให้เท่านั้น

### 8. Generate Domain

Service → **Settings** → **Networking** → **Generate Domain**

### 9. เปิดเว็บ

เปิด URL ที่ได้ — ควรเห็นหน้าสถิติขยะ

### 10. ทดสอบ endpoint สำคัญ

| URL | ควรได้ |
|---|---|
| `/` | หน้าสถิติขยะ |
| `/api/stats.php` | JSON สถิติ (มีข้อมูล 24 แถวหลังรัน `railway_init.sql`) |
| `/api/reports.php` | JSON รายการแจ้งเหตุ + `pagination` |
| `/api/vision.php` | JSON คิวเหตุการณ์ + `summary` |
| `/reports.html` | หน้ารายการแจ้งเหตุ |
| `/vision.html` | หน้าศูนย์ตรวจสอบ |
| `/tools/seed_demo.php` | **404** (รันได้จาก command line เท่านั้น) |
| `/tests/run_tests.php` | **404** (รันได้จาก command line เท่านั้น) |

ถ้า `/tools/seed_demo.php` หรือ `/tests/*.php` เปิดได้ผ่านเบราว์เซอร์ แปลว่า
การป้องกันหลุด — ต้องแก้ก่อนเปิดให้คนอื่นใช้ (ดู `afternoon/lib/cli_only.php`)

---

## ตัวตรวจจับด้วย AI

หน้า "ตรวจจับด้วย AI" บน Railway จะไม่มี detector ทำงานอยู่ และต้องแสดงสถานะตามจริงว่า
ตัวตรวจจับ Local AI ไม่ได้ทำงานบน deployment นี้ ส่วนอื่นของเว็บต้องใช้งานได้ตามปกติ

ตัวตรวจจับรันบนเครื่อง local เท่านั้น และต้องเตรียมเองเพราะไม่ได้อยู่ใน Git:

- โมเดล pLitter (`references/pLitter/weights/*.pt`) — ไม่ได้ publish ขึ้น GitHub
  เพราะเป็นของบุคคลที่สาม ต้องดาวน์โหลดเองจากต้นทาง
- Python environment ที่มี `torch`, `torchvision`, `opencv-python`

---

## สิ่งที่ห้ามทำ

- ห้ามรัน `schema.sql` บนฐานข้อมูลที่มีข้อมูลจริง
- ห้าม commit `.env` หรือรหัสผ่านของ Railway
- ห้ามเปิดให้ `seed_demo.php` / `tests/*.php` เรียกผ่าน URL สาธารณะ
- ระบบนี้ยังเป็นต้นแบบ (prototype/replay) ห้ามอธิบายว่าเชื่อมกับกล้อง CCTV ของเทศบาลจริง
