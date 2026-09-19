"""Logic หลักของ Bangsaen Waste Watch — เวอร์ชันมีบั๊กฝัง (แจกโดยผู้สอน)
ให้รัน test_waste_logic.py เพื่อหาบั๊ก แล้วใช้ AI ช่วยอธิบายและแก้
"""

VALID_WASTE_TYPES = {"general", "recyclable", "hazardous", "organic"}


def proper_disposal_rate(generated_tpd, proper_tpd):
    """คืนร้อยละของขยะที่กำจัดถูกต้อง ปัดทศนิยม 1 ตำแหน่ง
    - generated_tpd ต้อง > 0 มิฉะนั้น ValueError
    - proper_tpd ต้อง >= 0 และ <= generated_tpd มิฉะนั้น ValueError
    - ไม่รับ bool และไม่รับ string
    """
    if not isinstance(generated_tpd, (int, float)) or not isinstance(proper_tpd, (int, float)):
        raise ValueError("generated_tpd และ proper_tpd ต้องเป็นตัวเลข")
    if generated_tpd <= 0:
        raise ValueError("generated_tpd ต้องมากกว่า 0")
    if proper_tpd < 0 or proper_tpd > generated_tpd:
        raise ValueError("proper_tpd ต้องอยู่ระหว่าง 0 และ generated_tpd")
    return round(proper_tpd / generated_tpd * 100, 1)


def validate_report(data):
    """ตรวจข้อมูลแจ้งจุดขยะจาก form แล้วคืน dict ที่สะอาด
    - location: str ตัดช่องว่างหัวท้าย ยาว 3–100 ตัวอักษร
    - waste_type: หนึ่งใน VALID_WASTE_TYPES
    - amount_kg: int 1–1000 (รับ string ตัวเลขได้ เช่น "20" -> 20)
    - detail: str ไม่บังคับ ยาวไม่เกิน 500 ตัวอักษร ค่าเริ่มต้น ""
    - ผิดข้อใด -> ValueError พร้อมข้อความบอกชื่อฟิลด์
    """
    cleaned = {}
    if not isinstance(data, dict):
        raise ValueError("data: ไม่ใช่ dict")

    location = data.get("location")
    if not isinstance(location, str):
        raise ValueError("location: ต้องเป็น str")
    location = location.strip()
    if not location or len(location) > 100:
        raise ValueError("location: ยาวไม่ถูกต้อง")
    cleaned["location"] = location

    waste_type = data.get("waste_type")
    if waste_type not in VALID_WASTE_TYPES:
        raise ValueError("waste_type: ไม่ถูกต้อง")
    cleaned["waste_type"] = waste_type

    amount_kg = data.get("amount_kg")
    if isinstance(amount_kg, str):
        if not amount_kg.isdigit():
            raise ValueError("amount_kg: ไม่ใช่ตัวเลขจำนวนเต็ม")
        amount_kg = int(amount_kg)
    if not isinstance(amount_kg, int):
        raise ValueError("amount_kg: ต้องเป็น int")
    if amount_kg < 1 or amount_kg > 1000:
        raise ValueError("amount_kg: นอกช่วง 1-1000")
    cleaned["amount_kg"] = amount_kg

    detail = data.get("detail")
    if detail is None:
        detail = ""
    if not isinstance(detail, str):
        raise ValueError("detail: ต้องเป็น str")
    cleaned["detail"] = detail[:500]

    return cleaned
