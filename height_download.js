// ── 공통 getVal16 (tileImageDatas 직접 참조) ────────────────────
// allPixels 전역 캐시 제거 → tileImageDatas를 직접 읽음
function getVal16(tileRow, tileCol, px, py) {
	const tr  = Math.min(Math.max(tileRow, 0), GRID_COUNT - 1);
	const tc  = Math.min(Math.max(tileCol, 0), GRID_COUNT - 1);
	const ppx = Math.min(Math.max(Math.floor(px), 0), OUTPUT_TILE_SIZE - 1);
	const ppy = Math.min(Math.max(Math.floor(py), 0), OUTPUT_TILE_SIZE - 1);
	const td  = tileImageDatas[tr * GRID_COUNT + tc];
	if (!td) return 0;
	const idx = (ppy * OUTPUT_TILE_SIZE + ppx) * 4;
	return (td.data[idx] << 8) | td.data[idx + 1];
}


// ── Far Terrain 다운로드 ─────────────────────────────────────────
downloadFarBtn.onclick = async function() {
	this.innerText = 'Processing...';
	const zip = new JSZip();

	// 1. 해상도를 512로 변경
    const FAR_SIZE = 512;
	
	function sampleFarHeight(lx, ly) {
		const clampedLx = Math.min(Math.max(lx, 0), FAR_SIZE - 1);
		const clampedLy = Math.min(Math.max(ly, 0), FAR_SIZE - 1);

		const gx = clampedLx * (FULL_OUTPUT_SIZE - 1) / (FAR_SIZE - 1);
		const gy = clampedLy * (FULL_OUTPUT_SIZE - 1) / (FAR_SIZE - 1);

		const tileCol = Math.min(Math.floor(gx / OUTPUT_TILE_SIZE), GRID_COUNT - 1);
		const tileRow = Math.min(Math.floor(gy / OUTPUT_TILE_SIZE), GRID_COUNT - 1);
		const localX  = Math.floor(gx % OUTPUT_TILE_SIZE);
		const localY  = Math.floor(gy % OUTPUT_TILE_SIZE);

		const v00 = getVal16(tileRow, tileCol, localX,     localY);
		const v10 = getVal16(tileRow, tileCol, localX + 1, localY);
		const v01 = getVal16(tileRow, tileCol, localX,     localY + 1);
		const v11 = getVal16(tileRow, tileCol, localX + 1, localY + 1);
		return Math.max(v00, v10, v01, v11) / 65535.0;
	}
	
	function sampleFarHeight16(lx, ly) {
		const clampedLx = Math.min(Math.max(lx, 0), FAR_SIZE - 1);
		const clampedLy = Math.min(Math.max(ly, 0), FAR_SIZE - 1);

		const gx = clampedLx * (FULL_OUTPUT_SIZE - 1) / (FAR_SIZE - 1);
		const gy = clampedLy * (FULL_OUTPUT_SIZE - 1) / (FAR_SIZE - 1);

		const tileCol = Math.min(Math.floor(gx / OUTPUT_TILE_SIZE), GRID_COUNT - 1);
		const tileRow = Math.min(Math.floor(gy / OUTPUT_TILE_SIZE), GRID_COUNT - 1);
		const localX  = Math.floor(gx % OUTPUT_TILE_SIZE);
		const localY  = Math.floor(gy % OUTPUT_TILE_SIZE);

		const v00 = getVal16(tileRow, tileCol, localX,     localY);
		const v10 = getVal16(tileRow, tileCol, localX + 1, localY);
		const v01 = getVal16(tileRow, tileCol, localX,     localY + 1);
		const v11 = getVal16(tileRow, tileCol, localX + 1, localY + 1);
		return Math.max(v00, v10, v01, v11);
	}

	// ── 1. far_terrain.png (높이맵) ──────────────────────────────
	const farHeightCanvas = document.createElement('canvas');
	farHeightCanvas.width  = FAR_SIZE;
	farHeightCanvas.height = FAR_SIZE;
	const farHeightCtx = farHeightCanvas.getContext('2d');
	const farHeightImg  = farHeightCtx.createImageData(FAR_SIZE, FAR_SIZE);

	for (let ly = 0; ly < FAR_SIZE; ly++) {
		for (let lx = 0; lx < FAR_SIZE; lx++) {
			const val   = sampleFarHeight(lx, ly);
			const val16 = Math.round(val * 65535);
			const flippedLy = (FAR_SIZE - 1) - ly;
			const outIdx    = (flippedLy * FAR_SIZE + lx) * 4;
			farHeightImg.data[outIdx]     = (val16 >> 8) & 0xFF;
			farHeightImg.data[outIdx + 1] =  val16       & 0xFF;
			farHeightImg.data[outIdx + 2] = 0;
			farHeightImg.data[outIdx + 3] = 255;
		}
	}

	farHeightCtx.putImageData(farHeightImg, 0, 0);
	const heightBlob = await new Promise(resolve =>
		farHeightCanvas.toBlob(resolve, 'image/png'));
	zip.file('far_terrain.png', heightBlob);

	// ── 2. far_terrain_normal.png (노말맵) ───────────────────────

	const farNormalCanvas = document.createElement('canvas');
	farNormalCanvas.width  = FAR_SIZE;
	farNormalCanvas.height = FAR_SIZE;
	const farNormalCtx = farNormalCanvas.getContext('2d');
	const farNormalImg  = farNormalCtx.createImageData(FAR_SIZE, FAR_SIZE);

	for (let ly = 0; ly < FAR_SIZE; ly++) {
		for (let lx = 0; lx < FAR_SIZE; lx++) {
			const hL = sampleFarHeight(lx - 1, ly);
			const hR = sampleFarHeight(lx + 1, ly);
			const hD = sampleFarHeight(lx,     ly - 1);
			const hU = sampleFarHeight(lx,     ly + 1);

			// 물리적 거리(dist)와 최대 높이(maxHeight)를 1.0으로 가정
			// 나중에 셰이더에서 실제 수치를 곱해줄 것임
			const nx = (hL - hR); 
			const ny = (hD - hU);
			const nz = 1.0; // 기본 평면 벡터

			const len  = Math.sqrt(nx*nx + ny*ny + nz*nz);
			const nnx  =  nx / len;
			const nny  = -ny / len;
			const nnz  =  nz / len;

			const rr = Math.round((nnx * 0.5 + 0.5) * 255);
			const gg = Math.round((nny * 0.5 + 0.5) * 255);
			const bb = Math.round((nnz * 0.5 + 0.5) * 255);

			const flippedLy = (FAR_SIZE - 1) - ly;
			const outIdx    = (flippedLy * FAR_SIZE + lx) * 4;
			farNormalImg.data[outIdx]     = rr;
			farNormalImg.data[outIdx + 1] = gg;
			farNormalImg.data[outIdx + 2] = bb;
			farNormalImg.data[outIdx + 3] = 255;
		}
	}

	farNormalCtx.putImageData(farNormalImg, 0, 0);
	const normalBlobFar = await new Promise(resolve =>
		farNormalCanvas.toBlob(resolve, 'image/png'));
	zip.file('far_terrain_normal.png', normalBlobFar);

	const contentFar = await zip.generateAsync({ type: 'blob' });
	const linkFar = document.createElement('a');
	linkFar.href = URL.createObjectURL(contentFar);
	linkFar.download = `far_terrain_${globalOffsetX}x${globalOffsetY}.zip`;
	linkFar.click();

	this.innerText = 'DOWNLOAD FAR TERRAIN';
};


// ── 16-BIT ZIP 다운로드 ──────────────────────────────────────────
downloadBtn.onclick = async function()
{
	this.innerText = "Zipping...";
	const zip = new JSZip();

	const RAW_SIZE = OUTPUT_TILE_SIZE + 1; // 1025
	const LOW_SIZE = 129;

	// 부드러운 샘플링을 위한 이선형 보간 함수
	function getVal16Smooth(tileRow, tileCol, px, py) {
		const x1 = Math.floor(px);
		const y1 = Math.floor(py);
		const x2 = x1 + 1;
		const y2 = y1 + 1;

		const fx = px - x1;
		const fy = py - y1;

		const v11 = getVal16(tileRow, tileCol, x1, y1);
		const v21 = getVal16(tileRow, tileCol, x2, y1);
		const v12 = getVal16(tileRow, tileCol, x1, y2);
		const v22 = getVal16(tileRow, tileCol, x2, y2);

		const vx1 = v11 * (1 - fx) + v21 * fx;
		const vx2 = v12 * (1 - fx) + v22 * fx;

		return vx1 * (1 - fy) + vx2 * fy;
	}

	for (let r = 0; r < GRID_COUNT; r++) {
		for (let c = 0; c < GRID_COUNT; c++) {

			const x = c + globalOffsetX;
			const y = ((GRID_COUNT - 1) - r) + globalOffsetY;

			// ── 1025×1025 RAW ─────────────────────────────────
			const rawBuffer = new Uint8Array(RAW_SIZE * RAW_SIZE * 2);

			for (let ly = 0; ly < RAW_SIZE; ly++) {
				for (let lx = 0; lx < RAW_SIZE; lx++) {
					const srcTileCol = (lx < OUTPUT_TILE_SIZE) ? c : Math.min(c + 1, GRID_COUNT - 1);
					const srcTileRow = (ly < OUTPUT_TILE_SIZE) ? r : Math.min(r + 1, GRID_COUNT - 1);
					const srcPx = (lx < OUTPUT_TILE_SIZE) ? lx : 0;
					const srcPy = (ly < OUTPUT_TILE_SIZE) ? ly : 0;

					const val16 = getVal16(srcTileRow, srcTileCol, srcPx, srcPy);

					const flippedLy = (RAW_SIZE - 1) - ly;
					const outIdx = (flippedLy * RAW_SIZE + lx) * 2;
					rawBuffer[outIdx + 0] = val16 & 0xFF;
					rawBuffer[outIdx + 1] = (val16 >> 8) & 0xFF;
				}
			}
			zip.file(`tile_${x}_${y}.raw`, rawBuffer);

			// ── 129×129 RAW (다운샘플) ─────────────────────────
			const lowBuffer = new Uint8Array(LOW_SIZE * LOW_SIZE * 2);
			for (let ly = 0; ly < LOW_SIZE; ly++) {
				for (let lx = 0; lx < LOW_SIZE; lx++) {
					const srcLx = Math.round(lx * (RAW_SIZE - 1) / (LOW_SIZE - 1));
					const srcLy = Math.round(ly * (RAW_SIZE - 1) / (LOW_SIZE - 1));

					const srcTileCol = (srcLx < OUTPUT_TILE_SIZE) ? c : Math.min(c + 1, GRID_COUNT - 1);
					const srcTileRow = (srcLy < OUTPUT_TILE_SIZE) ? r : Math.min(r + 1, GRID_COUNT - 1);
					const srcPx = (srcLx < OUTPUT_TILE_SIZE) ? srcLx : 0;
					const srcPy = (srcLy < OUTPUT_TILE_SIZE) ? srcLy : 0;

					const val16 = getVal16(srcTileRow, srcTileCol, srcPx, srcPy);

					const flippedLy = (LOW_SIZE - 1) - ly;
					const outIdx = (flippedLy * LOW_SIZE + lx) * 2;
					lowBuffer[outIdx + 0] = val16 & 0xFF;
					lowBuffer[outIdx + 1] = (val16 >> 8) & 0xFF;
				}
			}
			zip.file(`tile_${x}_${y}_low.raw`, lowBuffer);

			// ── 메타데이터 (low 파일 기준 min/max height) ─────
			let minVal = 65535, maxVal = 0;
			for (let i = 0; i < LOW_SIZE * LOW_SIZE; i++) {
				const val = lowBuffer[i * 2] | (lowBuffer[i * 2 + 1] << 8);
				if (val < minVal) minVal = val;
				if (val > maxVal) maxVal = val;
			}

			const minH = minVal / 65535.0;
			const maxH = Math.min(maxVal / 65535.0 * 1.05, 1.0);

			zip.file(`tile_${x}_${y}_meta.txt`,
				`version=1\nminHeight=${minH.toFixed(6)}\nmaxHeight=${maxH.toFixed(6)}\n`);

			// ── 1025×1025 노말맵 ──────────────────────────────
			const normalBuffer = new Uint8Array(RAW_SIZE * RAW_SIZE * 3);

			const terrainMaxHeight = 600.00;
			const pixelUnitMeters  = 9216.0 / 1080.0; // 약 8.533m/px
			const sampleOffset     = 1.5;
			const dist             = (2.0 * sampleOffset) * pixelUnitMeters / 9.0;

			for (let ly = 0; ly < RAW_SIZE; ly++) {
				for (let lx = 0; lx < RAW_SIZE; lx++) {
					const srcTileCol = (lx < OUTPUT_TILE_SIZE) ? c : Math.min(c + 1, GRID_COUNT - 1);
					const srcTileRow = (ly < OUTPUT_TILE_SIZE) ? r : Math.min(r + 1, GRID_COUNT - 1);
					const srcPx = (lx < OUTPUT_TILE_SIZE) ? lx : 0;
					const srcPy = (ly < OUTPUT_TILE_SIZE) ? ly : 0;

					const hL = getVal16Smooth(srcTileRow, srcTileCol, srcPx - sampleOffset, srcPy) / 65535.0;
					const hR = getVal16Smooth(srcTileRow, srcTileCol, srcPx + sampleOffset, srcPy) / 65535.0;
					const hD = getVal16Smooth(srcTileRow, srcTileCol, srcPx, srcPy - sampleOffset) / 65535.0;
					const hU = getVal16Smooth(srcTileRow, srcTileCol, srcPx, srcPy + sampleOffset) / 65535.0;

					const nx = ((hL - hR) * terrainMaxHeight) / dist;
					const ny = ((hD - hU) * terrainMaxHeight) / dist;
					const nz = 1.0;

					const len = Math.sqrt(nx * nx + ny * ny + nz * nz);
					const nnx =  nx / len;
					const nny = -ny / len;
					const nnz =  nz / len;

					const rr = Math.round((nnx * 0.5 + 0.5) * 255);
					const gg = Math.round((nny * 0.5 + 0.5) * 255);
					const bb = Math.round((nnz * 0.5 + 0.5) * 255);

					const flippedLy = (RAW_SIZE - 1) - ly;
					const outIdx = (flippedLy * RAW_SIZE + lx) * 3;
					normalBuffer[outIdx]     = rr;
					normalBuffer[outIdx + 1] = gg;
					normalBuffer[outIdx + 2] = bb;
				}
			}
			zip.file(`tile_${x}_${y}_normal.raw`, normalBuffer);
			
			
			// ── 129×129 노말맵 (저해상도) ─────────────────────────
			const lowNormalBuffer = new Uint8Array(LOW_SIZE * LOW_SIZE * 3);

			// 저해상도에 맞게 샘플링 간격(dist) 조정
			// 해상도가 낮아지므로 샘플링 오프셋을 조절하여 디테일을 챙깁니다.
			const lowSampleOffset = (RAW_SIZE - 1) / (LOW_SIZE - 1); 
			const lowDist = (2.0 * lowSampleOffset) * pixelUnitMeters / 9.0;

			for (let ly = 0; ly < LOW_SIZE; ly++) {
				for (let lx = 0; lx < LOW_SIZE; lx++) {
					// 고해상도 좌표로 환산
					const srcLx = lx * (RAW_SIZE - 1) / (LOW_SIZE - 1);
					const srcLy = ly * (RAW_SIZE - 1) / (LOW_SIZE - 1);

					const srcTileCol = (srcLx < OUTPUT_TILE_SIZE) ? c : Math.min(c + 1, GRID_COUNT - 1);
					const srcTileRow = (srcLy < OUTPUT_TILE_SIZE) ? r : Math.min(r + 1, GRID_COUNT - 1);
					const srcPx = (srcLx < OUTPUT_TILE_SIZE) ? srcLx : 0;
					const srcPy = (srcLy < OUTPUT_TILE_SIZE) ? srcLy : 0;

					// 부드러운 법선 벡터를 위해 getVal16Smooth 사용
					const hL = getVal16Smooth(srcTileRow, srcTileCol, srcPx - lowSampleOffset, srcPy) / 65535.0;
					const hR = getVal16Smooth(srcTileRow, srcTileCol, srcPx + lowSampleOffset, srcPy) / 65535.0;
					const hD = getVal16Smooth(srcTileRow, srcTileCol, srcPx, srcPy - lowSampleOffset) / 65535.0;
					const hU = getVal16Smooth(srcTileRow, srcTileCol, srcPx, srcPy + lowSampleOffset) / 65535.0;

					const nx = ((hL - hR) * terrainMaxHeight) / lowDist;
					const ny = ((hD - hU) * terrainMaxHeight) / lowDist;
					const nz = 1.0;

					const len = Math.sqrt(nx * nx + ny * ny + nz * nz);
					const nnx =  nx / len;
					const nny = -ny / len;
					const nnz =  nz / len;

					const rr = Math.round((nnx * 0.5 + 0.5) * 255);
					const gg = Math.round((nny * 0.5 + 0.5) * 255);
					const bb = Math.round((nnz * 0.5 + 0.5) * 255);

					const flippedLy = (LOW_SIZE - 1) - ly;
					const outIdx = (flippedLy * LOW_SIZE + lx) * 3;
					lowNormalBuffer[outIdx]     = rr;
					lowNormalBuffer[outIdx + 1] = gg;
					lowNormalBuffer[outIdx + 2] = bb;
				}
			}
			zip.file(`tile_${x}_${y}_normal_low.raw`, lowNormalBuffer);
		}
	}

	const content = await zip.generateAsync({ type: "blob" });
	const link = document.createElement('a');
	link.href = URL.createObjectURL(content);
	link.download = `raw_tiles_${globalOffsetX}x${globalOffsetY}.zip`;
	link.click();
	this.innerText = "DOWNLOAD 16-BIT ZIP";
};


// ── Feature RAW 다운로드 ─────────────────────────────────────────
downloadFeature.onclick = async function()
{
	this.innerText = "Processing RAWs...";
	const zip = new JSZip();
	const RAW_SIZE = OUTPUT_TILE_SIZE + 1; // 1025

	for (let r = 0; r < GRID_COUNT; r++) {
		for (let c = 0; c < GRID_COUNT; c++) {
			const tileIdx = r * GRID_COUNT + c;

			// tileImageDatas를 직접 사용 (항상 최신 상태)
			const td = tileImageDatas[tileIdx];
			if (!td) continue;

			const x = c + globalOffsetX;
			const y = ((GRID_COUNT - 1) - r) + globalOffsetY;

			// 픽셀당 1바이트: Blue 채널의 비트마스크만 저장
			const rawBuffer = new Uint8Array(RAW_SIZE * RAW_SIZE);

			for (let ly = 0; ly < RAW_SIZE; ly++) {
				for (let lx = 0; lx < RAW_SIZE; lx++) {
					// 1025번째 픽셀(경계)은 현재 타일의 마지막 픽셀로 클램핑
					const srcLx = Math.min(lx, OUTPUT_TILE_SIZE - 1);
					const srcLy = Math.min(ly, OUTPUT_TILE_SIZE - 1);
					const srcIdx = (srcLy * OUTPUT_TILE_SIZE + srcLx) * 4;

					const flippedLy = (RAW_SIZE - 1) - ly;
					rawBuffer[flippedLy * RAW_SIZE + lx] = td.data[srcIdx + 2]; // Blue 채널
				}
			}

			zip.file(`tile_${x}_${y}_feature.raw`, rawBuffer);
		}
	}

	const content = await zip.generateAsync({ type: "blob" });
	const link = document.createElement('a');
	link.href = URL.createObjectURL(content);
	link.download = `feature_raw_tiles_${globalOffsetX}x${globalOffsetY}.zip`;
	link.click();

	this.innerText = "DOWNLOAD FEATURE RAW";
};