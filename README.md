# Bangsaen Waste Watch — ชุดไฟล์ผู้เข้าอบรม

## โครงสร้าง
```
├── SETUP_GUIDE.md      ← อ่าน+ทำก่อนวันอบรม (สำคัญ!)
├── SPEC_TEMPLATE.md    ← ใช้ตอน Bridge 11:35
├── config.yaml         ← ไปวางที่ C:\Users\<ชื่อ>\.continue\config.yaml
├── data/
│   └── waste_chonburi.csv      ← ข้อมูลเปิดจริง
├── morning/                    ← งานช่วงเช้า
│   ├── waste_logic.py          ← stub ให้ AI เติม (3.2)
│   ├── waste_logic_buggy.py    ← ไฟล์ฝึกหาบั๊ก (3.4)
│   ├── analyze.py              ← ให้ AI เขียน (3.5)
│   └── test_waste_logic.py     ← test สำหรับตรวจ
└── afternoon/                  ← copy ไป htdocs/bangsaen/ ตอน 4.2
    ├── hello.php               ← เช็กว่า PHP ทำงาน (localhost/bangsaen/hello.php)
    ├── index.html / report.html / js / css   ← stub รอ AI สร้าง (6.x)
    ├── api/reports_buggy.php   ← ไฟล์ฝึกหาบั๊ก (7.3)
    ├── lib/db.php response.php ← แจกให้ ใช้เลย (5.2)
    ├── lib/validate_buggy.php  ← ไฟล์ฝึกหาบั๊ก (7.3)
    ├── sql/                    ← ให้ AI สร้าง schema.sql (4.3)
    └── tests/run_tests.php     ← test PHP (7.2)
```

## หมายเหตุ
- ไฟล์ที่ขึ้นต้น `*_buggy` มีบั๊กฝังโดยตั้งใจ — ใช้ในแล็บ อย่าแก้ก่อนได้รับสั่ง
- ถ้าขั้นไหนติดนานเกิน ให้ถามผู้ช่วยสอน — มีไฟล์อ้างอิงแยกให้
- ตอนแล็บ 3.4 (เช้า): เช่นเดียวกัน — copy `morning/waste_logic_buggy.py` ไปทับ `morning/waste_logic.py` แล้วรัน `python test_waste_logic.py` (test import ชื่อ waste_logic ตายตัว)
