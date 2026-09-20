<?php
declare(strict_types=1);

require __DIR__ . "/../lib/cli_only.php";

/**
 * clear_demo.php — ลบ "ข้อมูลตัวอย่างสำหรับเดโม" ที่ tools/seed_demo.php ใส่ไว้
 *
 * ทำไมต้องมี:
 * เดโมต้องมีข้อมูลพอให้เห็น pagination / filter / สถานะ แต่หลังเวิร์กช็อปต้องเอาออกได้
 * ไม่ให้ค้างอยู่จนใครอ่านเป็นข้อมูลจริงของเทศบาล
 *
 * ขอบเขตการลบ — ตัดสินจากคอลัมน์ record_origin เท่านั้น:
 *   reports            WHERE record_origin = 'demo_seed'
 *   vision_observations WHERE record_origin = 'demo_seed'
 *   vision_incidents   WHERE record_origin = 'demo_seed'
 * แถวของคนแจ้งจริง (citizen) และแถวจาก detector จริง (detector_run) ไม่ถูกแตะ
 *
 * ค่าเริ่มต้นคือ dry-run: นับให้ดูแต่ไม่ลบ ต้องใส่ --yes ถึงจะลบจริง
 *   C:\xampp\php\php.exe afternoon\tools\clear_demo.php          # นับ ไม่ลบ
 *   C:\xampp\php\php.exe afternoon\tools\clear_demo.php --yes    # ลบจริง
 *
 * ต่างจาก sql/schema.sql: ไฟล์นั้น DROP ทั้งตาราง ไฟล์นี้ลบเฉพาะแถวที่ติดป้ายว่าเป็นตัวอย่าง
 */

$apply = in_array('--yes', array_slice($argv, 1), true);

require __DIR__ . "/../lib/db.php";
$pdo = db();

// observations ก่อน incidents — observation ของ incident ตัวอย่างต้องหายไปด้วย
// ไม่ใช่ค้างเป็น incident_id = NULL (FK เป็น ON DELETE SET NULL)
$targets = [
    'reports' => "record_origin = 'demo_seed'",
    'vision_observations' => "record_origin = 'demo_seed'",
    'vision_incidents' => "record_origin = 'demo_seed'",
];

echo $apply ? "ลบข้อมูลตัวอย่าง (demo_seed)\n" : "dry-run — นับเท่านั้น ยังไม่ลบ (ใส่ --yes เพื่อลบจริง)\n";

$total = 0;
foreach ($targets as $table => $condition) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM $table WHERE $condition")->fetchColumn();
    if ($apply && $count > 0) {
        $count = $pdo->exec("DELETE FROM $table WHERE $condition");
    }
    $total += $count;
    printf("  %-20s %s %d แถว\n", $table, $apply ? 'ลบแล้ว' : 'จะลบ', $count);
}

echo $total === 0
    ? "ไม่มีข้อมูลตัวอย่างค้างอยู่\n"
    : ($apply ? "รวม $total แถว\n" : "รวม $total แถว — รันซ้ำด้วย --yes เพื่อลบ\n");
