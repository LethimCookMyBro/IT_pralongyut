<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/response.php';

const VALID_REPORT_REVIEW_ACTIONS = ['accept', 'reject', 'resolve'];

function post_report_review(): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_error('Invalid JSON', 400);
    }

    $id = $data['id'] ?? null;
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        json_error('id: ต้องเป็นจำนวนเต็ม', 422);
    }
    $id = (int)$id;
    if ($id < 1) {
        json_error('id: ต้องมากกว่า 0', 422);
    }

    $action = $data['action'] ?? null;
    if (!is_string($action) || !in_array($action, VALID_REPORT_REVIEW_ACTIONS, true)) {
        json_error('action: ต้องเป็นหนึ่งใน ' . implode(', ', VALID_REPORT_REVIEW_ACTIONS), 422);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            json_error('ไม่พบ report นี้', 404);
        }

        if (($action === 'accept' || $action === 'reject') && $row['status'] !== 'PENDING') {
            $pdo->rollBack();
            json_error("report นี้ผ่านการตรวจแล้ว (status={$row['status']})", 409);
        }
        if ($action === 'resolve' && $row['status'] !== 'NEEDS_CHECK') {
            $pdo->rollBack();
            json_error("ปิดงานได้เฉพาะ report ที่รับเรื่องแล้ว (status={$row['status']})", 409);
        }

        $sql = match ($action) {
            'accept' => "UPDATE reports SET status = 'NEEDS_CHECK', reviewed_at = NOW() WHERE id = :id",
            'reject' => "UPDATE reports SET status = 'REJECTED', reviewed_at = NOW() WHERE id = :id",
            'resolve' => "UPDATE reports SET status = 'RESOLVED', resolved_at = NOW() WHERE id = :id",
        };
        $pdo->prepare($sql)->execute(['id' => $id]);
        $stmt = $pdo->prepare('SELECT id, status, reviewed_at, resolved_at FROM reports WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        json_response($updated);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'POST' => post_report_review(),
        default => json_error('Method Not Allowed', 405),
    };
} catch (PDOException $e) {
    json_error('database error', 500);
}
