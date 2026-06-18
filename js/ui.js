async function startDownload(format, onDone, cellOffset, centerLng, centerLat) {
  if (onDone     === undefined) onDone     = null;
  if (cellOffset === undefined) cellOffset = null;
  if (centerLng  === undefined) centerLng  = state.lng;
  if (centerLat  === undefined) centerLat  = state.lat;

  setBothBtnsDisabled(true);
  setStatus('', '');

  const TILE_PX = OUTPUT_PX + 1;
  const isPng   = format === 'png';
  const ext     = isPng ? 'png' : 'raw';
  const label   = isPng ? 'PNG' : 'Float32 RAW';
  const total   = GRID_N * GRID_N;

  initTileGrid();
  setPipeStep(0);
  document.getElementById('loading-overlay').classList.add('visible');
  document.getElementById('loading-text').textContent = GRID_N + '×' + GRID_N + ' 하이트맵 생성 중';
  document.getElementById('loading-format-badge').innerHTML =
    '<span class="format-badge ' + format + '">' + label +
    ' &nbsp;|&nbsp; ' + TILE_PX + '×' + TILE_PX +
    'px / tile &nbsp;|&nbsp; zoom' + FETCH_ZOOM + '</span>';

  try {
    setPipeStep(1); 
    setProgress(5, '버퍼1: 전체 영역 다운로드 중...');
    const { buf1, mpp } = await buildBuffer1(centerLng, centerLat, GRID_N);
    setProgress(30, '버퍼1 완료 → 버퍼2 업샘플링 중...');

    setPipeStep(2);
    document.getElementById('loading-sub').textContent =
      '버퍼2: 1024 → ' + (GRID_N * OUTPUT_PX + 1) + 'px bicubic 업샘플링...';
    await new Promise(function(r) { setTimeout(r, 10); });
	
    const { buf2, buf2Size } = buildBuffer2(buf1, GRID_N);
	const featureBuf = new Uint8Array(buf2Size * buf2Size);

    setProgress(55, '버퍼2 완료 → Gaussian blur 적용 중...');

    setPipeStep(3);
	document.getElementById('loading-sub').textContent =
	  'Gaussian blur (' + buf2Size + '×' + buf2Size + 'px 전체)...';
	await new Promise(function(r) { setTimeout(r, 10); });

	applyGaussian(buf2, buf2Size);
	
	// ↓ 디버그용 캐시 (평탄화 전 상태 복사본 저장)
	_debugBuf2     = new Int32Array(buf2);  // 복사!
	_debugBuf2Size = buf2Size;
	_debugMpp      = mpp;

	setProgress(60, 'Gaussian 완료 → 평탄화 확인 중...');

	// 스텝4: 도로 평탄화
	setPipeStep(4);
	const csvFile = getRoadCsvFile();
	const flattenDesc = document.getElementById('pipe-flatten-desc');

	if (csvFile) {
	  if (flattenDesc) flattenDesc.textContent = csvFile.name + ' · 적용 중...';
	  document.getElementById('loading-sub').textContent = '도로 평탄화 적용 중 (' + csvFile.name + ')...';
	  setProgress(65, '도로 평탄화 적용 중...');
	  try {
		const csvText = await csvFile.text();
		applyRoadFlatten(buf2, buf2Size, featureBuf, csvText);
		setProgress(70, '도로 평탄화 완료');
		if (flattenDesc) flattenDesc.textContent = csvFile.name + ' · 완료 ✓';
	  } catch(e) {
		console.warn('도로 평탄화 실패, 건너뜀:', e.message);
		if (flattenDesc) flattenDesc.textContent = '오류: ' + e.message;
	  }
	} else {
	  if (flattenDesc) flattenDesc.textContent = '도로 CSV 없음 · 스킵';
	  document.getElementById('loading-sub').textContent = '평탄화 스킵 → 타일 추출 중...';
	  setProgress(70, '평탄화 스킵');
	}
	
	// 스텝5: 강 파기
	const waterCsvFile    = getWaterCsvFile();
	const waterwayCsvFile = getWaterwayCsvFile(); // ← 추가 필요 (아래 참고)
	const flattenRiverDesc = document.getElementById('pipe-river-flatten-desc');

	if (waterCsvFile && waterwayCsvFile) {
		if (flattenRiverDesc) flattenRiverDesc.textContent = waterCsvFile.name + ' · 적용 중...';
		document.getElementById('loading-sub').textContent = '강 파기 적용 중...';
		setProgress(72, '강 파기 적용 중...');
		try {
			const waterText    = await waterCsvFile.text();
			const waterwayText = await waterwayCsvFile.text();

			const HW = 4;
			applyWaterMask(buf2, buf2Size, featureBuf, waterText);           // ✅ water 폐곡선 마스크
			applyWaterwayMask(buf2Size, featureBuf, waterwayText, HW);       // ✅ waterway 보완 (추가)
			await applyRiverCarving(buf2, buf2Size, featureBuf, waterwayText, 150, HW); // ✅ HW 파라미터도 통일

			setProgress(78, '강 파기 완료');
			if (flattenRiverDesc) flattenRiverDesc.textContent = waterCsvFile.name + ' · 완료 ✓';
		} catch(e) {
			console.warn('강 파기 실패, 건너뜀:', e.message);
			if (flattenRiverDesc) flattenRiverDesc.textContent = '오류: ' + e.message;
		}
	} else {
		if (flattenRiverDesc) flattenRiverDesc.textContent = 'CSV 없음 · 스킵';
		document.getElementById('loading-sub').textContent = '강 파기 스킵 → 타일 추출 중...';
		setProgress(78, '강 파기 스킵');
	}

	// 스텝5: 출력
	setPipeStep(5);
	
	//return;

    const zip = new JSZip();
    let globalMin = Infinity, globalMax = -Infinity, doneCount = 0;

    {
      document.getElementById('loading-sub').textContent =
        '전체맵 PNG 생성 중 (' + buf2Size + '×' + buf2Size + ')...';
      await new Promise(function(r) { setTimeout(r, 10); });
      const pixels = new Uint8ClampedArray(buf2Size * buf2Size * 4);
      for (let i = 0; i < buf2Size * buf2Size; i++) {
        const v = buf2[i];
        pixels[i*4]   = (v >> 16) & 0xFF;
        pixels[i*4+1] = (v >>  8) & 0xFF;
        pixels[i*4+2] =  v        & 0xFF;
        pixels[i*4+3] = 255;
      }
      const canvas  = document.createElement('canvas');
      canvas.width  = canvas.height = buf2Size;
      const ctx     = canvas.getContext('2d');
      const imgData = ctx.createImageData(buf2Size, buf2Size);
      imgData.data.set(pixels);
      ctx.putImageData(imgData, 0, 0);
      zip.file('fullmap_rgb3u8_elev.png',
        await new Promise(function(res) { canvas.toBlob(res, 'image/png'); }));
    }

    {
      document.getElementById('loading-sub').textContent =
        'overview_buf1: 광역 영역 다운로드 중...';
      const { sample: ovSample } = await buildOverviewRawBuf(null);
      const pixels = new Uint8ClampedArray(OUTPUT_PX * OUTPUT_PX * 4);
      for (let oy = 0; oy < OUTPUT_PX; oy++)
        for (let ox = 0; ox < OUTPUT_PX; ox++) {
          const v = ovSample(ox, oy, OUTPUT_PX, OUTPUT_PX);
          const i = (oy * OUTPUT_PX + ox) * 4;
          pixels[i]   = (v >> 16) & 0xFF;
          pixels[i+1] = (v >>  8) & 0xFF;
          pixels[i+2] =  v        & 0xFF;
          pixels[i+3] = 255;
        }
      const canvas  = document.createElement('canvas');
      canvas.width  = canvas.height = OUTPUT_PX;
      const ctx     = canvas.getContext('2d');
      const imgData = ctx.createImageData(OUTPUT_PX, OUTPUT_PX);
      imgData.data.set(pixels);
      ctx.putImageData(imgData, 0, 0);
      zip.file('overview_buf1_rgb3u8_elev.png',
        await new Promise(function(res) { canvas.toBlob(res, 'image/png'); }));
    }

    const baseOX = cellOffset ? cellOffset.ox * GRID_N : 0;
    const baseOY = cellOffset ? cellOffset.oy * GRID_N : 0;

    for (let row = 0; row < GRID_N; row++) {
      for (let col = 0; col < GRID_N; col++) {
        setTileCell(row, col, 'active');
        const flippedRow = GRID_N - 1 - row;
        const tileX = baseOX + col;
        const tileY = baseOY + flippedRow;
        document.getElementById('loading-sub').textContent =
          'tile_' + tileX + '_' + tileY + ' 추출 중... (' + (doneCount + 1) + '/' + total + ')';

        try {
			const { output, featureTile, minM, maxM } = extractTile(buf2, buf2Size, featureBuf, row, col);
			const offset = getHeightOffset();
			const { blob, normalBlob, lowBlob, lowNormalBlob, featureBlob, lowFeatureBlob }  = await makeTileFiles(output, featureTile, format, mpp, offset);

			const elevName = format === 'raw'
			? 'tile_' + tileX + '_' + tileY + '_r32f_elev.raw'
			: 'tile_' + tileX + '_' + tileY + '_rgb3u8_elev.png';

			zip.file(elevName,                                                       blob);
			zip.file('tile_' + tileX + '_' + tileY + '_rgb3u8_normal.raw',          normalBlob);
			zip.file('tile_' + tileX + '_' + tileY + '_rgb3u8_elev_low.raw',        lowBlob);
			zip.file('tile_' + tileX + '_' + tileY + '_rgb3u8_normal_low.raw',      lowNormalBlob);
			zip.file('tile_' + tileX + '_' + tileY + '_meta.txt',
			'version=1\n' +
			'minHeight='    + (minM + offset).toFixed(4) + '\n' +
			'maxHeight='    + (maxM + offset).toFixed(4) + '\n' +
			'heightOffset=' + offset.toFixed(4)          + '\n');
			
			zip.file('tile_' + tileX + '_' + tileY + '_u8_feature.raw',     featureBlob);
			zip.file('tile_' + tileX + '_' + tileY + '_u8_feature_low.raw', lowFeatureBlob);

			if (minM < globalMin) globalMin = minM;
			if (maxM > globalMax) globalMax = maxM;
			setTileCell(row, col, 'done');

        } catch(e) {
          console.error('tile_' + tileX + '_' + tileY + ' 오류:', e);
          setTileCell(row, col, 'error');
        }

        doneCount++;
        setProgress(65 + Math.round(doneCount / total * 30),
          '타일 ' + doneCount + '/' + total + ' 완료');
        await new Promise(function(r) { setTimeout(r, 0); });
      }
    }

    document.getElementById('loading-text').textContent = 'ZIP 압축 중...';
    document.getElementById('loading-sub').textContent  = '잠시만 기다려주세요...';
    setProgress(97, 'ZIP 생성 중...');

    const zipContent = await zip.generateAsync({ type: 'blob' });
    const link = document.createElement('a');
    link.href  = URL.createObjectURL(zipContent);
    link.download = 'heightmap_' + label + '_' + GRID_N + 'x' + GRID_N + '_' +
                    state.lat.toFixed(4) + '_' + state.lng.toFixed(4) + '.zip';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    state.globalMin = Math.round(globalMin * 10) / 10;
    state.globalMax = Math.round(globalMax * 10) / 10;
    updateDisplay();
    updateOffsetPreview();
    setProgress(100, '완료!');
    setStatus('✓ ' + label + ' ' + total + '개 완료  min ' + state.globalMin + 'm / max ' + state.globalMax + 'm', 'ok');
	
  } catch(e) {
    console.error(e);
    setStatus('오류: ' + e.message, 'err');
  } finally {
    setBothBtnsDisabled(false);
    document.getElementById('loading-overlay').classList.remove('visible');
    setTimeout(function() { setProgress(0); }, 1500);
    if (onDone) onDone();
  }
}

// ─── buf2 로드 후 파이프라인 재개 ────────────────────────
async function loadBuf2AndResume(format) {
	
    const rawFile  = document.getElementById('dbg-buf2-raw').files[0];
    const metaFile = document.getElementById('dbg-buf2-meta').files[0];

	const csvFile      = getRoadCsvFile();
	const waterCsvFile    = getWaterCsvFile();
	const waterwayCsvFile = getWaterwayCsvFile();

    if (!rawFile || !metaFile) {
        alert('raw 파일과 meta txt 파일을 모두 선택하세요.');
        return;
    }
	
	if (!csvFile) {
		alert('도로 평탄화 파일이 없습니다.');
		return;
	}
	
	if (!waterCsvFile || !waterwayCsvFile) {
		alert('강줄기, 강유역 파일이 없습니다.');
		return;
	}
	

    // ── 메타 파싱 ─────────────────────────────────────────
    const metaText = await metaFile.text();
    const metaMap  = {};
    metaText.split('\n').forEach(line => {
        const [k, v] = line.split('=');
        if (k && v) metaMap[k.trim()] = v.trim();
    });

    const buf2Size = parseInt(metaMap['buf2Size']);
    const mpp      = parseFloat(metaMap['mpp']);

    if (!buf2Size || isNaN(mpp)) {
        alert('meta 파일 파싱 실패.');
        return;
    }

    // ── Raw → Int32Array 복원 ─────────────────────────────
    const arrayBuffer = await rawFile.arrayBuffer();
    const buf2        = new Int32Array(arrayBuffer);

    if (buf2.length !== buf2Size * buf2Size) {
        alert('buf2 크기 불일치. raw 파일이 올바른지 확인하세요.\n'
            + '예상: ' + (buf2Size * buf2Size) + '  실제: ' + buf2.length);
        return;
    }

    // ── FeatureBuf 새로 할당 ──────────────────────────────
    const featureBuf = new Uint8Array(buf2Size * buf2Size);

    // ── 이하 startDownload() 의 STEP4~ 그대로 재개 ───────
    setBothBtnsDisabled(true);
    setStatus('', '');
    initTileGrid();
    setPipeStep(0);
    document.getElementById('loading-overlay').classList.add('visible');
    document.getElementById('loading-text').textContent = '디버그 모드: buf2 로드 완료';

    try {
        // 스텝4: 도로 평탄화
        setPipeStep(4);
        const flattenDesc  = document.getElementById('pipe-flatten-desc');

        if (csvFile) {
            if (flattenDesc) flattenDesc.textContent = csvFile.name + ' · 적용 중...';
            setProgress(65, '도로 평탄화 적용 중...');
            try {
                const csvText = await csvFile.text();
                applyRoadFlatten(buf2, buf2Size, featureBuf, csvText);
                setProgress(70, '도로 평탄화 완료');
                if (flattenDesc) flattenDesc.textContent = csvFile.name + ' · 완료 ✓';
            } catch(e) {
                console.warn('도로 평탄화 실패:', e.message);
                if (flattenDesc) flattenDesc.textContent = '오류: ' + e.message;
            }
        } else {
            if (flattenDesc) flattenDesc.textContent = '도로 CSV 없음 · 스킵';
            setProgress(70, '평탄화 스킵');
        }

        // 스텝5: 강 파기
        const flattenRiverDesc = document.getElementById('pipe-river-flatten-desc');

        if (waterCsvFile && waterwayCsvFile) {
            if (flattenRiverDesc) flattenRiverDesc.textContent = waterCsvFile.name + ' · 적용 중...';
            setProgress(72, '강 파기 적용 중...');
            try {
                const waterText    = await waterCsvFile.text();
                const waterwayText = await waterwayCsvFile.text();
                const HW = 2;
				
                applyWaterMask(buf2, buf2Size, featureBuf, waterText);
                applyWaterwayMask(buf2Size, featureBuf, waterwayText, HW);
				
                applyRiverCarving(buf2, buf2Size, featureBuf, waterwayText, 80, HW);
				
                setProgress(78, '강 파기 완료');
                if (flattenRiverDesc) flattenRiverDesc.textContent = waterCsvFile.name + ' · 완료 ✓';
            } catch(e) {
                console.warn('강 파기 실패:', e.message);
                if (flattenRiverDesc) flattenRiverDesc.textContent = '오류: ' + e.message;
            }
        } else {
            if (flattenRiverDesc) flattenRiverDesc.textContent = 'CSV 없음 · 스킵';
            setProgress(78, '강 파기 스킵');
        }
		
        //setProgress(100, '완료!');

		//debugSaveHeightPng(buf2, buf2Size, 'dbg_river_height.png');
		return;
		
		/*
        // 스텝5: 출력
        setPipeStep(5);
        const zip = new JSZip();
        let globalMin = Infinity, globalMax = -Infinity, doneCount = 0;
        const total   = GRID_N * GRID_N;
        const offset  = getHeightOffset();
        const label   = format === 'png' ? 'PNG' : 'Float32 RAW';

        for (let row = 0; row < GRID_N; row++) {
            for (let col = 0; col < GRID_N; col++) {
                setTileCell(row, col, 'active');
                const flippedRow = GRID_N - 1 - row;
                const tileX = col;
                const tileY = flippedRow;

                try {
                    const { output, featureTile, minM, maxM } = extractTile(buf2, buf2Size, featureBuf, row, col);
                    const { blob, normalBlob, lowBlob, lowNormalBlob, featureBlob, lowFeatureBlob }
                        = await makeTileFiles(output, featureTile, format, mpp, offset);

                    const elevName = format === 'raw'
                        ? 'tile_' + tileX + '_' + tileY + '_r32f_elev.raw'
                        : 'tile_' + tileX + '_' + tileY + '_rgb3u8_elev.png';

                    zip.file(elevName,                                                  blob);
                    zip.file('tile_' + tileX + '_' + tileY + '_rgb3u8_normal.raw',     normalBlob);
                    zip.file('tile_' + tileX + '_' + tileY + '_rgb3u8_elev_low.raw',   lowBlob);
                    zip.file('tile_' + tileX + '_' + tileY + '_rgb3u8_normal_low.raw', lowNormalBlob);
                    zip.file('tile_' + tileX + '_' + tileY + '_meta.txt',
                        'version=1\n' +
                        'minHeight='    + (minM + offset).toFixed(4) + '\n' +
                        'maxHeight='    + (maxM + offset).toFixed(4) + '\n' +
                        'heightOffset=' + offset.toFixed(4)          + '\n');
                    zip.file('tile_' + tileX + '_' + tileY + '_u8_feature.raw',     featureBlob);
                    zip.file('tile_' + tileX + '_' + tileY + '_u8_feature_low.raw', lowFeatureBlob);

                    if (minM < globalMin) globalMin = minM;
                    if (maxM > globalMax) globalMax = maxM;
                    setTileCell(row, col, 'done');
                } catch(e) {
                    console.error('tile 오류:', e);
                    setTileCell(row, col, 'error');
                }

                doneCount++;
                setProgress(78 + Math.round(doneCount / total * 19),
                    '타일 ' + doneCount + '/' + total + ' 완료');
                await new Promise(r => setTimeout(r, 0));
            }
        }

        document.getElementById('loading-text').textContent = 'ZIP 압축 중...';
        setProgress(97, 'ZIP 생성 중...');

        const zipContent = await zip.generateAsync({ type: 'blob' });
        const link       = document.createElement('a');
        link.href        = URL.createObjectURL(zipContent);
        link.download    = 'dbg_heightmap_' + label + '_' + GRID_N + 'x' + GRID_N + '.zip';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        state.globalMin = Math.round(globalMin * 10) / 10;
        state.globalMax = Math.round(globalMax * 10) / 10;
        updateDisplay();
        setProgress(100, '완료!');
        setStatus('✓ 디버그 ' + label + ' ' + total + '개 완료', 'ok');
		
		
		*/
	
	

    } catch(e) {
        console.error(e);
        setStatus('오류: ' + e.message, 'err');
    } finally {
        setBothBtnsDisabled(false);
        document.getElementById('loading-overlay').classList.remove('visible');
        setTimeout(() => setProgress(0), 1500);
    }
}

// ─── buf2 덤프 저장 ───────────────────────────────────────
function saveBuf2Dump() {
    if (!_debugBuf2) {
        alert('buf2가 없습니다. 먼저 다운로드를 한 번 실행하세요.');
        return;
    }

    // raw 파일 (Int32Array 바이너리 그대로)
    const rawBlob  = new Blob([_debugBuf2.buffer], { type: 'application/octet-stream' });
    const rawLink  = document.createElement('a');
    rawLink.href   = URL.createObjectURL(rawBlob);
    rawLink.download = 'buf2_' + _debugBuf2Size + '_' + state.lat.toFixed(4) + '_' + state.lng.toFixed(4) + '.raw';
    rawLink.click();

    // 메타 txt
    const meta =
        'buf2Size=' + _debugBuf2Size + '\n' +
        'GRID_N='   + GRID_N         + '\n' +
        'mpp='      + _debugMpp      + '\n' +
        'lat='      + state.lat      + '\n' +
        'lng='      + state.lng      + '\n';
    const metaBlob  = new Blob([meta], { type: 'text/plain' });
    const metaLink  = document.createElement('a');
    metaLink.href   = URL.createObjectURL(metaBlob);
    metaLink.download = 'buf2_' + _debugBuf2Size + '_' + state.lat.toFixed(4) + '_' + state.lng.toFixed(4) + '_meta.txt';
    metaLink.click();
}


// ─── 디스플레이 ──────────────────────────────────────────
function updateDisplay() {
  document.getElementById('disp-lng').textContent = state.lng.toFixed(5);
  document.getElementById('disp-lat').textContent = state.lat.toFixed(5);
  document.getElementById('disp-min').textContent = state.globalMin !== null ? state.globalMin + ' m' : '— m';
  document.getElementById('disp-max').textContent = state.globalMax !== null ? state.globalMax + ' m' : '— m';
}

// ─── 최고/최저 높이 마커 업데이트 ────────────────────────
function updateHeightMarkers(minPt, minM, maxPt, maxM) {
    if (!map.getSource('height-markers')) return;

    const features = [];

    if (minPt) {
        features.push({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [minPt.lng, minPt.lat] },
            properties: {
                color: '#3377ff',
                label: '▼ ' + minM + 'm',
            }
        });
    }

    if (maxPt) {
        features.push({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [maxPt.lng, maxPt.lat] },
            properties: {
                color: '#ff3344',
                label: '▲ ' + maxM + 'm',
            }
        });
    }

    map.getSource('height-markers').setData({
        type: 'FeatureCollection',
        features
    });
}

// ─── 높이 실시간 미리보기 ─────────────────────────────────
async function previewHeights() {
    const statusEl = document.getElementById('height-preview-status');
    if (statusEl) {
        statusEl.textContent = '⟳ 높이 계산 중...';
        statusEl.style.color = 'var(--muted)';
    }

    document.getElementById('disp-min').textContent = '— m';
    document.getElementById('disp-max').textContent = '— m';

    try {
        const centerPt  = turf.point([state.lng, state.lat]);
        const halfKm    = (TILE_SIZE_KM * GRID_N * 9) / 2;

        const nLat = turf.destination(centerPt, halfKm,   0, { units: 'kilometers' }).geometry.coordinates[1];
        const sLat = turf.destination(centerPt, halfKm, 180, { units: 'kilometers' }).geometry.coordinates[1];
        const wLng = turf.destination(centerPt, halfKm, 270, { units: 'kilometers' }).geometry.coordinates[0];
        const eLng = turf.destination(centerPt, halfKm,  90, { units: 'kilometers' }).geometry.coordinates[0];

        const SAMPLE_N = 5;
        const points = [];
        for (let r = 0; r < SAMPLE_N; r++) {
            for (let c = 0; c < SAMPLE_N; c++) {
                const lat = nLat + (sLat - nLat) * r / (SAMPLE_N - 1);
                const lng = wLng + (eLng - wLng) * c / (SAMPLE_N - 1);
                points.push({ lat, lng });
            }
        }

        const tileCache = new Map();

        async function getElevAt(lat, lng) {
            const tx = long2tile(lng, FETCH_ZOOM);
            const ty = lat2tile(lat,  FETCH_ZOOM);
            const key = tx + ',' + ty;

            if (!tileCache.has(key)) {
                const url = 'https://api.mapbox.com/v4/mapbox.terrain-rgb/'
                    + FETCH_ZOOM + '/' + tx + '/' + ty
                    + '@2x.pngraw?access_token=' + MAPBOX_TOKEN;
                try {
                    const pixels = await downloadPngToPixels(url);
                    tileCache.set(key, { pixels, tx, ty });
                } catch(e) {
                    tileCache.set(key, null);
                }
            }

            const cached = tileCache.get(key);
            if (!cached) return null;

            const lngF  = lng2tileF(lng, FETCH_ZOOM);
            const latF  = lat2tileF(lat, FETCH_ZOOM);
            const px    = Math.floor((lngF - cached.tx) * MAPBOX_TILE_PX);
            const py    = Math.floor((latF - cached.ty) * MAPBOX_TILE_PX);
            const cx    = Math.max(0, Math.min(MAPBOX_TILE_PX - 1, px));
            const cy    = Math.max(0, Math.min(MAPBOX_TILE_PX - 1, py));
            const si    = (cy * MAPBOX_TILE_PX + cx) * 4;
            const { pixels } = cached;
            return (pixels[si] * 65536 + pixels[si+1] * 256 + pixels[si+2] - 100000) / 10;
        }

        const elevations = await Promise.all(points.map(p => getElevAt(p.lat, p.lng)));

        const valid = elevations.filter(v => v !== null);
        if (valid.length === 0) throw new Error('샘플 없음');

        let minM = Infinity, maxM = -Infinity;
        let minPt = null, maxPt = null;

        elevations.forEach((elev, i) => {
            if (elev === null) return;
            if (elev < minM) { minM = elev; minPt = points[i]; }
            if (elev > maxM) { maxM = elev; maxPt = points[i]; }
        });

        minM = Math.round(minM);
        maxM = Math.round(maxM);

        document.getElementById('disp-min').textContent = '~' + minM + ' m';
        document.getElementById('disp-max').textContent = '~' + maxM + ' m';

        updateHeightMarkers(minPt, minM, maxPt, maxM);

        if (statusEl) {
            statusEl.textContent = '※ 근사값 (' + valid.length + '개 샘플) · 다운로드 후 정확한 값 표시';
            statusEl.style.color = 'var(--muted)';
        }

    } catch(e) {
        if (statusEl) {
            statusEl.textContent = '높이 미리보기 실패: ' + e.message;
            statusEl.style.color = 'var(--danger)';
        }
    }
}

function onGridSizeChange(val) {
  GRID_N       = parseInt(val, 10);
  FULL_SIZE_KM = TILE_SIZE_KM * GRID_N;
  const el = document.getElementById('info-total-km');
  if (el) el.textContent = FULL_SIZE_KM.toFixed(3) + ' km × ' + FULL_SIZE_KM.toFixed(3) + ' km';
  state.globalMin = null;
  state.globalMax = null;
  updateDisplay();
  updateGrid();
}

function setStatus(msg, cls) {
  const el = document.getElementById('status-result');
  el.textContent = msg;
  el.className   = 'status ' + (cls || '');
}

function setProgress(pct, label) {
  const wrap = document.getElementById('progress-wrap');
  wrap.classList.toggle('visible', pct > 0 && pct < 100);
  document.getElementById('progress-fill').style.width = pct + '%';
  if (label) document.getElementById('progress-label').textContent = label;
  const loadFill  = document.getElementById('load-progress-fill');
  const loadLabel = document.getElementById('load-progress-label');
  if (loadFill)  loadFill.style.width  = Math.min(100, pct) + '%';
  if (loadLabel) loadLabel.textContent = Math.min(100, Math.round(pct)) + '%';
}

function setPipeStep(step) {
  for (let i = 1; i <= 5; i++) {
    const el = document.getElementById('pipe-step-' + i);
    if (!el) continue;
    el.classList.remove('active', 'done');
    if (i < step)  el.classList.add('done');
    if (i === step) el.classList.add('active');
  }
}

function setBothBtnsDisabled(flag) {
  //document.getElementById('dl-btn-png').disabled = flag;
  //document.getElementById('dl-btn-raw').disabled = flag;
  //const ov = document.getElementById('dl-btn-overview');
  //if (ov) ov.disabled = flag;
  //const mg = document.getElementById('dl-btn-mega');
  //if (mg) mg.disabled = flag;
  //const vc = document.getElementById('dl-btn-vector');
  //if (vc) vc.disabled = flag;
}

function initTileGrid() {
  const g        = document.getElementById('tile-progress-grid');
  const cellSize = Math.max(6, Math.min(16, Math.floor(240 / GRID_N)));
  g.style.gridTemplateColumns = 'repeat(' + GRID_N + ', ' + cellSize + 'px)';
  g.innerHTML = '';
  for (let i = 0; i < GRID_N * GRID_N; i++) {
    const cell = document.createElement('div');
    cell.className = 'tile-cell';
    cell.id = 'tc-' + i;
    g.appendChild(cell);
  }
}

function setTileCell(row, col, status) {
  const cell = document.getElementById('tc-' + (row * GRID_N + col));
  if (cell) cell.className = 'tile-cell ' + status;
}

// ─── Height Offset ────────────────────────────────────────
function getHeightOffset() {
    const val = parseFloat(document.getElementById('input-height-offset').value);
    return isNaN(val) ? 0 : val;
}

function updateOffsetPreview() {
    const offset  = getHeightOffset();
    const el      = document.getElementById('offset-preview');
    const descEl  = document.getElementById('offset-desc');
    if (!el) return;

    if (offset > 0) {
        el.textContent = '+' + offset + ' m';
        el.className   = 'height-offset-preview';
    } else if (offset < 0) {
        el.textContent = offset + ' m';
        el.className   = 'height-offset-preview negative';
    } else {
        el.textContent = '±0 m';
        el.className   = 'height-offset-preview zero';
    }

    if (descEl) {
        descEl.textContent = offset === 0
            ? '다운로드 시 모든 픽셀 높이에 적용됩니다 (RAW 전용)'
            : '적용 후: min ' +
              (state.globalMin !== null ? (state.globalMin + offset) + ' m' : '?') +
              '  /  max ' +
              (state.globalMax !== null ? (state.globalMax + offset) + ' m' : '?');
    }
}

window.addEventListener('load', function () {
    const el = document.getElementById('input-height-offset');
    if (el) el.addEventListener('input', updateOffsetPreview);
});

// ─── 좌표 직접 입력 ──────────────────────────────────────
function syncInputFields() {
  const latEl = document.getElementById('input-lat');
  const lngEl = document.getElementById('input-lng');
  if (latEl) latEl.value = state.lat.toFixed(5);
  if (lngEl) lngEl.value = state.lng.toFixed(5);
  clearCoordError();
}

function clearCoordError() {
  const errEl = document.getElementById('coord-error');
  if (errEl) errEl.textContent = '';
  document.getElementById('input-lat').classList.remove('error');
  document.getElementById('input-lng').classList.remove('error');
}

function showCoordError(msg) {
  const errEl = document.getElementById('coord-error');
  if (errEl) errEl.textContent = msg;
}

function applyCoordInput() {
  const latRaw = document.getElementById('input-lat').value.trim();
  const lngRaw = document.getElementById('input-lng').value.trim();
  clearCoordError();

  if (map.getSource('height-markers')) {
      map.getSource('height-markers').setData({ type: 'FeatureCollection', features: [] });
  }

  const lat = parseFloat(latRaw), lng = parseFloat(lngRaw);
  let hasError = false;
  if (latRaw === '' || isNaN(lat) || lat < -90  || lat > 90)  { document.getElementById('input-lat').classList.add('error'); hasError = true; }
  if (lngRaw === '' || isNaN(lng) || lng < -180 || lng > 180) { document.getElementById('input-lng').classList.add('error'); hasError = true; }
  if (hasError) { showCoordError('위도 -90~90, 경도 -180~180 범위로 입력해주세요.'); return; }

  state.lat = lat; state.lng = lng;
  state.globalMin = null; state.globalMax = null;
  map.flyTo({ center: [lng, lat], zoom: Math.max(map.getZoom(), 9), essential: true });
  updateGrid(); updateDisplay();
  previewHeights();
  document.getElementById('hint').classList.add('hide');
  setStatus('좌표 입력 완료. 다운로드 버튼을 누르세요.', '');
}

window.addEventListener('load', function() {
  ['input-lat', 'input-lng'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('keydown', function(e) { if (e.key === 'Enter') applyCoordInput(); });
  });
});

// ─── 즐겨찾기 ────────────────────────────────────────────
let favorites = storageGet('map_favorites', []);

function renderFavorites() {
  const container = document.getElementById('fav-list');
  container.innerHTML = '';

  if (favorites.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'fav-empty';
    empty.textContent = '즐겨찾기가 없습니다';
    container.appendChild(empty);
    return;
  }

  favorites.forEach(function(fav, index) {
    const item = document.createElement('div');
    item.className = 'fav-item';
    item.innerHTML =
      '<div class="fav-item-icon"><i class="ti ti-map-pin" aria-hidden="true"></i></div>' +
      '<div class="fav-info" onclick="gotoFavorite(' + index + ')">' +
        '<div class="fav-name">' + fav.name + '</div>' + 
      '</div>' +
      '<button class="fav-del" aria-label="즐겨찾기 삭제" onclick="deleteFavorite(event,' + index + ')">' +
        '<i class="ti ti-x" aria-hidden="true"></i>' +
      '</button>';
    container.appendChild(item);
  });
}

function addFavorite() {
  const name = prompt('즐겨찾기 이름을 입력하세요:', '위치 ' + (favorites.length + 1));
  if (!name) return;
  favorites.push({ name: name, lng: state.lng, lat: state.lat });
  saveFavorites();
}

function deleteFavorite(event, index) {
  event.stopPropagation();
  if (confirm('삭제하시겠습니까?')) { favorites.splice(index, 1); saveFavorites(); }
}

function gotoFavorite(index) {
  const fav = favorites[index];
  state.lng = fav.lng; state.lat = fav.lat;
  state.globalMin = null; state.globalMax = null;
  map.flyTo({ center: [fav.lng, fav.lat], zoom: 12, essential: true });
  updateGrid(); updateDisplay(); syncInputFields();
  setStatus('즐겨찾기 \'' + fav.name + '\'(으)로 이동했습니다.', 'ok');
}

function storageGet(key, fallback) {
    try { return JSON.parse(localStorage.getItem(key)) || fallback; }
    catch(e) { return fallback; }
}

function storageSet(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); }
    catch(e) { console.warn('storage 저장 실패 (Tracking Prevention):', e.message); }
}

function saveFavorites() {
    storageSet('map_favorites', favorites);
    renderFavorites();
}

window.addEventListener('load', renderFavorites);

// ─── 다운로드 ─────────────────────────────────────────────

function showConfirm(title, desc, onOk) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-desc').innerHTML    = desc;
  const modal     = document.getElementById('confirm-modal');
  const btnOk     = document.getElementById('confirm-ok');
  const btnCancel = document.getElementById('confirm-cancel');
  modal.classList.add('visible');
  const close = function() { modal.classList.remove('visible'); };
  btnOk.onclick     = function() { close(); onOk(); };
  btnCancel.onclick = close;
}



async function startOverviewDownload() {
  const btn      = document.getElementById('dl-btn-overview');
  const statusEl = document.getElementById('status-overview');
  setBothBtnsDisabled(true);
  btn.disabled = true;
  statusEl.textContent = '준비 중...';
  statusEl.style.color = 'var(--muted)';

  try {
    const { sample } = await buildOverviewRawBuf(function(t) { statusEl.textContent = t; });
    const OVERVIEW_M_ = OV_GRID * GRID_N * TILE_SIZE_KM * 1000;
    const outMpp      = OVERVIEW_M_ / OVERVIEW_SIZE;

    statusEl.textContent = 'bicubic crop → ' + OVERVIEW_SIZE + '×' + OVERVIEW_SIZE + '...';
    await new Promise(function(r) { setTimeout(r, 10); });

    const output = new Int32Array(OVERVIEW_SIZE * OVERVIEW_SIZE);
    let minVal = Infinity, maxVal = -Infinity;

    const offset = getHeightOffset();

    for (let oy = 0; oy < OVERVIEW_SIZE; oy++)
      for (let ox = 0; ox < OVERVIEW_SIZE; ox++) {
        const v = sample(ox, oy, OVERVIEW_SIZE, OVERVIEW_SIZE);
        output[oy * OVERVIEW_SIZE + ox] = v;
        if (v > maxVal) maxVal = v;
        if (v < minVal) minVal = v;
      }

    const minM = ((minVal - 100000) / 10 + offset).toFixed(1);
    const maxM = ((maxVal - 100000) / 10 + offset).toFixed(1);

    function makePng(data, size, isNormal) {
      const pixels = new Uint8ClampedArray(size * size * 4);
      for (let i = 0; i < size * size; i++) {
        if (isNormal) {
          pixels[i*4]   = data[i*4];
          pixels[i*4+1] = data[i*4+1];
          pixels[i*4+2] = data[i*4+2];
        } else {
          const v = data[i];
          pixels[i*4]   = (v >> 16) & 0xFF;
          pixels[i*4+1] = (v >>  8) & 0xFF;
          pixels[i*4+2] =  v        & 0xFF;
        }
        pixels[i*4+3] = 255;
      }
      const canvas  = document.createElement('canvas');
      canvas.width  = canvas.height = size;
      const ctx     = canvas.getContext('2d');
      const imgData = ctx.createImageData(size, size);
      imgData.data.set(pixels);
      ctx.putImageData(imgData, 0, 0);
      return new Promise(function(res) { canvas.toBlob(res, 'image/png'); });
    }

    statusEl.textContent = 'elev + normal PNG 생성 중...';
    const elevBlob    = await makePng(output, OVERVIEW_SIZE, false);
    const normalBytes = generateNormalMap(output, OVERVIEW_SIZE, outMpp);
    const normalBlob  = await makePng(normalBytes, OVERVIEW_SIZE, true);

    const zip = new JSZip();
    const tag = state.lat.toFixed(4) + '_' + state.lng.toFixed(4);
    zip.file('overview_rgb3u8_elev.png',   elevBlob);
    zip.file('overview_rgb3u8_normal.png', normalBlob);
    zip.file('overview_meta.txt',
      'version=1\n' +
      'zoom='         + OVERVIEW_ZOOM          + '\n' +
      'coverM='       + OVERVIEW_M_            + '\n' +
      'mpp='          + outMpp.toFixed(2)      + '\n' +
      'minHeight='    + minM                   + '\n' +
      'maxHeight='    + maxM                   + '\n' +
      'heightOffset=' + offset.toFixed(4)      + '\n');

    const content = await zip.generateAsync({ type: 'blob' });
    const link = document.createElement('a');
    link.href  = URL.createObjectURL(content);
    link.download = 'overview_512_' + tag + '.zip';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    statusEl.textContent =
      '✓ ' + OVERVIEW_M_ + 'm×' + OVERVIEW_M_ + 'm  1px=' + outMpp + 'm' +
      '  min ' + minM + 'm / max ' + maxM + 'm';
    statusEl.style.color = 'var(--accent)';

  } catch(e) {
    console.error(e);
    statusEl.textContent = '오류: ' + e.message;
    statusEl.style.color = 'var(--danger)';
  } finally {
    setBothBtnsDisabled(false);
    btn.disabled = false;
  }
}


async function startMegaMapDownload() {
  const statusEl = document.getElementById('status-mega');
  setBothBtnsDisabled(true);
  statusEl.textContent = '준비 중...';
  statusEl.style.color = 'var(--muted)';

  try {
    const { sample } = await buildOverviewRawBuf(function(t) { statusEl.textContent = t; });

    const MEGA    = OV_GRID * OUTPUT_PX;
    const CELL_PX = OUTPUT_PX;

    statusEl.textContent = MEGA + '×' + MEGA + ' PNG 생성 중...';
    await new Promise(function(r) { setTimeout(r, 10); });

    const canvas  = document.createElement('canvas');
    canvas.width  = canvas.height = MEGA;
    const ctx     = canvas.getContext('2d');
    const imgData = ctx.createImageData(MEGA, MEGA);
    const pixels  = imgData.data;

    for (let oy = 0; oy < MEGA; oy++)
      for (let ox = 0; ox < MEGA; ox++) {
        const v  = sample(ox, oy, MEGA, MEGA);
        const pi = (oy * MEGA + ox) * 4;
        pixels[pi]   = (v >> 16) & 0xFF;
        pixels[pi+1] = (v >>  8) & 0xFF;
        pixels[pi+2] =  v        & 0xFF;
        pixels[pi+3] = 255;
      }
    ctx.putImageData(imgData, 0, 0);

    const fontSize = Math.max(24, Math.round(CELL_PX * 0.055));
    ctx.textAlign    = 'center';
    ctx.textBaseline = 'middle';

    for (let row = 0; row < OV_GRID; row++) {
      for (let col = 0; col < OV_GRID; col++) {
        const x = col * CELL_PX, y = row * CELL_PX;

        ctx.fillStyle = 'rgba(110, 0, 220, 0.52)';
        ctx.fillRect(x, y, CELL_PX, CELL_PX);

        ctx.strokeStyle = 'rgba(220, 150, 255, 0.90)';
        ctx.lineWidth   = 3;
        ctx.strokeRect(x, y, CELL_PX, CELL_PX);

        const lbl = '(' + col + ',' + (OV_GRID - 1 - row) + ')';
        const cx  = x + CELL_PX / 2, cy = y + CELL_PX / 2;
        ctx.font  = 'bold ' + fontSize + 'px "Space Mono", monospace';
        const tw  = ctx.measureText(lbl).width;
        const pad = fontSize * 0.35;

        ctx.fillStyle = 'rgba(0,0,0,0.72)';
        ctx.fillRect(cx - tw/2 - pad, cy - fontSize/2 - pad, tw + pad*2, fontSize + pad*2);

        ctx.shadowColor = 'rgba(0,0,0,1)';
        ctx.shadowBlur  = 6;
        ctx.fillStyle   = '#ffffff';
        ctx.fillText(lbl, cx, cy);
        ctx.shadowBlur  = 0;
      }
    }

    ctx.strokeStyle = 'rgba(220, 150, 255, 1.0)';
    ctx.lineWidth   = 5;
    ctx.strokeRect(0, 0, MEGA, MEGA);

    statusEl.textContent = 'PNG 인코딩 중... (잠시 기다려주세요)';
    await new Promise(function(r) { setTimeout(r, 10); });

    const tag = state.lat.toFixed(4) + '_' + state.lng.toFixed(4);
    canvas.toBlob(function(blob) {
      const link = document.createElement('a');
      link.href  = URL.createObjectURL(blob);
      link.download = 'megamap_' + MEGA + '_' + tag + '.png';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

      statusEl.textContent = '✓ ' + MEGA + '×' + MEGA;
      statusEl.style.color = 'var(--accent)';
      setBothBtnsDisabled(false);
    }, 'image/png');

  } catch(e) {
    console.error(e);
    statusEl.textContent = '오류: ' + e.message;
    statusEl.style.color = 'var(--danger)';
    setBothBtnsDisabled(false);
  }
}

// ─── 선택 모달 ────────────────────────────────────────────
function showChoiceModal(title, desc, onRoadCsv, onRiverCsv, onRaw) {
    document.getElementById('choice-title').textContent = title;
    document.getElementById('choice-desc').innerHTML    = desc;

    const modal = document.getElementById('choice-modal');
    modal.style.display = 'flex';

    const close = () => { modal.style.display = 'none'; };

    document.getElementById('choice-cancel').onclick   = close;
    document.getElementById('choice-csv').onclick      = function() { close(); onRoadCsv(); };
    document.getElementById('choice-river').onclick    = function() { close(); onRiverCsv(); };  // ← 추가
    document.getElementById('choice-raw').onclick      = function() { close(); showRawConfirmModal(onRaw); };
}

// ─── Road CSV 파일 입력 ───────────────────────────────────
function onRoadCsvSelected(input) {
    const nameEl = document.getElementById('road-csv-name');
    if (input.files.length > 0) {
        nameEl.textContent = '✓ ' + input.files[0].name;
    } else {
        nameEl.textContent = '';
    }
}

function getRoadCsvFile() {
    const el = document.getElementById('input-road-csv-raw');
    return (el && el.files.length > 0) ? el.files[0] : null;
}

// ─── RAW 확인 모달 ────────────────────────────────────────
let _rawConfirmCallback = null;

function showRawConfirmModal(onOk) {
    _rawConfirmCallback = onOk;

    // 파일 상태 초기화
    updateRawFileStatus();
	
	updateRawWaterFileStatus();

    const modal = document.getElementById('raw-confirm-modal');
    modal.classList.add('visible');

    document.getElementById('raw-confirm-ok').onclick = function() {
        modal.classList.remove('visible');
        if (_rawConfirmCallback) _rawConfirmCallback();
    };

    document.getElementById('raw-confirm-cancel').onclick = function() {
        modal.classList.remove('visible');
    };
}

function onRawWaterCsvSelected(input) {
    const other = document.getElementById('input-water-csv');
    if (input.files.length > 0 && other) {
        const dt = new DataTransfer();
        dt.items.add(input.files[0]);
        other.files = dt.files;
        const nameEl = document.getElementById('water-csv-name');
        if (nameEl) nameEl.textContent = '✓ ' + input.files[0].name;
    }
    updateRawWaterFileStatus();
}

function updateRawFileStatus() {
    const input       = document.getElementById('input-road-csv-raw');
    const iconEl      = document.getElementById('raw-file-icon');
    const labelEl     = document.getElementById('raw-file-label');
    const subEl       = document.getElementById('raw-file-sub');
    const attachLabel = document.getElementById('raw-attach-label');

    if (!iconEl || !labelEl || !subEl || !attachLabel) return; // ← 추가

    const hasFile = input && input.files.length > 0;

    if (hasFile) {
        iconEl.className    = 'raw-file-status-icon has-file';
        iconEl.innerHTML    = '<i class="ti ti-file-check" aria-hidden="true"></i>';
        labelEl.textContent = input.files[0].name;
        subEl.textContent   = '평탄화가 적용됩니다';
        attachLabel.textContent = '변경';
    } else {
        iconEl.className    = 'raw-file-status-icon';
        iconEl.innerHTML    = '<i class="ti ti-file-x" aria-hidden="true"></i>';
        labelEl.textContent = '도로 평탄화 CSV 없음';
        subEl.textContent   = '평탄화를 적용하려면 파일을 첨부하세요';
        attachLabel.textContent = '첨부';
    }
}

function updateRawWaterFileStatus() {
    const input     = document.getElementById('input-water-csv-raw');
    const iconEl    = document.getElementById('raw-water-file-icon');
    const labelEl   = document.getElementById('raw-water-file-label');
    const subEl     = document.getElementById('raw-water-file-sub');
    const attachLabel = document.getElementById('raw-water-attach-label');

    const hasFile = input && input.files.length > 0;

    if (hasFile) {
        iconEl.className   = 'raw-file-status-icon has-file';
        iconEl.innerHTML   = '<i class="ti ti-file-check" aria-hidden="true"></i>';
        labelEl.textContent = input.files[0].name;
        subEl.textContent   = '강 비트마스크가 적용됩니다';
        attachLabel.textContent = '변경';
    } else {
        iconEl.className   = 'raw-file-status-icon';
        iconEl.innerHTML   = '<i class="ti ti-file-x" aria-hidden="true"></i>';
        labelEl.textContent = '강 비트마스크 CSV 없음';
        subEl.textContent   = '강 영역을 비트마스크 처리하려면 파일을 첨부하세요';
        attachLabel.textContent = '첨부';
    }
}

function getWaterCsvFile() {
    const el = document.getElementById('input-water-csv-raw');
    return (el && el.files.length > 0) ? el.files[0] : null;
}

function getWaterwayCsvFile() {
    return document.getElementById('input-waterway-csv-raw')?.files[0] ?? null;
}

function onRawCsvSelected(input) {
    // 두 file input을 동기화
    const other = document.getElementById('input-road-csv');
    if (input.files.length > 0 && other) {
        // DataTransfer로 파일 객체 복사
        const dt = new DataTransfer();
        dt.items.add(input.files[0]);
        other.files = dt.files;
        // ui.js의 기존 표시도 갱신
        const nameEl = document.getElementById('road-csv-name');
        if (nameEl) nameEl.textContent = '✓ ' + input.files[0].name;
    }
    updateRawFileStatus();
}
