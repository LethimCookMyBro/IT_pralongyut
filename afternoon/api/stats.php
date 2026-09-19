<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';

// เหมือน morning/waste_logic.py::proper_disposal_rate แต่ใช้กับตัวเลขจาก DB โดยตรง
function proper_disposal_rate(float $generated_tpd, float $proper_tpd): ?float
{
    if ($generated_tpd <= 0) {
        return null;
    }
    return round($proper_tpd / $generated_tpd * 100, 1);
}

function get_stats(): void
{
    $pdo = db();

    $latest_year = $pdo->query("SELECT MAX(year) FROM waste_stats")->fetchColumn();
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)$latest_year;

    $years_stmt = $pdo->query("SELECT DISTINCT year FROM waste_stats ORDER BY year");
    $years = array_map('intval', $years_stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare(
        "SELECT district, local_gov, generated_tpd, collected_tpd, utilized_tpd, proper_tpd, improper_tpd
         FROM waste_stats WHERE year = :year ORDER BY generated_tpd DESC"
    );
    $stmt->execute(['year' => $year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'total_generated_tpd' => 0.0,
        'total_collected_tpd' => 0.0,
        'total_utilized_tpd' => 0.0,
        'total_proper_tpd' => 0.0,
        'total_improper_tpd' => 0.0,
    ];
    foreach ($rows as &$row) {
        foreach (['generated_tpd', 'collected_tpd', 'utilized_tpd', 'proper_tpd', 'improper_tpd'] as $field) {
            $row[$field] = $row[$field] !== null ? (float)$row[$field] : null;
        }
        $row['proper_disposal_rate'] = proper_disposal_rate($row['generated_tpd'], $row['proper_tpd']);
        $summary['total_generated_tpd'] += $row['generated_tpd'];
        $summary['total_collected_tpd'] += $row['collected_tpd'] ?? 0;
        $summary['total_utilized_tpd'] += $row['utilized_tpd'] ?? 0;
        $summary['total_proper_tpd'] += $row['proper_tpd'];
        $summary['total_improper_tpd'] += $row['improper_tpd'] ?? 0;
    }
    unset($row);
    $summary['proper_disposal_rate'] = proper_disposal_rate(
        $summary['total_generated_tpd'],
        $summary['total_proper_tpd']
    );

    json_response([
        'year' => $year,
        'years' => $years,
        'summary' => $summary,
        'top5_generated' => array_slice($rows, 0, 5),
        'rows' => $rows,
    ]);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_stats(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    json_error("database error", 500);
}
