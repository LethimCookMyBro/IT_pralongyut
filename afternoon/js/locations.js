// จุด/พื้นที่ที่เลือกได้ในหน้าแจ้งจุดขยะ (Phase B ทางเลือกที่ 1)
//
// ตั้งใจเก็บ "ชื่อ" อย่างเดียว ไม่ผูกพิกัด lat/lng ไว้ล่วงหน้า
// เพราะยังไม่มีชุดพิกัดขอบเขตพื้นที่ที่ตรวจสอบแล้วสำหรับเมืองแสนสุข
// การใส่พิกัดมั่ว ๆ จะทำให้ระบบตรวจรายการซ้ำด้วยระยะทางให้ผลผิด
// → preset ใช้ชื่อจับคู่ (location_source = 'preset', พิกัดเป็น NULL)
//   ถ้าผู้ใช้ต้องการพิกัดจริง ให้กด "ใช้ตำแหน่งปัจจุบัน" (location_source = 'gps')
//
// เก็บเป็นค่าคงที่ใน JS ไม่ทำเป็นตารางใหม่ใน DB เพราะรอบนี้ยังไม่มี
// หน้าจัดการพื้นที่ และรายการนี้แก้ไม่บ่อย

const PRESET_LOCATIONS = [
    {
        group: 'ชายหาดและชายฝั่ง',
        items: [
            'หาดบางแสน — โค้งวงเวียน',
            'หาดบางแสน — ฝั่งเหนือ',
            'หาดบางแสน — ฝั่งใต้',
            'หาดวอนนภา',
            'แหลมแท่น',
        ],
    },
    {
        group: 'ถนนและย่านชุมชน',
        items: [
            'ถนนเลียบหาดบางแสน',
            'ตลาดหนองมน',
            'ย่านหอพักบางแสน',
            'ตลาดสดเทศบาลเมืองแสนสุข',
        ],
    },
    {
        group: 'พื้นที่สาธารณะ',
        items: [
            'สวนสาธารณะเทศบาลเมืองแสนสุข',
            'เขาสามมุก',
            'มหาวิทยาลัยบูรพา — พื้นที่รอบนอก',
        ],
    },
    {
        group: 'ทางน้ำ',
        items: [
            'คลองบางแสน',
            'คลองระบายน้ำเลียบชายหาด',
        ],
    },
];

// เติม <option> ให้ <select> ของ preset (ใช้ <optgroup> ให้หาง่ายขึ้น)
function fillPresetLocations(select) {
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = '— เลือกพื้นที่ —';
    select.appendChild(blank);

    for (const { group, items } of PRESET_LOCATIONS) {
        const optgroup = document.createElement('optgroup');
        optgroup.label = group;
        for (const name of items) {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            optgroup.appendChild(option);
        }
        select.appendChild(optgroup);
    }
}
