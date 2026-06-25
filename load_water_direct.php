<?php
header('Content-Type: application/json; charset=utf-8');

// ── DB 연결 설정 ─────────────────────────────────────
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

// ── 파라미터 수신 ────────────────────────────────────
$region = trim($_GET['region'] ?? '');
$type   = trim($_GET['type']   ?? 'water');
$col    = isset($_GET['col'])   ? (int)$_GET['col'] : -1;
$row    = isset($_GET['row'])   ? (int)$_GET['row'] : -1;

// ── 검증 ─────────────────────────────────────────────
if (empty($region) || $col < 0 || $row < 0) {
    echo json_encode(['success' => false, 'message' => '유효하지 않은 요청 파라미터입니다.']);
    exit;
}

$allowed_types = ['water', 'mountain', 'road'];
if (!in_array($type, $allowed_types, true)) {
    echo json_encode(['success' => false, 'message' => '허용되지 않는 type 값입니다.']);
    exit;
}

// ── 쿼리 ─────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT `river_id`, `point_index`, `x`, `y`, `z`
         FROM   `river_points`
         WHERE  `region` = :region
           AND  `type`   = :type
           AND  `col`    = :col
           AND  `row`    = :row
         ORDER  BY `river_id`, `point_index`"
    );
    $stmt->execute([
        ':region' => $region,
        ':type'   => $type,
        ':col'    => $col,
        ':row'    => $row,
    ]);

    // river_id 기준으로 포인트 그룹핑
    $grouped = [];
    foreach ($stmt->fetchAll() as $p) {
        $grouped[$p['river_id']][] = ['x' => (float)$p['x'], 'y' => (float)$p['y'], 'z' => (float)($p['z'] ?? 0)];
    }

    echo json_encode([
        'success'  => true,
        'count'    => count($grouped),
        'features' => array_values($grouped),
    ]);

} catch (Exception $e) {
    error_log('[water-gis] 조회 실패: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB 조회 중 오류가 발생했습니다.']);
}
?>