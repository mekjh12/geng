<?php
// ════════════════════════════════════════════
// save_water_height.php
// 수면 RAW 파일 저장
//
// POST JSON:
//   region : 지역명
//   type   : 타입 (water 등)
//   col    : 셀 열
//   row    : 셀 행
//   data   : Float32Array base64 인코딩 문자열
// ════════════════════════════════════════════

header('Content-Type: application/json');

try {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!$body) {
        throw new Exception('요청 파싱 실패');
    }

    $region = $body['region'] ?? '';
    $type   = $body['type']   ?? '';
    $col    = $body['col']    ?? null;
    $row    = $body['row']    ?? null;
    $data   = $body['data']   ?? '';

    // 필수값 검증
    if ($region === '' || $type === '') throw new Exception('region/type 누락');
    if ($col === null  || $row === null) throw new Exception('col/row 누락');
    if ($data === '')                    throw new Exception('data 누락');

    // base64 디코딩
    $binary = base64_decode($data, true);
    if ($binary === false) throw new Exception('base64 디코딩 실패');

    // Float32Array(65×65) = 4225개 × 4바이트 = 16900바이트 검증
    $expectedBytes = 65 * 65 * 4;
    if (strlen($binary) !== $expectedBytes) {
        throw new Exception(
            '데이터 크기 불일치: ' . strlen($binary) . ' bytes (예상 ' . $expectedBytes . ')'
        );
    }

    // 저장 디렉토리 생성
    $dir = __DIR__ . '/data/' . $region . '/' . $type;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new Exception('디렉토리 생성 실패: ' . $dir);
        }
    }

    // 파일 저장
    $filename = $dir . '/water_height_' . intval($col) . '_' . intval($row) . '.raw';
    $result   = file_put_contents($filename, $binary);

    if ($result === false) {
        throw new Exception('파일 저장 실패: ' . $filename);
    }

    echo json_encode([
        'success' => true,
        'message' => "저장 완료: water_height_{$col}_{$row}.raw ({$result} bytes)",
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}