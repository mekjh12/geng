// ── Road CSV 로드 ─────────────────────────────────────────────────
roadInput.addEventListener('change', function(e) {
	const file = e.target.files[0];
	if (!file) return;

	const reader = new FileReader();
	reader.onload = (ev) => {
		const lines = ev.target.result.trim().split('\n');
		// 헤더로 구분자 자동 감지
		const delimiter = lines[0].includes('\t') ? '\t' : ',';

		const roadMap = {};
		for (let i = 1; i < lines.length; i++) {
			const cols = lines[i].split(delimiter); // ← 감지된 구분자 사용
			if (cols.length < 4) continue;          // ← != 4 대신 < 4 (후행 공백 컬럼 허용)

			const id = cols[0].trim();
			const pi = parseInt(cols[1].trim());
			const rx = parseFloat(cols[2].trim());
			const ry = parseFloat(cols[3].trim());
			if (isNaN(rx) || isNaN(ry) || isNaN(pi)) continue;

			// 원본 좌표(SOURCE_FULL_SIZE 기준)를 출력 해상도로 리매핑
			const px = (rx / SOURCE_FULL_SIZE) * FULL_OUTPUT_SIZE;
			const py = (ry / SOURCE_FULL_SIZE) * FULL_OUTPUT_SIZE;
			
			if (!roadMap[id]) roadMap[id] = [];
			roadMap[id][pi] = { px, py };					
		}

		roadSegments = Object.values(roadMap).map(pts => ({
			points: pts.filter(Boolean)
		}));

		const totalPts = roadSegments.reduce((s, seg) => s + seg.points.length, 0);
		roadStatus.innerText = `✓ ${roadSegments.length}개 도로/ ${totalPts}개 포인트 로드됨!`;

		applyRoads();
	};
	reader.readAsText(file);
});


/**
 * 두 점 p0→p1 사이의 도로 선분을 두께 있는 평행사변형으로 래스터라이즈한다.
 *
 * 기본 아이디어:
 *   선분을 중심으로 법선 방향 ±halfWidth 만큼 벌린 4개의 꼭짓점
 *   A, B, C, D 로 이루어진 평행사변형을 정의하고,
 *   Y행마다 스캔라인을 그어 xMin~xMax 범위를 빈틈 없이 채운다.
 *
 *   꼭짓점 배치:
 *     A(ax0,ay0) ──────── C(ax1,ay1)   ← +법선 방향 에지
 *          \                    \
 *     B(bx0,by0) ──────── D(bx1,by1)   ← -법선 방향 에지
 *
 * 높이 보간:
 *   픽셀 (x,y)를 선분 위에 수직 투영한 비율 t를 내적으로 구한 뒤
 *   h0와 h1 사이를 선형 보간한다.
 *
 *   방향벡터: d = P1 - P0 = (dx, dy)
 *
 *   t = clamp( ((x-p0x)*dx + (y-p0y)*dy) / |d|² , 0, 1 )
 *   H = H0 + (H1 - H0) * t
 */
function drawThickLineFilledScanline(p0, p1, halfWidth, getHeight, setPixel) {
    const dx = p1.px - p0.px;
    const dy = p1.py - p0.py;
    const len = Math.sqrt(dx * dx + dy * dy);
    if (len === 0) return;

    const nx = -dy / len;
    const ny =  dx / len;

    const ax0 = p0.px + nx * halfWidth,  ay0 = p0.py + ny * halfWidth;
    const ax1 = p1.px + nx * halfWidth,  ay1 = p1.py + ny * halfWidth;
    const bx0 = p0.px - nx * halfWidth,  by0 = p0.py - ny * halfWidth;
    const bx1 = p1.px - nx * halfWidth,  by1 = p1.py - ny * halfWidth;

    const minY = Math.floor(Math.min(ay0, ay1, by0, by1));
    const maxY = Math.ceil( Math.max(ay0, ay1, by0, by1));

    // ── 끝점 높이 유효성 체크만 유지 (선형보간 계수 h0/h1 제거) ──
    const h0 = getHeight(Math.round(p0.px), Math.round(p0.py));
    const h1 = getHeight(Math.round(p1.px), Math.round(p1.py));
    if (h0 < 0 || h1 < 0) return;

    const invLenSq = 1.0 / (len * len);

    for (let y = minY; y <= maxY; y++) {
        let xMin = Infinity;
        let xMax = -Infinity;

        const edges = [
            [ax0, ay0, ax1, ay1], [bx0, by0, bx1, by1],
            [ax0, ay0, bx0, by0], [ax1, ay1, bx1, by1]
        ];

        for (const [x1, y1, x2, y2] of edges) {
            if ((y1 <= y && y2 > y) || (y2 <= y && y1 > y)) {
                const ix = x1 + (x2 - x1) * (y - y1) / (y2 - y1);
                if (ix < xMin) xMin = ix;
                if (ix > xMax) xMax = ix;
            }
        }

        if (xMin > xMax) continue;

        for (let x = Math.floor(xMin); x <= Math.ceil(xMax); x++) {
            // 선분 위의 수직 투영점 계산
            const t = Math.max(0, Math.min(1,
                ((x - p0.px) * dx + (y - p0.py) * dy) * invLenSq
            ));

            // ── 핵심 변경: t 보간 대신 투영점 좌표를 직접 샘플링 ──
            const projX = Math.round(p0.px + dx * t);
            const projY = Math.round(p0.py + dy * t);
            const targetH = getHeight(projX, projY);

            if (targetH < 0) continue; // 투영점이 유효 범위 밖이면 스킵

            setPixel(x, y, targetH, 2);	// bit 1 (값   2 = 0000_0010) : 길
        }
    }
}

function setPixel(gx, gy, height16, bitflag) {
    // 1. 범위 체크 (정수로 변환하여 비교)
    const x = Math.floor(gx);
    const y = Math.floor(gy);
    if (x < 0 || y < 0 || x >= FULL_OUTPUT_SIZE || y >= FULL_OUTPUT_SIZE) return;

    // 2. 타일 좌표 계산
    const tileCol = (x / OUTPUT_TILE_SIZE) | 0; // Math.floor 대신 비트 연산으로 고속화
    const tileRow = (y / OUTPUT_TILE_SIZE) | 0;
    const tileIdx = tileRow * GRID_COUNT + tileCol; 

    const td = tileImageDatas[tileIdx];
    if (!td) return;

    // 3. 타일 내 로컬 인덱스 계산
    const lx = x % OUTPUT_TILE_SIZE;
    const ly = y % OUTPUT_TILE_SIZE;
    const i = (ly * OUTPUT_TILE_SIZE + lx) * 4;

    // 4. 데이터 저장 (16비트 분할 및 마커)
    td.data[i]     = (height16 >> 8) & 0xFF; // R: 상위 8비트
    td.data[i + 1] = height16 & 0xFF;        // G: 하위 8비트
    td.data[i + 2] = bitflag;                    // B:  Marker
    td.data[i + 3] = 255;                    // A: Alpha (반드시 채워야 캔버스에 보임)

	/*
	bit 0 (값   1 = 0000_0001) : 강
	bit 1 (값   2 = 0000_0010) : 길
	bit 2 (값   4 = 0000_0100) : 초원
	bit 3 (값   8 = 0000_1000) : 숲
	bit 4 (값  16 = 0001_0000) : 설원
	bit 5 (값  32 = 0010_0000) : 도시
	bit 6 (값  64 = 0100_0000) : 암석
	bit 7 (값 128 = 1000_0000) : (예비)
	*/
}

function getHeight(gx, gy) {
		if (gx < 0 || gy < 0 || gx >= FULL_OUTPUT_SIZE || gy >= FULL_OUTPUT_SIZE) return -1;
		const tileCol = Math.floor(gx / OUTPUT_TILE_SIZE);
		const tileRow = Math.floor(gy / OUTPUT_TILE_SIZE);
		const tileIdx = tileRow * GRID_COUNT + tileCol;
		const td = tileImageDatas[tileIdx];
		if (!td) return -1;
		const lx = gx % OUTPUT_TILE_SIZE;
		const ly = gy % OUTPUT_TILE_SIZE;
		const i = (ly * OUTPUT_TILE_SIZE + lx) * 4;
		return td.data[i] * 256 + td.data[i + 1];
	}
	
	

/**
 * 모든 도로 선분에 drawThickLineFilledScanline을 적용하고 캔버스에 반영한다.
 */
function applyRoads() {
	
    if (tileImageDatas.every(d => d === null)) return;
    const ROAD_HALF_WIDTH = 3; // 도로 반폭 (픽셀 단위, 전체 폭 = halfWidth * 2)

    for (const seg of roadSegments) {
        const pts = seg.points;
        if (pts.length < 2) continue;

        // 연속된 점 쌍마다 선분 하나씩 처리
        for (let si = 0; si < pts.length - 1; si++) {
            drawThickLineFilledScanline(
                pts[si], pts[si + 1],
                ROAD_HALF_WIDTH,
                getHeight,
                setPixel
            );
        }
    }

    // 변경된 ImageData를 각 타일 캔버스에 반영
    for (let i = 0; i < GRID_COUNT * GRID_COUNT; i++) {
        if (tileImageDatas[i]) {
            canvases[i].getContext('2d').putImageData(tileImageDatas[i], 0, 0);
        }
    }
}