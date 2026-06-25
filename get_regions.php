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

try {
    $stmt = $pdo->query(
        "SELECT `region`, `lng`, `lat`, `type`
         FROM   `regions`
         ORDER  BY `region` ASC"
    );

    $rows = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'regions' => array_map(function($r) {
            return [
                'name' => $r['region'],
                'lng'  => (float)$r['lng'],
                'lat'  => (float)$r['lat'],
                'type' => $r['type'],
            ];
        }, $rows),
    ]);
} catch (Exception $e) {
    error_log('[water-gis] 지역 조회 실패: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB 조회 중 오류가 발생했습니다.']);
}
?>