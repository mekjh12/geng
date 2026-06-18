
async function startTileRoadCsvDownload(centerLng, centerLat, labelCol, labelRow) {
    const zoom = 13;
    const MAPBOX_TOKEN = mapboxgl.accessToken;
    const mapSize = 9.216;

    // getExtent 대신 직접 계산
    const dist = Math.sqrt(2 * Math.pow(mapSize / 1080 * 1081 / 2, 2));
    const point = turf.point([centerLng, centerLat]);
    const topleft     = turf.destination(point, dist, -45, { units: 'kilometers' }).geometry.coordinates;
    const bottomright = turf.destination(point, dist, 135, { units: 'kilometers' }).geometry.coordinates;

    let x = long2tile(topleft[0], zoom);
    let y = lat2tile(topleft[1], zoom);
    let x2 = long2tile(bottomright[0], zoom);
    let y2 = lat2tile(bottomright[1], zoom);
    let tileCnt = Math.max(x2 - x + 1, y2 - y + 1);

    const tileLng  = tile2long(x, zoom);
    const tileLat  = tile2lat(y, zoom);
    const tileLng2 = tile2long(x + tileCnt, zoom);
    const tileLat2 = tile2lat(y + tileCnt, zoom);

    const distance = turf.distance(
        turf.point([tileLng, tileLat]),
        turf.point([tileLng2, tileLat2]),
        { units: 'kilometers' }
    ) / Math.SQRT2;

    const topDistance = turf.distance(
        turf.point([tileLng, tileLat]),
        turf.point([tileLng, topleft[1]]),
        { units: 'kilometers' }
    );
    const leftDistance = turf.distance(
        turf.point([tileLng, tileLat]),
        turf.point([topleft[0], tileLat]),
        { units: 'kilometers' }
    );

    // PBF 타일 다운로드
    const vTiles = Create2DArray(tileCnt);
    const promises = [];
    for (let i = 0; i < tileCnt; i++) {
        for (let j = 0; j < tileCnt; j++) {
            const url = `https://api.mapbox.com/v4/mapbox.mapbox-streets-v8/${zoom}/${x+j}/${y+i}.vector.pbf?access_token=${MAPBOX_TOKEN}`;
            promises.push(
                downloadPbfToTile(url).then(data => { vTiles[i][j] = data; })
            );
        }
    }
    await Promise.all(promises);

    const fullLength = Math.ceil(1080 * (distance / mapSize));
    const xOffset = Math.round(leftDistance / distance * fullLength);
    const yOffset = Math.round(topDistance / distance * fullLength);

    const csvBlob = await downloadRoadCsv(vTiles, tileCnt, fullLength, xOffset, yOffset);
    download(`roads_${labelCol}_${labelRow}.csv`, csvBlob);
}
function downloadRoadCsv(vTiles, tileCnt, fullLength, cropX, cropY) {
    return new Promise((resolve) => {
        const outputSize = 1081;
        const coef = fullLength / (tileCnt * 4096);
        
        let csvContent = "road_id,point_index,x,y\n";
        let roadIdCounter = 0;

        for (let ty = 0; ty < tileCnt; ty++) {
            for (let tx = 0; tx < tileCnt; tx++) {
                const tile = vTiles[ty][tx];
                if (!tile || !tile.layers || !tile.layers.road) continue;

                const xOffset = tx * fullLength / tileCnt;
                const yOffset = ty * fullLength / tileCnt;

                const layer = tile.layers.road;
                for (let f = 0; f < layer.length; f++) {
                    const geo = layer.feature(f).loadGeometry();
                    
                    geo.forEach(line => {
                        let linePoints = [];
                        
                        line.forEach((p) => {
                            const absX = p.x * coef + xOffset;
                            const absY = p.y * coef + yOffset;

                            // 하이트맵 크롭 영역 필터링 (산출물 1081px 기준)
                            if (absX >= cropX && absX <= cropX + outputSize &&
                                absY >= cropY && absY <= cropY + outputSize) {
                                
                                // 크롭 영역 좌상단을 (0,0)으로 하는 상대 좌표 계산
                                const relX = (absX - cropX).toFixed(2);
                                const relY = (absY - cropY).toFixed(2);
                                
                                linePoints.push({ x: relX, y: relY });
                            }
                        });

                        // 해당 도로가 크롭 영역 안에 일부라도 포함된 경우만 기록
                        if (linePoints.length > 0) {
                            linePoints.forEach((pt, pIdx) => {
                                csvContent += `${roadIdCounter},${pIdx},${pt.x},${pt.y}\n`;
                            });
                            roadIdCounter++;
                        }
                    });
                }
            }
        }

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        resolve(blob);
    });
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

			setPixel(x, y, targetH, 2);  // bit1 = 길
        }
    }
}

function applyRoadFlatten(buf2, buf2Size, featureBuf, csvText) {

    // ── 1. CSV 파싱 ───────────────────────────────────────
    const segMap = new Map();
    const lines = csvText.split('\n');
    for (let i = 1; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line) continue;
        const [roadId, , x, y] = line.split(',');
        const id = parseInt(roadId);
        if (isNaN(id)) continue;
        if (!segMap.has(id)) segMap.set(id, []);
        segMap.get(id).push({ px: parseFloat(x), py: parseFloat(y) });
    }
    const roadSegments = [...segMap.values()].map(points => ({ points }));

    // ── 선분 전처리: 긴 선분을 MAX_SEGMENT_LENGTH 이하로 분할 ──
    const SCALE = buf2Size / 1081;
    const MAX_SEGMENT_LENGTH = 8 / SCALE;

    for (const seg of roadSegments) {
        const subdivided = [];
        const pts = seg.points;
        for (let i = 0; i < pts.length - 1; i++) {
            const p0 = pts[i];
            const p1 = pts[i + 1];
            const dx = p1.px - p0.px;
            const dy = p1.py - p0.py;
            const len = Math.sqrt(dx * dx + dy * dy);
            const steps = Math.ceil(len / MAX_SEGMENT_LENGTH);
            for (let s = 0; s < steps; s++) {
                subdivided.push({
                    px: p0.px + dx * (s / steps),
                    py: p0.py + dy * (s / steps),
                });
            }
        }
        subdivided.push(pts[pts.length - 1]);
        seg.points = subdivided;
    }

    // ── 2. buf2 전용 getHeight / setPixel ────────────────
    function getHeight(gx, gy) {
        const x = Math.floor(gx);
        const y = Math.floor(gy);
        if (x < 0 || y < 0 || x >= buf2Size || y >= buf2Size) return -1;
        return buf2[y * buf2Size + x];
    }

    function setPixel(gx, gy, height16) {
        const x = Math.floor(gx);
        const y = Math.floor(gy);
        if (x < 0 || y < 0 || x >= buf2Size || y >= buf2Size) return;
        buf2[y * buf2Size + x] = height16;
        featureBuf[y * buf2Size + x] |= 0x02;
    }

    function setPixelBlend(gx, gy, roadH) {
        const x = Math.floor(gx);
        const y = Math.floor(gy);
        if (x < 0 || y < 0 || x >= buf2Size || y >= buf2Size) return;
        // 이미 길 플래그가 세워진 픽셀은 스킵 (다른 도로가 이미 처리한 영역)
        if (featureBuf[y * buf2Size + x] & 0x02) return;
        const originalH = buf2[y * buf2Size + x];
        if (originalH < 0) return;
        buf2[y * buf2Size + x] = Math.round((originalH + roadH) / 2);
        // 가장자리 블렌드 픽셀은 길 플래그 세우지 않음
    }

    // ── 3. 가장자리 블렌드: a0→a1 또는 b0→b1 선을 Bresenham으로 순회 ──
    function blendEdgeLine(ax0, ay0, ax1, ay1, nx, ny, p0, p1, h0, h1, len) {
        const dx = p1.px - p0.px;
        const dy = p1.py - p0.py;
        const invLenSq = 1.0 / (len * len);

        // Bresenham 정수 좌표 순회
        let x = Math.round(ax0);
        let y = Math.round(ay0);
        const ex = Math.round(ax1);
        const ey = Math.round(ay1);

        const stepDx = Math.abs(ex - x);
        const stepDy = Math.abs(ey - y);
        const sx = x < ex ? 1 : -1;
        const sy = y < ey ? 1 : -1;
        let err = stepDx - stepDy;

        while (true) {
            // 이 엣지 픽셀에서의 t → 도로 높이 보간
            const t = Math.max(0, Math.min(1,
                ((x - p0.px) * dx + (y - p0.py) * dy) * invLenSq
            ));
            const roadH = Math.round(h0 + (h1 - h0) * t);

            // 법선 바깥쪽 1픽셀
            const ox = x + Math.round(nx);
            const oy = y + Math.round(ny);
            setPixelBlend(ox, oy, roadH);

            if (x === ex && y === ey) break;
            const e2 = 2 * err;
            if (e2 > -stepDy) { err -= stepDy; x += sx; }
            if (e2 <  stepDx) { err += stepDx; y += sy; }
        }
    }

    // ── 4. 스케일 변환 후 래스터라이즈 ──────────────────
    const ROAD_HALF_WIDTH = 3;

    for (const seg of roadSegments) {
        const pts = seg.points.map(p => ({
            px: p.px * SCALE,
            py: p.py * SCALE,
        }));
        if (pts.length < 2) continue;

        for (let si = 0; si < pts.length - 1; si++) {
            const p0 = pts[si];
            const p1 = pts[si + 1];

            drawThickLineFilledScanline(p0, p1, ROAD_HALF_WIDTH, getHeight, setPixel);

            // 가장자리 블렌드
            const dx = p1.px - p0.px;
            const dy = p1.py - p0.py;
            const len = Math.sqrt(dx * dx + dy * dy);
            if (len === 0) continue;

            const nx = -dy / len;
            const ny =  dx / len;

            const h0 = getHeight(Math.round(p0.px), Math.round(p0.py));
            const h1 = getHeight(Math.round(p1.px), Math.round(p1.py));
            if (h0 < 0 || h1 < 0) continue;

            // a선 (+ 법선 방향 가장자리) → 바깥쪽은 +nx, +ny 방향
            blendEdgeLine(
                p0.px + nx * ROAD_HALF_WIDTH, p0.py + ny * ROAD_HALF_WIDTH,
                p1.px + nx * ROAD_HALF_WIDTH, p1.py + ny * ROAD_HALF_WIDTH,
                nx, ny, p0, p1, h0, h1, len
            );

            // b선 (- 법선 방향 가장자리) → 바깥쪽은 -nx, -ny 방향
            blendEdgeLine(
                p0.px - nx * ROAD_HALF_WIDTH, p0.py - ny * ROAD_HALF_WIDTH,
                p1.px - nx * ROAD_HALF_WIDTH, p1.py - ny * ROAD_HALF_WIDTH,
                -nx, -ny, p0, p1, h0, h1, len
            );
        }
    }
}
