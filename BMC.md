# Business Model Canvas — Bangsaen Waste Vision

> สถานะ: **BMC สำหรับ Proposal / Prototype**
>
> Buyer, Revenue และ Customer Relationship ด้านล่างเป็น **สมมติฐานทางธุรกิจ** ที่ต้อง Validate กับเทศบาล/ผู้รับเหมาก่อนนำไปใช้จริง

## Product Concept
**Bangsaen Waste Vision** คือ Software + Local Computer Vision ที่ช่วยคัดกรองภาพจากกล้อง แล้วรวม Raw Observations ที่เกิดซ้ำจากจุดเดียวกันให้เป็น Incident ก่อนส่งให้เจ้าหน้าที่ Confirm / Reject และดำเนินการ

หลักการ: **Detect → Raw Observations → Event Aggregation → Incident Queue → Human Verify → Action → Resolve → Feedback**

## Pain / User / Buyer / Proof

### Pain
เจ้าหน้าที่ไม่สามารถเฝ้าดูหลายพื้นที่หรือหลายกล้องได้ตลอดเวลา และถ้าระบบส่ง detection ทุก frame ให้คนตรวจโดยตรงก็อาจเกิด alert flood จึงต้องคัดกรองภาพและรวมเหตุซ้ำก่อนให้คนตรวจ

### User
ผู้ใช้หลัก:
- เจ้าหน้าที่สิ่งแวดล้อมเทศบาล
- ทีมควบคุมงานความสะอาด
- ทีมดูแลชายหาด/พื้นที่สาธารณะ

ผู้ใช้รอง:
- บริษัทรับเหมาดูแลความสะอาด
- ประชาชน/ร้านค้า ผ่านช่องทางแจ้งปัญหาตามระบบเดิม

### Buyer — Hypothesis
1. เทศบาล / หน่วยงานท้องถิ่น
2. บริษัทรับเหมาดูแลความสะอาด
3. ผู้ดูแลพื้นที่ท่องเที่ยวหรือพื้นที่สาธารณะขนาดใหญ่

ยังไม่ถือว่า Buyer เหล่านี้ผ่านการ Validate จนกว่าจะสัมภาษณ์หรือทดลอง Pilot

### Proof
วันนี้พิสูจน์ด้านเทคนิค: **Real replay image → real local inference → Raw Observation → Event Aggregation → Incident → PHP/MySQL → Dashboard/API → Human Review**

## 1. Customer Segments
Primary: เทศบาล/อบต./หน่วยงานท้องถิ่น และบริษัทรับเหมาจัดเก็บ/ดูแลความสะอาด

Secondary: ผู้ดูแลพื้นที่ท่องเที่ยว มหาวิทยาลัย หรือพื้นที่สาธารณะขนาดใหญ่ที่มีกล้องและทีมดูแลพื้นที่

End Beneficiaries: ประชาชน นักท่องเที่ยว ร้านค้า และผู้ประกอบการในพื้นที่

## 2. Value Propositions
**ช่วยให้คนไม่ต้องเฝ้าดูทุกกล้องตลอดเวลา และไม่ต้องตรวจทุก detection ซ้ำ ๆ โดยใช้ Local Computer Vision คัดกรองภาพและรวม observation เป็น incident ก่อนให้เจ้าหน้าที่ตัดสินใจ**

จุดเด่น:
- ใช้กล้องเดิมได้เมื่อมุมกล้องและสิทธิ์เข้าถึงเหมาะสม
- เพิ่มกล้องราคาต่ำเฉพาะ blind spot ได้
- ประมวลผล Local ได้ ไม่จำเป็นต้องส่งวิดีโอขึ้น Cloud ใน architecture ที่เสนอ
- Event Aggregation ลด alert flood จาก frame/detection ซ้ำ
- Human-in-the-loop ลดความเสี่ยงจาก False Positive
- Confirm/Reject สร้าง feedback สำหรับ evaluation และการปรับโมเดลในอนาคต
- รองรับ LAND และ WATER monitoring โดยใช้โมเดล/จุดติดตั้งที่เหมาะสมต่างกัน

## 3. Channels
- Pilot ร่วมกับเทศบาลหรือผู้รับเหมา
- ทดลองพื้นที่เล็ก 1–3 จุดก่อน
- Web Dashboard ภายในองค์กร
- ขยายผ่านผู้ติดตั้ง CCTV/System Integrator เมื่อ product ผ่านการพิสูจน์

## 4. Customer Relationships
- สำรวจพื้นที่และเลือกกล้องที่เหมาะสม
- ติดตั้ง/ตั้งค่า software
- ฝึกเจ้าหน้าที่ใช้ Dashboard
- Maintenance และ monitoring ระบบ
- Model evaluation/adaptation เมื่อมีข้อมูลพื้นที่จริง
- รายงานผล Pilot

## 5. Revenue Streams — Hypothesis
- ค่า Setup / Installation
- ค่าบริการดูแลและบำรุงรายปี
- ค่าเพิ่มจุดกล้อง/อุปกรณ์เฉพาะจุด
- ในอนาคตอาจมี Package ตามจำนวนจุดตรวจหรือจำนวนกล้อง

ยังไม่กำหนดราคา และยังไม่ Claim willingness-to-pay

## 6. Key Resources
- Local Computer Vision model
- เครื่องประมวลผล Local AI
- CCTV / IP camera / fixed camera
- PHP + MySQL Dashboard
- Event Aggregation / Incident data model
- Open Data ของจังหวัดสำหรับ Historical/Macro layer
- Dataset / pretrained model จากโครงการ open-source ที่ license อนุญาต
- ข้อมูล Confirm/Reject สำหรับ evaluation ในอนาคต
- ทีมพัฒนาและผู้เชี่ยวชาญด้านพื้นที่

## 7. Key Activities
- เชื่อมแหล่งภาพ
- รัน Local inference
- จัดเก็บ Raw Observations
- Aggregate observations เป็น Incident
- จัด Incident Queue แบบ explainable
- Human verification workflow
- ดูแล Dashboard/API/database
- ทดสอบโมเดลกับข้อมูลพื้นที่จริง
- เก็บ feedback และทำ model evaluation
- Maintenance ระบบ

## 8. Key Partners
สำหรับ Pilot จริง:
- เทศบาลเมืองแสนสุข / หน่วยงานเจ้าของพื้นที่
- ผู้ดูแลระบบ CCTV / System Integrator
- ทีมเก็บขยะหรือบริษัทรับเหมาดูแลความสะอาด
- มหาวิทยาลัย/ทีมวิจัยที่ร่วมทดสอบระบบ

Technical references:
- Open-source projects / datasets เช่น pLitter และงานที่เกี่ยวข้อง

> อย่าเรียกเจ้าของ open-source project ว่า “Partner” หากไม่มีความร่วมมือจริง ให้เรียกว่า Technical Reference

## 9. Cost Structure
- เครื่องประมวลผล Local AI
- กล้อง/อุปกรณ์เพิ่มเติมเฉพาะจุด
- Network และ storage ตาม deployment จริง
- Installation
- Software development
- Maintenance
- Data labeling / model evaluation
- Model adaptation เมื่อใช้กับพื้นที่จริง
- ค่าแรงทีมสนับสนุน

## Adoption Strategy
Phase 1: Software-only PoC — Replay imagery → AI → Raw Observations → Event Aggregation → Incidents → Human review

Phase 2: Small Pilot — กล้อง 1–3 จุด เพื่อวัด false positive/precision หลัง human review และ workflow เจ้าหน้าที่

Phase 3: CCTV Integration — เชื่อมกล้องเดิมที่ได้รับสิทธิ์และมุมเหมาะสม

Phase 4: Water Monitoring — fixed camera ที่ปากคลอง/สะพาน/ทางน้ำ

Phase 5: Scale — เพิ่มจุดตรวจหลังมีผล Pilot รองรับ

## Metrics ที่ควรวัดใน Pilot
- จำนวน Raw Observations ต่อ Incident
- จำนวน Incidents ที่คน Confirm / Reject
- Precision ของ Incident หลัง Human Review
- False positive rate
- จำนวนเหตุที่ระบบช่วยพบก่อนการแจ้งด้วยคน
- เวลาจาก first_seen → Human Review
- เวลาจาก Confirm → Resolve
- ชั่วโมงที่เจ้าหน้าที่ใช้เฝ้าดูกล้อง ก่อน/หลัง Pilot
- Uptime ของระบบ
- Cost per monitored location

ห้ามใส่ผลลัพธ์ตัวเลขจนกว่าจะวัดจริง

## One-line Business Pitch
**“เปลี่ยนกล้องที่มีอยู่ให้ช่วยคัดกรองและรวมเหตุขยะที่ควรตรวจสอบ เพื่อให้เจ้าหน้าที่รู้ว่าควรดูจุดไหนก่อน โดยยังให้มนุษย์เป็นผู้ยืนยันและตัดสินใจ”**
