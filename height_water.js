// ── Water CSV 로드 ─────────────────────────────────────────────────
let waterPolygons = []; // 전역 변수로 선언

waterInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (ev) => {
        const lines = ev.target.result.trim().split('\n');
        const delimiter = lines[0].includes('\t') ? '\t' : ',';
        const waterMap = {};

        for (let i = 1; i < lines.length; i++) {
            const cols = lines[i].split(delimiter);
            if (cols.length < 4) continue;

            const id = cols[0].trim();
            const pi = parseInt(cols[1].trim());
            const rx = parseFloat(cols[2].trim());
            const ry = parseFloat(cols[3].trim());

            // 전역 출력 좌표계로 변환 (9216 사이즈 기준)
            const px = (rx / SOURCE_FULL_SIZE) * FULL_OUTPUT_SIZE;
            const py = (ry / SOURCE_FULL_SIZE) * FULL_OUTPUT_SIZE;
            
            if (!waterMap[id]) waterMap[id] = [];
            waterMap[id][pi] = { px, py };					
        }

        waterPolygons = Object.values(waterMap).map(pts => pts.filter(Boolean));
        waterStatus.innerText = `✓ ${waterPolygons.length}개 강 구역 로드됨`;

        // Heightmap이 이미 로드되어 있다면 즉시 적용
        applyWaterWays();
    };
    reader.readAsText(file);
});

function applyWaterWays() {
    if (tileImageDatas.every(d => d === null)) return;
    if (waterPolygons.length === 0) return;

    // --- [추가] 시각화 전 원본 데이터 백업 로직 ---
    originalTileDataBackups = []; // 기존 백업 초기화
    for (let i = 0; i < GRID_COUNT * GRID_COUNT; i++) {
        const td = tileImageDatas[i];
        if (td) {
            // .slice() 또는 new Uint8ClampedArray()를 사용하여 데이터 배열을 복사합니다.
            // 이렇게 해야 나중에 캔버스를 수정해도 백업 데이터는 변하지 않습니다.
            originalTileDataBackups[i] = new Uint8ClampedArray(td.data);
        } else {
            originalTileDataBackups[i] = null;
        }
    }
    // ------------------------------------------

    const RIVER_WIDTH = 6;    
    const VERTEX_SIZE = 3;    

    waterPolygons.forEach((pts) => {
        if (pts.length < 2) return;

        for (let i = 0; i < GRID_COUNT * GRID_COUNT; i++) {
            const td = tileImageDatas[i];
            if (!td) continue;

            const tileX = (i % GRID_COUNT) * OUTPUT_TILE_SIZE;
            const tileY = Math.floor(i / GRID_COUNT) * OUTPUT_TILE_SIZE;

            const offCanvas = document.createElement('canvas');
            offCanvas.width = OUTPUT_TILE_SIZE;
            offCanvas.height = OUTPUT_TILE_SIZE;
            const offCtx = offCanvas.getContext('2d', { willReadFrequently: true });

            // 1. 빨간색 폴리라인 마스크 (이 작업이 td.data를 수정하게 됩니다)
            offCtx.clearRect(0, 0, OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE);
            offCtx.strokeStyle = 'white';
            offCtx.lineWidth = RIVER_WIDTH;
            offCtx.lineCap = 'round';
            offCtx.lineJoin = 'round';
            offCtx.beginPath();
            offCtx.moveTo(pts[0].px - tileX, pts[0].py - tileY);
            for (let k = 1; k < pts.length; k++) {
                offCtx.lineTo(pts[k].px - tileX, pts[k].py - tileY);
            }
            offCtx.stroke();
            const lineMask = offCtx.getImageData(0, 0, OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE).data;

            // 2. 모든 정점 마스크
            offCtx.clearRect(0, 0, OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE);
            offCtx.fillStyle = 'white';
            pts.forEach(p => {
                offCtx.beginPath();
                offCtx.arc(p.px - tileX, p.py - tileY, VERTEX_SIZE, 0, Math.PI * 2);
                offCtx.fill();
            });
            const pointMask = offCtx.getImageData(0, 0, OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE).data;

            // 3. 픽셀 데이터 합성 (화면 출력용 td.data 수정)
            for (let j = 0; j < td.data.length; j += 4) {
                if (pointMask[j] > 0) {
                    td.data[j] = 0; td.data[j+1] = 255; td.data[j+2] = 0; td.data[j+3] = 255;
                } else if (lineMask[j] > 0) {
                    td.data[j] = 255; td.data[j+1] = 0; td.data[j+2] = 0; td.data[j+3] = 255;
                }
            }
            canvases[i].getContext('2d').putImageData(td, 0, 0);
        }
    });
}

// ── 공통 유틸리티: 전역 좌표계에서 픽셀 값 읽기 ──
function getPixelFromGlobal(gx, gy) {
    const col = Math.floor(gx / OUTPUT_TILE_SIZE);
    const row = Math.floor(gy / OUTPUT_TILE_SIZE);
    if (col < 0 || col >= GRID_COUNT || row < 0 || row >= GRID_COUNT) return null;

    const tileIdx = row * GRID_COUNT + col;
    const td = tileImageDatas[tileIdx];
    if (!td || !td.data) return null;

    const lx = Math.floor(gx % OUTPUT_TILE_SIZE);
    const ly = Math.floor(gy % OUTPUT_TILE_SIZE);
    const pIdx = (ly * OUTPUT_TILE_SIZE + lx) * 4;

    return { r: td.data[pIdx], g: td.data[pIdx + 1] };
}

// ── 핵심 함수: 강 적용 및 부드러운 평탄화 ──
function applyWaters() {
    if (tileImageDatas.every(d => d === null)) return;
    if (waterPolygons.length === 0) return;

    // 각 강 폴리곤별로 처리
    waterPolygons.forEach((pts, polyIdx) => {
        if (pts.length < 3) return;

        // 1. 샘플링: 반시계 방향 폴리곤의 '왼쪽' 픽셀 높이 수집
        let totalR = 0, totalG = 0, sampleCount = 0;
        const LOOK_LEFT_OFFSET = 8; // 강 안쪽으로 8픽셀 지점을 조회

        for (let k = 0; k < pts.length; k++) {
            const p1 = pts[k];
            const p2 = pts[(k + 1) % pts.length];

            const dx = p2.px - p1.px;
            const dy = p2.py - p1.py;
            const len = Math.sqrt(dx * dx + dy * dy);
            if (len < 0.1) continue;

            // 법선 벡터 (왼쪽 90도 회전): (-dy, dx)
            const nx = -dy / len;
            const ny = dx / len;

            const sx = p1.px + nx * LOOK_LEFT_OFFSET;
            const sy = p1.py + ny * LOOK_LEFT_OFFSET;

            const pixel = getPixelFromGlobal(sx, sy);
            if (pixel) {
                totalR += pixel.r;
                totalG += pixel.g;
                sampleCount++;
            }
        }

        // 샘플링 실패 시 기본값 혹은 건너뛰기
        const avgR = sampleCount > 0 ? totalR / sampleCount : 0;
        const avgG = sampleCount > 0 ? totalG / sampleCount : 0;

        // 2. 타일별 렌더링 (그라데이션 적용)
        for (let i = 0; i < GRID_COUNT * GRID_COUNT; i++) {
            const td = tileImageDatas[i];
            if (!td) continue;

            const tileX = (i % GRID_COUNT) * OUTPUT_TILE_SIZE;
            const tileY = Math.floor(i / GRID_COUNT) * OUTPUT_TILE_SIZE;

            // 폴리곤의 Bounding Box 체크 (성능 최적화)
            // *실제 구현 시 pts의 min/max를 구해 타일 범위와 겹치는지 확인하는 코드가 들어가면 좋습니다.

            const offCanvas = document.createElement('canvas');
            offCanvas.width = OUTPUT_TILE_SIZE;
            offCanvas.height = OUTPUT_TILE_SIZE;
            const offCtx = offCanvas.getContext('2d');

            // 마스크 생성 (그라데이션을 위해 Blur 적용)
            offCtx.clearRect(0, 0, OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE);
            offCtx.filter = 'blur(6px)'; // 둑의 경계를 얼마나 부드럽게 할지 결정
            offCtx.fillStyle = 'white';
            
            offCtx.beginPath();
            offCtx.moveTo(pts[0].px - tileX, pts[0].py - tileY);
            for (let k = 1; k < pts.length; k++) {
                offCtx.lineTo(pts[k].px - tileX, pts[k].py - tileY);
            }
            offCtx.closePath();
            offCtx.fill();

            const maskData = offCtx.getImageData(0, 0, OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE).data;

            // 픽셀 데이터 업데이트
            for (let j = 0; j < td.data.length; j += 4) {
                const alpha = maskData[j] / 255; // 0(경계 밖) ~ 1(강 중심)
                if (alpha > 0) {
                    // 높이 보간: 원본 지형과 평균값(강 바닥) 사이를 부드럽게 연결
                    td.data[j] = td.data[j] * (1 - alpha) + (avgR * alpha);
                    td.data[j + 1] = td.data[j + 1] * (1 - alpha) + (avgG * alpha);
                    
                    // Blue 채널: 강 영역임을 표시 (Blue=128)
                    if (alpha > 0.3) {
                        td.data[j + 2] = 128;
                    }
                }
            }
            // 캔버스에 결과 반영
            canvases[i].getContext('2d').putImageData(td, 0, 0);
        }
    });
}


/*
// ── 강 면(Polygon)을 Blue=128로 마킹 ──────────────────────────────
function applyWaters() {
    if (tileImageDatas.every(d => d === null)) return;
    if (waterPolygons.length === 0) return;

    for (let i = 0; i < GRID_COUNT * GRID_COUNT; i++) {
        const td = tileImageDatas[i];
        if (!td) continue;

        const ctx = canvases[i].getContext('2d');
        const tileCol = i % GRID_COUNT;
        const tileRow = Math.floor(i / GRID_COUNT);
        const tileX = tileCol * OUTPUT_TILE_SIZE;
        const tileY = tileRow * OUTPUT_TILE_SIZE;

        // 1. 오프스크린 캔버스에 해당 타일 영역의 폴리곤 그리기
        const offCanvas = document.createElement('canvas');
        offCanvas.width = OUTPUT_TILE_SIZE;
        offCanvas.height = OUTPUT_TILE_SIZE;
        const offCtx = offCanvas.getContext('2d');

        // Blue 값을 128로 설정하여 채우기
        offCtx.fillStyle = 'rgb(0, 0, 128)'; 

        for (const pts of waterPolygons) {
            if (pts.length < 3) continue;
            offCtx.beginPath();
            offCtx.moveTo(pts[0].px - tileX, pts[0].py - tileY);
            for (let k = 1; k < pts.length; k++) { 
                offCtx.lineTo(pts[k].px - tileX, pts[k].py - tileY);
            }
            offCtx.closePath();
            offCtx.fill();
        }

        // 2. 그려진 마스크를 순회하며 tileImageDatas의 Blue 채널 업데이트
        const maskData = offCtx.getImageData(0, 0, OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE).data;
        for (let j = 0; j < td.data.length; j += 4) {
            // 마스크 캔버스에 Blue(128)가 칠해진 픽셀만 타겟팅
            if (maskData[j + 2] === 128) {
                td.data[j + 2] = 128; 
                
                // 만약 강 면 높이를 평탄화하고 싶다면 (예: 해수면 0)
                // td.data[j] = 0;     // R
                // td.data[j+1] = 0;   // G
            }
        }

        // 3. 최종 결과 반영
        ctx.putImageData(td, 0, 0);
    }
}
*/