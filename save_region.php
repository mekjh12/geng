<?php
header('Content-Type: application/json; charset=utf-8');

$host = 'localhost'; $db = 'mekjh12'; $user = 'mekjh12';
$pass = 'gh041116393!'; $charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (\PDOException $e) {
    error_log('[water-gis] DB 연결 실패: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB 연결에 실패했습니다.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if ($input === null) {
    echo json_encode(['success' => false, 'message' => 'JSON 파싱 실패']);
    exit;
}

$region = trim($input['region'] ?? '');
$lng    = (float)($input['lng'] ?? 0);
$lat    = (float)($input['lat'] ?? 0);
$type   = trim($input['type']   ?? 'water');

if (empty($region) || !is_finite($lng) || !is_finite($lat)) {
    echo json_encode(['success' => false, 'message' => '유효하지 않은 파라미터입니다.']);
    exit;
}

$allowed_types = ['water', 'mountain', 'road'];
if (!in_array($type, $allowed_types, true)) $type = 'water';

try {
    $pdo->prepare(
        "INSERT INTO `regions` (`region`, `lng`, `lat`, `type`)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE `lng` = VALUES(`lng`), `lat` = VALUES(`lat`), `type` = VALUES(`type`)"
    )->execute([$region, $lng, $lat, $type]);

    echo json_encode(['success' => true, 'message' => "지역 [{$region}] 저장 완료"]);
} catch (Exception $e) {
    error_log('[water-gis] 지역 저장 실패: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB 저장 중 오류가 발생했습니다.']);
}
?>