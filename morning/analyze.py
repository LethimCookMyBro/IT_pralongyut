"""สคริปต์วิเคราะห์ข้อมูลขยะ — ให้ AI เขียนตามโจทย์ในขั้น 3.5

โจทย์:
1) พิมพ์ 5 อปท.ที่ generated_tpd สูงสุดในปีล่าสุด
2) พิมพ์อัตรากำจัดถูกต้องของ "เทศบาลเมืองแสนสุข" ทุกปี
   โดย import proper_disposal_rate จาก waste_logic
ข้ามแถวที่ตัวเลขว่างหรือแปลงไม่ได้ และนับจำนวนแถวที่ข้ามไว้พิมพ์ตอนจบ
ไม่ใช้ pandas
"""

import csv
import sys
from pathlib import Path

# ให้รันตรง ๆ บน Windows ได้แม้ console default ไม่ใช่ UTF-8
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8")

from waste_logic import proper_disposal_rate

CSV_PATH = Path(__file__).resolve().parent.parent / "data" / "waste_chonburi.csv"
NUMERIC_FIELDS = ("generated_tpd", "collected_tpd", "utilized_tpd", "proper_tpd", "improper_tpd")
SAENSUK = "เทศบาลเมืองแสนสุข"


def load_rows(path):
    """อ่าน CSV แล้วคืน (rows, skipped_count)
    ข้ามแถวที่ปีหรือตัวเลขคอลัมน์ใดคอลัมน์หนึ่งว่าง/แปลงเป็นตัวเลขไม่ได้
    """
    rows = []
    skipped = 0
    with open(path, newline="", encoding="utf-8") as f:
        for raw in csv.DictReader(f):
            try:
                row = {
                    "year": int(raw["year"]),
                    "district": raw["district"],
                    "local_gov": raw["local_gov"],
                }
                for field in NUMERIC_FIELDS:
                    row[field] = float(raw[field])
            except (ValueError, TypeError, KeyError):
                skipped += 1
                continue
            rows.append(row)
    return rows, skipped


def print_top5_latest_year(rows):
    latest_year = max(row["year"] for row in rows)
    of_latest_year = [row for row in rows if row["year"] == latest_year]
    top5 = sorted(of_latest_year, key=lambda row: row["generated_tpd"], reverse=True)[:5]

    print(f"=== 5 อปท. ที่มีปริมาณขยะเกิดขึ้นสูงสุดในปี {latest_year} ===")
    for rank, row in enumerate(top5, start=1):
        print(f"{rank}. {row['local_gov']} ({row['district']}): {row['generated_tpd']:.2f} ตัน/วัน")


def print_saensuk_rate(rows):
    saensuk_rows = sorted(
        (row for row in rows if row["local_gov"] == SAENSUK),
        key=lambda row: row["year"],
    )

    print(f"\n=== อัตรากำจัดถูกต้องของ {SAENSUK} รายปี ===")
    for row in saensuk_rows:
        rate = proper_disposal_rate(row["generated_tpd"], row["proper_tpd"])
        print(f"ปี {row['year']}: {rate}%")


def main():
    rows, skipped = load_rows(CSV_PATH)
    print_top5_latest_year(rows)
    print_saensuk_rate(rows)
    print(f"\nข้ามแถวที่ตัวเลขว่างหรือแปลงไม่ได้: {skipped} แถว")


if __name__ == "__main__":
    main()
