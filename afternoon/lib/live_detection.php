<?php
declare(strict_types=1);

// Runtime contract between the local Python detector worker and the PHP UI.
// The worker writes latest.json/latest.jpg atomically under afternoon/runtime/vision.
// This file never starts a detector and never reads a path from user input.

const LIVE_DETECTION_STALE_SECONDS = 6;

function live_detection_runtime_dir(): string
{
    return __DIR__ . '/../runtime/vision';
}

function live_detection_offline(string $status, string $message): array
{
    return [
        'available' => false,
        'status' => $status,
        'message' => $message,
        'age_seconds' => null,
        'frame_url' => null,
        'snapshot' => null,
    ];
}

function live_detection_read(?string $runtimeDir = null, ?int $now = null): array
{
    $runtimeDir ??= live_detection_runtime_dir();
    $now ??= time();

    $jsonPath = rtrim($runtimeDir, '/\\') . DIRECTORY_SEPARATOR . 'latest.json';
    $imagePath = rtrim($runtimeDir, '/\\') . DIRECTORY_SEPARATOR . 'latest.jpg';

    if (!is_file($jsonPath)) {
        return live_detection_offline(
            'offline',
            'ตัวตรวจจับ Local AI ไม่ได้ทำงานบน deployment นี้'
        );
    }

    $raw = @file_get_contents($jsonPath);
    if ($raw === false || trim($raw) === '') {
        return live_detection_offline('invalid', 'อ่านสถานะตัวตรวจจับไม่ได้');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return live_detection_offline('invalid', 'ข้อมูลสถานะตัวตรวจจับไม่ถูกต้อง');
    }

    $requiredStrings = ['generated_at', 'source_id', 'source_label', 'camera_name', 'location', 'area_type', 'model'];
    foreach ($requiredStrings as $key) {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
            return live_detection_offline('invalid', 'ข้อมูลสถานะตัวตรวจจับไม่ครบ');
        }
    }

    if (!isset($data['generated_at_epoch']) || !is_numeric($data['generated_at_epoch'])) {
        return live_detection_offline('invalid', 'ข้อมูลเวลาของตัวตรวจจับไม่ถูกต้อง');
    }
    if (!isset($data['detected_count']) || is_bool($data['detected_count']) || !is_int($data['detected_count']) || $data['detected_count'] < 0) {
        return live_detection_offline('invalid', 'ข้อมูลจำนวนที่ตรวจพบไม่ถูกต้อง');
    }

    $maxConfidence = $data['max_confidence'] ?? null;
    if ($maxConfidence !== null) {
        if (is_bool($maxConfidence) || !is_numeric($maxConfidence)) {
            return live_detection_offline('invalid', 'ข้อมูลความมั่นใจไม่ถูกต้อง');
        }
        $maxConfidence = (float)$maxConfidence;
        if ($maxConfidence < 0 || $maxConfidence > 1) {
            return live_detection_offline('invalid', 'ข้อมูลความมั่นใจอยู่นอกช่วง 0-1');
        }
    }

    $classes = $data['classes'] ?? [];
    if (!is_array($classes)) {
        $classes = [];
    }
    $cleanClasses = [];
    foreach ($classes as $name => $count) {
        if (is_string($name) && is_int($count) && $count >= 0) {
            $cleanClasses[$name] = $count;
        }
    }

    $epoch = (int)floor((float)$data['generated_at_epoch']);
    $age = max(0, $now - $epoch);
    $status = $age > LIVE_DETECTION_STALE_SECONDS ? 'stale' : 'live';

    $frameUrl = null;
    if (is_file($imagePath)) {
        $mtime = @filemtime($imagePath);
        $frameUrl = 'runtime/vision/latest.jpg?v=' . ($mtime === false ? $epoch : $mtime);
    }

    return [
        'available' => true,
        'status' => $status,
        'message' => $status === 'live'
            ? 'ตัวตรวจจับ Local AI กำลังทำงาน'
            : 'ไม่ได้รับเฟรมใหม่ในช่วงเวลาที่กำหนด — แสดงผลล่าสุดที่มี',
        'age_seconds' => $age,
        'frame_url' => $frameUrl,
        'snapshot' => [
            'generated_at' => $data['generated_at'],
            'source_id' => $data['source_id'],
            'source_label' => $data['source_label'],
            'camera_name' => $data['camera_name'],
            'location' => $data['location'],
            'area_type' => $data['area_type'],
            'model' => $data['model'],
            'detected_count' => $data['detected_count'],
            'max_confidence' => $maxConfidence,
            'classes' => $cleanClasses,
            'inference_ms' => isset($data['inference_ms']) && is_numeric($data['inference_ms'])
                ? round((float)$data['inference_ms'], 1)
                : null,
            'last_post_status' => isset($data['last_post_status']) && is_int($data['last_post_status'])
                ? $data['last_post_status']
                : null,
            'last_post_at' => isset($data['last_post_at']) && is_string($data['last_post_at'])
                ? $data['last_post_at']
                : null,
            'last_post_error' => isset($data['last_post_error']) && is_string($data['last_post_error'])
                ? $data['last_post_error']
                : null,
        ],
    ];
}
