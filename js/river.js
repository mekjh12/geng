function applyRiverCarving(buf2, buf2Size, featureBuf, waterwayText, iterations = 1) {
    const SCALE     = buf2Size / 1081;
    const CELL_SIZE = 64;
    const COLS      = Math.ceil(buf2Size / CELL_SIZE);
    const ROWS      = Math.ceil(buf2Size / CELL_SIZE);

    // 강 유역 세그먼트 분리 (연결 성분 분석)
    const { labels, totalRivers } = computeRiverLabels(featureBuf, buf2Size);
    console.log(`=== 강 유역 세그먼트 완료: 총 ${totalRivers}개 수계 발견 ===`);

    // 강줄기 벡터 데이터 파싱 및 격자 등록
    const segments = parseSegments(waterwayText, SCALE);
    if (segments.length === 0) return;

    // 가속 격자(Grid) 등록
    const grid = buildGrid(segments, COLS, ROWS, CELL_SIZE);

    // 강 가장자리 경계 거리 계산
    const { dist: distToBorder } = computeBorderDist(featureBuf, buf2Size);
    const smoothedDist = gaussianBlurDist(distToBorder, featureBuf, buf2Size, 2.0);

    // 투영점 사전 캐싱
    const projCache = new Array(buf2Size * buf2Size).fill(null);
    for (let py = 0; py < buf2Size; py++) {
        for (let px = 0; px < buf2Size; px++) {
            const idx = py * buf2Size + px;
            if ((featureBuf[idx] & 0x01) === 0) continue;
            const result = nearestProjection(px, py, grid, segments, buf2Size, CELL_SIZE);
            if (result) projCache[idx] = result;
        }
    }

    // 유역별 평균 고도 계산
    const baseHeightBuf = computeRiverAvgHeights(labels, totalRivers, featureBuf, projCache, buf2, buf2Size);
    console.log(`=== (1) 유역별 최저 고도 계산 완료 ===`);

    // 침식: 강 픽셀을 smoothedDist만큼 파내기
    erodeRiver(buf2, buf2Size, featureBuf, smoothedDist, baseHeightBuf);
    console.log(`=== (2) 침식 완료 ===`);

    // 강 경계 부드럽게 하기
    smoothRiverBorders(buf2, featureBuf, buf2Size, 1.5);
    smoothRiverBorders(buf2, featureBuf, buf2Size, 1.5);
    smoothRiverBorders(buf2, featureBuf, buf2Size, 1.5);

    // 피처맵 플래그 계산
    computeFeatureFlags(featureBuf, buf2, buf2Size, smoothedDist, labels);
    console.log(`=== (3) 피처맵 플래그 계산 완료 ===`);

    console.log(`=== 강 파기 전체 완료 ===`);
	
	return { baseHeightBuf, projCache };

} 

const FeatureFlags = {
    RIVER:         0x01,  // 강 여부
    ROAD:          0x02,  // 도로 여부
    RIVER_EDGE:    0x04,  // 강 가장자리
    RIVER_CENTER:  0x08,  // 강 중심부
    RIVER_SHALLOW: 0x10,  // 여울
};

function computeFeatureFlags(featureBuf, buf2, buf2Size, smoothedDist, labels) {
    for (let idx = 0; idx < buf2Size * buf2Size; idx++) {

        // 강 픽셀이 아니면 스킵
        if ((featureBuf[idx] & FeatureFlags.RIVER) === 0) continue;

        const dist = smoothedDist[idx];
        const x = idx % buf2Size;
        const y = (idx - x) / buf2Size;

        // 강 가장자리: dist 작은 구간
        if (dist < 2) {
            featureBuf[idx] |= FeatureFlags.RIVER_EDGE;
        }

        // 강 중심부: dist 큰 구간
        if (dist >= 6) {
            featureBuf[idx] |= FeatureFlags.RIVER_CENTER;
        }

        // 여울: 폭이 좁고 주변보다 고도가 높은 곳
        if (dist < 4) {
            const h  = buf2[idx];
            const hN = y > 0            ? buf2[(y-1)*buf2Size + x] : h;
            const hS = y < buf2Size - 1 ? buf2[(y+1)*buf2Size + x] : h;
            const hW = x > 0            ? buf2[y*buf2Size + (x-1)] : h;
            const hE = x < buf2Size - 1 ? buf2[y*buf2Size + (x+1)] : h;
            const avgNeighbor = (hN + hS + hW + hE) / 4;

            if (h > avgNeighbor) {
                featureBuf[idx] |= FeatureFlags.RIVER_SHALLOW;
            }
        }
    }
}

function erodeRiver(buf2, buf2Size, featureBuf, smoothedDist, baseHeightBuf) {
    for (let idx = 0; idx < buf2Size * buf2Size; idx++) {
        if ((featureBuf[idx] & 0x01) === 0) continue;

        // 강 평균 고도보다 높으면 강 픽셀에서 제외
        if (buf2[idx] > baseHeightBuf[idx] + 100) {
            featureBuf[idx] &= ~0x01;
            continue;
        } 

        const carvingHeight = Math.min(200, smoothedDist[idx] * 10); 
        buf2[idx] = Math.max(0, buf2[idx] - carvingHeight);
    }
}

/**
 * 강 유역별로 투영점들의 평균 고도를 계산하여 통일된 기준 고도 버퍼를 생성합니다.
 * @param {Int32Array} labels       - 강 영역 레이블 배열 (computeRiverLabels 결과물)
 * @param {number} totalRivers      - 발견된 총 강 유역 개수
 * @param {Uint8Array} featureBuf   - 강 영역 마스크 버퍼
 * @param {Array} projCache         - 미리 계산된 투영점 캐시 배열
 * @param {Int32Array} buf2         - 원본 고도 데이터 버퍼
 * @param {number} buf2Size         - 버퍼의 가로/세로 크기
 * @returns {Float32Array} 유역별 평균 고도로 채워진 기준 고도 버퍼 (baseHeightBuf)
 */
function computeRiverAvgHeights(labels, totalRivers, featureBuf, projCache, buf2, buf2Size) {
    const baseHeightBuf = new Float32Array(buf2Size * buf2Size);
    
    // 유역별 고도 합산 및 픽셀 카운트 배열 (0번 index는 육지이므로 비워둠)
    const riverHeightSums = new Float64Array(totalRivers + 1);
    const riverPixelCounts = new Int32Array(totalRivers + 1);

    let minValue = Infinity;  
    let maxValue = -Infinity; 
    let validPixelCount = 0;  

    // 1. 투영점 고도를 미터 단위 실수로 변환하여 유역별로 누적 합산
    for (let i = 0; i < buf2Size * buf2Size; i++) {
        if ((featureBuf[i] & 0x01) === 0) continue;

        const result = projCache[i];
        if (!result) continue;

        // 투영점의 원본 고도 추출 및 실수 변환
        const rawValue = buf2[result.qy * buf2Size + result.qx];
        const actualHeightInMeters = (rawValue - 100000) / 10.0;

        // 전체 통계용 계산
        if (actualHeightInMeters < minValue) minValue = actualHeightInMeters;
        if (actualHeightInMeters > maxValue) maxValue = actualHeightInMeters;
        validPixelCount++;

        // 해당 유역 그룹에 누적
        const rId = labels[i];
        if (rId > 0) {
            riverHeightSums[rId] += actualHeightInMeters;
            riverPixelCounts[rId]++;
        }
    }

    if (validPixelCount > 0) {
        console.log(`=== 강 투영점 고도 분석 완료 (총 ${validPixelCount} 픽셀) ===`);
        console.log(`전체 최소 고도: ${minValue.toFixed(4)}m / 최대 고도: ${maxValue.toFixed(4)}m`);
    } else {
        console.log('강 내부 영역에서 유효한 투영점 고도 데이터를 찾지 못했습니다.');
        return baseHeightBuf;
    }

    // 2. 유역별 평균 고도 계산 및 Mapbox 정수 포맷 역변환 매핑 테이블 생성
    const riverAvgRawValues = new Int32Array(totalRivers + 1);
    for (let i = 1; i <= totalRivers; i++) {
        if (riverPixelCounts[i] > 0) {
            const avgHeight = riverHeightSums[i] / riverPixelCounts[i];
            // 실수를 다시 Mapbox 인코딩 정수로 역변환
            riverAvgRawValues[i] = Math.round(avgHeight * 10.0 + 100000);
            console.log(`[유역 분석] 강 ID ${i} -> 픽셀 수: ${riverPixelCounts[i]}, 평균 고도: ${avgHeight.toFixed(2)}m`);
        }
    }

    // 3. 최종 baseHeightBuf를 유역 평균 정수값으로 채우기
    for (let i = 0; i < buf2Size * buf2Size; i++) {
        if ((featureBuf[i] & 0x01) !== 0) {
            const rId = labels[i];
            baseHeightBuf[i] = riverAvgRawValues[rId]; 
        }
    }

    return baseHeightBuf;
}


/**
 * 강 침식 후 경계면 고도 완화 (Border Gaussian Blur)
 * @param {Int16Array|Float32Array} buf2 - 최종 고도 데이터 버퍼
 * @param {Uint8Array} featureBuf - 강 영역 마스크 버퍼
 * @param {number} size - 버퍼 크기
 * @param {number} sigma - 블러 반경 조절 변수 (기본값 1.5)
 */
function smoothRiverBorders(buf2, featureBuf, size, sigma = 1.5) {
    const radius = Math.ceil(sigma * 3);
    const kSize = radius * 2 + 1;
    const kernel = new Float32Array(kSize * kSize);

    // 1. 가우시안 커널 생성
    let kernelSum = 0;
    for (let ky = -radius; ky <= radius; ky++) {
        for (let kx = -radius; kx <= radius; kx++) {
            const w = Math.exp(-(kx * kx + ky * ky) / (2 * sigma * sigma));
            kernel[(ky + radius) * kSize + (kx + radius)] = w;
            kernelSum += w;
        }
    }
    for (let i = 0; i < kernel.length; i++) kernel[i] /= kernelSum;

    // 임시 버퍼 복사 (동시 원본 참조 문제 방지)
    const tempBuf = new Float32Array(buf2);

    // 2. 경계면 순회 및 블러 적용
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const idx = y * size + x;

            // [조건] 4방향 중 강 안팎의 경계가 교차하는 지점만 타겟팅합니다.
            if (x === 0 || x === size - 1 || y === 0 || y === size - 1) continue;
            
            const isRiver = (featureBuf[idx] & 0x01) !== 0;
            const hasLandNeighbor = 
                ((featureBuf[(y - 1) * size + x] & 0x01) === 0) ||
                ((featureBuf[(y + 1) * size + x] & 0x01) === 0) ||
                ((featureBuf[y * size + (x - 1)] & 0x01) === 0) ||
                ((featureBuf[y * size + (x + 1)] & 0x01) === 0);

            const hasRiverNeighbor = 
                ((featureBuf[(y - 1) * size + x] & 0x01) !== 0) ||
                ((featureBuf[(y + 1) * size + x] & 0x01) !== 0) ||
                ((featureBuf[y * size + (x - 1)] & 0x01) !== 0) ||
                ((featureBuf[y * size + (x + 1)] & 0x01) !== 0);

            // 강 바로 안쪽 경계와 강 바로 바깥쪽 육지 경계를 모두 부드럽게 만들기 위해 
            // 두 조건 중 하나에 걸리는 '진짜 경계선 부근'만 블러링합니다.
            const isBorderZone = (isRiver && hasLandNeighbor) || (!isRiver && hasRiverNeighbor);
            if (!isBorderZone) continue;

            let weightedSum = 0;
            let validWeight = 0;

            // 주변 커널 샘플링
            for (let ky = -radius; ky <= radius; ky++) {
                for (let kx = -radius; kx <= radius; kx++) {
                    const nx = x + kx;
                    const ny = y + ky;

                    if (nx < 0 || nx >= size || ny < 0 || ny >= size) continue;

                    const ni = ny * size + nx;
                    const w = kernel[(ky + radius) * kSize + (kx + radius)];

                    weightedSum += tempBuf[ni] * w;
                    validWeight += w;
                }
            }

            if (validWeight > 1e-6) {
                buf2[idx] = Math.max(0, Math.round(weightedSum / validWeight));
            }
        }
    }
}

/**
 * projCache 데이터를 PNG 파일로 시각화하여 다운로드합니다.
 * @param {Array} projCache - { qx, qy, d } 또는 null이 담긴 1차원 배열
 * @param {number} buf2Size - 이미지의 가로/세로 크기
 * @param {Object} options - 시각화 옵션
 * @param {string} options.type - 'distance' (거리 맵) 또는 'coordinate' (좌표 맵)
 * @param {number} options.maxDistance - 'distance' 모드일 때 최대 거리 기준값 (기본값: 100)
 * @param {string} options.filename - 다운로드될 파일명
 */
function downloadProjCacheAsPNG(projCache, buf2Size, options = {}) {
    const type = options.type || 'distance';
    const maxDistance = options.maxDistance || 100;
    const filename = options.filename || `river_map_${type}.png`;

    // 1. 오프스크린 캔버스 생성
    const canvas = document.createElement('canvas');
    canvas.width = buf2Size;
    canvas.height = buf2Size;
    const ctx = canvas.getContext('2d');
    
    // 2. 픽셀 데이터를 담을 ImageData 객체 생성
    const imgData = ctx.createImageData(buf2Size, buf2Size);
    const data = imgData.data;

    for (let i = 0; i < projCache.length; i++) {
        const cacheItem = projCache[i];
        const idx = i * 4;

        // 데이터가 없는 곳 (강 영역이 아니거나 매핑 실패) -> 투명하게 처리
        if (!cacheItem) {
            data[idx]     = 255; // R
            data[idx + 1] = 255; // G
            data[idx + 2] = 255; // B
            data[idx + 3] = 0;   // A (투명)
            continue;
        }

        if (type === 'distance') {
            // [방식 A] 거리 맵: 가까우면 검은색, 멀면 흰색
            const colorValue = Math.floor((Math.min(cacheItem.d, maxDistance) / maxDistance) * 255);
            data[idx]     = colorValue; // R
            data[idx + 1] = colorValue; // G
            data[idx + 2] = colorValue; // B
            data[idx + 3] = 255;        // A (불투명)
        } 
        else if (type === 'coordinate') {
            // [방식 B] 좌표 맵: 투영된 qx, qy 좌표를 RGB 색상으로 매핑 (보로노이 다이어그램 형태)
            // 좌표 값을 0 ~ 255 범위로 정규화
            const r = Math.floor((cacheItem.qx / buf2Size) * 255);
            const g = Math.floor((cacheItem.qy / buf2Size) * 255);
            
            data[idx]     = r;   // R (X 좌표 신호)
            data[idx + 1] = g;   // G (Y 좌표 신호)
            data[idx + 2] = 128; // B (중간값 고정 또는 다른 데이터 활용 가능)
            data[idx + 3] = 255; // A (불투명)
        }
    }

    // 3. 캔버스에 픽셀 데이터 주입
    ctx.putImageData(imgData, 0, 0);

    // 4. 가상 링크 생성을 통한 다운로드 트리거
    const link = document.createElement('a');
    link.download = filename;
    link.href = canvas.toDataURL('image/png');
    
    // DOM에 첨부하지 않고도 click() 호출로 다운로드 가능합니다.
    link.click();
}

/**
 * 강 유역별로 투영점들의 최저 고도를 계산하여 통일된 기준 고도 버퍼를 생성합니다.
 * @param {Int32Array} labels       - 강 영역 레이블 배열 (computeRiverLabels 결과물)
 * @param {number} totalRivers      - 발견된 총 강 유역 개수
 * @param {Uint8Array} featureBuf   - 강 영역 마스크 버퍼
 * @param {Array} projCache         - 미리 계산된 투영점 캐시 배열
 * @param {Int32Array} buf2         - 원본 고도 데이터 버퍼
 * @param {number} buf2Size         - 버퍼의 가로/세로 크기
 * @returns {Float32Array} 유역별 최저 고도로 채워진 기준 고도 버퍼 (baseHeightBuf)
 */
function computeRiverMinHeights(labels, totalRivers, featureBuf, projCache, buf2, buf2Size) {
    const baseHeightBuf = new Float32Array(buf2Size * buf2Size);
    
    // 유역별 최저 고도 및 픽셀 카운트 배열 (0번 index는 육지이므로 비워둠)
    // 최저값을 찾기 위해 초기값을 Infinity로 설정합니다.
    const riverMinHeights = new Float64Array(totalRivers + 1).fill(Infinity);
    const riverPixelCounts = new Int32Array(totalRivers + 1);

    let minValue = Infinity;  
    let maxValue = -Infinity; 
    let validPixelCount = 0;  

    // 1. 투영점 고도를 미터 단위 실수로 변환하여 유역별로 최저 고도 갱신
    for (let i = 0; i < buf2Size * buf2Size; i++) {
        if ((featureBuf[i] & 0x01) === 0) continue;

        const result = projCache[i];
        if (!result) continue;

        // 투영점의 원본 고도 추출 및 실수 변환
        const rawValue = buf2[result.qy * buf2Size + result.qx];
        const actualHeightInMeters = (rawValue - 100000) / 10.0;

        // 전체 통계용 계산
        if (actualHeightInMeters < minValue) minValue = actualHeightInMeters;
        if (actualHeightInMeters > maxValue) maxValue = actualHeightInMeters;
        validPixelCount++;

        // 해당 유역 그룹의 최저 고도 갱신
        const rId = labels[i];
        if (rId > 0) {
            if (actualHeightInMeters < riverMinHeights[rId]) {
                riverMinHeights[rId] = actualHeightInMeters;
            }
            riverPixelCounts[rId]++;
        }
    }

    if (validPixelCount > 0) {
        console.log(`=== 강 투영점 고도 분석 완료 (총 ${validPixelCount} 픽셀) ===`);
        console.log(`전체 최소 고도: ${minValue.toFixed(4)}m / 최대 고도: ${maxValue.toFixed(4)}m`);
    } else {
        console.log('강 내부 영역에서 유효한 투영점 고도 데이터를 찾지 못했습니다.');
        return baseHeightBuf;
    }

    // 2. 유역별 최저 고도 기반 Mapbox 정수 포맷 역변환 매핑 테이블 생성
    const riverMinRawValues = new Int32Array(totalRivers + 1);
    for (let i = 1; i <= totalRivers; i++) {
        if (riverPixelCounts[i] > 0 && riverMinHeights[i] !== Infinity) {
            const minHeight = riverMinHeights[i];
            // 실수를 다시 Mapbox 인코딩 정수로 역변환
            riverMinRawValues[i] = Math.round(minHeight * 10.0 + 100000);
            console.log(`[유역 분석] 강 ID ${i} -> 픽셀 수: ${riverPixelCounts[i]}, 최저 고도: ${minHeight.toFixed(2)}m`);
        }
    }

    // 3. 최종 baseHeightBuf를 유역 최저 정수값으로 채우기
    for (let i = 0; i < buf2Size * buf2Size; i++) {
        if ((featureBuf[i] & 0x01) !== 0) {
            const rId = labels[i];
            baseHeightBuf[i] = riverMinRawValues[rId]; 
        }
    }

    return baseHeightBuf;
}

/**
 * 강 영역 연결 성분 분석 (Connected Component Labeling via BFS)
 * @param {Uint8Array} featureBuf - 강 영역 마스크 버퍼 (bit0 = 강 내부 여부)
 * @param {number} buf2Size       - 버퍼의 가로/세로 크기
 * @returns {{labels: Int32Array, totalRivers: number}} 레이블 배열 및 발견된 총 강 개수
 */
function computeRiverLabels(featureBuf, buf2Size) {
    const labels = new Int32Array(buf2Size * buf2Size);
    let currentLabel = 0;

    // 상하좌우 4방향 탐색용 오프셋
    const dx = [1, -1, 0, 0];
    const dy = [0, 0, 1, -1];

    for (let y = 0; y < buf2Size; y++) {
        for (let x = 0; x < buf2Size; x++) {
            const idx = y * buf2Size + x;

            // 이미 레이블이 지정되었거나, 강 영역이 아니라면 건너뜀
            if (labels[idx] !== 0 || (featureBuf[idx] & 0x01) === 0) continue;

            // 새로운 독립 수계 발견
            currentLabel++;
            
            // BFS 처리를 위한 큐 가상화 (배열을 할당하여 포인터로 접근하는 것이 push/shift보다 빠름)
            const queue = [idx];
            labels[idx] = currentLabel;
            let qHead = 0;

            while (qHead < queue.length) {
                const curIdx = queue[qHead++];
                const cx = curIdx % buf2Size;
                const cy = Math.floor(curIdx / buf2Size);

                for (let i = 0; i < 4; i++) {
                    const nx = cx + dx[i];
                    const ny = cy + dy[i];

                    if (nx >= 0 && nx < buf2Size && ny >= 0 && ny < buf2Size) {
                        const nIdx = ny * buf2Size + nx;

                        // 강 영역이면서 아직 방문하지 않은 인접 픽셀 그룹화
                        if ((featureBuf[nIdx] & 0x01) !== 0 && labels[nIdx] === 0) {
                            labels[nIdx] = currentLabel;
                            queue.push(nIdx);
                        }
                    }
                }
            }
        }
    }

    return { labels, totalRivers: currentLabel };
}

/**
 * distBuckets 데이터를 PNG 이미지로 시각화하여 다운로드합니다.
 *
 * 각 픽셀의 색상은 강 경계로부터의 거리(버킷 인덱스)를 나타냅니다.
 * - 경계(거리 0) → 빨간색
 * - 중간 거리     → 녹색 계열 그라디언트
 * - 최대 거리     → 파란색
 * - 강 외부(비강 영역) → 흰색
 * - Infinity 픽셀  → 검정색 (BFS 미도달, 있을 경우)
 *
 * @param {Array<Array<number>>} distBuckets - computeBorderDist가 반환한 버킷 배열
 *        distBuckets[d] 에는 경계로부터 거리가 d인 픽셀 인덱스들이 들어 있습니다.
 * @param {Float32Array}         dist        - computeBorderDist가 반환한 원본 거리 배열 (선택적 검증용)
 * @param {number}               size        - 이미지의 가로/세로 크기 (buf2Size)
 * @param {string}               [filename='debug_dist_buckets.png'] - 다운로드 파일명
 */
function downloadDistBucketsAsPNG(distBuckets, dist, size, filename = 'debug_dist_buckets.png') {
    // ── 1. 오프스크린 캔버스 생성 ────────────────────────────────
    const canvas  = document.createElement('canvas');
    canvas.width  = size;
    canvas.height = size;
    const ctx     = canvas.getContext('2d');
    const imgData = ctx.createImageData(size, size);
    const data    = imgData.data;

    // ── 2. 전체 픽셀을 "흰색(강 외부)"으로 초기화 ───────────────
    for (let i = 0; i < size * size; i++) {
        const base = i * 4;
        data[base]     = 255; // R
        data[base + 1] = 255; // G
        data[base + 2] = 255; // B
        data[base + 3] = 255; // A
    }

    // ── 3. 버킷의 최대 거리 계산 (정규화 기준) ──────────────────
    const maxDist = distBuckets.length - 1; // 인덱스 0 ~ maxDist

    if (maxDist <= 0) {
        console.warn('[downloadDistBucketsAsPNG] distBuckets가 비어 있습니다.');
        return;
    }

   // ── 4. 버킷 순회 → 거리에 따른 랜덤 색상 매핑 ───────────────────
    for (let d = 0; d < distBuckets.length; d++) {
        // 거리(d) 하나당 하나의 랜덤 RGB 색상을 생성합니다.
        const r = Math.floor(Math.random() * 256);
        const g = Math.floor(Math.random() * 256);
        const b = Math.floor(Math.random() * 256);

        for (const pixelIdx of distBuckets[d]) {
            const base     = pixelIdx * 4;
            data[base]     = r;
            data[base + 1] = g;
            data[base + 2] = b;
            data[base + 3] = 255;
        }
    }

    // ── 5. 범례(Legend) 오버레이 렌더링 ────────────────────────
    ctx.putImageData(imgData, 0, 0);

    // ── 6. 파일 다운로드 트리거 ─────────────────────────────────
    const link    = document.createElement('a');
    link.download = filename;
    link.href     = canvas.toDataURL('image/png');
    link.click();

    console.log(`[downloadDistBucketsAsPNG] 완료: maxDist=${maxDist}, 버킷 수=${distBuckets.length}`);
}

// -----------------------------------------------------------------------------
// 강 내부 픽셀 중 4방향 이웃에 강이 픽셀이 있으면 경계 픽셀
// @returns {{dist: Float32Array, distBuckets: Array<Int32Array>}}
// -----------------------------------------------------------------------------
function computeBorderDist(featureBuf, size) {
    const dist = new Float32Array(size * size).fill(Infinity);
    const queue = [];
    const maxDist = size; // 최대 거리 제한 (메모리 최적화)
    
    // 1. 경계 픽셀 찾기
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const idx = y * size + x;
            if ((featureBuf[idx] & 0x01) === 0) continue;

            const isBorder = x === 0 || x === size - 1 || y === 0 || y === size - 1 ||
                ((featureBuf[(y - 1) * size + x] & 0x01) === 0) ||
                ((featureBuf[(y + 1) * size + x] & 0x01) === 0) ||
                ((featureBuf[y * size + (x - 1)] & 0x01) === 0) ||
                ((featureBuf[y * size + (x + 1)] & 0x01) === 0);

            if (isBorder) {
                dist[idx] = 0;
                queue.push(idx);
            }
        }
    }

    // 2. BFS 거리 전파
    const DX = [1, -1, 0, 0];
    const DY = [0, 0, 1, -1];
    let head = 0;
    while (head < queue.length) {
        const idx = queue[head++];
        const x = idx % size;
        const y = (idx - x) / size;
        const dCur = dist[idx];

        for (let k = 0; k < 4; k++) {
            const nx = x + DX[k], ny = y + DY[k];
            if (nx < 0 || nx >= size || ny < 0 || ny >= size) continue;
            const ni = ny * size + nx;
            if ((featureBuf[ni] & 0x01) !== 0 && dist[ni] === Infinity) {
                dist[ni] = dCur + 1;
                queue.push(ni);
            }
        }
    }

    // 3. 거리별 픽셀 분류 (버킷 생성)
    // 최대 거리를 찾아서 버킷 크기 결정
    let maxD = 0;
    for (let i = 0; i < dist.length; i++) {
        if (dist[i] !== Infinity && dist[i] > maxD) maxD = dist[i];
    }

    const distBuckets = Array.from({ length: maxD + 1 }, () => []);
    for (let i = 0; i < dist.length; i++) {
        const d = dist[i];
        if (d !== Infinity) {
            distBuckets[d].push(i);
        }
    }

    return { dist, distBuckets };
}

// ── CSV 파싱 → 선분 배열 ─────────────────────────────────────
// 반환: [{ax, ay, bx, by}, ...]
function parseSegments(waterwayText, scale) {
    const segMap = new Map();
    const lines  = waterwayText.split('\n');

    for (let i = 1; i < lines.length; i++) {
        const parts = lines[i].trim().split(',');
        if (parts.length < 4) continue;
        const id = parseInt(parts[0]);
        if (isNaN(id)) continue;
        if (!segMap.has(id)) segMap.set(id, []);
        segMap.get(id).push({
            x: parseFloat(parts[2]) * scale,
            y: parseFloat(parts[3]) * scale,
        });
    }

    const segments = [];
    for (const pts of segMap.values()) {
        for (let i = 0; i < pts.length - 1; i++) {
            segments.push({ ax: pts[i].x, ay: pts[i].y, bx: pts[i+1].x, by: pts[i+1].y });
        }
    }
    return segments;
}

// ── 그리드 셀 인덱싱 ─────────────────────────────────────────
// 각 선분 AABB가 걸치는 셀에 선분 인덱스 등록
function buildGrid(segments, cols, rows, cellSize) {
    const grid    = new Map();
    const gridKey = (cx, cy) => `${cx},${cy}`;

    segments.forEach((seg, si) => {
        const cx0 = Math.max(0,        Math.floor(Math.min(seg.ax, seg.bx) / cellSize));
        const cy0 = Math.max(0,        Math.floor(Math.min(seg.ay, seg.by) / cellSize));
        const cx1 = Math.min(cols - 1, Math.floor(Math.max(seg.ax, seg.bx) / cellSize));
        const cy1 = Math.min(rows - 1, Math.floor(Math.max(seg.ay, seg.by) / cellSize));

        for (let cy = cy0; cy <= cy1; cy++) {
            for (let cx = cx0; cx <= cx1; cx++) {
                const key = gridKey(cx, cy);
                if (!grid.has(key)) grid.set(key, []);
                grid.get(key).push(si);
            }
        }
    });
    return grid;
}

function nearestProjection(px, py, grid, segments, buf2Size, cellSize) {
    // 1. 입력받은 좌표(px, py)가 격자(Grid) 상에서 어떤 칸(cx, cy)에 위치하는지 계산합니다.
    const cx = Math.floor(px / cellSize);
    const cy = Math.floor(py / cellSize);

    const seen = new Set();       // 중복된 선분 탐색을 방지하기 위한 Set
    const candidates = [];        // 거리를 비교할 후보 선분들의 인덱스를 담는 배열

    // 2. 중심 격자로부터 반경(radius)을 1부터 최대 8까지 넓혀가며 선분을 찾습니다.
    // 후보군(candidates)이 하나라도 발견되면 루프를 종료합니다.
    for (let radius = 1; candidates.length === 0 && radius <= 8; radius++) {
        // 현재 반경 범위 내의 격자들을 순회합니다 (-radius ~ +radius)
        for (let dy = -radius; dy <= radius; dy++) {
            for (let dx = -radius; dx <= radius; dx++) {
                // [최적화] 반경이 2 이상일 때, 이전 반경(radius-1)에서 이미 탐색한 내부 격자는 건너넙니다 (껍질만 탐색).
                if (radius > 1 && Math.abs(dx) < radius && Math.abs(dy) < radius) continue;

                // 탐색할 격자의 고유 키 생성 (예: "5,3")
                const key = `${cx + dx},${cy + dy}`;
                const list = grid.get(key); // 해당 격자에 할당된 선분 인덱스 리스트를 가져옴
                
                if (!list) continue; // 격자가 비어있다면 패스
                
                // 격자 내에 존재하는 선분들을 후보군에 추가 (중복 제거)
                for (const si of list) {
                    if (!seen.has(si)) { 
                        seen.add(si); 
                        candidates.push(si); 
                    }
                }
            }
        }
    }

    // 반경 8까지 탐색했음에도 주변에 선분이 하나도 없다면 null 반환
    if (candidates.length === 0) return null;

    let minDist = Infinity; // 가장 가까운 거리를 저장할 변수 (초기값은 무한대)
    let bestQx = -1, bestQy = -1; // 가장 가까운 투영 점의 좌표를 저장할 변수

    // 3. 수집된 후보 선분들을 대상으로 실제 최단 거리를 계산합니다.
    for (const si of candidates) {
        const { ax, ay, bx, by } = segments[si]; // 선분의 시작점(A)과 끝점(B) 좌표
        const dx = bx - ax, dy = by - ay;       // 선분의 벡터 (A -> B)
        const lenSq = dx * dx + dy * dy;        // 선분 길이의 제곱
        
        // 투영 비율 t 계산 (0 <= t <= 1)
        // 선분의 길이가 거의 0이라면 시작점(0)을 사용하고, 그렇지 않다면 내적을 통해 최단 투영 점의 위치 비율을 구함
        const t = lenSq < 1e-9 ? 0 :
            Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / lenSq));

        // 선분 위에서 입력 점(px, py)과 가장 가까운 투영 점 Q(qx, qy)의 좌표 계산
        const qx = ax + t * dx;
        const qy = ay + t * dy;
        
        // 입력 점과 투영 점 사이의 실제 유클리드 거리 계산
        const d = Math.hypot(px - qx, py - qy);

        // 현재 계산한 거리가 기존 최단 거리보다 짧다면 정보를 갱신
        if (d < minDist) {
            minDist = d;
            bestQx = Math.round(qx); // 격자나 버퍼 크기에 맞추기 위해 반올림 처리
            bestQy = Math.round(qy);
        }
    }

    // 유효한 투영 점을 찾지 못했다면 null 반환
    if (bestQx < 0) return null;

    // 4. 최종 계산된 좌표가 이미지/맵 버퍼 범위(0 ~ buf2Size - 1)를 벗어나지 않도록 클램핑(Clamping) 처리
    bestQx = Math.max(0, Math.min(buf2Size - 1, bestQx));
    bestQy = Math.max(0, Math.min(buf2Size - 1, bestQy));

    // 최단 투영 점의 좌표와 그 때의 거리를 반환
    return { qx: bestQx, qy: bestQy, d: minDist };
}

// ── projBuf splat ─────────────────────────────────────────────
// q 주변 radius 픽셀에 d 값을 최댓값으로 기록
function splatProjBuf(projBuf, buf2Size, qx, qy, d, radius = 1) {
    for (let sy = -radius; sy <= radius; sy++) {
        for (let sx = -radius; sx <= radius; sx++) {
            const nx = qx + sx, ny = qy + sy;
            if (nx < 0 || nx >= buf2Size || ny < 0 || ny >= buf2Size) continue;
            const ni = ny * buf2Size + nx;
            if (d > projBuf[ni]) projBuf[ni] = d;
        }
    }
}
