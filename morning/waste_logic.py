"""Logic หลักของ Bangsaen Waste Watch — ให้ AI เติมตาม docstring"""

VALID_WASTE_TYPES = {"general", "recyclable", "hazardous", "organic"}


def proper_disposal_rate(generated_tpd, proper_tpd):
    """คืนร้อยละของขยะที่กำจัดถูกต้อง ปัดทศนิยม 1 ตำแหน่ง
    - generated_tpd ต้อง > 0 มิฉะนั้น ValueError
    - proper_tpd ต้อง >= 0 และ <= generated_tpd มิฉะนั้น ValueError
    - ไม่รับ bool และไม่รับ string
    """
    for value in (generated_tpd, proper_tpd):
        if isinstance(value, bool) or not isinstance(value, (int, float)):
            raise ValueError("generated_tpd and proper_tpd must be int or float, not bool/str")

    if generated_tpd <= 0:
        raise ValueError("generated_tpd must be > 0")
    if proper_tpd < 0 or proper_tpd > generated_tpd:
        raise ValueError("proper_tpd must be >= 0 and <= generated_tpd")

    return round(proper_tpd / generated_tpd * 100, 1)


def validate_report(report: dict) -> dict:
    """Validates and processes a waste report.

    Args:
        report: A dictionary containing waste report information.

    Returns:
        A dictionary with only the keys: 'location', 'waste_type', 'amount_kg', 'detail'

    Raises:
        ValueError: If any required field is missing or invalid.
    """
    # Check for missing keys
    required_keys = ['location', 'waste_type', 'amount_kg', 'detail']
    for key in required_keys:
        if key not in report:
            raise ValueError(f"{key}: missing")
    
    # Validate location (string, trimmed, 3-100 chars)
    location = report['location']
    if not isinstance(location, str):
        raise ValueError(f"location: must be a string, found {type(location).__name__}")
    location = location.strip()
    if len(location) < 3 or len(location) > 100:
        raise ValueError("location: must be 3-100 characters after trimming")

    # Validate waste_type (string, one of VALID_WASTE_TYPES)
    waste_type = report['waste_type']
    if not isinstance(waste_type, str):
        raise ValueError(f"waste_type: must be a string, found {type(waste_type).__name__}")
    if waste_type not in VALID_WASTE_TYPES:
        raise ValueError(f"waste_type: must be one of {sorted(VALID_WASTE_TYPES)}")

    # Validate amount_kg (convert to integer if string, reject other types, range 1-1000)
    amount_kg = report['amount_kg']
    if isinstance(amount_kg, bool):
        raise ValueError("amount_kg: must be integer or string, found boolean")
    elif isinstance(amount_kg, float):
        raise ValueError("amount_kg: must be integer or string, found float")
    elif isinstance(amount_kg, str):
        try:
            amount_kg = int(amount_kg)
        except ValueError:
            raise ValueError("amount_kg: must be string of integer number")
    elif not isinstance(amount_kg, int):
        raise ValueError(f"amount_kg: must be integer or string, found {type(amount_kg).__name__}")
    if amount_kg < 1 or amount_kg > 1000:
        raise ValueError("amount_kg: must be between 1 and 1000")

    # Validate detail (string, optional/None -> "", max 500 chars)
    detail = report['detail']
    if detail is None:
        detail = ''
    if not isinstance(detail, str):
        raise ValueError(f"detail: must be a string, found {type(detail).__name__}")
    if len(detail) > 500:
        raise ValueError("detail: must be at most 500 characters")

    # Return with cleaned values
    return {
        'location': location,
        'waste_type': waste_type,
        'amount_kg': amount_kg,
        'detail': detail
    }
