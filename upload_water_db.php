<?php
header('Content-Type: application/json; charset=utf-8');

// 1. DB 연결 설정 (프로젝트 환경에 맞게 수정하세요)
$host = 'localhost';
$db   = 'your_database_name';
$user = 'your_db_user';
$pass = 'your_db_password';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB 연결 실패: ' . $e->getMessage()]);
    exit;
}

// 2. POST 데이터 검증
$region = $_POST['region'] ?? '';
$type   = $_POST['type'] ?? 'water';

if (empty($region) || !isset($_FILES['csv_file'])) {
    echo json_encode(['success' => false, 'message' => '지역명과 CSV 파일은 필수 항목입니다.']);
    exit;
}

$file = $_FILES['csv_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '파일 업로드 중 오류가 발생했습니다.']);
    exit;
}

// 3. 파일명에서 col, row 자동 파싱 (형식: water_3_5.csv 등)
// 만약 파싱에 실패하면 기본값 0으로 처리합니다.
$filename = $file['name'];
$col = 0;
$row = 0;
if (preg_match('/_(\d+)_(\d+)\.csv$/i', $filename, $matches)) {
    $col = (int)$matches[1];
    $row = (int)$matches[2];
}

// 4. CSV 파일 파싱 및 벌크 인서트(Bulk Insert) 준비
$rows = [];
if (($handle = fopen($file['tmp_name'], 'r')) !== FALSE) {
    // 헤더 행 건너뛰기 (river_id,point_index,x,y)
    fgetcsv($handle, 1000, ","); 
    
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($data) < 4) continue;
        
        $river_id    = (int)$data[0];
        $point_index = (int)$data[1];
        $x           = (float)$data[2];
        $y           = (float)$data[3];
        
        $rows[] = [$region, $type, $col, $row, $river_id, $point_index, $x, $y];
    }
    fclose($handle);
}

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => '정상적인 데이터가 존재하지 않거나 빈 CSV 파일입니다.']);
    exit;
}

// 5. 대량 다중 행 Insert 실행 (성능 최적화)
try {
    $pdo->beginTransaction();
    
    // 삽입할 행의 개수가 많을 수 있으므로 대량 삽입용 쿼리 빌드
    $rowPlaces = array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?, ?)');
    $allPlaces = implode(', ', $rowPlaces);
    
    $sql = "INSERT INTO `river_points` 
            (`region`, `type`, `col`, `row`, `river_id`, `point_index`, `x`, `y`) 
            VALUES " . $allPlaces;
            
    $stmt = $pdo->prepare($sql);
    
    // 1차원 배열로 플랫화하여 바인딩
    $flatValues = [];
    foreach ($rows as $r) {
        foreach ($r as $val) {
            $flatValues[] = $val;
        }
    }
    
    $stmt->execute($flatValues);
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => "성공적으로 DB에 저장되었습니다! (총 " . count($rows) . "개의 포인트 삽입 완료)",
        'parsed_grid' => "Grid: ($col, $row)"
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'DB 트랜잭션 오류: ' . $e->getMessage()]);
}