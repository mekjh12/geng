<?php
// ════════════════════════════════════════════
// load_water_height.php
// 수면 RAW 파일 조회
//
// GET:
//   region : 지역명
//   type   : 타입 (water 등)
//   col    : 셀 열
//   row    : 셀 행
//
// 응답:
//   파일 존재 시 → application/octet-stream (binary)
//   파일 없음    → application/json { success: false, message: ... }
// ════════════════════════════════════════════

try {
    $region = $_GET['region'] ?? '';
    $type   = $_GET['type']   ?? '';
    $col    = $_GET['col']    ?? null;
    $row    = $_GET['row']    ?? null;

    // 필수값 검증
    if ($region === '' || $type === '') throw new Exception('region/type 누락');
    if ($col === null  || $row === null) throw new Exception('col/row 누락');

    // 경로 traversal 방지
    $region = basename($region);
    $type   = basename($type);

    $filename = __DIR__ . '/data/' . $region . '/' . $type
              . '/water_height_' . intval($col) . '_' . intval($row) . '.raw';

    if (!file_exists($filename)) {
        throw new Exception("파일 없음: water_height_{$col}_{$row}.raw");
    }

    $binary = file_get_contents($filename);
    if ($binary === false) {
        throw new Exception('파일 읽기 실패');
    }

    // Float32Array(65×65) 크기 검증
    $expectedBytes = 65 * 65 * 4;
    if (strlen($binary) !== $expectedBytes) {
        throw new Exception(
            '파일 크기 불일치: ' . strlen($binary) . ' bytes (예상 ' . $expectedBytes . ')'
        );
    }

    // 바이너리 그대로 응답
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: no-cache');
    echo $binary;

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}