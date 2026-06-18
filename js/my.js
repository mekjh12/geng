
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

function Create2DArray(rows, def = null) {
    let arr = new Array(rows);
    for (let i = 0; i < rows; i++) {
        arr[i] = new Array(rows).fill(def);
    }
    return arr;
}

function download(filename, blob) {
    const element = document.createElement('a');
    element.setAttribute('href', window.URL.createObjectURL(blob));
    element.setAttribute('download', filename);
    element.style.display = 'none';
    document.body.appendChild(element);
    element.click();
    document.body.removeChild(element);
}

function setLngLat() {
    // 1. 현재 지도의 중심 좌표를 가져옵니다.
    let center = map.getCenter();

    // 2. 전역 변수인 Original 좌표를 현재 위치로 업데이트합니다.
    lngOriginal = center.lng;
    latOriginal = center.lat;

    // 3. 입력 필드 값도 현재 위치로 맞춥니다.
    document.getElementById('lngInput').value = lngOriginal.toFixed(5);
    document.getElementById('latInput').value = latOriginal.toFixed(5);

    // 4. 새로운 기준점이 잡혔으므로 오프셋(누적 이동량)을 0으로 초기화합니다.
    document.getElementById('offsetX').value = 0;
    document.getElementById('offsetY').value = 0;

    // 5. 내부 grid 객체 업데이트 및 UI 반영
    grid.lng = lngOriginal;
    grid.lat = latOriginal;

    setGrid(grid.lng, grid.lat, vmapSize);
    saveSettings();
    hideDebugLayer();
    updateInfopanel();

    alert("현재 위치가 새로운 기준점(Original)으로 설정되었습니다.");
}

function setQuickLngLat(offsetX, offsetY) {
    let lngInput = document.getElementById('lngInput');
    let latInput = document.getElementById('latInput');

    let offX = document.getElementById('offsetX');
    let offY = document.getElementById('offsetY');

	if (offsetX==0 && offsetY==0) {
		// 계림
		if (lngInput.value == '') lngInput.value = lngOriginal;//'110.43266';
		if (latInput.value == '') latInput.value = latOriginal;//'24.98872';
		
		// 홍콩 114.12794, 22.22225
		//if (lngInput.value == '') lngInput.value = '114.12794';
		//if (latInput.value == '') latInput.value = '22.22225';
		
		offX.value = 0;
		offY.value = 0;
	}
	
	offX.value = parseInt(offX.value) + parseInt(offsetX);
	offY.value = parseInt(offY.value) + parseInt(offsetY);


	// 수정 (9.216km 기준)
	lngInput.value = parseFloat(lngInput.value) + parseFloat(offsetX) * 0.0910;
	latInput.value = parseFloat(latInput.value) + parseFloat(offsetY) * 0.0828;
	
	if ((lngInput.value) && (latInput.value)) {
		grid.lng = parseFloat(lngInput.value);
		grid.lat = parseFloat(latInput.value);

		setGrid(grid.lng, grid.lat, vmapSize);
		map.panTo(new mapboxgl.LngLat(grid.lng, grid.lat));

		saveSettings();
		hideDebugLayer();
		updateInfopanel();
	}
}

function drawRiverAndRoad(vTiles, tileCnt, fullLength, cropX, cropY) {
    return new Promise((resolve) => {
        const outputSize = 1081;

        // 1) 타일 전체 영역 크기의 캔버스에 그리기 (하이트맵과 동일 스케일)
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = fullLength;
        canvas.height = fullLength;
        ctx.fillStyle = '#000000';
        ctx.fillRect(0, 0, fullLength, fullLength);

        const coef = fullLength / (tileCnt * 4096);

        for (let ty = 0; ty < tileCnt; ty++) {
            for (let tx = 0; tx < tileCnt; tx++) {
                const tile = vTiles[ty][tx];
                if (!tile || !tile.layers) continue;

                const xOffset = tx * fullLength / tileCnt;
                const yOffset = ty * fullLength / tileCnt;


				if (tile.layers.landuse) {					
					const landuseColors = {
						'park':              '#00FF00', // 진초록 - 공원
						'forest':            '#00FF00', // 어두운 초록 - 숲
						'wood':              '#00FF00', // 초록 - 나무
						'grass':             '#00FF00', // 연초록 - 잔디
						'meadow':            '#00FF00', // 황록 - 초원
						'scrub':             '#6B8E23', // 올리브 - 관목
						'farmland':          '#F5DEB3', // 밀색 - 농지
						'farmyard':          '#DEB887', // 갈색 - 농장
						'crop':              '#DAA520', // 황금 - 작물
						'residential':       '#CD853F', // 밝은 갈색 - 주거지
						'commercial':        '#FFD700', // 금색 - 상업지
						'industrial':        '#A9A9A9', // 회색 - 공업지
						'retail':            '#FFA500', // 주황 - 소매
						//'school':            '#FF69B4', // 분홍 - 학교
						'hospital':          '#FF0000', // 빨강 - 병원
						'cemetery':          '#708090', // 슬레이트 - 묘지
						'military':          '#8B0000', // 어두운 빨강 - 군사
						'airport':           '#87CEEB', // 하늘색 - 공항
						'parking':           '#C0C0C0', // 은색 - 주차장
						'pitch':             '#32CD32', // 라임 - 운동장
						'stadium':           '#FF8C00', // 다크오렌지 - 경기장
						'playground':        '#FFB6C1', // 연분홍 - 놀이터
						'golf_course':       '#7CFC00', // 잔디색 - 골프장
						'sand':              '#F4A460', // 모래색 - 모래사장
						'rock':              '#808080', // 회색 - 암석
						'glacier':           '#E0FFFF', // 연하늘 - 빙하
					};

					const defaultColor = '#333333'; // 매핑되지 않은 landuse 기본색

					const layer = tile.layers.landuse;
					for (let f = 0; f < layer.length; f++) {
						const feature = layer.feature(f);
						const cls = feature.properties.class || feature.properties.type || '';
						const color = landuseColors[cls] || defaultColor;

						ctx.fillStyle = color;
						ctx.strokeStyle = color;

						const geo = feature.loadGeometry();
						ctx.beginPath();
						geo.forEach(ring => {
							ctx.moveTo(ring[0].x * coef + xOffset, ring[0].y * coef + yOffset);
							for (let k = 1; k < ring.length; k++) {
								ctx.lineTo(ring[k].x * coef + xOffset, ring[k].y * coef + yOffset);
							}
						});
						ctx.fill();
					}
				}

                // 도로 - 빨간색 (R채널)
                if (tile.layers.road) {
                    ctx.strokeStyle = '#FF0000';
                    ctx.lineWidth = 1;
                    const layer = tile.layers.road;
                    for (let f = 0; f < layer.length; f++) {
                        const geo = layer.feature(f).loadGeometry();
                        ctx.beginPath();
                        geo.forEach(line => {
                            ctx.moveTo(line[0].x * coef + xOffset, line[0].y * coef + yOffset);
                            for (let k = 1; k < line.length; k++)
                                ctx.lineTo(line[k].x * coef + xOffset, line[k].y * coef + yOffset);
                        });
                        ctx.stroke();
                    }
                }
				
                // 강 면(water polygon) - 파란색 (B채널)
                if (tile.layers.water) {
                    ctx.fillStyle = '#0000FF';
                    ctx.strokeStyle = '#0000FF';
                    const layer = tile.layers.water;
                    for (let f = 0; f < layer.length; f++) {
                        const geo = layer.feature(f).loadGeometry();
                        ctx.beginPath();
                        geo.forEach(ring => {
                            ctx.moveTo(ring[0].x * coef + xOffset, ring[0].y * coef + yOffset);
                            for (let k = 1; k < ring.length; k++)
                                ctx.lineTo(ring[k].x * coef + xOffset, ring[k].y * coef + yOffset);
                        });
                        ctx.fill();
                    }
                }
				
                // 강줄기(waterway) - 파란색 (B채널)
                if (tile.layers.waterway) {
                    ctx.strokeStyle = '#00FF00';
                    ctx.lineWidth = 2;
                    const layer = tile.layers.waterway;
                    for (let f = 0; f < layer.length; f++) {
                        const geo = layer.feature(f).loadGeometry();
                        ctx.beginPath();
                        geo.forEach(line => {
                            ctx.moveTo(line[0].x * coef + xOffset, line[0].y * coef + yOffset);
                            for (let k = 1; k < line.length; k++)
                                ctx.lineTo(line[k].x * coef + xOffset, line[k].y * coef + yOffset);
                        });
                        ctx.stroke();
                    }
                }
				
					
            }
        }

        // 2) cropX, cropY에서 1081x1081 크롭
        const cropCanvas = document.createElement('canvas');
        const cropCtx = cropCanvas.getContext('2d');
        cropCanvas.width = outputSize;
        cropCanvas.height = outputSize;
        cropCtx.drawImage(canvas,
            cropX, cropY, outputSize, outputSize,  // 소스 영역
            0, 0, outputSize, outputSize             // 대상 영역
        );

        cropCanvas.toBlob((blob) => resolve(blob), 'image/png');
    });
}
