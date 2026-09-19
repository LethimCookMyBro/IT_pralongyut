# Setup Guide — Bangsaen Waste Watch Workshop

**ทำก่อนวันอบรม** — อบรมวันนี้ต้องใช้โปรแกรม 6 อย่างบนเครื่องของคุณ ถ้าลงครบและเช็กผ่านตาม checklist ท้ายเอกสารแล้ว วันงานจะเริ่มได้ทันทีโดยไม่เสียเวลา

> ⏱️ เวลาที่ควรเผื่อ: 45–90 นาที (ส่วนใหญ่คือรอดาวน์โหลดโมเดล AI ~5 GB)

---

## 0. สเปกเครื่องขั้นต่ำ

| รายการ | ขั้นต่ำ | แนะนำ |
|---|---|---|
| RAM | 8 GB | 16 GB (โมเดล 7B ต้องการ ~5 GB RAM) |
| พื้นที่ว่าง | 15 GB | 20 GB |
| OS | Windows 10/11, macOS, Linux | — |
| สิทธิ์ | **ต้องเป็น admin บนเครื่อง** — ใช้ตอนลง XAMPP / หยุด service / แก้ PATH | |

> ⚠️ เครื่ององค์กรที่ไม่มีสิทธิ์ admin อาจทำขั้น XAMPP/หยุด service ไม่ได้ — แจ้งผู้จัดล่วงหน้า

---

## 1. Python 3

**ลง:** https://www.python.org/downloads/ → Download Python 3.x → รัน installer

- ⚠️ **Windows: ติ๊ก "Add python.exe to PATH"** ในหน้าแรกของ installer — ถ้าลืม คำสั่ง `python` จะไม่ทำงาน
- ⚠️ Windows: ถ้าพิมพ์ `python` แล้วเด้งหน้า Microsoft Store = เจอ alias ปลอม → ลงจริงจาก python.org หรือปิด alias ที่ Settings → Apps → App execution aliases

**เช็ก:** เปิด terminal/cmd ใหม่แล้วพิมพ์
```
python --version
```
ต้องเห็น `Python 3.x.x` (บางเครื่องใช้ `python3` หรือ `py` แทน)

---

## 2. VS Code

**ลง:** https://code.visualstudio.com/ → Download → รัน installer (ติ๊กทุกช่องที่เสนอ "Add to PATH"/"Open with Code")

**เช็ก:** เปิดโปรแกรมได้ + ใน terminal พิมพ์ `code --version` เห็นเลขเวอร์ชัน

---

## 3. Ollama (local AI server)

**ลง:** https://ollama.com/download → เลือก OS → รัน installer

**สำคัญ:** หลังลงต้อง **เปิดโปรแกรม Ollama ค้างไว้** (Windows: icon จะอยู่ใน system tray มุมขวาล่าง; มันคือ background service ไม่มีหน้าต่าง)

- ⚠️ หลังลงเสร็จต้อง **ปิด-เปิด terminal/VS Code ใหม่** ถึงจะเห็นคำสั่ง `ollama` (PATH ไม่รีเฟรช)

**เช็ก:**
```
ollama list
curl http://127.0.0.1:11434/api/version
```
ต้องเห็นตารางโมเดล (อาจว่าง) และ JSON เวอร์ชัน

**โหลดโมเดล (ทำตามลำดับนี้):**

```
ollama pull qwen2.5-coder:1.5b     # ~1 GB — โหลดก่อน ใช้ทดสอบ
ollama pull qwen2.5-coder:7b       # ~4.7 GB — ปล่อยโหลดข้ามคืน/ระหว่างทำอย่างอื่น
```

> ⚠️ โมเดล 7B โหลดนาน (10–60 นาทีตามเน็ต) — **ห้ามรอวันงาน**; ถ้าเน็ตบ้านช้าให้แจ้งผู้จัด มีแผนสำรอง USB
> ถ้าเครื่อง RAM < 8 GB ให้โหลดแค่ 1.5b

**เช็ก:** `ollama list` ต้องเห็น `qwen2.5-coder:1.5b` (และ `:7b` ถ้าโหลดเสร็จ)

---

## 4. Continue (VS Code extension)

**ลง:** เปิด VS Code → Extensions (`Ctrl+Shift+X`) → ค้นหา **"Continue"** (ผู้พัฒนา: Continue) → Install → **Reload Window** ตามที่มันบอก

- หน้า onboarding/ชวน sign-in → **กดข้าม/ปิดได้** เราใช้ local model ไม่ต้อง login
- แผง Continue อยู่ที่ icon ด้านข้างซ้าย (รูป >_) หรือกด `Ctrl+L`

**ตั้งค่า (ทำครั้งเดียว):**

1. เปิดไฟล์ `C:\Users\<ชื่อคุณ>\.continue\config.yaml` (ถ้าไม่มีให้สร้างโฟลเดอร์+ไฟล์เอง; macOS/Linux = `~/.continue/config.yaml`)
2. วางเนื้อนี้ทับทั้งไฟล์:

```yaml
name: Workshop Config
version: 1.0.0
schema: v1
models:
  - name: Qwen2.5-Coder 7B
    provider: ollama
    model: qwen2.5-coder:7b
    apiBase: http://127.0.0.1:11434
    roles: [chat, edit, apply]
    defaultCompletionOptions:
      contextLength: 8192
      maxTokens: 4096
      temperature: 0.1
  - name: Qwen2.5-Coder 1.5B (สำรอง)
    provider: ollama
    model: qwen2.5-coder:1.5b
    apiBase: http://127.0.0.1:11434
    roles: [chat, edit, apply]
    defaultCompletionOptions:
      contextLength: 4096
      maxTokens: 4096
      temperature: 0.1
```

3. บันทึก → `Ctrl+Shift+P` → `Developer: Reload Window`

**เช็ก:** เปิดแผง Continue → dropdown เลือกโมเดลเห็น 2 ตัว → เลือก 1.5B → พิมพ์ `hi` ในแชท → ต้องมีคำตอบกลับมา (รอ 10-30 วินาทีครั้งแรก)

> ⚠️ **สำคัญ:** ดูตัวเลือก mode มุมล่างซ้ายของกล่องพิมพ์ ต้องเป็น **Chat** (ไม่ใช่ Agent) — ถ้าเป็น Agent โมเดลจะตอบเป็นข้อความแปลก ๆ เหมือน JSON

---

## 5. XAMPP (Apache + PHP 8 + MySQL)

**ลง:** https://www.apachefriends.org/ → เลือกเวอร์ชัน **PHP 8.1 ขึ้นไป** (แนะนำ 8.2) → ลงที่ `C:\xampp` (Windows) — ติ๊ก component เฉพาะ Apache, MySQL, PHP, phpMyAdmin พอ

**เริ่มใช้:** เปิด **XAMPP Control Panel** → กด **Start** ที่ **Apache** และ **MySQL** → ต้องเขียวทั้งคู่

**เช็ก:**
- http://localhost → เห็นหน้า XAMPP Dashboard (สีส้ม)
- http://localhost/phpmyadmin → เข้าได้
- `C:\xampp\php\php.exe -v` → เห็น PHP 8.x

> ⚠️ **ถ้า Start แล้วแดง / localhost เจอหน้าอื่น** = port ชน ดูหัวข้อ 7 ด้านล่าง

---

## 6. curl

Windows 10+/macOS/Linux มีในตัว — **เช็ก:** `curl --version` เห็นเลขเวอร์ชัน

> ⚠️ PowerShell: `curl` อาจเป็น alias ของคำสั่งอื่น — ใน workshop ใช้ **cmd** หรือ **Git Bash** จะชัวร์กว่า

---

## 7. แก้ปัญหา port ชน (เครื่องที่เคยลง AppServ/IIS/XAMPP เก่า)

**อาการ:** XAMPP Control Panel กด Start แล้ว Apache/MySQL แดง หรือเปิด `http://localhost` เจอหน้าอื่น (AppServ/IIS/เว็บเก่า)

**เช็กว่าใครยึด port:**
```
netstat -ano | findstr ":80 :3306"
tasklist | findstr <PIDที่เจอ>
curl -I http://localhost     ← ดูบรรทัด Server: ถ้าเห็น PHP/7.x เก่าหรือชื่ออื่น = ไม่ใช่ XAMPP
```

**วิธีหยุด (ต้อง admin — เลือกวิธีเดียว):**
- **GUI:** `Win+R` → `services.msc` → หา service ที่เกี่ยว (ชื่อที่เจอบ่อย: `Apache24`, `Apache2.4`, `mysql8`, `MySQL57`, `wampapache`, `W3SVC` (IIS)) → คลิกขวา → **Stop**
- **cmd (Run as administrator):** `net stop Apache24` และ `net stop mysql8` → ต้องขึ้น "was stopped successfully" (ถ้า Access denied = ลืมเปิด admin)

**กันชนซ้ำ:** services.msc → คลิกขวา service เดิม → Properties → Startup type = **Manual** → ครั้งหน้าเปิดเครื่องจะไม่มาแย่ง port

**ถ้าหยุดไม่ได้ (ไม่มีสิทธิ์):** เปลี่ยน XAMPP ไป port อื่น — `C:\xampp\apache\conf\httpd.conf` แก้ `Listen 8080` + `ServerName localhost:8080` → URL ทั้งหมดในเอกสารเปลี่ยนเป็น `http://localhost:8080/...` (MySQL เปลี่ยนเป็น 3307 ใน `my.ini` + แก้ port ใน `lib/db.php` ด้วย)

---

## 8. Preflight Checklist — รันก่อนออกจากบ้าน

เปิด cmd/terminal รันทีละบรรทัด ต้องได้ผลตามนี้ทุกข้อ:

```
python --version                              → Python 3.x
code --version                                → เลขเวอร์ชัน
ollama list                                   → เห็น qwen2.5-coder:1.5b (+ 7b)
curl http://127.0.0.1:11434/api/version       → JSON {"version":...}
C:\xampp\php\php.exe -v                       → PHP 8.x
curl -I http://localhost                      → Server: Apache/... PHP/8.x  (หลัง start XAMPP)
```

+ เปิด VS Code → Continue → โมเดลตอบแชทได้ (mode = Chat)

ถ้าข้อไหนไม่ผ่าน → ย้อนหัวข้อนั้น หรือแจ้งผู้จัดพร้อม screenshot error ล่วงหน้า

---

## 9. แผนสำรอง (ถ้าวันงานมีปัญหา)

- **เน็ตหมด/โหลดโมเดลไม่ทัน** → ผู้จัดมีโมเดลบน USB (copy เข้า `C:\Users\<ชื่อ>\.ollama\models` หรือใช้ `ollama cp`)
- **เครื่องใครลงไม่ได้จริง ๆ** → จับคู่ใช้เครื่องเพื่อน (workshop ออกแบบเป็นทีม ทำบนเครื่องเดียวได้)
- **เครื่องช้าเกิน** → ใช้โมเดล 1.5B ตลอด (ช้ากว่าแต่ทำได้) + เขียน prompt ภาษาอังกฤษ
