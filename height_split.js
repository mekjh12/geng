

// ── Heightmap 처리 (R+G 인코딩) ──────────────────────────────────
async function processHeightmap(img) 
{
	// 원본 픽셀 데이터를 1081x1081로 읽기
	const srcCanvas = document.createElement('canvas');
	srcCanvas.width  = SOURCE_FULL_SIZE;
	srcCanvas.height = SOURCE_FULL_SIZE;
	const srcCtx = srcCanvas.getContext('2d');
	srcCtx.drawImage(img, 0, 0, SOURCE_FULL_SIZE, SOURCE_FULL_SIZE);
	const srcData = srcCtx.getImageData(0, 0, SOURCE_FULL_SIZE, SOURCE_FULL_SIZE).data;

	// 원본에서 bilinear 샘플링 (0~1 정규화 좌표)
	function sampleBilinear(u, v) {
		// u, v: 원본 픽셀 좌표 (소수점 포함)
		const x0 = Math.floor(u);
		const y0 = Math.floor(v);
		const x1 = Math.min(x0 + 1, SOURCE_FULL_SIZE - 1);
		const y1 = Math.min(y0 + 1, SOURCE_FULL_SIZE - 1);
		const fx = u - x0;
		const fy = v - y0;

		const i00 = (y0 * SOURCE_FULL_SIZE + x0) * 4;
		const i10 = (y0 * SOURCE_FULL_SIZE + x1) * 4;
		const i01 = (y1 * SOURCE_FULL_SIZE + x0) * 4;
		const i11 = (y1 * SOURCE_FULL_SIZE + x1) * 4;

		// R채널(그레이스케일)만 보간
		const h00 = srcData[i00];
		const h10 = srcData[i10];
		const h01 = srcData[i01];
		const h11 = srcData[i11];

		return h00 * (1 - fx) * (1 - fy)
			 + h10 *      fx  * (1 - fy)
			 + h01 * (1 - fx) *      fy
			 + h11 *      fx  *      fy;
	}

	function sampleBicubic(u, v) {
		// Catmull-Rom cubic 커널
		function cubic(t) {
			const t2 = t * t;
			const t3 = t2 * t;
			// Catmull-Rom: a = -0.5
			return {
				w0: -0.5*t3 + 1.0*t2 - 0.5*t,
				w1:  1.5*t3 - 2.5*t2 + 1.0,
				w2: -1.5*t3 + 2.0*t2 + 0.5*t,
				w3:  0.5*t3 - 0.5*t2
			};
		}

		function getSrc(x, y) {
			const cx = Math.min(Math.max(x, 0), SOURCE_FULL_SIZE - 1);
			const cy = Math.min(Math.max(y, 0), SOURCE_FULL_SIZE - 1);
			return srcData[(cy * SOURCE_FULL_SIZE + cx) * 4]; // R채널
		}

		const x0 = Math.floor(u);
		const y0 = Math.floor(v);
		const fx = u - x0;
		const fy = v - y0;

		const wx = cubic(fx);
		const wy = cubic(fy);

		// 4x4 이웃 픽셀 가중합
		let result = 0;
		for (let dy = -1; dy <= 2; dy++) {
			const wy_val = [wy.w0, wy.w1, wy.w2, wy.w3][dy + 1];
			for (let dx = -1; dx <= 2; dx++) {
				const wx_val = [wx.w0, wx.w1, wx.w2, wx.w3][dx + 1];
				result += getSrc(x0 + dx, y0 + dy) * wx_val * wy_val;
			}
		}

		return Math.min(Math.max(result, 0), 255);
	}

	for (let r = 0; r < GRID_COUNT; r++) {
		for (let c = 0; c < GRID_COUNT; c++) {
			
			const idx = r * GRID_COUNT + c;
			const imageData = canvases[idx].getContext('2d')
				.createImageData(OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE);
			const data = imageData.data;

			// 이 타일의 원본 시작 픽셀 (SOURCE_TILE_SIZE = 1081/9 = 120.11...)
			const tileOriginX = c * SOURCE_TILE_SIZE;
			const tileOriginY = r * SOURCE_TILE_SIZE;

			// OUTPUT_TILE_SIZE = 1024;
			for (let py = 0; py < OUTPUT_TILE_SIZE; py++) {
				for (let px = 0; px < OUTPUT_TILE_SIZE; px++) {
					// 출력 픽셀 → 원본 좌표 매핑
					// OUTPUT_TILE_SIZE(1024) 픽셀이 SOURCE_TILE_SIZE(120.11) 구간을 커버
					const u = tileOriginX + (px / (OUTPUT_TILE_SIZE - 1)) * SOURCE_TILE_SIZE;
					const v = tileOriginY + (py / (OUTPUT_TILE_SIZE - 1)) * SOURCE_TILE_SIZE;
					
					/*
					const height8 = sampleBilinear(
						Math.min(u, SOURCE_FULL_SIZE - 1),
						Math.min(v, SOURCE_FULL_SIZE - 1)
					);
					*/
					
					const height8 = sampleBicubic(
						Math.min(u, SOURCE_FULL_SIZE - 1),
						Math.min(v, SOURCE_FULL_SIZE - 1)
					);						
					
					const total16Bit = Math.max(0, Math.min(65535, height8 * 256));

					const i = (py * OUTPUT_TILE_SIZE + px) * 4; 
					data[i + 0] = Math.floor(total16Bit / 256); // R (high byte)
					data[i + 1] = Math.floor(total16Bit % 256); // G (low byte)
					data[i + 2] = 0;                            // B (도로용)
					data[i + 3] = 255;
				}
			}

			tileImageDatas[idx] = imageData;
			canvases[idx].getContext('2d').putImageData(imageData, 0, 0);
			
			// 브라우저에게 화면을 그릴 시간을 줍니다 (중요)
            await new Promise(resolve => setTimeout(resolve, 0));
		}
	}

	downloadBtn.disabled = false;
	downloadFeature.disabled = false;
	downloadFarBtn.disabled = false;
}