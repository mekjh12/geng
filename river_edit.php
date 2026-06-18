<!DOCTYPE html>
<html lang="ko">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Water CSV Editor</title>

	<link href="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.css" rel="stylesheet" />
	<script src="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.js"></script>

	<link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-draw/v1.4.3/mapbox-gl-draw.css" type="text/css" />
	<script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-draw/v1.4.3/mapbox-gl-draw.js"></script>

	<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>

	<script src="js/config.js" onerror="console.warn('config.js를 찾을 수 없어 기본값을 사용합니다.')"></script>

	<style>
		:root {
			--bg: #1a1a2e;
			--surface: #232342;
			--border: #3a3a5c;
			--accent: #534AB7;
			--accent2: #AFA9EC;
			--text: #eee;
			--muted: #999;
			--ok: #00cc44;
			--warn: #ffcc00;
			--danger: #b33939;
		}
		html, body { margin: 0; padding: 0; height: 100%; font-family: -apple-system, "Segoe UI", "Malgun Gothic", sans-serif; background: var(--bg); }
		#map { position: absolute; top: 0; left: 0; right: 0; bottom: 0; }

		/* 우상단 플로팅 패널 */
		.panel {
			position: absolute; top: 14px; right: 14px; z-index: 5;
			width: 290px;
			background: rgba(35, 35, 66, 0.95); color: var(--text);
			border: 1px solid var(--border); border-radius: 10px;
			padding: 14px; box-shadow: 0 4px 16px rgba(0,0,0,0.5);
			font-size: 13px; box-sizing: border-box;
		}
		.panel h2 {
			margin: 0 0 12px; font-size: 15px; color: var(--accent2);
			display: flex; align-items: center; gap: 6px; border-bottom: 1px solid var(--border);
			padding-bottom: 6px;
		}
		.panel .row { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
		.panel .label { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
		.panel .value { font-weight: 600; font-size: 14px; color: #fff; }
		.panel .hint { color: var(--muted); font-size: 11px; line-height: 1.5; }

		/* 버튼 레이아웃 */
		.btn-group { display: flex; gap: 6px; margin-bottom: 8px; }
		.style-btn {
			flex: 1; background: var(--bg); border: 1px solid var(--border); color: var(--muted);
			padding: 6px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600;
			transition: all 0.2s;
		}
		.style-btn:hover { color: #fff; background: var(--border); }
		.style-btn.active { background: var(--accent); color: #fff; border-color: var(--accent2); }

		.file-btn, .action-btn {
			display: inline-flex; align-items: center; justify-content: center; gap: 6px;
			padding: 8px 10px; border-radius: 6px; border: none; cursor: pointer;
			font-size: 12px; font-weight: 600; color: #fff; width: 100%; box-sizing: border-box;
			transition: filter 0.15s; margin-bottom: 6px;
		}
		.file-btn:hover, .action-btn:hover { filter: brightness(1.15); }
		.file-btn:disabled, .action-btn:disabled { opacity: 0.3; cursor: not-allowed; filter: none; }

		.btn-load  { background: var(--accent); text-align: center; }
		.btn-save  { background: var(--ok); }
		.btn-clear { background: var(--danger); margin-bottom: 0; }

		.divider { border: none; border-top: 1px solid var(--border); margin: 12px 0; }

		.status-msg {
			margin-top: 8px; font-size: 11.5px; line-height: 1.4;
			padding: 8px; border-radius: 6px; background: rgba(0,0,0,0.2);
			color: var(--muted); white-space: pre-line; border-left: 3px solid var(--border);
		}
		.status-msg.ok   { color: var(--ok); border-left-color: var(--ok); background: rgba(0, 204, 68, 0.05); }
		.status-msg.warn { color: var(--warn); border-left-color: var(--warn); background: rgba(255, 204, 0, 0.05); }

		input[type="file"] { display: none; }
	</style>
</head>
<body>

	<div id="map"></div>

	<div class="panel">
		<h2>🌊 Water CSV Editor</h2>

		<div class="row">
			<div class="label">지도 스타일</div>
			<div class="btn-group">
				<button class="style-btn active" id="btn-style-sat">🛰️ 위성</button>
				<button class="style-btn" id="btn-style-str">⛰️ 등고선</button>
			</div>
		</div>

		<div class="row">
			<div class="label">현재 서브타일</div>
			<div class="value" id="cell-info">선택 안 됨</div>
			<div class="hint">지도를 클릭해 그리드를 배치하고, 보라색 칸 중 하나를 클릭해 서브타일을 지정하세요.</div>
		</div>

		<hr class="divider">

		<div class="row">
			<label class="file-btn btn-load" for="input-water-csv" id="label-load-csv">📂 water.csv 불러오기</label>
			<input type="file" id="input-water-csv" accept=".csv">
			<button class="action-btn btn-save" id="btn-save-csv" disabled>💾 water.csv로 저장</button>
			<button class="action-btn btn-clear" id="btn-clear-draw" disabled>🗑 편집 내용 지우기</button>
		</div>

		<div class="hint">
			좌상단 Draw 툴로 편집을 수행합니다.<br>
			(닫힌 폴리곤 = 호수/면적, 열린 선 = 강줄기)
		</div>

		<div class="status-msg" id="status-msg">서브타일을 먼저 선택하세요.</div>
	</div>

	<script>
	// ──────────────────────────────────────────────────────────
	// 0. 설정값 및 안전장치 기본값 처리
	// ──────────────────────────────────────────────────────────
	mapboxgl.accessToken = (typeof MAPBOX_TOKEN !== 'undefined') ? MAPBOX_TOKEN : '';

	const TILE_SIZE_KM_ = (typeof TILE_SIZE_KM !== 'undefined') ? TILE_SIZE_KM : 1.024;
	const GRID_N_       = (typeof GRID_N       !== 'undefined') ? GRID_N       : 9;
	const OV_GRID_      = (typeof OV_GRID      !== 'undefined') ? OV_GRID      : 9;

	const initLng  = (typeof state !== 'undefined') ? state.lng  : 127.0;
	const initLat  = (typeof state !== 'undefined') ? state.lat  : 37.5;
	const initZoom = (typeof state !== 'undefined') ? state.zoom : 10;

	const center = { lng: initLng, lat: initLat };
	let selectedCell = null;
	let currentStyle = 'mapbox://styles/mapbox/satellite-streets-v11';

	// 지도 스타일 사전정의 (streets를 등고선이 포함된 outdoors로 적용)
	const mapStyles = {
		streets:   'mapbox://styles/mapbox/outdoors-v11',
		satellite: 'mapbox://styles/mapbox/satellite-streets-v11'
	};

	// ──────────────────────────────────────────────────────────
	// 1. 픽셀(0~1081) ↔ 위경도 변환 공식
	// ──────────────────────────────────────────────────────────
	const ZOOM = 13;
	const MAP_SIZE_KM = 9.216; // = TILE_SIZE_KM_ * GRID_N_

	function long2tile(lon, zoom) { return Math.floor((lon + 180) / 360 * Math.pow(2, zoom)); }
	function lat2tile(lat, zoom) {
		return Math.floor((1 - Math.log(Math.tan(lat * Math.PI / 180) + 1 / Math.cos(lat * Math.PI / 180)) / Math.PI) / 2 * Math.pow(2, zoom));
	}
	function tile2long(x, zoom) { return x / Math.pow(2, zoom) * 360 - 180; }
	function tile2lat(y, zoom) {
		const n = Math.PI - 2 * Math.PI * y / Math.pow(2, zoom);
		return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
	}

	function computeTransform(centerLng, centerLat) {
		const dist = Math.sqrt(2 * Math.pow(MAP_SIZE_KM / 1080 * 1081 / 2, 2));
		const point = turf.point([centerLng, centerLat]);
		const topleft     = turf.destination(point, dist, -45, { units: 'kilometers' }).geometry.coordinates;
		const bottomright = turf.destination(point, dist, 135, { units: 'kilometers' }).geometry.coordinates;

		const x  = long2tile(topleft[0], ZOOM);
		const y  = lat2tile(topleft[1], ZOOM);
		const x2 = long2tile(bottomright[0], ZOOM);
		const y2 = lat2tile(bottomright[1], ZOOM);
		const tileCnt = Math.max(x2 - x + 1, y2 - y + 1);

		const tileLng  = tile2long(x, ZOOM);
		const tileLat  = tile2lat(y, ZOOM);
		const tileLng2 = tile2long(x + tileCnt, ZOOM);
		const tileLat2 = tile2lat(y + tileCnt, ZOOM);

		const distance     = turf.distance(turf.point([tileLng, tileLat]), turf.point([tileLng2, tileLat2]), { units: 'kilometers' }) / Math.SQRT2;
		const topDistance  = turf.distance(turf.point([tileLng, tileLat]), turf.point([tileLng, topleft[1]]), { units: 'kilometers' });
		const leftDistance = turf.distance(turf.point([tileLng, tileLat]), turf.point([topleft[0], tileLat]), { units: 'kilometers' });

		const fullLength = Math.ceil(1080 * (distance / MAP_SIZE_KM));
		const xOffset = Math.round(leftDistance / distance * fullLength);
		const yOffset = Math.round(topDistance / distance * fullLength);

		return { fullLength, xOffset, yOffset, lng0: tileLng, lat0: tileLat, lng1: tileLng2, lat1: tileLat2 };
	}

	function pxToLngLat(px, py, t) {
		const absX = px + t.xOffset;
		const absY = py + t.yOffset;
		const lng = t.lng0 + (absX / t.fullLength) * (t.lng1 - t.lng0);
		const lat = t.lat0 + (absY / t.fullLength) * (t.lat1 - t.lat0);
		return [lng, lat];
	}

	function lngLatToPx(lng, lat, t) {
		const absX = (lng - t.lng0) / (t.lng1 - t.lng0) * t.fullLength;
		const absY = (lat - t.lat0) / (t.lat1 - t.lat0) * t.fullLength;
		return [absX - t.xOffset, absY - t.yOffset];
	}

	// ──────────────────────────────────────────────────────────
	// 2. 지도 및 플러그인 초기화
	// ──────────────────────────────────────────────────────────
	const map = new mapboxgl.Map({
		container: 'map',
		style: currentStyle,
		center: [center.lng, center.lat],
		zoom: initZoom,
		fadeDuration: 0
	});
	map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

	const draw = new MapboxDraw({
		displayControlsDefault: false,
		controls: { point: true, line_string: true, polygon: true, trash: true },
	});
	map.addControl(draw, 'top-left');

	// ──────────────────────────────────────────────────────────
	// 3. 9x9 오버뷰 그리드 생성 함수군
	// ──────────────────────────────────────────────────────────
	function makeOverviewBox() {
		const regionKm = TILE_SIZE_KM_ * GRID_N_;
		const halfKm   = regionKm * OV_GRID_ / 2;
		const centerPt = turf.point([center.lng, center.lat]);

		const nwLat = turf.destination(centerPt, halfKm,   0, { units: 'kilometers' }).geometry.coordinates[1];
		const nwLng = turf.destination(centerPt, halfKm, 270, { units: 'kilometers' }).geometry.coordinates[0];

		const lats = [], lngs = [];
		for (let i = 0; i <= OV_GRID_; i++) {
			lats.push(turf.destination(turf.point([center.lng, nwLat]), regionKm * i, 180, { units: 'kilometers' }).geometry.coordinates[1]);
			lngs.push(turf.destination(turf.point([nwLng, center.lat]), regionKm * i,  90, { units: 'kilometers' }).geometry.coordinates[0]);
		}

		const features = [];
		for (let row = 0; row < OV_GRID_; row++) {
			for (let col = 0; col < OV_GRID_; col++) {
				const n = lats[row], s = lats[row + 1];
				const w = lngs[col], e = lngs[col + 1];
				features.push({
					type: 'Feature',
					geometry: { type: 'Polygon', coordinates: [[[w,n],[e,n],[e,s],[w,s],[w,n]]] },
					properties: { col, row, label: '(' + col + ',' + (OV_GRID_ - 1 - row) + ')', w, e, s, n }
				});
			}
		}
		return { type: 'FeatureCollection', features };
	}

	function makeOverviewLabels(boxFc) {
		const features = boxFc.features.map(f => {
			const ring = f.geometry.coordinates[0];
			const cx = (ring[0][0] + ring[2][0]) / 2;
			const cy = (ring[0][1] + ring[2][1]) / 2;
			return { type: 'Feature', geometry: { type: 'Point', coordinates: [cx, cy] }, properties: f.properties };
		});
		return { type: 'FeatureCollection', features };
	}

	function updateGrid() {
		if (!map.isStyleLoaded()) return;
		const box = makeOverviewBox();
		map.getSource('overview-box').setData(box);
		map.getSource('overview-labels').setData(makeOverviewLabels(box));
		
		if (selectedCell) {
			const f = box.features.find(ft => {
				const lCol = ft.properties.col;
				const lRow = OV_GRID_ - 1 - ft.properties.row;
				return lCol === selectedCell.col && lRow === selectedCell.row;
			});
			map.getSource('selected-cell').setData(f ? { type: 'FeatureCollection', features: [f] } : { type: 'FeatureCollection', features: [] });
			
			const t = selectedCell.transform;
			const corners = [pxToLngLat(0, 0, t), pxToLngLat(1081, 0, t), pxToLngLat(1081, 1081, t), pxToLngLat(0, 1081, t), pxToLngLat(0, 0, t)];
			map.getSource('csv-area').setData({
				type: 'FeatureCollection',
				features: [{ type: 'Feature', geometry: { type: 'Polygon', coordinates: [corners] }, properties: {} }]
			});
		} else {
			map.getSource('selected-cell').setData({ type: 'FeatureCollection', features: [] });
			map.getSource('csv-area').setData({ type: 'FeatureCollection', features: [] });
		}
	}

	function initMapLayers() {
		if (map.getLayer('layer-overview-fill')) return; 

		map.addSource('overview-box', { type: 'geojson', data: makeOverviewBox() });
		map.addLayer({
			id: 'layer-overview-fill', type: 'fill', source: 'overview-box',
			paint: { 'fill-color': '#534AB7', 'fill-opacity': 0.08 }
		});
		map.addLayer({
			id: 'layer-overview-line', type: 'line', source: 'overview-box',
			paint: { 'line-color': '#AFA9EC', 'line-width': 1.2, 'line-opacity': 0.6 }
		});

		map.addSource('overview-labels', { type: 'geojson', data: makeOverviewLabels(makeOverviewBox()) });
		map.addLayer({
			id: 'layer-overview-labels', type: 'symbol', source: 'overview-labels',
			layout: {
				'text-field': ['get', 'label'],
				'text-font': ['Open Sans Semibold', 'Arial Unicode MS Bold'],
				'text-size': 11,
				'text-allow-overlap': true,
				'text-ignore-placement': true,
			},
			paint: { 'text-color': '#AFA9EC', 'text-halo-color': '#1a1a2e', 'text-halo-width': 1.5 }
		});

		map.addSource('selected-cell', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
		map.addLayer({
			id: 'layer-selected-cell', type: 'line', source: 'selected-cell',
			paint: { 'line-color': '#ffcc00', 'line-width': 3 }
		});

		map.addSource('csv-area', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
		map.addLayer({
			id: 'layer-csv-area', type: 'line', source: 'csv-area',
			paint: { 'line-color': '#00ffff', 'line-width': 1.5, 'line-dasharray': [3, 3] }
		});
	}

	map.on('load', () => {
		initMapLayers();
		updateGrid();
	});

	map.on('style.load', () => {
		initMapLayers();
		updateGrid();
	});

	// ──────────────────────────────────────────────────────────
	// 4. 지도 클릭 이벤트 인터랙션
	// ──────────────────────────────────────────────────────────
	map.on('click', e => {
		if (draw.getMode() !== 'simple_select' && draw.getMode() !== 'direct_select') return;
		
		const cellFeatures = map.queryRenderedFeatures(e.point, { layers: ['layer-overview-fill'] });
		if (cellFeatures.length > 0) return; 

		center.lng = e.lngLat.lng;
		center.lat = e.lngLat.lat;
		clearSelection();
		updateGrid();
	});

	map.on('click', 'layer-overview-fill', e => {
		if (draw.getMode() !== 'simple_select' && draw.getMode() !== 'direct_select') return;

		const props  = e.features[0].properties;
		const ovCol  = props.col;
		const ovRow  = props.row;
		const labelCol = ovCol;
		const labelRow = OV_GRID_ - 1 - ovRow;

		const cellCenterLng = (props.w + props.e) / 2;
		const cellCenterLat = (props.s + props.n) / 2;

		selectCell(labelCol, labelRow, cellCenterLng, cellCenterLat);
	});

	map.on('mouseenter', 'layer-overview-fill', () => { if(draw.getMode() === 'simple_select') map.getCanvas().style.cursor = 'pointer'; });
	map.on('mouseleave', 'layer-overview-fill', () => { map.getCanvas().style.cursor = ''; });

	function selectCell(col, row, lng, lat) {
		selectedCell = { col, row, lng, lat, transform: computeTransform(lng, lat) };
		updateGrid();

		document.getElementById('cell-info').textContent = `(${col}, ${row})`;
		document.getElementById('btn-save-csv').disabled = false;
		document.getElementById('btn-clear-draw').disabled = false;
		setStatus(`서브타일 (${col}, ${row}) 선택됨. CSV를 불러오거나 그리기를 시작하세요.`, '');
	}

	function clearSelection() {
		selectedCell = null;
		draw.deleteAll();
		if (map.getSource('selected-cell')) map.getSource('selected-cell').setData({ type: 'FeatureCollection', features: [] });
		if (map.getSource('csv-area'))      map.getSource('csv-area').setData({ type: 'FeatureCollection', features: [] });
		document.getElementById('cell-info').textContent = '선택 안 됨';
		document.getElementById('btn-save-csv').disabled = true;
		document.getElementById('btn-clear-draw').disabled = true;
		setStatus('서브타일을 먼저 선택하세요.', '');
	}

	// ──────────────────────────────────────────────────────────
	// 5. 스타일 토글 로직
	// ──────────────────────────────────────────────────────────
	document.getElementById('btn-style-sat').addEventListener('click', function() {
		if (currentStyle === mapStyles.satellite) return;
		changeMapStyle('satellite');
	});

	document.getElementById('btn-style-str').addEventListener('click', function() {
		if (currentStyle === mapStyles.streets) return;
		changeMapStyle('streets');
	});

	function changeMapStyle(styleKey) {
		currentStyle = mapStyles[styleKey];
		map.setStyle(currentStyle);

		map.once('style.load', () => {
			// 지형 3D 효과 및 음영(등고선)을 위한 DEM 소스 탑재
			if (!map.getSource('mapbox-dem')) {
				map.addSource('mapbox-dem', {
					'type': 'raster-dem',
					'url': 'mapbox://mapbox.mapbox-terrain-rgb',
					'tileSize': 512
				});
			}
			map.setTerrain({ 'source': 'mapbox-dem', 'exaggeration': 1.5 });
			
			// 높이 마커 구조 안전장치 유지
			if (!map.getSource('height-markers')) {
				map.addSource('height-markers', {
					type: 'geojson',
					data: { type: 'FeatureCollection', features: [] }
				});
				map.addLayer({
					id: 'layer-height-markers-circle',
					type: 'circle',
					source: 'height-markers',
					paint: {
						'circle-radius': 10,
						'circle-color': ['get', 'color'],
						'circle-opacity': 0.85,
						'circle-stroke-width': 2,
						'circle-stroke-color': '#ffffff',
					}
				});
				map.addLayer({
					id: 'layer-height-markers-label',
					type: 'symbol',
					source: 'height-markers',
					layout: {
						'text-field': ['get', 'label'],
						'text-font': ['DIN Offc Pro Bold', 'Arial Unicode MS Bold'],
						'text-size': 11,
						'text-offset': [0, 1.8],
						'text-allow-overlap': true,
						'text-ignore-placement': true,
					},
					paint: {
						'text-color': '#ffffff',
						'text-halo-color': '#000000',
						'text-halo-width': 2,
					}
				});
			}

			// 그리드 정보 복구 토글 
			const gridCheckbox = document.getElementById('grid-toggle');
			if (gridCheckbox) {
				toggleGrid(gridCheckbox.checked);
			}
		});

		// UI 활성화 클래스 핸들링 수정
		const btnSat = document.getElementById('btn-style-sat');
		const btnStr = document.getElementById('btn-style-str');
		if (styleKey === 'streets') {
			btnStr.classList.add('active');
			btnSat.classList.remove('active');
		} else {
			btnSat.classList.add('active');
			btnStr.classList.remove('active');
		}
	}

	// ──────────────────────────────────────────────────────────
	// 6. CSV 로드 파서
	// ──────────────────────────────────────────────────────────
	document.getElementById('input-water-csv').addEventListener('change', e => {
		const file = e.target.files[0];
		if (!file) return;

		if (!selectedCell) {
			setStatus('먼저 지도에서 서브타일을 클릭해 선택하세요.', 'warn');
			e.target.value = '';
			return;
		}

		const reader = new FileReader();
		reader.onload = ev => {
			const features = parseWaterCsvToGeoJSON(ev.target.result, selectedCell.transform);
			draw.deleteAll();
			if (features.length > 0) {
				draw.add({ type: 'FeatureCollection', features });
				const bbox = turf.bbox({ type: 'FeatureCollection', features });
				map.fitBounds(bbox, { padding: 60, maxZoom: 17 });
			}
			setStatus(`${file.name} 불러오기 완료: 도형 ${features.length}개`, 'ok');
		};
		reader.onerror = () => setStatus('파일을 읽는 중 오류가 발생했습니다.', 'warn');
		reader.readAsText(file);
		e.target.value = '';
	});

	function parseWaterCsvToGeoJSON(csvText, t) {
		const segMap = new Map();
		const lines = csvText.split('\n');

		for (let i = 1; i < lines.length; i++) {
			const line = lines[i].trim();
			if (!line) continue; 
			const parts = line.split(',');
			if (parts.length < 4) continue;

			const id = parseInt(parts[0]);
			if (isNaN(id)) continue;
			const x = parseFloat(parts[2]);
			const y = parseFloat(parts[3]);
			if (isNaN(x) || isNaN(y)) continue;

			if (!segMap.has(id)) segMap.set(id, []);
			segMap.get(id).push({ x, y });
		}

		const features = [];
		for (const pts of segMap.values()) {
			if (pts.length < 1) continue;

			if (pts.length === 1) {
				const [lng, lat] = pxToLngLat(pts[0].x, pts[0].y, t);
				features.push({ type: 'Feature', properties: {}, geometry: { type: 'Point', coordinates: [lng, lat] } });
				continue;
			}

			const dx = pts[0].x - pts[pts.length - 1].x;
			const dy = pts[0].y - pts[pts.length - 1].y;
			const isClosed = pts.length >= 3 && Math.sqrt(dx * dx + dy * dy) < 2.0;

			const coords = pts.map(p => pxToLngLat(p.x, p.y, t));

			if (isClosed) {
				const ring = coords.slice();
				const first = ring[0], last = ring[ring.length - 1];
				if (first[0] !== last[0] || first[1] !== last[1]) ring.push([first[0], first[1]]);
				features.push({ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [ring] } });
			} else {
				features.push({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } });
			}
		}
		return features;
	}

	// ──────────────────────────────────────────────────────────
	// 7. water.csv 익스포트 저장
	// ──────────────────────────────────────────────────────────
	document.getElementById('btn-save-csv').addEventListener('click', () => {
		if (!selectedCell) {
			setStatus('먼저 서브타일을 선택하세요.', 'warn');
			return;
		}

		const all = draw.getAll().features;
		if (all.length === 0) {
			setStatus('저장할 도형이 없습니다.', 'warn');
			return;
		}

		const t = selectedCell.transform;
		let csv = 'river_id,point_index,x,y\n';
		let riverId = 0;

		for (const f of all) {
			let ringList = [];

			if (f.geometry.type === 'Polygon') {
				ringList = [f.geometry.coordinates[0]];
			} else if (f.geometry.type === 'LineString') {
				ringList = [f.geometry.coordinates];
			} else if (f.geometry.type === 'Point') {
				ringList = [[f.geometry.coordinates]];
			} else {
				continue; 
			}

			for (const coords of ringList) {
				let pts = coords.map(c => lngLatToPx(c[0], c[1], t));

				if (f.geometry.type === 'Polygon' && pts.length > 1) {
					const a = pts[0], b = pts[pts.length - 1];
					if (Math.abs(a[0] - b[0]) < 1e-6 && Math.abs(a[1] - b[1]) < 1e-6) pts = pts.slice(0, -1);
				}

				pts.forEach((p, idx) => {
					csv += `${riverId},${idx},${p[0].toFixed(2)},${p[1].toFixed(2)}\n`;
				});
				riverId++;
			}
		}

		const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
		const filename = `water_${selectedCell.col}_${selectedCell.row}.csv`;
		downloadBlob(filename, blob);
		setStatus(`${filename} 저장 완료 (도형 ${riverId}개)`, 'ok');
	});

	document.getElementById('btn-clear-draw').addEventListener('click', () => {
		if (confirm('현재 편집 중인 모든 그래픽 요소를 캔버스에서 제거하시겠습니까?')) {
			draw.deleteAll();
			setStatus('편집 내용을 모두 지웠습니다.', '');
		}
	});

	function downloadBlob(filename, blob) {
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}

	function setStatus(msg, type) {
		const el = document.getElementById('status-msg');
		el.textContent = msg;
		el.className = 'status-msg' + (type ? ' ' + type : '');
	}
	</script>
</body>
</html>