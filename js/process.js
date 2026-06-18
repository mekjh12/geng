// ════════════════════════════════════════════════════════════════════════════
// process.js — 하이트맵 데이터 처리 파이프라인
//
// 【전체 흐름】
//
// [rawBuf]  zoom14 타일 N×N장 → 하나의 큰 픽셀 배열로 합치기
//     ↓
// [buf1]    rawBuf에서 실제 영역만 bicubic crop → 1024×1024
//     ↓
// [buf2]    buf1을 GRID_N배 bicubic 업샘플 → (GRID_N×1024+1)²  예) 9217×9217
//     ↓
//           buf2 전체에 Gaussian blur 적용 (타일 경계 부드럽게)
//     ↓
// [output]  buf2에서 타일 1장(1025×1025) 추출 + Y축 플립
//           인접 타일 경계 픽셀 공유: 타일(n) 끝 = 타일(n+1) 시작
//     ↓
//           output → 고도맵 Blob / 노말맵 Blob / 축소판 Blob 생성 → ZIP
//
// ── Overview / MegaMap 별도 경로 ──────────────────────────────────────────
// [rawBuf]  zoom10 타일 다운로드 (더 넓은 광역 범위)
//     ↓
//           bicubic 샘플러(sample 함수) 반환 → 원하는 해상도로 즉시 리샘플링
//
// ════════════════════════════════════════════════════════════════════════════
//
//  [1] buildBuffer1()
//      지정한 중심 좌표를 기준으로 전체 영역(GRID_N × 1.024km)에 해당하는
//      Mapbox terrain-rgb 타일을 zoom14로 다운로드한다.
//      여러 타일을 하나의 rawBuf에 이어붙인 뒤, 실제 필요한 영역만
//      bicubic(Catmull-Rom) 보간으로 1024×1024 픽셀(buf1)로 리샘플링한다.
//
//  [2] buildBuffer2()
//      buf1(1024×1024)을 bicubic 업샘플링하여
//      (GRID_N×1024+1) × (GRID_N×1024+1) 크기의 buf2를 만든다.
//      예) GRID_N=9 → 9217×9217
//      타일 경계 픽셀을 인접 타일과 공유하기 위해 +1 크기로 생성한다.
//      buf2[n*1024] 픽셀이 타일 n과 타일 n+1의 경계 픽셀로 동시에 사용된다.
//
//  [3] applyGaussian()
//      buf2 전체에 Gaussian blur(sigma=3)를 적용한다.
//      타일 경계의 계단 현상을 완화하고 지형을 부드럽게 만든다.
//
//  [4] extractTile()
//      buf2에서 각 타일(row, col)에 해당하는 1025×1025 영역을 잘라낸다.
//      시작 좌표: (gridCol*1024, gridRow*1024)
//      끝 좌표:   (gridCol*1024+1024, gridRow*1024+1024)
//      인접 타일과 경계 픽셀 1개를 공유하므로 타일 간 높이 불일치가 없다.
//      3D 엔진 좌표계에 맞게 Y축을 플립(상하 반전)한다.
//
//  [5] generateNormalMap()
//      고도 데이터에 Sobel 필터를 적용해 법선(Normal) 벡터를 계산한다.
//      결과를 RGB(0~255)로 인코딩하여 노말맵 바이트 배열로 반환한다.
//
//  [6] makeTileFiles()
//      타일 1장에 대해 아래 4종의 파일 Blob을 생성한다.
//        - elev      : 고도맵 1025×1025 (PNG 또는 RAW 24bit)
//        - normal    : 노말맵 1025×1025 RAW
//        - elev_low  : 고도맵 129×129 축소판 RAW
//        - normal_low: 노말맵 129×129 축소판 RAW
//      elev_low/normal_low도 129×129로 생성하여 저해상도 타일 간
//      경계 픽셀을 공유한다.
//
//  [7] buildOverviewRawBuf()
//      Overview / MegaMap 전용 광역 샘플러.
//      zoom10으로 9×GRID_N×1.024km 범위를 커버하는 타일을 다운로드하고
//      bicubic crop 샘플러 함수를 반환한다.
//      반환된 sample(ox, oy, outW, outH) 을 호출하면 임의 해상도로 리샘플링된다.
//
// 【픽셀값 인코딩】
//      Mapbox terrain-rgb: height = (R*65536 + G*256 + B - 100000) / 10  (단위: m)
//      이 파일에서는 raw 정수값(R*65536+G*256+B)을 그대로 Int32Array에 저장하고,
//      실제 높이가 필요할 때만 (val - 100000) / 10 으로 변환한다.
//
// 【타일 크기 규칙】
//      고해상도 elev/normal : 1025×1025 (= 1024+1, 경계 공유)
//      저해상도 elev/normal : 129×129   (= 128+1, 경계 공유)
//
// ════════════════════════════════════════════════════════════════════════════


// ─── 타일 좌표 변환 ──────────────────────────────────────────────────────────
// 경위도를 Mercator 슬리피맵 타일 정수 좌표로 변환한다.

function long2tile(lon, zoom) {
    return Math.floor((lon + 180) / 360 * Math.pow(2, zoom));
}

function lat2tile(lat, zoom) {
    return Math.floor(
        (1 - Math.log(Math.tan(lat * Math.PI / 180) + 1 / Math.cos(lat * Math.PI / 180)) / Math.PI)
        / 2 * Math.pow(2, zoom)
    );
}

// 소수점 타일 좌표 (픽셀 단위 정밀 crop에 사용)
function lng2tileF(lng, zoom) {
    return (lng + 180) / 360 * Math.pow(2, zoom);
}

function lat2tileF(lat, zoom) {
    const s = Math.sin(lat * Math.PI / 180);
    return (1 - Math.log((1 + s) / (1 - s)) / (2 * Math.PI)) / 2 * Math.pow(2, zoom);
}

// 소수점 타일 좌표 → 픽셀 오프셋 (startTile 기준)
function tileF2px(tileF, startTile) {
    return (tileF - startTile) * MAPBOX_TILE_PX;
}


// ─── PNG 타일 다운로드 → RGBA 픽셀 배열 반환 ────────────────────────────────
// fetch로 terrain-rgb PNG를 받아 OffscreenCanvas로 디코딩한 뒤
// Uint8ClampedArray(RGBA) 형태로 반환한다.

async function downloadPngToPixels(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error('HTTP ' + res.status);

    const blob   = await res.blob();
    const bitmap = await createImageBitmap(blob);
    const oc     = new OffscreenCanvas(MAPBOX_TILE_PX, MAPBOX_TILE_PX);
    const ctx    = oc.getContext('2d');
    ctx.drawImage(bitmap, 0, 0);
    return ctx.getImageData(0, 0, MAPBOX_TILE_PX, MAPBOX_TILE_PX).data;
}


// ─── RGB → 정수 고도값 변환 ──────────────────────────────────────────────────
// Mapbox terrain-rgb 인코딩: R*65536 + G*256 + B (raw 정수, 아직 미터 변환 전)

function rgbToVal(r, g, b) {
    return r * 65536 + g * 256 + b;
}


// ─── bicubic 보간 가중치 (Catmull-Rom 스플라인) ──────────────────────────────
// t: 샘플 위치와 격자점 사이의 거리 (-2 ~ 2 범위에서 의미 있음)

function cubicWeight(t) {
    const a = -0.5, at = Math.abs(t);
    if (at < 1) return (a + 2) * at * at * at - (a + 3) * at * at + 1;
    if (at < 2) return a * at * at * at - 5 * a * at * at + 8 * a * at - 4 * a;
    return 0;
}


// ─── STEP 1: 버퍼1 생성 (1024×1024) ─────────────────────────────────────────
// 중심 좌표 주변의 terrain-rgb 타일을 zoom14로 다운로드하여
// 필요한 영역을 bicubic 보간으로 1024×1024(buf1)에 리샘플링한다.
//
// 반환값: { buf1: Int32Array(1024×1024), mpp: 미터/픽셀 }

async function buildBuffer1(centerLng, centerLat, gridN) {
    const totalKm = TILE_SIZE_KM * gridN;
    const totalM  = totalKm * 1000;
    const latIdx  = Math.min(90, Math.round(Math.abs(centerLat)));
    const mpp     = METERS_PER_PIXEL_BY_LAT[latIdx];
    const tilesN  = Math.ceil(totalM / (MAPBOX_TILE_PX * mpp)) + 1;

    const half     = totalKm / 2;
    const centerPt = turf.point([centerLng, centerLat]);
    const nwLng = turf.destination(centerPt, half, 270, { units: 'kilometers' }).geometry.coordinates[0];
    const nwLat = turf.destination(centerPt, half,   0, { units: 'kilometers' }).geometry.coordinates[1];
    const seLng = turf.destination(centerPt, half,  90, { units: 'kilometers' }).geometry.coordinates[0];
    const seLat = turf.destination(centerPt, half, 180, { units: 'kilometers' }).geometry.coordinates[1];

    const startX = long2tile(nwLng, FETCH_ZOOM);
    const startY = lat2tile(nwLat,  FETCH_ZOOM);

    const tileList = [];
    for (let ty = 0; ty < tilesN; ty++)
        for (let tx = 0; tx < tilesN; tx++)
            tileList.push({ tx: startX + tx, ty: startY + ty, col: tx, row: ty });

    document.getElementById('loading-sub').textContent =
        '버퍼1: ' + tilesN + 'x' + tilesN + ' = ' + (tilesN * tilesN) + '장 다운로드 중...';

    const pixelMap = new Map();
    await Promise.all(tileList.map(async function({ tx, ty, col, row }) {
        const url = 'https://api.mapbox.com/v4/mapbox.terrain-rgb/'
            + FETCH_ZOOM + '/' + tx + '/' + ty
            + '@2x.pngraw?access_token=' + MAPBOX_TOKEN;
        try {
            pixelMap.set(col + ',' + row, await downloadPngToPixels(url));
        } catch(e) {
            console.warn('타일 ' + tx + ',' + ty + ' 실패:', e.message);
        }
    }));

    const rawSize = tilesN * MAPBOX_TILE_PX;
    const rawBuf  = new Int32Array(rawSize * rawSize);
    for (let row = 0; row < tilesN; row++) {
        for (let col = 0; col < tilesN; col++) {
            const px = pixelMap.get(col + ',' + row);
            if (!px) continue;
            const ox = col * MAPBOX_TILE_PX;
            const oy = row * MAPBOX_TILE_PX;
            for (let y = 0; y < MAPBOX_TILE_PX; y++)
                for (let x = 0; x < MAPBOX_TILE_PX; x++) {
                    const si = (y * MAPBOX_TILE_PX + x) * 4;
                    rawBuf[(oy + y) * rawSize + (ox + x)] = rgbToVal(px[si], px[si + 1], px[si + 2]);
                }
        }
    }

    const cropX0  = tileF2px(lng2tileF(nwLng, FETCH_ZOOM), startX);
    const cropY0  = tileF2px(lat2tileF(nwLat, FETCH_ZOOM), startY);
    const cropX1  = tileF2px(lng2tileF(seLng, FETCH_ZOOM), startX);
    const cropY1  = tileF2px(lat2tileF(seLat, FETCH_ZOOM), startY);
    const cropPxW = cropX1 - cropX0;
    const cropPxH = cropY1 - cropY0;

    document.getElementById('loading-sub').textContent = '버퍼1: crop → 1024x1024 bicubic...';

    const buf1 = new Int32Array(OUTPUT_PX * OUTPUT_PX);
    for (let oy = 0; oy < OUTPUT_PX; oy++) {
        for (let ox = 0; ox < OUTPUT_PX; ox++) {
            const srcX = cropX0 + ox * cropPxW / (OUTPUT_PX - 1);
            const srcY = cropY0 + oy * cropPxH / (OUTPUT_PX - 1);
            const ix   = Math.floor(srcX), iy = Math.floor(srcY);
            const dx   = srcX - ix,        dy = srcY - iy;
            let val = 0;
            for (let m = -1; m <= 2; m++) {
                const wy = cubicWeight(m - dy);
                if (wy === 0) continue;
                for (let n = -1; n <= 2; n++) {
                    const cx = Math.max(0, Math.min(rawSize - 1, ix + n));
                    const cy = Math.max(0, Math.min(rawSize - 1, iy + m));
                    val += rawBuf[cy * rawSize + cx] * cubicWeight(n - dx) * wy;
                }
            }
            buf1[oy * OUTPUT_PX + ox] = Math.max(0, Math.min(16777215, Math.round(val)));
        }
    }
    return { buf1, mpp };
}


// ─── STEP 2: 버퍼2 생성 ((GRID_N×1024+1)² 사이즈로 bicubic 업샘플) ──────────
// buf1(1024×1024)을 bicubic 업샘플링하여 (GRID_N*1024+1)² 크기의 buf2를 만든다.
// 예) GRID_N=9 → 9217×9217
//
// +1 픽셀 이유:
//   타일 n의 끝 픽셀(col n*1024+1024)과 타일 n+1의 시작 픽셀(col (n+1)*1024)이
//   동일한 buf2 인덱스를 가리켜 경계 픽셀을 완전히 공유한다.
//
// 매핑 수식:
//   buf2의 픽셀 ox → buf1의 srcX = ox * (OUTPUT_PX-1) / (buf2Size-1)
//   → buf2Size-1 = GRID_N*1024 구간이 buf1의 0~1023 구간에 정확히 대응
//
// 반환값: { buf2: Int32Array, buf2Size: number }

function buildBuffer2(buf1, gridN) {
    const buf2Size = gridN * OUTPUT_PX + 1;  // 예) 9*1024+1 = 9217
    const buf2     = new Int32Array(buf2Size * buf2Size);

    // buf1 경계 클램핑 샘플러
    function sampleBuf1(sx, sy) {
        const cx = Math.max(0, Math.min(OUTPUT_PX - 1, Math.round(sx)));
        const cy = Math.max(0, Math.min(OUTPUT_PX - 1, Math.round(sy)));
        return buf1[cy * OUTPUT_PX + cx];
    }

    for (let oy = 0; oy < buf2Size; oy++) {
        for (let ox = 0; ox < buf2Size; ox++) {
            // buf2 전체(0~buf2Size-1)를 buf1(0~OUTPUT_PX-1)에 매핑
            const srcX = ox * (OUTPUT_PX - 1) / (buf2Size - 1);
            const srcY = oy * (OUTPUT_PX - 1) / (buf2Size - 1);
            const ix   = Math.floor(srcX), iy = Math.floor(srcY);
            const dx   = srcX - ix,        dy = srcY - iy;
            let val = 0;
            for (let m = -1; m <= 2; m++) {
                const wy = cubicWeight(m - dy);
                if (wy === 0) continue;
                for (let n = -1; n <= 2; n++)
                    val += sampleBuf1(ix + n, iy + m) * cubicWeight(n - dx) * wy;
            }
            buf2[oy * buf2Size + ox] = Math.max(0, Math.min(16777215, Math.round(val)));
        }
    }
    return { buf2, buf2Size };
}


// ─── STEP 3: Gaussian blur 적용 ──────────────────────────────────────────────
// buf2 전체에 분리 가능한(separable) Gaussian 필터를 적용한다.
// 가로 패스 → tmp 배열, 세로 패스 → buf2 덮어쓰기.
// sigma=3, radius=ceil(sigma*3)=9 → 커널 크기 19
// 경계는 클램핑(가장자리 픽셀 반복)으로 처리한다.

function applyGaussian(buf2, buf2Size) {
    const SIGMA  = 3.0;
    const RADIUS = Math.ceil(SIGMA * 3);
    const KSIZE  = RADIUS * 2 + 1;
    const kernel = new Float32Array(KSIZE);
    let sum = 0;
    for (let i = 0; i < KSIZE; i++) {
        kernel[i] = Math.exp(-((i - RADIUS) ** 2) / (2 * SIGMA * SIGMA));
        sum += kernel[i];
    }
    for (let i = 0; i < KSIZE; i++) kernel[i] /= sum;

    // 가로 패스 (행 방향)
    const tmp = new Float32Array(buf2Size * buf2Size);
    for (let y = 0; y < buf2Size; y++)
        for (let x = 0; x < buf2Size; x++) {
            let s = 0;
            for (let k = -RADIUS; k <= RADIUS; k++)
                s += buf2[y * buf2Size + Math.max(0, Math.min(buf2Size - 1, x + k))] * kernel[k + RADIUS];
            tmp[y * buf2Size + x] = s;
        }

    // 세로 패스 (열 방향) → buf2 덮어쓰기
    for (let y = 0; y < buf2Size; y++)
        for (let x = 0; x < buf2Size; x++) {
            let s = 0;
            for (let k = -RADIUS; k <= RADIUS; k++)
                s += tmp[Math.max(0, Math.min(buf2Size - 1, y + k)) * buf2Size + x] * kernel[k + RADIUS];
            buf2[y * buf2Size + x] = Math.max(0, Math.min(16777215, Math.round(s)));
        }
}


// ─── STEP 4: 타일 1장 추출 (Y축 플립) ───────────────────────────────────────
// buf2에서 (gridRow, gridCol) 위치의 1025×1025 블록을 잘라낸다.
//
// 추출 범위:
//   x: gridCol*1024 ~ gridCol*1024+1024  (1025픽셀)
//   y: gridRow*1024 ~ gridRow*1024+1024  (1025픽셀)
//
// 경계 공유:
//   타일(n)의 마지막 열/행 = 타일(n+1)의 첫 번째 열/행 (동일한 buf2 픽셀)
//   → 타일 간 높이 불일치 없음
//
// Y축 플립:
//   3D 엔진은 Y축이 위로 증가하므로 픽셀 Y를 뒤집어 저장한다.
//   output[(TILE_PX-1-y)*TILE_PX + x] = buf2[(oy+y)*buf2Size + (ox+x)]
//
// 반환값: { output: Int32Array(1025×1025), minM: number, maxM: number }
function extractTile(buf2, buf2Size, featureBuf, gridRow, gridCol) {
    const TILE_PX     = OUTPUT_PX + 1;          // 1025
    const output      = new Int32Array(TILE_PX * TILE_PX);
    const featureTile = new Uint8Array(TILE_PX * TILE_PX);
    const ox = gridCol * OUTPUT_PX;
    const oy = gridRow * OUTPUT_PX;
    let minVal = Infinity, maxVal = -Infinity;

    for (let y = 0; y < TILE_PX; y++) {
        for (let x = 0; x < TILE_PX; x++) {
            const srcIdx = (oy + y) * buf2Size + (ox + x);
            const dstIdx = (TILE_PX - 1 - y) * TILE_PX + x;  // Y플립

            output[dstIdx]      = buf2[srcIdx];
            featureTile[dstIdx] = featureBuf[srcIdx];          // ▼ 추가

            const v = buf2[srcIdx];
            if (v > maxVal) maxVal = v;
            if (v < minVal) minVal = v;
        }
    }

    return {
        output,
        featureTile,   // ▼ 추가
        minM: (minVal - 100000) / 10,
        maxM: (maxVal - 100000) / 10,
    };
}


// ─── 노말맵 생성 (Sobel 필터) ────────────────────────────────────────────────
// 고도 데이터에 3×3 Sobel 커널을 적용해 법선 벡터(nx, ny, nz)를 계산하고
// RGB(0~255)로 인코딩한다.
//   nx → R,  ny → G,  nz → B
//   인코딩: (n * 0.5 + 0.5) * 255
// metersPerPixel: 픽셀 1개가 실제 몇 미터인지 (Sobel 분모)
//
// 반환값: Uint8Array (size×size×3, RGB 순서)

function generateNormalMap(output, size, metersPerPixel, featureTile) {
    const bytes = new Uint8Array(size * size * 4);  // *3 → *4 (RGBA)
	//const bytes = new Uint8Array(size * size * 3); // RGB


    function hAt(x, y) {
        const cx = Math.max(0, Math.min(size - 1, x));
        const cy = Math.max(0, Math.min(size - 1, y));
        return (output[cy * size + cx] - 100000) / 10;
    }

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const dX = (
                -hAt(x-1, y-1) + hAt(x+1, y-1)
                - hAt(x-1, y  ) * 2 + hAt(x+1, y  ) * 2
                - hAt(x-1, y+1) + hAt(x+1, y+1)
            ) / (8 * metersPerPixel);

            const dY = (
                -hAt(x-1, y-1) - hAt(x, y-1) * 2 - hAt(x+1, y-1)
                + hAt(x-1, y+1) + hAt(x, y+1) * 2 + hAt(x+1, y+1)
            ) / (8 * metersPerPixel);

            const nx  = -dX, ny = dY, nz = 1.0;
            const len = Math.sqrt(nx*nx + ny*ny + nz*nz);
            const i   = (y * size + x) * 4;  // *3 → *4

            bytes[i]     = Math.round(( nx / len * 0.5 + 0.5) * 255);  // R: nx
            bytes[i + 1] = Math.round((-ny / len * 0.5 + 0.5) * 255);  // G: ny
            bytes[i + 2] = Math.round(( nz / len * 0.5 + 0.5) * 255);  // B: nz
            bytes[i + 3] = 255;//featureTile ? featureTile[y * size + x] : 0; // A: biome
        }
    }
    return bytes;
}

// ─── 타일 파일 Blob 생성 ─────────────────────────────────────────────────────
// 타일 output(1025×1025) 배열로부터 4종의 파일 Blob을 생성한다.
//   - elev      : 고도맵 1025×1025 (PNG 또는 RAW 24bit)
//   - normal    : 노말맵 1025×1025 RAW 24bit
//   - elev_low  : 고도맵 129×129 축소판 RAW  (= 128+1, 저해상도 경계 공유)
//   - normal_low: 노말맵 129×129 축소판 RAW
//
// 반환값: { blob, normalBlob, lowBlob, lowNormalBlob }
async function makeTileFiles(output, featureTile, format, mpp, heightOffset) {
    if (heightOffset === undefined) heightOffset = 0;

    const TILE_PX    = OUTPUT_PX + 1;   // 1025
    const LOW_PX     = 129;
    const tileCoverM = TILE_SIZE_KM * 1000;

    // ── 노말맵 1025×1025 ──────────────────────────────────
    const normalBytes = generateNormalMap(output, TILE_PX, tileCoverM / (TILE_PX - 1), featureTile);
    const normalBlob  = new Blob([normalBytes], { type: 'application/octet-stream' });

    // ── 축소판 129×129 다운샘플 ───────────────────────────
    const lowOutput   = new Int32Array(LOW_PX * LOW_PX);
    const lowFloats   = new Float32Array(LOW_PX * LOW_PX);
    const lowFeature  = new Uint8Array(LOW_PX * LOW_PX);  // ▼ 추가

    for (let oy = 0; oy < LOW_PX; oy++) {
        for (let ox = 0; ox < LOW_PX; ox++) {
            const srcX = ox * (TILE_PX - 1) / (LOW_PX - 1);
            const srcY = oy * (TILE_PX - 1) / (LOW_PX - 1);
            const ix   = Math.floor(srcX), iy = Math.floor(srcY);
            const dx   = srcX - ix,        dy = srcY - iy;
            const clamp = (v, mx) => Math.max(0, Math.min(v, mx));

            const v = Math.round(
                output[clamp(iy,     TILE_PX-1) * TILE_PX + clamp(ix,     TILE_PX-1)] * (1-dx) * (1-dy) +
                output[clamp(iy,     TILE_PX-1) * TILE_PX + clamp(ix + 1, TILE_PX-1)] * dx     * (1-dy) +
                output[clamp(iy + 1, TILE_PX-1) * TILE_PX + clamp(ix,     TILE_PX-1)] * (1-dx) * dy     +
                output[clamp(iy + 1, TILE_PX-1) * TILE_PX + clamp(ix + 1, TILE_PX-1)] * dx     * dy
            );
            lowOutput[oy * LOW_PX + ox] = v;
            lowFloats[oy * LOW_PX + ox] = (v - 100000) / 10.0 + heightOffset;

            // ▼ 추가: nearest 샘플링 (bitflag는 보간 불가)
            const nx = clamp(Math.round(srcX), TILE_PX - 1);
            const ny = clamp(Math.round(srcY), TILE_PX - 1);
            lowFeature[oy * LOW_PX + ox] = featureTile ? featureTile[ny * TILE_PX + nx] : 0;
        }
    }

    const lowBlob = new Blob([lowFloats.buffer], { type: 'application/octet-stream' });

    // ── 노말맵 129×129 ────────────────────────────────────
    const lowNormalBlob = new Blob(
        [generateNormalMap(lowOutput, LOW_PX, tileCoverM / (LOW_PX - 1), lowFeature)],  // ▼ 수정
        { type: 'application/octet-stream' }
    );

    // ── 고도맵 메인 Blob (기존과 동일) ───────────────────
    let blob;
    if (format === 'raw') {
        const floats = new Float32Array(TILE_PX * TILE_PX);
        for (let i = 0; i < TILE_PX * TILE_PX; i++) {
            floats[i] = (output[i] - 100000) / 10.0 + heightOffset;
        }
        blob = new Blob([floats.buffer], { type: 'application/octet-stream' });
    } else {
        const pixels = new Uint8ClampedArray(TILE_PX * TILE_PX * 4);
        for (let i = 0; i < TILE_PX * TILE_PX; i++) {
            const v = output[i];
            pixels[i * 4]     =  v        & 0xFF;
            pixels[i * 4 + 1] = (v >>  8) & 0xFF;
            pixels[i * 4 + 2] = (v >> 16) & 0xFF;
            pixels[i * 4 + 3] = 255;
        }
        const canvas  = document.createElement('canvas');
        canvas.width  = canvas.height = TILE_PX;
        const ctx     = canvas.getContext('2d');
        const imgData = ctx.createImageData(TILE_PX, TILE_PX);
        imgData.data.set(pixels);
        ctx.putImageData(imgData, 0, 0);
        blob = await new Promise(res => canvas.toBlob(res, 'image/png'));
    }

	// ── 피처맵 단일 채널 RAW (Uint8, 1채널) ──────────────
	const featureBlob = new Blob(
		[featureTile ? featureTile : new Uint8Array(TILE_PX * TILE_PX)],
		{ type: 'application/octet-stream' }
	);
	const lowFeatureBlob = new Blob(
		[lowFeature],
		{ type: 'application/octet-stream' }
	);

	return { blob, normalBlob, lowBlob, lowNormalBlob, featureBlob, lowFeatureBlob };
}


// ─── Overview / MegaMap 공통 rawBuf 샘플러 ───────────────────────────────────
// Overview(512×512) 및 MegaMap(9216×9216) 생성에 공통으로 사용하는 함수.
// zoom10으로 광역(9×GRID_N×1.024km) 타일을 다운로드하고,
// bicubic crop 샘플러(sample 함수)를 클로저로 반환한다.
//
// 반환값: { sample(ox, oy, outW, outH) → Int32 고도값 }

async function buildOverviewRawBuf(onStatus) {
    const OVERVIEW_M_ = OV_GRID * GRID_N * TILE_SIZE_KM * 1000;
    const halfKm      = OVERVIEW_M_ / 2 / 1000;
    const centerPt    = turf.point([state.lng, state.lat]);

    const nwLng = turf.destination(centerPt, halfKm, 270, { units: 'kilometers' }).geometry.coordinates[0];
    const nwLat = turf.destination(centerPt, halfKm,   0, { units: 'kilometers' }).geometry.coordinates[1];
    const seLng = turf.destination(centerPt, halfKm,  90, { units: 'kilometers' }).geometry.coordinates[0];
    const seLat = turf.destination(centerPt, halfKm, 180, { units: 'kilometers' }).geometry.coordinates[1];

    const nwTileX = lng2tileF(nwLng, OVERVIEW_ZOOM), nwTileY = lat2tileF(nwLat, OVERVIEW_ZOOM);
    const seTileX = lng2tileF(seLng, OVERVIEW_ZOOM), seTileY = lat2tileF(seLat, OVERVIEW_ZOOM);
    const startX  = Math.floor(nwTileX), startY = Math.floor(nwTileY);
    const tilesW  = Math.ceil(seTileX) - startX;
    const tilesH  = Math.ceil(seTileY) - startY;

    const tileList = [];
    for (let ty = 0; ty < tilesH; ty++)
        for (let tx = 0; tx < tilesW; tx++)
            tileList.push({ tx: startX + tx, ty: startY + ty, col: tx, row: ty });

    if (onStatus) onStatus('zoom' + OVERVIEW_ZOOM + ': ' + tilesW + 'x' + tilesH + '=' + (tilesW * tilesH) + '장 다운로드 중...');

    const pixelMap = new Map();
    await Promise.all(tileList.map(async function({ tx, ty, col, row }) {
        const url = 'https://api.mapbox.com/v4/mapbox.terrain-rgb/'
            + OVERVIEW_ZOOM + '/' + tx + '/' + ty
            + '@2x.pngraw?access_token=' + MAPBOX_TOKEN;
        try {
            pixelMap.set(col + ',' + row, await downloadPngToPixels(url));
        } catch(e) {
            console.warn('overview 타일 ' + tx + ',' + ty + ' 실패:', e.message);
        }
    }));

    if (onStatus) onStatus('rawBuf 합성 중...');
    await new Promise(function(r) { setTimeout(r, 10); });

    const rawW   = tilesW * MAPBOX_TILE_PX;
    const rawH   = tilesH * MAPBOX_TILE_PX;
    const rawBuf = new Int32Array(rawW * rawH);
    for (let row = 0; row < tilesH; row++) {
        for (let col = 0; col < tilesW; col++) {
            const px = pixelMap.get(col + ',' + row);
            if (!px) continue;
            const ox = col * MAPBOX_TILE_PX, oy = row * MAPBOX_TILE_PX;
            for (let y = 0; y < MAPBOX_TILE_PX; y++)
                for (let x = 0; x < MAPBOX_TILE_PX; x++) {
                    const si = (y * MAPBOX_TILE_PX + x) * 4;
                    rawBuf[(oy + y) * rawW + (ox + x)] = rgbToVal(px[si], px[si + 1], px[si + 2]);
                }
        }
    }

    const cropX0  = tileF2px(nwTileX, startX), cropY0 = tileF2px(nwTileY, startY);
    const cropX1  = tileF2px(seTileX, startX), cropY1 = tileF2px(seTileY, startY);
    const cropPxW = cropX1 - cropX0, cropPxH = cropY1 - cropY0;

    // bicubic 샘플러 클로저 반환
    function sample(ox, oy, outW, outH) {
        const srcX = cropX0 + ox * cropPxW / (outW - 1);
        const srcY = cropY0 + oy * cropPxH / (outH - 1);
        const ix   = Math.floor(srcX), iy = Math.floor(srcY);
        const dx   = srcX - ix,        dy = srcY - iy;
        let val = 0;
        for (let m = -1; m <= 2; m++) {
            const wy = cubicWeight(m - dy);
            if (wy === 0) continue;
            for (let n = -1; n <= 2; n++) {
                val += rawBuf[
                    Math.max(0, Math.min(rawH - 1, iy + m)) * rawW +
                    Math.max(0, Math.min(rawW - 1, ix + n))
                ] * cubicWeight(n - dx) * wy;
            }
        }
        return Math.max(0, Math.min(16777215, Math.round(val)));
    }

    return { sample, rawW, rawH };
}

// ─── 타일 정수 좌표 → 경위도 역변환 ─────────────────────────────────────────
// my.js의 PBF 벡터 타일 처리에서 타일 좌상단 경위도 계산에 사용한다.

function tile2long(x, z) {
    return x / Math.pow(2, z) * 360 - 180;
}

function tile2lat(y, z) {
    const n = Math.PI - 2 * Math.PI * y / Math.pow(2, z);
    return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
}