function gaussianBlurDist(dist, featureBuf, size, sigma = 2.0) {
    const radius = Math.ceil(sigma * 3);
    const kSize  = radius * 2 + 1;
    const kernel = new Float32Array(kSize);

    // 1차원 가우시안 커널 생성
    let kernelSum = 0;
    for (let k = -radius; k <= radius; k++) {
        const w = Math.exp(-(k * k) / (2 * sigma * sigma));
        kernel[k + radius] = w;
        kernelSum += w;
    }
    for (let i = 0; i < kernel.length; i++) kernel[i] /= kernelSum;

    // 가로(Horizontal) 패스를 위한 임시 버퍼
    const tmp = new Float32Array(dist.length);

    // ── PASS 1: 가로 방향 블러 ────────────────────────────
    for (let y = 0; y < size; y++) {
        const yOffset = y * size;
        for (let x = 0; x < size; x++) {
            const idx = yOffset + x;

            if ((featureBuf[idx] & 0x01) === 0) {
                tmp[idx] = dist[idx];
                continue;
            }

            let weightedSum = 0;
            let validWeight = 0;

            for (let kx = -radius; kx <= radius; kx++) {
                const nx = x + kx;
                if (nx < 0 || nx >= size) continue;

                const ni = yOffset + nx;
                if ((featureBuf[ni] & 0x01) === 0) continue;
                if (!isFinite(dist[ni])) continue;

                const w = kernel[kx + radius];
                weightedSum += dist[ni] * w;
                validWeight += w;
            }
            tmp[idx] = validWeight > 1e-6 ? weightedSum / validWeight : dist[idx];
        }
    }

    // ── PASS 2: 세로 방향 블러 (최종 출력 out) ─────────────────
    const out = new Float32Array(dist.length);

    for (let y = 0; y < size; y++) {
        const yOffset = y * size;
        for (let x = 0; x < size; x++) {
            const idx = yOffset + x;

            if ((featureBuf[idx] & 0x01) === 0) {
                out[idx] = tmp[idx];
                continue;
            }

            let weightedSum = 0;
            let validWeight = 0;

            for (let ky = -radius; ky <= radius; ky++) {
                const ny = y + ky;
                if (ny < 0 || ny >= size) continue;

                const ni = ny * size + x;
                // 강 내부 마스크 및 유효성 검사 (가로 패스의 결과물인 tmp를 샘플링)
                if ((featureBuf[ni] & 0x01) === 0) continue;
                if (!isFinite(tmp[ni])) continue;

                const w = kernel[ky + radius];
                weightedSum += tmp[ni] * w;
                validWeight += w;
            }
            out[idx] = validWeight > 1e-6 ? weightedSum / validWeight : tmp[idx];
        }
    }

    return out;
}

// ---------------------------------------------------------------------------------------
// 벡터 타일 데이터에서 'water' 레이어의 지오메트리를 추출하여 CSV Blob으로 반환합니다.
// ---------------------------------------------------------------------------------------
function downloadWaterCsv(vTiles, tileCnt, fullLength, cropX, cropY) {
    return new Promise((resolve) => {
        const outputSize = 1081;
        const coef = fullLength / (tileCnt * 4096);
        let csvContent = "river_id,point_index,x,y\n";
        let riverIdCounter = 0;

        for (let ty = 0; ty < tileCnt; ty++) {
            for (let tx = 0; tx < tileCnt; tx++) {
                const tile = vTiles[ty][tx];

                // 데이터 검증 및 water 레이어 접근
                if (!tile || !tile.layers?.water) continue;
                const layer = tile.layers.water;

                const xOffset = tx * fullLength / tileCnt;
                const yOffset = ty * fullLength / tileCnt;

                // 피처 순회 및 지오메트리 좌표 변환
                for (let f = 0; f < layer.length; f++) {
                    const feature = layer.feature(f);
                    const geo = feature.loadGeometry();

                    geo.forEach(line => {
                        let linePoints = [];
                        line.forEach((p) => {
                            const absX = p.x * coef + xOffset;
                            const absY = p.y * coef + yOffset;

                            // 영역 내 포인트 필터링 및 상대 좌표 계산
                            if (absX >= cropX && absX <= cropX + outputSize &&
                                absY >= cropY && absY <= cropY + outputSize) {
                                linePoints.push({ 
                                    x: (absX - cropX).toFixed(2), 
                                    y: (absY - cropY).toFixed(2) 
                                });
                            }
                        });

                        // CSV 데이터 기록
                        if (linePoints.length > 0) {
                            linePoints.forEach((pt, pIdx) => {
                                csvContent += `${riverIdCounter},${pIdx},${pt.x},${pt.y}\n`;
                            });
                            riverIdCounter++;
                        }
                    });
                }
            }
        }
        resolve(new Blob([csvContent], { type: 'text/csv;charset=utf-8;' }));
    });
}

// ---------------------------------------------------------------------------------------
// 벡터 타일 데이터에서 'waterway' 레이어의 지오메트리를 추출하여 CSV Blob으로 반환합니다.
// ---------------------------------------------------------------------------------------
function downloadWaterWayCsv(vTiles, tileCnt, fullLength, cropX, cropY) {
    return new Promise((resolve) => {
        const outputSize = 1081;
        const coef = fullLength / (tileCnt * 4096);
        let csvContent = "river_id,point_index,x,y\n";
        let riverIdCounter = 0;

        for (let ty = 0; ty < tileCnt; ty++) {
            for (let tx = 0; tx < tileCnt; tx++) {
                const tile = vTiles[ty][tx];

                if (!tile || !tile.layers?.waterway) continue;
                const layer = tile.layers.waterway;

                const xOffset = tx * fullLength / tileCnt;
                const yOffset = ty * fullLength / tileCnt;

                for (let f = 0; f < layer.length; f++) {
                    const feature = layer.feature(f);
                    const geo = feature.loadGeometry();

                    geo.forEach(line => {
                        let linePoints = [];
                        line.forEach((p) => {
                            const absX = p.x * coef + xOffset;
                            const absY = p.y * coef + yOffset;

                            if (absX >= cropX && absX <= cropX + outputSize &&
                                absY >= cropY && absY <= cropY + outputSize) {
                                linePoints.push({ 
                                    x: (absX - cropX).toFixed(2), 
                                    y: (absY - cropY).toFixed(2) 
                                });
                            }
                        });

                        if (linePoints.length > 0) {
                            linePoints.forEach((pt, pIdx) => {
                                csvContent += `${riverIdCounter},${pIdx},${pt.x},${pt.y}\n`;
                            });
                            riverIdCounter++;
                        }
                    });
                }
            }
        }
        resolve(new Blob([csvContent], { type: 'text/csv;charset=utf-8;' }));
    });
}

// ---------------------------------------------------------------------------------------
// 중심 좌표를 기준으로 주변 타일을 다운로드하고 water/waterway 데이터를 CSV로 저장합니다.
// ---------------------------------------------------------------------------------------
async function startTileRiverCsvDownload(centerLng, centerLat, labelCol, labelRow) {
    const zoom = 13;
    const MAPBOX_TOKEN = mapboxgl.accessToken;
    const mapSize = 9.216;

    const dist = Math.sqrt(2 * Math.pow(mapSize / 1080 * 1081 / 2, 2));
    const point = turf.point([centerLng, centerLat]);
    const topleft = turf.destination(point, dist, -45, { units: 'kilometers' }).geometry.coordinates;
    const bottomright = turf.destination(point, dist, 135, { units: 'kilometers' }).geometry.coordinates;

    let x = long2tile(topleft[0], zoom);
    let y = lat2tile(topleft[1], zoom);
    let x2 = long2tile(bottomright[0], zoom);
    let y2 = lat2tile(bottomright[1], zoom);
    let tileCnt = Math.max(x2 - x + 1, y2 - y + 1);

    const vTiles = Create2DArray(tileCnt);
    const promises = [];
    for (let i = 0; i < tileCnt; i++) {
        for (let j = 0; j < tileCnt; j++) {
            const url = `https://api.mapbox.com/v4/mapbox.mapbox-streets-v8/${zoom}/${x+j}/${y+i}.vector.pbf?access_token=${MAPBOX_TOKEN}`;
            promises.push(downloadPbfToTile(url).then(data => { vTiles[i][j] = data; }));
        }
    }
    await Promise.all(promises);

    const tileLng = tile2long(x, zoom);
    const tileLat = tile2lat(y, zoom);
    const distance = turf.distance(turf.point([tileLng, tileLat]), turf.point([tile2long(x + tileCnt, zoom), tile2lat(y + tileCnt, zoom)], { units: 'kilometers' })) / Math.SQRT2;
    const topDistance = turf.distance(turf.point([tileLng, tileLat]), turf.point([tileLng, topleft[1]]), { units: 'kilometers' });
    const leftDistance = turf.distance(turf.point([tileLng, tileLat]), turf.point([topleft[0], tileLat]), { units: 'kilometers' });

    const fullLength = Math.ceil(1080 * (distance / mapSize));
    const xOffset = Math.round(leftDistance / distance * fullLength);
    const yOffset = Math.round(topDistance / distance * fullLength);

    const waterBlob = await downloadWaterCsv(vTiles, tileCnt, fullLength, xOffset, yOffset);
    const waterwayBlob = await downloadWaterWayCsv(vTiles, tileCnt, fullLength, xOffset, yOffset);

    download(`water_${labelCol}_${labelRow}.csv`, waterBlob);
    download(`waterway_${labelCol}_${labelRow}.csv`, waterwayBlob);
}

function onRawWaterwayCsvSelected(input) {
    const file  = input.files[0];
    const icon  = document.getElementById('raw-waterway-file-icon');
    const label = document.getElementById('raw-waterway-file-label');
    const sub   = document.getElementById('raw-waterway-file-sub');
    const btn   = document.getElementById('raw-waterway-attach-label');

    if (!icon || !label || !sub || !btn) return;

    if (file) {
        icon.innerHTML    = '<i class="ti ti-file-check" aria-hidden="true"></i>';
        label.textContent = file.name;
        sub.textContent   = (file.size / 1024).toFixed(1) + ' KB';
        btn.textContent   = '변경';
    } else {
        icon.innerHTML    = '<i class="ti ti-file-x" aria-hidden="true"></i>';
        label.textContent = '강 중심선 CSV 없음';
        sub.textContent   = '강 침식을 적용하려면 waterway 파일을 첨부하세요';
        btn.textContent   = '첨부';
    }
}

function getWaterwayCsvFile() {
    return document.getElementById('input-waterway-csv-raw')?.files[0] ?? null;
}

// ---------------------------------------------------------------------------------------
// [디버그] waterway CSV 선분을 흰색 선으로 그린 PNG 저장
// 강 중심선 위치와 그리드 셀 인덱싱 범위를 함께 시각화
// ---------------------------------------------------------------------------------------
function debugSaveWaterwayPng(waterwayText, buf2Size, filename) {
    const SCALE = buf2Size / 1081;

    // CSV 파싱 → segMap
    const segMap = new Map();
    const lines = waterwayText.split('\n');
    for (let i = 1; i < lines.length; i++) {
        const parts = lines[i].trim().split(',');
        if (parts.length < 4) continue;
        const id = parseInt(parts[0]);
        if (isNaN(id)) continue;
        if (!segMap.has(id)) segMap.set(id, []);
        segMap.get(id).push({
            x: parseFloat(parts[2]) * SCALE,
            y: parseFloat(parts[3]) * SCALE,
        });
    }

    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = buf2Size;
    const ctx = canvas.getContext('2d');

    // 배경 검정
    ctx.fillStyle = '#000000';
    ctx.fillRect(0, 0, buf2Size, buf2Size);

    // 그리드 셀 경계선 (어두운 회색)
    const CELL_SIZE = 64;
    ctx.strokeStyle = 'rgba(120,120,120,1)';
    ctx.lineWidth = 0.5;
    for (let x = 0; x < buf2Size; x += CELL_SIZE) {
        ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, buf2Size); ctx.stroke();
    }
    for (let y = 0; y < buf2Size; y += CELL_SIZE) {
        ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(buf2Size, y); ctx.stroke();
    }

    // 선분별 색상 (river_id 기반 색상 순환)
    const PALETTE = [
        '#00BFFF', '#00FF99', '#FF6B6B', '#FFD700',
        '#FF69B4', '#7CFC00', '#FF8C00', '#DA70D6',
    ];

    let segIdx = 0;
    for (const [id, pts] of segMap.entries()) {
        if (pts.length < 2) continue;

        const color = PALETTE[segIdx % PALETTE.length];
        segIdx++;

        // 선분 그리기
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        for (let i = 1; i < pts.length; i++) {
            ctx.lineTo(pts[i].x, pts[i].y);
        }
        ctx.stroke();

        // 시작점 (원)
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(pts[0].x, pts[0].y, 2.5, 0, Math.PI * 2);
        ctx.fill();

        // 끝점 (원)
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(pts[pts.length-1].x, pts[pts.length-1].y, 2.5, 0, Math.PI * 2);
        ctx.fill();
    }

    // 범례
    ctx.font = '11px monospace';
    ctx.fillStyle = 'rgba(255,255,255,0.6)';
    ctx.fillText(`segments: ${segIdx}  |  buf2Size: ${buf2Size}  |  scale: ${SCALE.toFixed(2)}`, 8, buf2Size - 8);

    canvas.toBlob(blob => download(filename, blob), 'image/png');
    console.log(`[DEBUG] waterway 선분 PNG 저장: ${filename} (${segIdx}개 선분)`);
}


// ---------------------------------------------------------------------------------------
// [디버그] computeBorderDist의 dist 배열을 그레이스케일 PNG로 저장
// 경계(dist=0)는 검정, 내부 멀수록 밝음, 강 외부는 빨강
// ---------------------------------------------------------------------------------------
function debugSaveBorderDistPng(dist, featureBuf, size, filename = 'dbg_border_dist.png') {
    // dist 배열에서 유한한 값의 max 계산 (정규화 기준)
    let maxDist = 0;
    for (let i = 0; i < dist.length; i++) {
        if (isFinite(dist[i]) && dist[i] > maxDist) maxDist = dist[i];
    }
    if (maxDist === 0) maxDist = 1;

    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(size, size);

    for (let i = 0; i < dist.length; i++) {
        const p = i * 4;
        const isRiver = (featureBuf[i] & 0x01) !== 0;

        if (!isRiver) {
            // 강 외부: 어두운 빨강으로 표시
            img.data[p]   = 80;
            img.data[p+1] = 0;
            img.data[p+2] = 0;
            img.data[p+3] = 255;
        } else if (dist[i] === 0) {
            // 경계 픽셀: 완전 검정
            img.data[p]   = 0;
            img.data[p+1] = 0;
            img.data[p+2] = 0;
            img.data[p+3] = 255;
        } else if (!isFinite(dist[i])) {
            // 미처리 픽셀(Infinity): 밝은 초록으로 표시 (BFS 버그 탐지용)
            img.data[p]   = 0;
            img.data[p+1] = 255;
            img.data[p+2] = 0;
            img.data[p+3] = 255;
        } else {
            // 강 내부: 거리 비례 밝기 (경계=어둠, 중심=밝음)
            const v = Math.round((dist[i] / maxDist) * 255);
            img.data[p]   = v;
            img.data[p+1] = v;
            img.data[p+2] = v;
            img.data[p+3] = 255;
        }
    }

    ctx.putImageData(img, 0, 0);

    // 범례 텍스트
    ctx.font = '12px monospace';
    ctx.fillStyle = 'rgba(255, 255, 100, 0.85)';
    ctx.fillText(`maxDist: ${maxDist}px  |  size: ${size}`, 8, size - 8);

    canvas.toBlob(blob => download(filename, blob), 'image/png');
    console.log(`[DEBUG] borderDist PNG 저장: ${filename} (maxDist=${maxDist})`);
}

// ---------------------------------------------------------------------------------------
// [디버그] waterway CSV를 직접 받아 applyRiverCarving 결과를 PNG로 저장합니다.
// startDownload() 전처리 없이 단독 실행 가능.
//
// 사용법:
//   1. water CSV,  waterway CSV 파일을 input으로 받음
//   2. applyWaterMask → applyRiverCarving 순서로 실행
//   3. 결과를 그레이스케일 + 강 오버레이 PNG 2장으로 저장
//
// HTML에 추가:
//   <input type="file" id="dbg-water-csv">    ← water.csv
//   <input type="file" id="dbg-waterway-csv"> ← waterway.csv
//   <input type="file" id="dbg-heightmap">    ← 기존 raw heightmap (Float32, buf2Size²)
//   <button onclick="debugRiverCarving()">강 침식 테스트</button>
// ---------------------------------------------------------------------------------------
async function debugRiverCarving() {

    // ── 0. 파일 읽기 헬퍼 ────────────────────────────────────
    const readText = (inputId) => new Promise((res, rej) => {
        const file = document.getElementById(inputId)?.files[0];
        if (!file) { res(null); return; }
        const r = new FileReader();
        r.onload = e => res(e.target.result);
        r.onerror = rej;
        r.readAsText(file);
    });

    const readRaw = (inputId) => new Promise((res, rej) => {
        const file = document.getElementById(inputId)?.files[0];
        if (!file) { res(null); return; }
        const r = new FileReader();
        r.onload = e => res(e.target.result);
        r.onerror = rej;
        r.readAsArrayBuffer(file);
    });

    console.log('[DEBUG] 파일 읽는 중...');

    const waterText    = await readText('dbg-water-csv');
    const waterwayText = await readText('dbg-waterway-csv');
    const rawBuf       = await readRaw('dbg-heightmap');

    if (!waterwayText) { alert('waterway CSV 파일을 선택하세요.'); return; }

    // ── 1. buf2 구성 ─────────────────────────────────────────
    // heightmap raw 파일이 있으면 사용, 없으면 평탄한 더미 버퍼 생성
    let buf2, buf2Size;

    if (rawBuf) {
        // Float32 raw 파일로부터 복원
        const f32 = new Float32Array(rawBuf);
        buf2Size = Math.round(Math.sqrt(f32.length));
        buf2 = new Float32Array(f32); // 복사본 (원본 보존)
        console.log(`[DEBUG] heightmap 로드: ${buf2Size}×${buf2Size}`);
    } else {
        // 더미: 5000 고도 평탄 지형 (기능 확인용)
        buf2Size = 1081;
        buf2 = new Float32Array(buf2Size * buf2Size).fill(5000);
        console.log(`[DEBUG] 더미 buf2 생성: ${buf2Size}×${buf2Size} (고도 5000)`);
    }

    // ── 2. featureBuf 구성 ───────────────────────────────────
    const featureBuf = new Uint8Array(buf2Size * buf2Size);

    // water.csv → applyWaterMask로 bit0 마킹
    if (waterText) {
        console.log('[DEBUG] applyWaterMask 실행 중...');
        applyWaterMask(buf2, buf2Size, featureBuf, waterText);
        console.log('[DEBUG] applyWaterMask 완료');
    } else {
        // water CSV 없으면 전체를 강으로 간주 (waterway만 테스트)
        console.warn('[DEBUG] water CSV 없음 → featureBuf 전체 bit0 세팅');
        featureBuf.fill(0x01);
    }
	
	// 2. waterway.csv 중심선으로 부족한 강 보완 (추가)
	//    water에 없는 강줄기도 bit0에 OR로 합산
	const HW = 2; // 또는 4, 8 등

	applyWaterwayMask(buf2Size, featureBuf, waterwayText, HW);

    // ── 3. applyRiverCarving 실행 ────────────────────────────
    console.log('[DEBUG] applyRiverCarving 실행 중...');
    const t0 = performance.now();

	applyRiverCarving(buf2, buf2Size, featureBuf, waterwayText, 80, HW);

    const elapsed = (performance.now() - t0).toFixed(1);
    console.log(`[DEBUG] applyRiverCarving 완료: ${elapsed}ms`);

    // ── 4. PNG 저장 ───────────────────────────────────────────
    // 4-a. 그레이스케일 고도맵 (강 침식 결과)
    debugSaveHeightPng(buf2, buf2Size, 'dbg_river_height.png');

    // 4-b. 피처 오버레이 (강=파랑, 길=빨강 채널 시각화)
    debugSaveFeaturePng(featureBuf, buf2Size, 'dbg_river_feature.png');

    // 4-c. 침식 깊이 히트맵 (강 영역만 보라→노랑 그라디언트)
    debugSaveDepthHeatmap(buf2, buf2Size, featureBuf, 'dbg_river_heatmap.png');
    debugSaveWaterwayPng(waterwayText, buf2Size, 'dbg_river_waterway.png');  // ← 추가

    console.log(`[DEBUG] PNG 3장 저장 완료 (${elapsed}ms)`);
    alert(`완료! (${elapsed}ms)\n저장: dbg_river_height.png / dbg_river_feature.png / dbg_river_heatmap.png`);
}


// ---------------------------------------------------------------------------------------
// [디버그] buf2(Float32) → 그레이스케일 PNG 저장
// 전체 min/max 정규화 후 8비트로 저장
// ---------------------------------------------------------------------------------------
function debugSaveHeightPng(buf2, buf2Size, filename) {
    let min = Infinity, max = -Infinity;
    for (let i = 0; i < buf2.length; i++) {
        if (buf2[i] < min) min = buf2[i];
        if (buf2[i] > max) max = buf2[i];
    }
    const range = max - min || 1;
    console.log(`[DEBUG] 고도 범위: ${min.toFixed(1)} ~ ${max.toFixed(1)}`);

    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = buf2Size;
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(buf2Size, buf2Size);

    for (let i = 0; i < buf2.length; i++) {
        const v = Math.round(((buf2[i] - min) / range) * 255);
        const p = i * 4;
        img.data[p] = img.data[p+1] = img.data[p+2] = v;
        img.data[p+3] = 255;
    }
    ctx.putImageData(img, 0, 0);
    canvas.toBlob(blob => download(filename, blob), 'image/png');
}


// ---------------------------------------------------------------------------------------
// [디버그] featureBuf → 비트 채널별 컬러 PNG 저장
//   bit0(강)  → 파랑
//   bit1(길)  → 빨강
//   둘 다     → 보라
// ---------------------------------------------------------------------------------------
function debugSaveFeaturePng(featureBuf, buf2Size, filename) {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = buf2Size;
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(buf2Size, buf2Size);

    for (let i = 0; i < featureBuf.length; i++) {
        const f = featureBuf[i];
        const p = i * 4;
        img.data[p]   = (f & 0x02) ? 220 : 0;   // R: 길
        img.data[p+1] = 0;                        // G
        img.data[p+2] = (f & 0x01) ? 220 : 0;   // B: 강
        img.data[p+3] = (f & 0x03) ? 255 : 80;  // A: 배경은 반투명
    }
    ctx.putImageData(img, 0, 0);
    canvas.toBlob(blob => download(filename, blob), 'image/png');
}


// ---------------------------------------------------------------------------------------
// [디버그] 침식 깊이 히트맵 PNG 저장
// 강 영역 픽셀의 고도를 보라(낮음)→노랑(높음) 그라디언트로 표현
// 강 외부는 어두운 회색
// ---------------------------------------------------------------------------------------
function debugSaveDepthHeatmap(buf2, buf2Size, featureBuf, filename) {
    // 강 영역 내 min/max만 따로 계산
    let min = Infinity, max = -Infinity;
    for (let i = 0; i < buf2.length; i++) {
        if ((featureBuf[i] & 0x01) === 0) continue;
        if (buf2[i] < min) min = buf2[i];
        if (buf2[i] > max) max = buf2[i];
    }
    if (!isFinite(min)) { console.warn('[DEBUG] 강 영역 없음'); return; }
    const range = max - min || 1;

    // Inferno 유사 컬러맵 (보라→빨강→노랑)
    const colormap = (t) => {
        // t: 0(낮음=깊음) → 1(높음=얕음)
        const r = Math.round(Math.min(255, t * 2.0 * 255));
        const g = Math.round(Math.max(0, (t - 0.5) * 2.0 * 255));
        const b = Math.round(Math.max(0, (0.5 - t) * 2.0 * 255));
        return [r, g, b];
    };

    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = buf2Size;
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(buf2Size, buf2Size);

    for (let i = 0; i < buf2.length; i++) {
        const p = i * 4;
        if (featureBuf[i] & 0x01) {
            const t = (buf2[i] - min) / range;
            const [r, g, b] = colormap(t);
            img.data[p] = r; img.data[p+1] = g; img.data[p+2] = b;
            img.data[p+3] = 255;
        } else {
            // 강 외부: 어두운 회색
            img.data[p] = img.data[p+1] = img.data[p+2] = 40;
            img.data[p+3] = 255;
        }
    }
    ctx.putImageData(img, 0, 0);
    canvas.toBlob(blob => download(filename, blob), 'image/png');
}


// ---------------------------------------------------------------------------------------
// waterway CSV(선분 중심선)를 기반으로 강 침식을 적용합니다.
// 중심선에서 멀수록 얕아지는 코사인 단면으로 buf2 고도를 낮춥니다.
//
// 흐름:
//   1. CSV 파싱 → 선분 목록 구성
//   2. 그리드 셀 인덱싱 (공간 분할 → 딕셔너리)
//   3. featureBuf bit0(강) 픽셀 순회 → 후보 선분만 distToSegment
//   4. 코사인 감쇠로 buf2 고도 침식
// ---------------------------------------------------------------------------------------
/*
function applyRiverCarving(buf2, buf2Size, featureBuf, waterwayText, halfWidthPx = 12, maxDepth = 80) {

    const SCALE      = buf2Size / 1081;   // 1081px 좌표계 → buf2 좌표계 배율
    const CELL_SIZE  = 64;                // 그리드 셀 크기 (buf2 픽셀 단위)
    const COLS       = Math.ceil(buf2Size / CELL_SIZE);
    const ROWS       = Math.ceil(buf2Size / CELL_SIZE);

    // ── 1. CSV 파싱 → 선분 배열 ──────────────────────────────
    // waterway.csv: river_id, point_index, x, y  (1081px 기준)
    const segMap = new Map();
    const lines = waterwayText.split('\n');
    for (let i = 1; i < lines.length; i++) {
        const parts = lines[i].trim().split(',');
        if (parts.length < 4) continue;
        const id = parseInt(parts[0]);
        if (isNaN(id)) continue;
        if (!segMap.has(id)) segMap.set(id, []);
        // buf2 좌표계로 변환
        segMap.get(id).push({
            x: parseFloat(parts[2]) * SCALE,
            y: parseFloat(parts[3]) * SCALE,
        });
    }

    // 연속된 점 쌍 → 선분 배열 [(ax,ay,bx,by), ...]
    const segments = [];
    for (const pts of segMap.values()) {
        for (let i = 0; i < pts.length - 1; i++) {
            segments.push({
                ax: pts[i].x,   ay: pts[i].y,
                bx: pts[i+1].x, by: pts[i+1].y,
            });
        }
    }
    if (segments.length === 0) return;

    // ── 2. 그리드 셀 인덱싱 ──────────────────────────────────
    // grid[(col, row)] = [선분 인덱스, ...]
    // 선분 AABB가 걸치는 셀 전체에 등록
    const hw = halfWidthPx * SCALE;              // buf2 기준 반폭
    const grid = new Map();                      // key: "col,row"

    const gridKey = (cx, cy) => `${cx},${cy}`;

    segments.forEach((seg, si) => {
        // 선분 AABB + 반폭 여유
        const x0 = Math.min(seg.ax, seg.bx) - hw;
        const y0 = Math.min(seg.ay, seg.by) - hw;
        const x1 = Math.max(seg.ax, seg.bx) + hw;
        const y1 = Math.max(seg.ay, seg.by) + hw;

        const cx0 = Math.max(0, Math.floor(x0 / CELL_SIZE));
        const cy0 = Math.max(0, Math.floor(y0 / CELL_SIZE));
        const cx1 = Math.min(COLS - 1, Math.floor(x1 / CELL_SIZE));
        const cy1 = Math.min(ROWS - 1, Math.floor(y1 / CELL_SIZE));

        for (let cy = cy0; cy <= cy1; cy++) {
            for (let cx = cx0; cx <= cx1; cx++) {
                const key = gridKey(cx, cy);
                if (!grid.has(key)) grid.set(key, []);
                grid.get(key).push(si);
            }
        }
    });

    // ── 3 & 4. 픽셀 순회 → 침식 ─────────────────────────────
    for (let y = 0; y < buf2Size; y++) {
        for (let x = 0; x < buf2Size; x++) {
            const idx = y * buf2Size + x;

            // 강 비트마스크(bit0) 안쪽 픽셀만 처리
            if ((featureBuf[idx] & 0x01) === 0) continue;

            // 소속 셀 → 후보 선분 목록
            const cx = Math.floor(x / CELL_SIZE);
            const cy = Math.floor(y / CELL_SIZE);
            const candidates = grid.get(gridKey(cx, cy));
            if (!candidates) continue;

            // 후보 선분 중 최단 거리
            let minDist = Infinity;
            for (const si of candidates) {
                const d = distToSegment(x, y, segments[si]);
                if (d < minDist) minDist = d;
            }

            if (minDist >= hw) continue;

            // 코사인 감쇠: 중심(d=0) → maxDepth, 가장자리(d=hw) → 0
            const t     = minDist / hw;                            // 0~1
            const depth = maxDepth * 0.5 * (1 + Math.cos(Math.PI * t));

            buf2[idx] = Math.max(0, buf2[idx] - minDist);
        }
    }
}
*/

// ---------------------------------------------------------------------------------------
// CSV 파싱 후 계단 현상을 방지하기 위해 타겟 해상도에 맞춰 직접 그립니다.
// ---------------------------------------------------------------------------------------
function applyWaterMask(buf2, buf2Size, featureBuf, csvText) {
    // 1. CSV 파싱 및 세그먼트 데이터 구성
    const segMap = new Map();
    const lines = csvText.split('\n');
    const ORIGINAL_CSV_SIZE = 1081; // 기준이 되는 원본 데이터 해상도

    for (let i = 1; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line) continue;

        const parts = line.split(',');
        if (parts.length < 4) continue;

        const riverId = parseInt(parts[0]);
        if (isNaN(riverId)) continue;

        const px = parseFloat(parts[2]);
        const py = parseFloat(parts[3]);
        if (isNaN(px) || isNaN(py)) continue;

        if (!segMap.has(riverId)) segMap.set(riverId, []);
        segMap.get(riverId).push({ px, py });
    }

    if (segMap.size === 0) return;

    // 2. 캔버스 초기화 - 처음부터 'buf2Size' 크기로 생성
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = buf2Size;
    const ctx = canvas.getContext('2d');

    // 검은색 바탕 초기화
    ctx.fillStyle = '#000000';
    ctx.fillRect(0, 0, buf2Size, buf2Size);

    // 선 및 채우기 스타일 설정
    ctx.fillStyle = ctx.strokeStyle = '#ffffff';

    // 해상도에 따른 선 두께 조정 (최소 1픽셀 이상 유지)
    const scale = buf2Size / ORIGINAL_CSV_SIZE;
    ctx.lineWidth = Math.max(1, 1 * scale); 
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round'; // 선 끝을 부드럽게 처리

    // 3. 데이터를 스케일링하여 타겟 캔버스에 직접 렌더링
    for (const pts of segMap.values()) {
        if (pts.length < 2) continue;
        
        ctx.beginPath();
        // 첫 번째 점을 스케일링하여 이동
        ctx.moveTo(pts[0].px * scale, pts[0].py * scale);
        
        for (let i = 1; i < pts.length; i++) {
            // 나머지 점들을 스케일링하여 연결
            ctx.lineTo(pts[i].px * scale, pts[i].py * scale);
        }

        const dx = pts[0].px - pts[pts.length - 1].px;
        const dy = pts[0].py - pts[pts.length - 1].py;
        const isClosed = pts.length >= 3 && Math.sqrt(dx * dx + dy * dy) < 2.0;

        if (isClosed) {
            ctx.closePath();
            ctx.fill();
        } else {
            ctx.stroke();
        }
    }

    // 4. 생성된 고해상도 캔버스에서 비트마스크 업데이트
    const pixels = ctx.getImageData(0, 0, buf2Size, buf2Size).data;
    
    // 1차원 루프로 빠르게 처리
    for (let i = 0; i < buf2Size * buf2Size; i++) {
        // R 채널이 0이 아니면 (흰색) 마킹
        if (pixels[i * 4] !== 0) {
            featureBuf[i] |= 0x01;
        }
    }
}

// ---------------------------------------------------------------------------------------
// waterway CSV(중심선 선분)를 기반으로 featureBuf의 bit0(강 내부)를 마킹합니다.
// 각 선분을 halfWidth 두께로 래스터라이즈하여 강 영역을 확정합니다.
//
// @param buf2Size    number    - 버퍼 한 변 길이
// @param featureBuf  Uint8Array - bit0: 강 내부 마킹 대상
// @param waterwayText string   - waterway CSV 텍스트
// @param halfWidth   number    - 강 반폭 (픽셀, 기본 2 → 선 두께 4px)
// ---------------------------------------------------------------------------------------
function applyWaterwayMask(buf2Size, featureBuf, waterwayText, halfWidth = 2) {

    const SCALE    = buf2Size / 1081;
    const segments = parseSegments(waterwayText, SCALE);
    if (segments.length === 0) return;

    const hw2 = halfWidth * halfWidth;  // 비교용 제곱

    // ── 각 선분의 AABB 범위만 순회 → 점-선분 거리 판정 ──────
    for (const { ax, ay, bx, by } of segments) {

        // 선분 AABB + halfWidth 여유
        const x0 = Math.max(0,           Math.floor(Math.min(ax, bx) - halfWidth));
        const y0 = Math.max(0,           Math.floor(Math.min(ay, by) - halfWidth));
        const x1 = Math.min(buf2Size - 1, Math.ceil(Math.max(ax, bx) + halfWidth));
        const y1 = Math.min(buf2Size - 1, Math.ceil(Math.max(ay, by) + halfWidth));

        const dx    = bx - ax;
        const dy    = by - ay;
        const lenSq = dx * dx + dy * dy;

        for (let py = y0; py <= y1; py++) {
            for (let px = x0; px <= x1; px++) {

                // 점 → 선분 최근접점 거리² 계산
                let t = 0;
                if (lenSq > 1e-9) {
                    t = ((px - ax) * dx + (py - ay) * dy) / lenSq;
                    t = Math.max(0, Math.min(1, t));
                }

                const qx   = ax + t * dx;
                const qy   = ay + t * dy;
                const dist2 = (px - qx) * (px - qx) + (py - qy) * (py - qy);

                if (dist2 <= hw2) {
                    featureBuf[py * buf2Size + px] |= 0x01;
                }
            }
        }
    }
}

/**
 * 레이블 버퍼를 색상별로 구분하여 PNG 파일로 다운로드합니다.
 * @param {Int32Array} labels - computeRiverLabels에서 생성된 레이블 배열
 * @param {number} size       - 버퍼의 가로/세로 크기 (buf2Size)
 * @param {number} totalRivers - 발견된 총 강의 개수
 * @param {string} filename   - 저장할 파일명
 */
function debugSaveLabelsPng(labels, size, totalRivers, filename = 'dbg_river_segments.png') {
    // 1. 강 그룹 ID별 고유 색상 사전 생성 (고대비 HSL 색상 활용)
    const colorMap = new Map();
    // 0번(육지)은 완전 투명
    colorMap.set(0, { r: 0, g: 0, b: 0, a: 0 }); 

    for (let i = 1; i <= totalRivers; i++) {
        // ID별로 색상이 겹치지 않도록 색상환(Hue)을 균등 분할
        const hue = (i * (360 / Math.min(totalRivers, 20))) % 360;
        // HSL을 RGB로 임시 변환하는 간이 로직
        const f = (n) => {
            const k = (n + hue / 30) % 12;
            const a = 0.8 * 100 / 100; // 채도 80%
            const l = 0.5; // 밝기 50%
            const min = l - a * Math.min(l, 1 - l);
            const max = l + a * Math.min(l, 1 - l);
            // 0~1 사이 값을 0~255 범위로 매핑
            const v = Math.round((min + (max - min) * Math.max(0, Math.min(1, Math.min(k - 3, 9 - k, 1)))) * 255);
            return v;
        };
        colorMap.set(i, { r: f(0), g: f(8), b: f(4), a: 255 });
    }

    // 2. 캔버스 픽셀 데이터(RGBA) 생성
    const pixels = new Uint8ClampedArray(size * size * 4);
    for (let i = 0; i < size * size; i++) {
        const labelId = labels[i];
        const color = colorMap.get(labelId) || { r: 255, g: 255, b: 255, a: 255 }; // 예외 처리용 화이트

        const pi = i * 4;
        pixels[pi]     = color.r;
        pixels[pi + 1] = color.g;
        pixels[pi + 2] = color.b;
        pixels[pi + 3] = color.a; // 강 영역은 255(불투명), 육지는 0(투명)
    }

    // 3. OffscreenCanvas 또는 가상 Canvas를 이용해 이미지화 후 다운로드
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    const ctx = canvas.getContext('2d');
    
    const imgData = ctx.createImageData(size, size);
    imgData.data.set(pixels);
    ctx.putImageData(imgData, 0, 0);

    // 브라우저 다운로드 트리거
    canvas.toBlob((blob) => {
        if (!blob) return;
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        console.log(`[디버그] 세그먼트 시각화 다운로드 완료: ${filename}`);
    }, 'image/png');
}