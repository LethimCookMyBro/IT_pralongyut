<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';

const VALID_REVIEW_ACTIONS = ["confirm", "reject", "resolve"];

// Human-in-the-loop ทำกับ INCIDENT ไม่ใช่ raw observation.
// Flow: PENDING REVIEW -> CONFIRM/REJECT -> (ถ้า confirm) NEEDS CHECK -> RESOLVED
function post_review(): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_error("Invalid JSON", 400);
    }

    $id = $data['id'] ?? null;
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        json_error("id: ต้องเป็นจำนวนเต็ม", 422);
    }
    $id = (int)$id;
    if ($id < 1) {
        json_error("id: ต้องมากกว่า 0", 422);
    }

    $action = $data['action'] ?? null;
    if (!is_string($action) || !in_array($action, VALID_REVIEW_ACTIONS, true)) {
        json_error("action: ต้องเป็นหนึ่งใน " . implode(", ", VALID_REVIEW_ACTIONS), 422);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM vision_incidents WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            json_error("ไม่พบ incident นี้", 404);
        }

        if ($action === 'confirm' || $action === 'reject') {
            if ($row['review_status'] !== 'pending') {
                $pdo->rollBack();
                json_error("incident นี้ถูกตรวจสอบไปแล้ว (review_status={$row['review_status']})", 409);
            }

            if ($action === 'confirm') {
                $update = $pdo->prepare(
                    "UPDATE vision_incidents
                     SET review_status = 'confirmed',
                         action_status = 'needs_check',
                         reviewed_at = NOW()
                     WHERE id = :id"
                );
            } else {
                $update = $pdo->prepare(
                    "UPDATE vision_incidents
                     SET review_status = 'rejected',
                         reviewed_at = NOW()
                     WHERE id = :id"
                );
            }
            $update->execute(['id' => $id]);
        } elseif ($action === 'resolve') {
            if ($row['review_status'] !== 'confirmed' || $row['action_status'] !== 'needs_check') {
                $pdo->rollBack();
                json_error(
                    "ต้อง Confirm incident ก่อน ถึงจะ Resolve ได้ " .
                    "(review_status={$row['review_status']}, action_status={$row['action_status']})",
                    409
                );
            }
            $update = $pdo->prepare(
                "UPDATE vision_incidents
                 SET action_status = 'resolved', resolved_at = NOW()
                 WHERE id = :id"
            );
            $update->execute(['id' => $id]);
        }

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
        'POST' => post_review(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    json_error("database error", 500);
}
