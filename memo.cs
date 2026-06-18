
// ─── 타일 파일 Blob 생성 ─────────────────────────────────────────────────────
// 타일 output(1025×1025) 배열로부터 4종의 파일 Blob을 생성한다.
//   - elev      : 고도맵 1025×1025 (PNG 또는 RAW 24bit)
//   - normal    : 노말맵 1025×1025 RAW 24bit
//   - elev_low  : 고도맵 129×129 축소판 RAW  (= 128+1, 저해상도 경계 공유)
//   - normal_low: 노말맵 129×129 축소판 RAW
//
// 반환값: { blob, normalBlob, lowBlob, lowNormalBlob }

async function makeTileFiles(output, format, mpp) {
    const TILE_PX    = OUTPUT_PX + 1;   // 1025
    const LOW_PX     = 129;             // 128+1, 저해상도 경계 공유
    const tileCoverM = TILE_SIZE_KM * 1000;

    // ── 노말맵 1025×1025 ──
    const normalBytes = generateNormalMap(output, TILE_PX, tileCoverM / TILE_PX);
    const normalBlob  = new Blob([normalBytes], { type: 'application/octet-stream' });

    // ── 축소판 129×129 — output(1025) 기준 bilinear 다운샘플 ──
	const lowOutput  = new Int32Array(LOW_PX * LOW_PX);
	const lowFloats  = new Float32Array(LOW_PX * LOW_PX);

	for (let oy = 0; oy < LOW_PX; oy++) {
		for (let ox = 0; ox < LOW_PX; ox++) {
			const srcX = ox * (TILE_PX - 1) / (LOW_PX - 1);
			const srcY = oy * (TILE_PX - 1) / (LOW_PX - 1);
			const ix   = Math.floor(srcX), iy = Math.floor(srcY);
			const dx   = srcX - ix,        dy = srcY - iy;
			const clamp = function(v, mx) { return Math.min(v, mx); };
			const v = Math.round(
				output[clamp(iy,     TILE_PX-1) * TILE_PX + clamp(ix,     TILE_PX-1)] * (1-dx) * (1-dy) +
				output[clamp(iy,     TILE_PX-1) * TILE_PX + clamp(ix + 1, TILE_PX-1)] * dx     * (1-dy) +
				output[clamp(iy + 1, TILE_PX-1) * TILE_PX + clamp(ix,     TILE_PX-1)] * (1-dx) * dy     +
				output[clamp(iy + 1, TILE_PX-1) * TILE_PX + clamp(ix + 1, TILE_PX-1)] * dx     * dy
			);
			lowOutput[oy * LOW_PX + ox] = v;
			// Float32 변환 (미터값)
			lowFloats[oy * LOW_PX + ox] = (v - 100000) / 10.0;
		}
	}
	const lowBlob = new Blob([lowFloats.buffer], { type: 'application/octet-stream' });

    // ── 노말맵 129×129 ──
    const lowNormalBlob = new Blob(
        [generateNormalMap(lowOutput, LOW_PX, tileCoverM / LOW_PX)],
        { type: 'application/octet-stream' }
    );

    // ── 고도맵 메인 Blob ──
    let blob;
	
	// ── 변경 후 ──
	if (format === 'raw') {
		// Float32 RAW: 1025×1025×4 bytes, GL_R32F
		// (R*65536 + G*256 + B - 100000) / 10 → 실제 고도(m)
		const floats = new Float32Array(TILE_PX * TILE_PX);
		for (let i = 0; i < TILE_PX * TILE_PX; i++) {
			floats[i] = (output[i] - 100000) / 10.0;
		}
		blob = new Blob([floats.buffer], { type: 'application/octet-stream' });
	} else {
        // PNG: 1025×1025, Mapbox RGB 채널 순서(B→R채널, G→G, R→B채널)로 재인코딩
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
        blob = await new Promise(function(res) { canvas.toBlob(res, 'image/png'); });
    }

    return { blob, normalBlob, lowBlob, lowNormalBlob };
}

