<?php
header('Content-Type: application/json; charset=utf-8');

const CHUNK_SIZE  = 500;    // 1회 INSERT 최대 포인트 수
const COORD_MAX   = 1081.0; // 유효 좌표 범위 0 ~ COORD_MAX

// ── DB 연결 ──────────────────────────────────────────
$host    = 'localhost';
$db      = 'mekjh12';
$user    = 'mekjh12';
$pass    = 'gh041116393!';
$charset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=$charset",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (\PDOException $e) {
    error_log('[water-gis] DB 연결 실패: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB 연결에 실패했습니다.']);
    exit;
}

// ── JSON 수신 ────────────────────────────────────────
$inputData = json_decode(file_get_contents('php://input'), true);
if ($inputData === null) {
    echo json_encode(['success' => false, 'message' => 'JSON 파싱 실패: ' . json_last_error_msg()]);
    exit;
}

$region   = trim($inputData['region']   ?? '');
$type     = trim($inputData['type']     ?? 'water');
$col      = isset($inputData['col'])    ? (int)$inputData['col']    : 0;
$row      = isset($inputData['row'])    ? (int)$inputData['row']    : 0;
$features = $inputData['features']      ?? [];

// ── 검증 ─────────────────────────────────────────────
if (empty($region)) {
    echo json_encode(['success' => false, 'message' => '지역명(region)은 필수 항목입니다.']);
    exit;
}

$allowed_types = ['water', 'mountain', 'road'];
if (!in_array($type, $allowed_types, true)) {
    echo json_encode(['success' => false, 'message' => '허용되지 않는 type 값입니다.']);
    exit;
}

if (empty($features)) {
    echo json_encode(['success' => false, 'message' => '저장할 도형 데이터가 없습니다.']);
    exit;
}

// ── 포인트 행 구성 ────────────────────────────────────
$insertRows = [];
$riverId    = 0;

foreach ($features as $f) {
    $points = $f['points'] ?? [];
    if (empty($points)) continue;

    foreach ($points as $idx => $pt) {
        $x = (float)($pt['x'] ?? 0);
        $y = (float)($pt['y'] ?? 0);

        // 좌표 유효성 검사 (NaN, INF만 차단 — 픽셀 범위는 셀마다 다르므로 검사하지 않음)
        if (!is_finite($x) || !is_finite($y)) {
            echo json_encode(['success' => false, 'message' => "유효하지 않은 좌표값: x={$x}, y={$y}"]);
            exit;
        }

        $z = (float)($pt['z'] ?? 0);
        $insertRows[] = [$region, $type, $col, $row, $riverId, $idx, $x, $y, $z];
    }
    $riverId++;
}

// ── 트랜잭션 ─────────────────────────────────────────
try {
    $pdo->beginTransaction();

	// 해당 타일 기존 데이터 삭제
	$pdo->prepare(
		"DELETE FROM `river_points`
		 WHERE `region` = ? AND `type` = ? AND `col` = ? AND `row` = ?"
	)->execute([$region, $type, $col, $row]);

    // 청크 단위 Bulk INSERT
    $chunks = array_chunk($insertRows, CHUNK_SIZE);
    foreach ($chunks as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?)'));
        $stmt = $pdo->prepare(
            "INSERT INTO `river_points`
                (`region`, `type`, `col`, `row`, `river_id`, `point_index`, `x`, `y`, `z`)
             VALUES $placeholders
             ON DUPLICATE KEY UPDATE `x` = VALUES(`x`), `y` = VALUES(`y`), `z` = VALUES(`z`)"
        );
        // 2차원 배열 평탄화
        $flat = array_merge(...array_map('array_values', $chunk));
        $stmt->execute($flat);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "서브타일 ({$col}, {$row}) 저장 완료\n총 " . count($insertRows) . "개 포인트 ({$riverId}개 도형)",
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[water-gis] 저장 실패: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB 오류: ' . $e->getMessage()]);
}
?>