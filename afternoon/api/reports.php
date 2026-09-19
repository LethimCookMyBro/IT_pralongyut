<?php
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/response.php';
require __DIR__ . '/../lib/validate.php';

function get_reports(): void
{
    $type = $_GET['type'] ?? null;
    $sql = "SELECT * FROM reports";
    $params = [];
    if ($type) {
        $sql .= " WHERE waste_type = :type";
        $params['type'] = $type;
    }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT 20";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function post_report(): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_error("Invalid JSON", 400);
    }

    try {
        $clean = validate_report($data);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "INSERT INTO reports (location, waste_type, amount_kg, detail)
         VALUES (:location, :waste_type, :amount_kg, :detail)"
    );
    $stmt->execute($clean);
    json_response(["id" => (int)$pdo->lastInsertId()] + $clean, 201);
}

try {
    match ($_SERVER['REQUEST_METHOD']) {
        'GET' => get_reports(),
        'POST' => post_report(),
        default => json_error("Method Not Allowed", 405),
    };
} catch (PDOException $e) {
    json_error("database error", 500);
}
