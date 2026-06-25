<!DOCTYPE html>
<html lang="ko">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Water GIS Desktop</title>

	<link href="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.css" rel="stylesheet" />
	<script src="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.js"></script>
	<link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-draw/v1.4.3/mapbox-gl-draw.css" type="text/css" />
	<script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-draw/v1.4.3/mapbox-gl-draw.js"></script>
	<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
	<script src="js/config.js" onerror="console.warn('config.js 없음 — 기본값 사용')"></script>
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-dragdata@2.2.5/dist/chartjs-plugin-dragdata.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

	<style>
		/* ══════════════════════════════
		   CSS 변수 (디자인 토큰)
		   ══════════════════════════════ */
		:root {
			--bg:       #1a1a2e;
			--surface:  #232342;
			--border:   #3a3a5c;
			--accent:   #534AB7;
			--accent2:  #AFA9EC;
			--text:     #eee;
			--muted:    #999;
			--ok:       #00cc44;
			--warn:     #ffcc00;
			--danger:   #b33939;
		}

		.btn-water-raw { background: #1a6b8a; }

		html, body {
			margin: 0; padding: 0; height: 100%;
			font-family: -apple-system, "Segoe UI", "Malgun Gothic", sans-serif;
			background: var(--bg); overflow: hidden;
		}

		/* ── 상단 앱 바 ── */
		.app-bar {
			position: absolute; top: 0; left: 0; right: 0; height: 50px;
			background: var(--surface); border-bottom: 1px solid var(--border);
			display: flex; align-items: center; justify-content: space-between;
			padding: 0 16px; z-index: 10; box-shadow: 0 2px 10px rgba(0,0,0,.4);
		}
		.app-title {
			font-size: 15px; font-weight: bold; color: var(--accent2);
			display: flex; align-items: center; gap: 6px; white-space: nowrap;
		}
		.bar-group {
			display: flex; align-items: center;
			background: #2b2b4d; border: 1px solid var(--border);
			border-radius: 6px; padding: 4px 10px; gap: 8px;
		}
		.bar-group + .bar-group { margin-left: 10px; }
		.group-label {
			font-size: 10px; color: var(--accent2); font-weight: bold;
			text-transform: uppercase; letter-spacing: .5px;
			padding-right: 8px; border-right: 1px solid var(--border);
		}

		/* ── 공통 버튼 ── */
		.btn {
			display: inline-flex; align-items: center; gap: 4px;
			padding: 0 10px; height: 26px; border-radius: 4px;
			border: none; cursor: pointer; font-size: 11.5px; font-weight: 600;
			color: #fff; transition: filter .15s; background: #4a4a75;
			white-space: nowrap;
		}
		.btn:hover  { filter: brightness(1.25); }
		.btn:disabled { opacity: .3; cursor: not-allowed; filter: none; }
		.btn-save   { background: var(--ok); }
		.btn-clear  { background: var(--danger); }
		.btn-sat    { background: #34495e; }
		.btn-str    { background: #34495e; }
		.btn-sat.active, .btn-str.active {
			background: var(--accent); border: 1px solid var(--accent2);
		}

		/* ── 지역 뱃지 ── */
		.region-badge {
			display: flex; align-items: center; gap: 6px;
			background: rgba(83,74,183,.25); border: 1px solid var(--accent);
			border-radius: 4px; padding: 0 10px; height: 26px;
			font-size: 12px; color: var(--accent2); font-weight: 600;
		}
		.region-dot {
			width: 6px; height: 6px; border-radius: 50%;
			background: var(--ok); flex-shrink: 0;
		}

		/* ── 왼쪽 사이드바 ── */
		.sidebar {
			position: absolute; top: 50px; left: 0; bottom: 0; width: 180px;
			background: var(--surface); border-right: 1px solid var(--border);
			z-index: 5; display: flex; flex-direction: column; overflow: hidden;
		}
		.sidebar-title {
			padding: 10px 12px 6px;
			font-size: 10px; font-weight: bold; color: var(--accent2);
			text-transform: uppercase; letter-spacing: .5px;
			border-bottom: 1px solid var(--border); flex-shrink: 0;
			display: flex; align-items: center; justify-content: space-between;
		}
		.sidebar-list {
			overflow-y: auto; flex: 1; padding: 6px 0;
		}
		.sidebar-list::-webkit-scrollbar { width: 4px; }
		.sidebar-list::-webkit-scrollbar-track { background: transparent; }
		.sidebar-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
		.region-item {
			display: flex; align-items: center; gap: 8px;
			padding: 7px 12px; cursor: pointer; font-size: 12px; color: var(--text);
			transition: background .12s; border-left: 3px solid transparent;
			white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
		}
		.region-item:hover { background: rgba(175,169,236,.08); }
		.region-item.active {
			background: rgba(83,74,183,.2); border-left-color: var(--accent2);
			color: var(--accent2); font-weight: 600;
		}
		.region-item .dot {
			width: 6px; height: 6px; border-radius: 50%;
			background: var(--muted); flex-shrink: 0;
		}
		.region-item.active .dot { background: var(--ok); }
		.sidebar-empty {
			padding: 16px 12px; font-size: 11px; color: var(--muted); line-height: 1.5;
		}
		.sidebar-refresh {
			cursor: pointer; color: var(--muted); font-size: 13px;
			transition: color .15s;
		}
		.sidebar-refresh:hover { color: var(--accent2); }

		/* ── 맵 ── */
		#map { position: absolute; top: 50px; left: 180px; right: 0; bottom: 0; }

		/* ── 우상단 정보 패널 ── */
		.panel {
			position: absolute; top: 64px; right: 14px; z-index: 5;
			width: 250px; background: rgba(35,35,66,.93); color: var(--text);
			border: 1px solid var(--border); border-radius: 8px;
			padding: 12px; box-shadow: 0 4px 16px rgba(0,0,0,.5); font-size: 12px;
		}
		.panel-row  { display: flex; flex-direction: column; gap: 3px; margin-bottom: 8px; }
		.panel-label { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
		.panel-value { font-size: 14px; font-weight: 600; color: #fff; }
		.panel-hint  { font-size: 11px; color: var(--muted); line-height: 1.4; margin-top: 2px; }
		.divider { border: none; border-top: 1px solid var(--border); margin: 8px 0; }

		/* ── 좌표 입력 ── */
		.coord-input {
			background: var(--bg); border: 1px solid var(--border); color: #fff;
			padding: 0 8px; height: 26px; border-radius: 4px; font-size: 12px;
			width: 110px; box-sizing: border-box;
		}
		.coord-input:focus { outline: none; border-color: var(--accent2); }
		.coord-input::placeholder { color: var(--muted); }
		.btn-confirm { background: #b07d20; }
		.btn-confirm:hover { filter: brightness(1.3); }
		.btn-file { background: #2471a3; cursor: pointer; }

		/* ── 상태 메시지 ── */
		.status {
			font-size: 11px; line-height: 1.4; padding: 6px 8px;
			border-radius: 4px; background: rgba(0,0,0,.3);
			color: var(--muted); white-space: pre-line;
			border-left: 3px solid var(--border);
		}
		.status.ok   { color: var(--ok);   border-left-color: var(--ok);   background: rgba(0,204,68,.04);  }
		.status.warn { color: var(--warn); border-left-color: var(--warn); background: rgba(255,204,0,.04); }

		/* ── 로딩 오버레이 ── */
		.loader {
			position: absolute; inset: 0; background: rgba(26,26,46,.6);
			display: flex; align-items: center; justify-content: center;
			z-index: 20; pointer-events: all; opacity: 0;
			transition: opacity .2s; visibility: hidden;
		}
		.loader.show { opacity: 1; visibility: visible; }
		.loader-inner {
			background: var(--surface); border: 1px solid var(--border);
			border-radius: 8px; padding: 20px 28px;
			display: flex; align-items: center; gap: 12px;
			font-size: 13px; color: var(--accent2);
		}
		.spinner {
			width: 18px; height: 18px; border: 2px solid var(--border);
			border-top-color: var(--accent2); border-radius: 50%;
			animation: spin .7s linear infinite;
		}
		@keyframes spin { to { transform: rotate(360deg); } }

		/* ── 도형 목록 아이템 ── */
		.shape-item {
			display: flex; align-items: center; gap: 8px;
			padding: 6px 12px; cursor: pointer; font-size: 11px; color: var(--text);
			transition: background .12s; border-left: 3px solid transparent;
			white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
		}
		.shape-item:hover { background: rgba(175,169,236,.08); }
		.shape-item.active {
			background: rgba(83,74,183,.2); border-left-color: #ffcc00;
			color: #ffcc00; font-weight: 600;
		}
		.shape-icon { font-size: 12px; flex-shrink: 0; }
		.shape-sub  { font-size: 10px; color: var(--muted); margin-left: auto; flex-shrink: 0; }
	</style>
</head>
<body>

<!-- 지형 고도 쿼리용 숨겨진 iframe (terrain_elevation.php와 postMessage 통신) -->
<iframe
    id="elevation-frame"
    src="terrain_elevation.php"
    style="position:absolute; visibility:hidden; width:1px; height:1px; top:-9999px;"
    scrolling="no">
</iframe>

<!-- 상단 앱 바 -->
<div class="app-bar">
	<!-- 좌: 타이틀 + 현재 지역 -->
	<div style="display:flex;align-items:center;gap:10px;flex:1;">
		<div class="app-title">🌊 Water GIS Desktop</div>
		<div class="bar-group">
			<div class="group-label">지역</div>
			<div class="region-badge">
				<span class="region-dot"></span>
				<span id="region-name-display">로딩 중...</span>
			</div>
		</div>
		<div class="bar-group">
			<div class="group-label">뷰어</div>
			<button onclick="location.href='water_mesh.php';" style="
				display: inline-flex; align-items: center; gap: 6px;
				background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
				border: 1px solid rgba(255,255,255,0.15); color: #fff;
				font-size: 12px; font-weight: 600; cursor: pointer;
				border-radius: 5px; padding: 0 12px; height: 26px;
				white-space: nowrap; letter-spacing: 0.3px;
				box-shadow: 0 2px 6px rgba(13,110,253,0.35); transition: all 0.18s ease;
			"
			onmouseover="this.style.background='linear-gradient(135deg,#1a7aff 0%,#1562e0 100%)';this.style.boxShadow='0 3px 10px rgba(13,110,253,0.5)';"
			onmouseout="this.style.background='linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%)';this.style.boxShadow='0 2px 6px rgba(13,110,253,0.35)';"
			onmousedown="this.style.transform='scale(0.97)';"
			onmouseup="this.style.transform='scale(1)';">
				🗺️ WaterMesh View
			</button>
		</div>
	</div>

	<!-- 중앙: 좌표 이동 + 지역 확정 -->
	<div class="bar-group" style="flex-shrink:0;">
		<div class="group-label">중심 좌표</div>
		<input type="number" id="input-lng" class="coord-input" placeholder="경도 (Lng)" step="0.0001">
		<input type="number" id="input-lat" class="coord-input" placeholder="위도 (Lat)" step="0.0001">
		<button class="btn" id="btn-goto" title="Enter">↩ 이동</button>
		<div style="width:1px;height:18px;background:var(--border);margin:0 2px;"></div>
		<button class="btn btn-confirm" id="btn-confirm-region" title="지역명을 입력하고 현재 위치를 확정합니다">📌 지역 확정</button>
	</div>

	<!-- 우: 뷰 전환 + 파일 + 작업 -->
	<div style="display:flex;align-items:center;gap:10px;flex:1;justify-content:flex-end;">
		<div class="bar-group">
			<div class="group-label">뷰</div>
			<button class="btn btn-str active" id="btn-str">⛰️ 등고선</button>
			<button class="btn btn-sat" id="btn-sat">🛰️ 위성</button>
		</div>
		<div class="bar-group">
			<div class="group-label">파일</div>
			<label class="btn btn-file" for="input-csv" id="label-csv">📂 CSV / Waterway 열기</label>
			<input type="file" id="input-csv" accept=".csv" style="display:none;">
			<button class="btn btn-file" id="btn-csv-export" onclick="exportCSV()" disabled>📥 CSV 내보내기</button>
		</div>
		<div class="bar-group">
			<div class="group-label">작업</div>
			<button class="btn btn-save"      id="btn-db-save"   disabled>🚀 DB 저장</button>
			<button class="btn btn-water-raw" id="btn-water-raw" disabled>📐 수면 RAW 생성</button>
			<button class="btn" id="btn-water-bitmap" disabled style="background:#117a65;">🗺️ 수면 비트맵</button>
			<button class="btn"               id="btn-water-png"  disabled style="background:#1a5276;">🖼️ 수면 PNG 생성</button>
			<button class="btn btn-clear"     id="btn-clear"     disabled>🗑️ 전체 지우기</button>
		</div>
	</div>
</div>

<!-- 왼쪽 사이드바 -->
<div class="sidebar">
	<div class="sidebar-title">
		<span>지역 목록</span>
		<span class="sidebar-refresh" id="btn-refresh-regions" title="새로고침">↻</span>
	</div>
	<div class="sidebar-list" id="region-list">
		<div class="sidebar-empty">로딩 중...</div>
	</div>
	<!-- 도형 목록 -->
	<div class="sidebar-title" id="shape-list-title" style="border-top:1px solid var(--border);">
		<span>도형 목록 <span id="shape-count" style="color:var(--muted);font-weight:normal;"></span></span>
	</div>
	<div class="sidebar-list" id="shape-list" style="max-height:820px;">
		<div class="sidebar-empty">셀을 선택하면 목록이 표시됩니다.</div>
	</div>
</div>

<!-- 맵 컨테이너 -->
<div id="map"></div>

<!-- Z 프로파일 하단 패널 -->
<div id="z-profile-panel" style="
    display:none; position:absolute; left:180px; right:0; bottom:0;
    height:180px; background:rgba(26,26,46,.95);
    border-top:1px solid var(--border); z-index:6; flex-direction:column;">

	<!-- 패널 헤더 -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 14px;border-bottom:1px solid var(--border);flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:12px;flex-shrink:0;">
            <span style="font-size:13px;font-weight:700;color:var(--accent2);text-transform:uppercase;letter-spacing:.5px;">
                📊 Z 프로파일
            </span>
            <span id="z-profile-title" style="font-size:13px;color:var(--muted);"></span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <button onclick="fetchTerrainElevation()" style="
                background:#1a6fa8;border:none;color:#fff;
                font-size:12px;font-weight:600;cursor:pointer;
                border-radius:4px;padding:0 10px;height:24px;
                white-space:nowrap;display:flex;align-items:center;gap:4px;"
                title="Mapbox 지형 고도로 Z값 자동 설정">
                🏔️ 지형 고도 자동조정
            </button>
            <button onclick="applyUniformZ()" style="
                background:#6c3483;border:none;color:#fff;
                font-size:12px;font-weight:600;cursor:pointer;
                border-radius:4px;padding:0 10px;height:24px;white-space:nowrap;"
                title="선택된 시작점 Z값으로 모든 점 일괄 설정">
                📐 높이 일괄(클릭)
            </button>
            <button onclick="applyUniformZInput()" style="
                background:#6c3483;border:none;color:#fff;
                font-size:12px;font-weight:600;cursor:pointer;
                border-radius:4px;padding:0 10px;height:24px;white-space:nowrap;"
                title="값을 입력해 선택된 폴리곤 모든 점 일괄 설정">
                ✏️ 높이 일괄(값입력)
            </button>
            <button onclick="applyAllPolygonsZ()" style="
                background:#b94000;border:none;color:#fff;
                font-size:12px;font-weight:600;cursor:pointer;
                border-radius:4px;padding:0 10px;height:24px;white-space:nowrap;"
                title="모든 폴리곤 Z값 일괄 설정">
                🌐 전체 높이 설정
            </button>
            <div style="width:1px;height:18px;background:var(--border);margin:0 4px;"></div>
            <button onclick="closeZPanel()" style="
                background:none;border:none;color:var(--muted);
                font-size:18px;cursor:pointer;line-height:1;padding:0 4px;"
                title="닫기">×</button>
        </div>
    </div>

	<!-- 선형보간 확인 버튼 (우클릭 구간 선택 후 표시) -->
    <div id="interp-buttons" style="display:none;align-items:center;gap:6px;padding:4px 14px;border-bottom:1px solid var(--border);flex-shrink:0;">
        <span style="font-size:13px;color:var(--warn);">📐 선형보간</span>
        <button onclick="confirmInterp()" style="
            background:#1e8449;border:none;color:#fff;
            font-size:13px;font-weight:600;cursor:pointer;
            border-radius:4px;padding:0 10px;height:22px;">✔ 적용</button>
        <button onclick="cancelInterp()" style="
            background:#922b21;border:none;color:#fff;
            font-size:13px;font-weight:600;cursor:pointer;
            border-radius:4px;padding:0 10px;height:22px;">✖ 취소</button>
    </div>

	<!-- 차트 영역 -->
    <div style="flex:1;padding:8px 14px;min-height:0;">
        <canvas id="z-chart"></canvas>
    </div>
</div>

<!-- 우상단 정보 패널 -->
<div class="panel">
	<div class="panel-row">
		<div class="panel-label">뷰포트 스타일</div>
		<div class="panel-hint" id="style-hint">등고선 지형도</div>
	</div>
	<div class="divider"></div>
	<div class="panel-row">
		<div class="panel-label">선택된 서브타일</div>
		<div class="panel-value" id="cell-info">선택 안 됨</div>
		<div class="panel-hint">지도를 클릭해 그리드를 이동하고,<br>내부 셀을 클릭해 데이터를 불러오세요.</div>
	</div>
	<div class="status" id="status">서브타일을 먼저 선택하세요.</div>
</div>

<!-- 로딩 오버레이 -->
<div class="loader" id="loader">
	<div class="loader-inner">
		<div class="spinner"></div>
		<span id="loader-msg">데이터 로딩 중...</span>
	</div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     메인 스크립트
     ══════════════════════════════════════════════════════════════ -->
<script>
"use strict";

/* ════════════════════════════════════════════
   §0. 전역 설정 (config.js에서 주입, 없으면 기본값)
   ════════════════════════════════════════════ */
mapboxgl.accessToken = (typeof MAPBOX_TOKEN  !== 'undefined') ? MAPBOX_TOKEN  : '';

const CFG = {
	region:    (typeof DEFAULT_REGION    !== 'undefined') ? DEFAULT_REGION    : 'null',
	type:      (typeof DEFAULT_TYPE      !== 'undefined') ? DEFAULT_TYPE      : 'water',
	tileSizeKm:(typeof TILE_SIZE_KM      !== 'undefined') ? TILE_SIZE_KM      : 1.024,
	gridN:     (typeof GRID_N            !== 'undefined') ? GRID_N            : 9,
	ovGrid:    (typeof OV_GRID           !== 'undefined') ? OV_GRID           : 9,
	initLng:   (typeof STATE_LNG         !== 'undefined') ? STATE_LNG         : 0.51047,
	initLat:   (typeof STATE_LAT         !== 'undefined') ? STATE_LAT         : 0.91962,
	initZoom:  (typeof STATE_ZOOM        !== 'undefined') ? STATE_ZOOM        : 1,
};

document.getElementById('region-name-display').textContent = CFG.region;


/* ════════════════════════════════════════════
   §1. 좌표 변환 유틸 (위경도 ↔ 픽셀 0~1081)
   ════════════════════════════════════════════ */

/** 경도 → 타일 X (정수) */
function long2tile(lon, zoom) {
	return Math.floor((lon + 180) / 360 * Math.pow(2, zoom));
}
/** 위도 → 타일 Y (정수) */
function lat2tile(lat, zoom) {
	return Math.floor(
		(1 - Math.log(Math.tan(lat * Math.PI / 180) + 1 / Math.cos(lat * Math.PI / 180)) / Math.PI)
		/ 2 * Math.pow(2, zoom)
	);
}
/** 경도 → 타일 X (실수) */
function lng2tileF(lng, zoom) {
	return (lng + 180) / 360 * Math.pow(2, zoom);
}
/** 위도 → 타일 Y (실수) */
function lat2tileF(lat, zoom) {
	const s = Math.sin(lat * Math.PI / 180);
	return (1 - Math.log((1 + s) / (1 - s)) / (2 * Math.PI)) / 2 * Math.pow(2, zoom);
}
/** 타일 X → 경도 */
function tile2long(x, z) {
	return x / Math.pow(2, z) * 360 - 180;
}
/** 타일 Y → 위도 */
function tile2lat(y, z) {
	const n = Math.PI - 2 * Math.PI * y / Math.pow(2, z);
	return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
}

/**
 * 중심 좌표를 기준으로 CSV 픽셀 ↔ 위경도 변환에 필요한 transform 객체를 계산한다.
 * CSV 생성 시 mapSize = 9.216km 기준과 반드시 동일해야 한다.
 */
function computeTransform(centerLng, centerLat) {
	const zoom    = 13;
	const mapSize = 9.216;

	// 중심에서 대각선 거리 → 타일 범위 산출
	const dist    = Math.sqrt(2 * Math.pow(mapSize / 1080 * 1081 / 2, 2));
	const point   = turf.point([centerLng, centerLat]);
	const topLeft = turf.destination(point, dist, -45,  { units: 'kilometers' }).geometry.coordinates;
	const botRight= turf.destination(point, dist,  135, { units: 'kilometers' }).geometry.coordinates;

	const x       = long2tile(topLeft[0], zoom);
	const y       = lat2tile(topLeft[1],  zoom);
	const x2      = long2tile(botRight[0], zoom);
	const y2      = lat2tile(botRight[1],  zoom);
	const tileCnt = Math.max(x2 - x + 1, y2 - y + 1);

	const tileLng = tile2long(x, zoom);
	const tileLat = tile2lat(y,  zoom);

	// 타일 전체 크기(km) → fullLength(픽셀) 산출
	const distance = turf.distance(
		turf.point([tileLng, tileLat]),
		turf.point([tile2long(x + tileCnt, zoom), tile2lat(y + tileCnt, zoom)]),
		{ units: 'kilometers' }
	) / Math.SQRT2;

	// 타일 원점에서 크롭 오프셋(픽셀) 산출
	const topDist  = turf.distance(turf.point([tileLng, tileLat]), turf.point([tileLng, topLeft[1]]), { units: 'kilometers' });
	const leftDist = turf.distance(turf.point([tileLng, tileLat]), turf.point([topLeft[0], tileLat]), { units: 'kilometers' });

	const fullLength = Math.ceil(1080 * (distance / mapSize));
	const cropX = Math.round(leftDist / distance * fullLength);
	const cropY = Math.round(topDist  / distance * fullLength);

	return { zoom, x, y, tileCnt, fullLength, cropX, cropY, centerLng, centerLat };
}

/** 픽셀 좌표 → [lng, lat] */
function px2ll(px, py, t) {
	const tileFx = t.x + (px + t.cropX) * t.tileCnt / t.fullLength;
	const tileFy = t.y + (py + t.cropY) * t.tileCnt / t.fullLength;
	return [tile2long(tileFx, t.zoom), tile2lat(tileFy, t.zoom)];
}

/** [lng, lat] → 픽셀 좌표 */
function ll2px(lng, lat, t) {
	const tileFx = lng2tileF(lng, t.zoom);
	const tileFy = lat2tileF(lat, t.zoom);
	const px = (tileFx - t.x) * t.fullLength / t.tileCnt - t.cropX;
	const py = (tileFy - t.y) * t.fullLength / t.tileCnt - t.cropY;
	return [px, py];
}


/* ════════════════════════════════════════════
   §2. Mapbox 맵 & Draw 초기화
   ════════════════════════════════════════════ */
const center = { lng: CFG.initLng, lat: CFG.initLat };
let selectedCell = null;
let currentStyle = 'streets';

const STYLES = {
	satellite: 'mapbox://styles/mapbox/satellite-streets-v11',
	streets:   'mapbox://styles/mapbox/outdoors-v11',
};

const map = new mapboxgl.Map({
	container: 'map',
	style: STYLES.streets,
	center: [center.lng, center.lat],
	zoom: CFG.initZoom,
	fadeDuration: 0,
});
map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

const draw = new MapboxDraw({
	displayControlsDefault: false,
	controls: { point: true, line_string: true, polygon: true, trash: true },
});
map.addControl(draw, 'top-left');


/* ════════════════════════════════════════════
   §3. 그리드 오버레이 (9×9 서브타일)
   ════════════════════════════════════════════ */
function buildSubGridFC(cell) {
    const regionKm  = CFG.tileSizeKm * CFG.gridN;  // 셀 하나의 크기(km)
    const subSize   = regionKm / 9;                 // 서브셀 하나의 크기(km)
    const c         = turf.point([cell.lng, cell.lat]);

    const nwLat = turf.destination(c, regionKm/2,   0, { units:'kilometers' }).geometry.coordinates[1];
    const nwLng = turf.destination(c, regionKm/2, 270, { units:'kilometers' }).geometry.coordinates[0];

    const lats = [], lngs = [];
    for (let i = 0; i <= 9; i++) {
        lats.push(turf.destination(turf.point([cell.lng, nwLat]), subSize * i, 180, { units:'kilometers' }).geometry.coordinates[1]);
        lngs.push(turf.destination(turf.point([nwLng, cell.lat]), subSize * i,  90, { units:'kilometers' }).geometry.coordinates[0]);
    }

    const features = [];
    for (let r = 0; r < 9; r++) {
        for (let c2 = 0; c2 < 9; c2++) {
            const [n, s, w, e] = [lats[r], lats[r+1], lngs[c2], lngs[c2+1]];
            features.push({
                type: 'Feature',
                geometry: { type:'Polygon', coordinates:[[[w,n],[e,n],[e,s],[w,s],[w,n]]] },
                properties: { subCol: c2, subRow: 8-r },
            });
        }
    }
    return { type:'FeatureCollection', features };
}


/** 현재 center 기준으로 ovGrid×ovGrid 셀 Feature 배열 생성 */
function buildGridFC() {
	const regionKm = CFG.tileSizeKm * CFG.gridN;
	const half     = regionKm * CFG.ovGrid / 2;
	const c        = turf.point([center.lng, center.lat]);

	const nwLat = turf.destination(c, half,   0, { units: 'kilometers' }).geometry.coordinates[1];
	const nwLng = turf.destination(c, half, 270, { units: 'kilometers' }).geometry.coordinates[0];

	const lats = [], lngs = [];
	for (let i = 0; i <= CFG.ovGrid; i++) {
		lats.push(turf.destination(turf.point([center.lng, nwLat]), regionKm * i, 180, { units: 'kilometers' }).geometry.coordinates[1]);
		lngs.push(turf.destination(turf.point([nwLng, center.lat]), regionKm * i,  90, { units: 'kilometers' }).geometry.coordinates[0]);
	}

	const features = [];
	for (let r = 0; r < CFG.ovGrid; r++) {
		for (let c2 = 0; c2 < CFG.ovGrid; c2++) {
			const [n, s, w, e] = [lats[r], lats[r+1], lngs[c2], lngs[c2+1]];
			features.push({
				type: 'Feature',
				geometry: { type: 'Polygon', coordinates: [[[w,n],[e,n],[e,s],[w,s],[w,n]]] },
				properties: { col: c2, row: CFG.ovGrid-1-r, label: `(${c2},${CFG.ovGrid-1-r})`, w, e, s, n },
			});
		}
	}
	return { type: 'FeatureCollection', features };
}

/** 그리드 셀 중심점 라벨 FC 생성 */
function buildLabelFC(gridFC) {
	return {
		type: 'FeatureCollection',
		features: gridFC.features.map(f => {
			const ring = f.geometry.coordinates[0];
			return {
				type: 'Feature',
				geometry: { type: 'Point', coordinates: [(ring[0][0]+ring[2][0])/2, (ring[0][1]+ring[2][1])/2] },
				properties: f.properties,
			};
		}),
	};
}

/** 맵 레이어 초기화 (style 로드 후 1회) */
function initMapLayers() {
	if (map.getLayer('ov-fill')) return;

	map.addSource('ov-box',   { type: 'geojson', data: buildGridFC() });
	map.addSource('ov-label', { type: 'geojson', data: buildLabelFC(buildGridFC()) });
	map.addSource('sel-cell', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
	map.addSource('csv-area', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });

	map.addLayer({ id:'ov-fill', type:'fill',   source:'ov-box',   paint:{ 'fill-color':'#534AB7', 'fill-opacity':0.06 } });
	map.addLayer({ id:'ov-line', type:'line',   source:'ov-box',   paint:{ 'line-color':'#AFA9EC', 'line-width':3, 'line-opacity':.5 } });
	map.addLayer({
		id:'ov-lbl', type:'symbol', source:'ov-label',
		layout:{ 'text-field':['get','label'], 'text-font':['Open Sans Semibold','Arial Unicode MS Bold'], 'text-size':11, 'text-allow-overlap':true },
		paint:{ 'text-color':'#AFA9EC', 'text-halo-color':'#1a1a2e', 'text-halo-width':1.5 },
	});
	map.addLayer({ id:'sel-cell', type:'line', source:'sel-cell', paint:{ 'line-color':'#ffcc00', 'line-width':5 } });
	map.addLayer({ id:'csv-area', type:'line', source:'csv-area', paint:{ 'line-color':'#00ffff', 'line-width':1.5, 'line-dasharray':[3,3] } });
	
	map.addSource('sub-grid', { type:'geojson', data:{ type:'FeatureCollection', features:[] } });
	map.addLayer({ id:'sub-grid-line', type:'line', source:'sub-grid', paint:{ 'line-color':'#ff0000', 'line-width':1, 'line-opacity':.6, 'line-dasharray':[2,2] }});
}

/** 그리드·선택 셀·CSV 영역 표시 갱신 */
function refreshGrid() {
	if (!map.isStyleLoaded()) return;
	const grid = buildGridFC();
	map.getSource('ov-box').setData(grid);
	map.getSource('ov-label').setData(buildLabelFC(grid));

	if (selectedCell) {
		// 선택된 셀 테두리
		const f = grid.features.find(ft => ft.properties.col === selectedCell.col && ft.properties.row === selectedCell.row);
		map.getSource('sel-cell').setData(f ? { type:'FeatureCollection', features:[f] } : { type:'FeatureCollection', features:[] });
		// CSV 좌표 영역 표시
		const t = selectedCell.transform;
		const corners = [[0,0],[1080,0],[1080,1080],[0,1080],[0,0]].map(([x,y]) => px2ll(x, y, t));
		map.getSource('csv-area').setData({ type:'FeatureCollection', features:[{ type:'Feature', geometry:{ type:'Polygon', coordinates:[corners] }, properties:{} }] });
	} else {
		map.getSource('sel-cell').setData({ type:'FeatureCollection', features:[] });
		map.getSource('csv-area').setData({ type:'FeatureCollection', features:[] });
	}
}

// 스타일 로드 완료 시 레이어 초기화
map.on('load',       () => { initMapLayers(); refreshGrid(); });
map.on('style.load', () => { initMapLayers(); refreshGrid(); });


/* ════════════════════════════════════════════
   §4. 맵 클릭 인터랙션
   ════════════════════════════════════════════ */

/**
 * 좌클릭: 꼭짓점 20px 이내 클릭 시 Z 차트 시작점 하이라이트
 */
map.on('click', e => {
	if (!_activeFeatureId) return;
	const feature = draw.get(_activeFeatureId);
	if (!feature) return;

	const coords = feature.geometry.type === 'Polygon'
		? feature.geometry.coordinates[0].slice(0, -1)
		: feature.geometry.coordinates;

	// 가장 가까운 꼭짓점 탐색
	let minDist = Infinity, minIndex = -1;
	coords.forEach(([lng, lat], i) => {
		const pt = map.project([lng, lat]);
		const dx = e.point.x - pt.x;
		const dy = e.point.y - pt.y;
		const d  = Math.sqrt(dx*dx + dy*dy);
		if (d < minDist) { minDist = d; minIndex = i; }
	});

	if (minDist <= 20 && minIndex !== -1) highlightZBar(minIndex, 'start');
});

/**
 * 우클릭: 끝점 선택 후 선형보간 구간 확정
 */
map.on('contextmenu', e => {
	if (!_activeFeatureId || _startIndex === null) return;
	const feature = draw.get(_activeFeatureId);
	if (!feature) return;

	const coords = feature.geometry.type === 'Polygon'
		? feature.geometry.coordinates[0].slice(0, -1)
		: feature.geometry.coordinates;

	let minDist = Infinity, minIndex = -1;
	coords.forEach(([lng, lat], i) => {
		const pt = map.project([lng, lat]);
		const dx = e.point.x - pt.x;
		const dy = e.point.y - pt.y;
		const d  = Math.sqrt(dx*dx + dy*dy);
		if (d < minDist) { minDist = d; minIndex = i; }
	});

	if (minDist <= 20 && minIndex !== -1 && minIndex !== _startIndex) {
		e.preventDefault();
		highlightZBar(minIndex, 'end');

		const from  = Math.min(_startIndex, minIndex);
		const to    = Math.max(_startIndex, minIndex);
		const zVals = zChartInstance.data.datasets[0].data;
		document.getElementById('z-profile-title').textContent =
			`#${from+1} ~ #${to+1} (${to-from+1}개) · Z ${zVals[from]} → ${zVals[to]}`;
		showInterpButtons(true);
		setStatus(`#${from+1} ~ #${to+1} 구간 선택됨 — 적용 또는 취소를 누르세요`, 'warn');
	}
});

// 그리드 셀 클릭 → selectCell 호출
map.on('click', 'ov-fill', e => {
	if (!['simple_select','direct_select'].includes(draw.getMode())) return;
	const p = e.features[0].properties;
	// 이미 선택된 셀 재클릭 무시 (Draw 도형 삭제 방지)
	if (selectedCell && selectedCell.col === p.col && selectedCell.row === p.row) return;
	selectCell(p.col, p.row, (p.w + p.e) / 2, (p.s + p.n) / 2);
});

// Draw 이벤트 디버그 로그
map.on('draw.create', e => console.log('[draw.create]', e.features.length, '개 생성'));
map.on('draw.delete', e => console.log('[draw.delete]', e.features.length, '개 삭제'));
map.on('draw.update', e => console.log('[draw.update]', e.features.length, '개 수정'));

map.on('mouseenter', 'ov-fill', () => { if (draw.getMode() === 'simple_select') map.getCanvas().style.cursor = 'pointer'; });
map.on('mouseleave', 'ov-fill', () => { map.getCanvas().style.cursor = ''; });


/* ════════════════════════════════════════════
   §5. 셀 선택 / 해제
   ════════════════════════════════════════════ */

/** 서브타일 셀 선택 — DB 로드 및 UI 활성화 */
function selectCell(col, row, lng, lat) {
	selectedCell = { col, row, lng, lat, transform: computeTransform(lng, lat) };
	refreshGrid();
	// ★ 서브그리드 표시
    map.getSource('sub-grid').setData(buildSubGridFC({ lng, lat }));
	
	document.getElementById('cell-info').textContent = `(${col}, ${row})`;
	document.getElementById('btn-db-save').disabled   = false;
	document.getElementById('btn-clear').disabled     = false;
	document.getElementById('btn-csv-export').disabled= false;
	document.getElementById('btn-water-raw').disabled = false;
	document.getElementById('btn-water-png').disabled = false;
	document.getElementById('btn-water-bitmap').disabled = false;
	loadFromDB(col, row);
}

/** 셀 선택 해제 — 캔버스 및 UI 초기화 */
function clearSelection() {
	selectedCell = null;
	draw.deleteAll();
	['sel-cell','csv-area'].forEach(id => map.getSource(id)?.setData({ type:'FeatureCollection', features:[] }));
	document.getElementById('cell-info').textContent  = '선택 안 됨';
	document.getElementById('btn-db-save').disabled   = true;
	document.getElementById('btn-clear').disabled     = true;
	document.getElementById('btn-csv-export').disabled= true;
	document.getElementById('btn-water-raw').disabled = true;
	document.getElementById('btn-water-png').disabled = true;
	document.getElementById('btn-water-bitmap').disabled = true;

	refreshShapeList();
	map.getSource('sub-grid')?.setData({ type:'FeatureCollection', features:[] });
	setStatus('서브타일을 먼저 선택하세요.', '');
}


/* ════════════════════════════════════════════
   §6. DB 로드 (공간 데이터 조회)
   ════════════════════════════════════════════ */

/** 서버에서 해당 셀의 폴리곤 데이터를 불러와 Draw에 추가 */
function loadFromDB(col, row) {
	showLoader('DB 데이터 불러오는 중...');
	setStatus('원격 DB 공간 데이터를 조회 중...', 'warn');

	const url = `load_water_direct.php?region=${encodeURIComponent(CFG.region)}&type=${encodeURIComponent(CFG.type)}&col=${col}&row=${row}`;

	fetch(url)
		.then(r => r.json())
		.then(data => {
			hideLoader();
			if (!data.success) { setStatus(`DB 조회 실패: ${data.message}`, 'warn'); return; }

			draw.deleteAll();

			if (!data.features || data.features.length === 0) {
				setStatus(`(${col}, ${row}) — 등록된 폴리곤 없음. 새로 그려서 저장하세요.`, '');
				return;
			}

			const t = selectedCell.transform;
			let count = 0;
			data.features.forEach(pts => {
				const coords  = pts.map(p => px2ll(p.x, p.y, t));
				const zValues = pts.map(p => p.z ?? 0);

				if (coords.length < 2) return;

				if (coords.length >= 3) {
					// 폴리곤: 닫힘 처리
					if (coords[0][0] !== coords[coords.length-1][0]) coords.push([...coords[0]]);
					draw.add({ type:'Feature', geometry:{ type:'Polygon', coordinates:[coords] }, properties:{ zValues } });
				} else {
					draw.add({ type:'Feature', geometry:{ type:'LineString', coordinates:coords }, properties:{ zValues } });
				}
				count++;
			});

			setStatus(`DB 동기화 완료 — 폴리곤 ${count}개 로드됨`, 'ok');
			refreshShapeList();
		})
		.catch(err => {
			hideLoader();
			console.error(err);
			setStatus('네트워크 오류로 DB 로드 실패', 'warn');
		});
}


/* ════════════════════════════════════════════
   §7. DB 저장
   ════════════════════════════════════════════ */
document.getElementById('btn-db-save').addEventListener('click', () => {
	if (!selectedCell) return;

	const allFeatures = draw.getAll().features;
	if (allFeatures.length === 0) { alert('저장할 도형이 없습니다.'); return; }

	const t = selectedCell.transform;
	const payload = [];

	for (const f of allFeatures) {
		let raw = [];
		if      (f.geometry.type === 'Polygon')   raw = f.geometry.coordinates[0];
		else if (f.geometry.type === 'LineString') raw = f.geometry.coordinates;
		else continue;

		let pts = raw.map(c => ll2px(c[0], c[1], t));
		// 폴리곤 닫힘 점 제거
		if (f.geometry.type === 'Polygon' && pts.length > 1) pts = pts.slice(0, -1);

		const zValues = Array.isArray(f.properties?.zValues) ? f.properties.zValues : pts.map(() => 0);

		payload.push({
			points: pts.map(([x, y], i) => ({
				x: parseFloat(x.toFixed(2)),
				y: parseFloat(y.toFixed(2)),
				z: parseFloat((zValues[i] ?? 0).toFixed(1)),
			}))
		});
	}

	showLoader('DB에 저장 중...');
	setStatus('서버로 전송 중...', 'warn');

	const bodyData = { region: CFG.region, type: CFG.type, col: selectedCell.col, row: selectedCell.row, features: payload };
	console.log('[save] 전송 데이터:', JSON.stringify(bodyData).substring(0, 300));

	fetch('save_water_direct.php', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(bodyData),
	})
		.then(r => { console.log('[save] HTTP 상태:', r.status); return r.text(); })
		.then(rawText => {
			console.log('[save] 서버 응답:', rawText.substring(0, 500));
			hideLoader();
			try {
				const data = JSON.parse(rawText);
				if (data.success) {
					setStatus(data.message, 'ok');
				} else {
					setStatus(`저장 실패: ${data.message}`, 'warn');
					console.error('[save] 실패 원인:', data.message);
				}
			} catch(e) {
				setStatus('서버 응답 파싱 실패 — 콘솔 확인', 'warn');
				console.error('[save] JSON 파싱 실패:', rawText);
			}
		})
		.catch(err => {
			hideLoader();
			console.error('[save] fetch 오류:', err);
			setStatus('네트워크 오류로 저장 실패', 'warn');
		});
});


/* ════════════════════════════════════════════
   §8. 전체 지우기 (캔버스 초기화, DB 미반영)
   ════════════════════════════════════════════ */
document.getElementById('btn-clear').addEventListener('click', () => {
	if (!confirm('현재 편집 중인 모든 도형을 지우시겠습니까?\n(DB에는 영향 없음)')) return;
	draw.deleteAll();
	setStatus('캔버스를 초기화했습니다. (DB 미반영)', '');
});


/* ════════════════════════════════════════════
   §9. 뷰 스타일 전환 (등고선 ↔ 위성)
   ════════════════════════════════════════════ */
document.getElementById('btn-sat').addEventListener('click', () => switchStyle('satellite'));
document.getElementById('btn-str').addEventListener('click', () => switchStyle('streets'));

function switchStyle(key) {
	if (currentStyle === key) return;
	currentStyle = key;
	map.setStyle(STYLES[key]);
	map.once('style.load', refreshGrid);
	document.getElementById('btn-sat').classList.toggle('active', key === 'satellite');
	document.getElementById('btn-str').classList.toggle('active', key === 'streets');
	document.getElementById('style-hint').textContent = key === 'satellite' ? '위성 이미지' : '등고선 지형도';
}


/* ════════════════════════════════════════════
   §10. 지역 사이드바
   ════════════════════════════════════════════ */

/** 서버에서 지역 목록을 조회해 사이드바에 렌더링 */
function loadRegionList() {
	const listEl = document.getElementById('region-list');
	listEl.innerHTML = '<div class="sidebar-empty">조회 중...</div>';

	fetch('get_regions.php')
		.then(r => r.json())
		.then(data => {
			if (!data.success || !data.regions.length) {
				listEl.innerHTML = '<div class="sidebar-empty">등록된 지역이 없습니다.</div>';
				return;
			}
			renderRegionList(data.regions);
		})
		.catch(() => { listEl.innerHTML = '<div class="sidebar-empty">조회 실패</div>'; });
}

function renderRegionList(regions) {
	const listEl = document.getElementById('region-list');
	listEl.innerHTML = '';
	regions.forEach(r => {
		const item = document.createElement('div');
		item.className   = 'region-item' + (r.name === CFG.region ? ' active' : '');
		item.dataset.region = r.name;
		item.innerHTML   = `<span class="dot"></span><span>${r.name}</span>`;
		item.addEventListener('click', () => switchRegion(r));
		listEl.appendChild(item);
	});
}

function updateSidebarActive() {
	document.querySelectorAll('.region-item').forEach(el => {
		el.classList.toggle('active', el.dataset.region === CFG.region);
	});
}

/** 지역 전환 — CFG 업데이트 후 맵 이동 */
function switchRegion(r) {
	if (r.name === CFG.region) return;
	CFG.region = r.name;
	CFG.type   = r.type || CFG.type;
	document.getElementById('region-name-display').textContent = r.name;
	updateSidebarActive();
	clearSelection();
	center.lng = r.lng;
	center.lat = r.lat;
	map.once('moveend', () => refreshGrid());
	map.flyTo({ center: [r.lng, r.lat], zoom: Math.max(map.getZoom(), 10), duration: 800 });
	setStatus(`지역 전환: [${r.name}]  ${r.lng.toFixed(6)}, ${r.lat.toFixed(6)}`, 'ok');
}

document.getElementById('btn-refresh-regions').addEventListener('click', loadRegionList);
loadRegionList(); // 초기 로드


/* ════════════════════════════════════════════
   §11. 좌표 이동 & 지역 확정
   ════════════════════════════════════════════ */

/** 입력창에서 경도/위도 파싱 */
function parseCoordInputs() {
	const lng = parseFloat(document.getElementById('input-lng').value);
	const lat = parseFloat(document.getElementById('input-lat').value);
	if (isNaN(lng) || isNaN(lat))              { setStatus('올바른 경도/위도를 입력하세요.', 'warn'); return null; }
	if (lng < -180 || lng > 180 || lat < -90 || lat > 90) { setStatus('좌표 범위를 벗어났습니다. (경도 ±180, 위도 ±90)', 'warn'); return null; }
	return { lng, lat };
}

/** 지역명 변경 없이 맵 중심만 이동 */
function gotoCoords() {
	const pos = parseCoordInputs();
	if (!pos) return;
	center.lng = pos.lng;
	center.lat = pos.lat;
	clearSelection();
	map.once('moveend', () => refreshGrid());
	map.flyTo({ center: [pos.lng, pos.lat], zoom: Math.max(map.getZoom(), 10), duration: 800 });
	setStatus(`중심 이동: ${pos.lng.toFixed(4)}, ${pos.lat.toFixed(4)}`, 'ok');
}

/** 지역명 + 중심 좌표를 확정하고 서버에 upsert */
function confirmRegion() {
	const pos = parseCoordInputs();
	if (!pos) return;

	const newRegion = prompt(`새 지역명을 입력하세요.\n현재: ${CFG.region}`, CFG.region);
	if (newRegion === null) return;
	const trimmed = newRegion.trim();
	if (!trimmed) { setStatus('지역명은 비워둘 수 없습니다.', 'warn'); return; }

	CFG.region = trimmed;
	document.getElementById('region-name-display').textContent = trimmed;

	// regions 테이블에 upsert 후 목록 갱신
	fetch('save_region.php', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ region: trimmed, lng: pos.lng, lat: pos.lat, type: CFG.type }),
	}).then(() => loadRegionList());

	center.lng = pos.lng;
	center.lat = pos.lat;
	clearSelection();
	updateSidebarActive();
	map.once('moveend', () => refreshGrid());
	map.flyTo({ center: [pos.lng, pos.lat], zoom: Math.max(map.getZoom(), 10), duration: 800 });
	setStatus(`지역 확정: [${trimmed}]  ${pos.lng.toFixed(4)}, ${pos.lat.toFixed(4)}`, 'ok');
}

document.getElementById('btn-goto').addEventListener('click', gotoCoords);
document.getElementById('btn-confirm-region').addEventListener('click', confirmRegion);
['input-lng','input-lat'].forEach(id => {
	document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') gotoCoords(); });
});
document.getElementById('input-lng').value = CFG.initLng;
document.getElementById('input-lat').value = CFG.initLat;


/* ════════════════════════════════════════════
   §12. CSV 파일 열기 / 내보내기
   ════════════════════════════════════════════ */

/** CSV 파일 선택 → 파싱 후 Draw에 누적 추가 */
document.getElementById('input-csv').addEventListener('change', e => {
    const file = e.target.files[0];
    if (!file) return;

    const filename = file.name.toLowerCase();

    if (filename.includes('waterway')) {
        if (!selectedCell) {
            alert('CSV를 불러올 서브타일을 먼저 클릭해서 선택하세요.');
            e.target.value = '';
            return;
        }
        const widthInput = prompt(
            '강폭을 입력하세요 (픽셀 단위, 쉼표로 구분하면 다중 강폭)\n예: 20  또는  10,20,40',
            '20'
        );
        if (widthInput === null) { e.target.value = ''; return; }

        const widths = widthInput.split(',')
            .map(s => parseFloat(s.trim()))
            .filter(v => !isNaN(v) && v > 0);

        if (widths.length === 0) {
            setStatus('올바른 강폭을 입력하세요.', 'warn');
            e.target.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = ev => {
            const features = parseWaterwayCsv(ev.target.result, selectedCell.transform, widths);
            if (features.length === 0) {
                setStatus('waterway CSV에서 유효한 선분을 찾지 못했습니다.', 'warn');
                return;
            }
            features.forEach(f => draw.add(f));
            const bbox = turf.bbox({ type: 'FeatureCollection', features });
            map.fitBounds(bbox, { padding: 60, maxZoom: 17 });
            setStatus(
                `Waterway 로드 완료 — ${features.length}개 폴리곤 생성 (강폭: ${widths.join(', ')}px)`,
                'ok'
            );
            refreshShapeList();
        };
        reader.onerror = () => setStatus('파일 읽기 오류', 'warn');
        reader.readAsText(file);
        e.target.value = '';
        return;
    }

    // 기존 루트 (water / 일반 CSV)
    if (!selectedCell) {
        alert('CSV를 불러올 서브타일을 먼저 클릭해서 선택하세요.');
        e.target.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = ev => {
        const features = parseCsv(ev.target.result, selectedCell.transform);
        if (features.length === 0) {
            setStatus('CSV에서 유효한 도형을 찾지 못했습니다.', 'warn');
            return;
        }
        features.forEach(f => draw.add(f));
        const bbox = turf.bbox({ type: 'FeatureCollection', features });
        map.fitBounds(bbox, { padding: 60, maxZoom: 17 });
        setStatus(`CSV 로드 완료 — ${features.length}개 도형 추가됨 (기존 유지)`, 'ok');
    };
    reader.onerror = () => setStatus('파일 읽기 오류', 'warn');
    reader.readAsText(file);
    e.target.value = '';
});

/**
 * CSV 파싱 — 형식: river_id, point_index, x, y[, z]
 * river_id가 같은 점끼리 묶어 LineString 또는 Polygon 생성
 */
function parseCsv(csvText, t) {
	const segMap = new Map();
	const lines  = csvText.split('\n');

	for (let i = 1; i < lines.length; i++) {
		const line  = lines[i].trim();
		if (!line) continue;
		const parts = line.split(',');
		if (parts.length < 4) continue;

		const id = parseInt(parts[0]);
		const x  = parseFloat(parts[2]);
		const y  = parseFloat(parts[3]);
		if (isNaN(id) || isNaN(x) || isNaN(y)) continue;

		if (!segMap.has(id)) segMap.set(id, []);
		segMap.get(id).push({ x, y });
	}

	const features = [];
	for (const pts of segMap.values()) {
		if (pts.length === 0) continue;
		if (pts.length === 1) {
			const [lng, lat] = px2ll(pts[0].x, pts[0].y, t);
			features.push({ type:'Feature', properties:{}, geometry:{ type:'Point', coordinates:[lng, lat] } });
			continue;
		}

		const dx = pts[0].x - pts[pts.length-1].x;
		const dy = pts[0].y - pts[pts.length-1].y;
		const isClosed = pts.length >= 3 && Math.sqrt(dx*dx + dy*dy) < 2.0;
		const coords   = pts.map(p => px2ll(p.x, p.y, t));

		if (isClosed) {
			const ring = [...coords];
			if (ring[0][0] !== ring[ring.length-1][0]) ring.push([ring[0][0], ring[0][1]]);
			features.push({ type:'Feature', properties:{}, geometry:{ type:'Polygon', coordinates:[ring] } });
		} else {
			features.push({ type:'Feature', properties:{}, geometry:{ type:'LineString', coordinates:coords } });
		}
	}
	return features;
}

/** Draw의 모든 도형을 CSV로 내보내기 (river_id, point_index, x, y, z) */
function exportCSV() {
	if (!selectedCell) return;
	const features = draw.getAll().features;
	if (features.length === 0) { setStatus('내보낼 도형이 없습니다.', 'warn'); return; }

	const t    = selectedCell.transform;
	const rows = ['river_id,point_index,x,y,z'];

	features.forEach((f, riverId) => {
		const coords = f.geometry.type === 'Polygon'
			? f.geometry.coordinates[0].slice(0, -1)
			: f.geometry.coordinates;
		const zValues = Array.isArray(f.properties?.zValues) ? f.properties.zValues : coords.map(() => 0);
		coords.forEach((lngLat, i) => {
			const [px, py] = ll2px(lngLat[0], lngLat[1], t);
			rows.push(`${riverId+1},${i},${px.toFixed(2)},${py.toFixed(2)},${(zValues[i]??0).toFixed(1)}`);
		});
	});

	const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' });
	const url  = URL.createObjectURL(blob);
	const a    = document.createElement('a');
	a.href = url; a.download = `${CFG.region}_${selectedCell.col}_${selectedCell.row}.csv`; a.click();
	URL.revokeObjectURL(url);
	setStatus(`CSV 내보내기 완료 — ${features.length}개 폴리곤, ${rows.length-1}개 점`, 'ok');
}


/* ════════════════════════════════════════════
   §13. 단축키
   ════════════════════════════════════════════ */
document.addEventListener('keydown', e => {
	if ((e.ctrlKey || e.metaKey) && e.key === 's') {
		e.preventDefault();
		document.getElementById('btn-db-save').click();
	}
});


/* ════════════════════════════════════════════
   §14. 도형 선택 정보 표시 & Z 프로파일 연동
   ════════════════════════════════════════════ */
map.on('draw.selectionchange', e => {
	const features = e.features;
	if (!selectedCell) return;

	if (features.length === 0) {
		document.getElementById('cell-info').textContent = `(${selectedCell.col}, ${selectedCell.row})`;
		closeZPanel();
		return;
	}

	const f    = features[0];
	const type = f.geometry.type;
	let label  = '';
	if      (type === 'Polygon')    label = `🔷 다각형 (꼭짓점 ${f.geometry.coordinates[0].length-1}개)`;
	else if (type === 'LineString') label = `〰️ 선 (점 ${f.geometry.coordinates.length}개)`;
	else if (type === 'Point')      label = `📍 점`;
	else                             label = type;

	document.getElementById('cell-info').textContent = label + (features.length > 1 ? ` 외 ${features.length-1}개` : '');

	if (type === 'Polygon' || type === 'LineString') showZProfile(f);
	else closeZPanel();
});


/* ════════════════════════════════════════════
   §15. 도형 목록 패널
   ════════════════════════════════════════════ */

/** 사이드바 도형 목록 갱신 */
function refreshShapeList() {
	const listEl   = document.getElementById('shape-list');
	const countEl  = document.getElementById('shape-count');
	const features = draw.getAll().features;

	if (!selectedCell || features.length === 0) {
		listEl.innerHTML = `<div class="sidebar-empty">${selectedCell ? '도형 없음' : '셀을 선택하면 목록이 표시됩니다.'}</div>`;
		countEl.textContent = '';
		return;
	}

	countEl.textContent = `(${features.length})`;
	listEl.innerHTML = '';

	features.forEach((f, i) => {
		const type = f.geometry.type;
		let icon = '📍', desc = '점';
		if      (type === 'Polygon')    { icon = '🔷'; desc = `다각형 · 꼭짓점 ${f.geometry.coordinates[0].length-1}개`; }
		else if (type === 'LineString') { icon = '〰️'; desc = `선 · 점 ${f.geometry.coordinates.length}개`; }

		const item = document.createElement('div');
		item.className   = 'shape-item';
		item.dataset.fid = f.id;
		item.title       = desc;
		item.innerHTML   = `
			<span class="shape-icon">${icon}</span>
			<span style="flex:1;overflow:hidden;text-overflow:ellipsis;">#${i+1} ${type === 'Polygon' ? '다각형' : type === 'LineString' ? '선' : '점'}</span>
			<span class="shape-sub">${desc.split('·')[1] || ''}</span>
		`;

		item.addEventListener('click', () => {
			draw.changeMode('simple_select', { featureIds: [f.id] });
			document.querySelectorAll('.shape-item').forEach(el => el.classList.remove('active'));
			item.classList.add('active');
			document.getElementById('cell-info').textContent =
				type === 'Polygon'    ? `🔷 다각형 (꼭짓점 ${f.geometry.coordinates[0].length-1}개)` :
				type === 'LineString' ? `〰️ 선 (점 ${f.geometry.coordinates.length}개)` : '📍 점';
			const bbox = turf.bbox({ type: 'FeatureCollection', features: [f] });
			map.fitBounds(bbox, { padding: 100, maxZoom: 17, duration: 400 });
		});

		listEl.appendChild(item);
	});
}

// Draw 이벤트마다 목록 갱신
['draw.create','draw.delete','draw.update'].forEach(evt => map.on(evt, refreshShapeList));

// 선택 변경 시 목록 하이라이트 동기화
map.on('draw.selectionchange', e => {
	const ids = e.features.map(f => f.id);
	document.querySelectorAll('.shape-item').forEach(el => {
		el.classList.toggle('active', ids.includes(el.dataset.fid));
	});
});


/* ════════════════════════════════════════════
   §16. Z 프로파일 차트 (Chart.js + dragData)
   ════════════════════════════════════════════ */
let zChartInstance   = null;  // Chart.js 인스턴스
let _activeFeatureId = null;  // 현재 선택된 Draw feature ID
let _startIndex      = null;  // 선형보간 시작 인덱스 (좌클릭)
let _endIndex        = null;  // 선형보간 끝 인덱스 (우클릭)

/**
 * feature의 Z값 배열 반환.
 * properties.zValues가 없으면 모두 0으로 초기화.
 */
function getZValues(feature) {
	const z = feature.properties?.zValues;
	if (Array.isArray(z) && z.length > 0) return z;
	const coords = feature.geometry.type === 'Polygon'
		? feature.geometry.coordinates[0]
		: feature.geometry.coordinates;
	return coords.map(() => 0);
}

/** 선택된 feature의 Z 프로파일 막대차트를 하단 패널에 표시 */
function showZProfile(feature) {
	_activeFeatureId = feature.id;

	const panel   = document.getElementById('z-profile-panel');
	const title   = document.getElementById('z-profile-title');
	const canvas  = document.getElementById('z-chart');
	const zValues = getZValues(feature);
	const labels  = zValues.map((_, i) => i + 1);
	const maxZ    = Math.max(...zValues);
	const minZ    = Math.min(...zValues);
	const ptCount = feature.geometry.type === 'Polygon'
		? feature.geometry.coordinates[0].length - 1
		: feature.geometry.coordinates.length;

	title.textContent = `꼭짓점 ${ptCount}개 · Z 범위 ${minZ} ~ ${maxZ}`;
	panel.style.display = 'flex';
	document.getElementById('map').style.bottom = '180px';

	if (zChartInstance) zChartInstance.destroy();

	zChartInstance = new Chart(canvas, {
		type: 'bar',
		data: {
			labels,
			datasets: [{
				label: 'Z값',
				data: zValues,
				backgroundColor: makeBarColors(zValues),
				borderWidth: 0,
				borderRadius: 2,
			}]
		},
		options: {
			responsive: true, maintainAspectRatio: false, animation: false,
			plugins: {
				legend: { display: false },
				dragData: {
					round: 1, showTooltip: true,
					onDragEnd: (e, _di, index, value) => {
						e.target.style.cursor = 'default';
						updateFeatureZ(index, value);
					},
				},
				tooltip: {
					callbacks: {
						title: ctx => `꼭짓점 #${ctx[0].label}`,
						label: ctx => `Z = ${ctx.raw}`,
					}
				}
			},
			scales: {
				x: { ticks:{ color:'#999', font:{ size:10 } }, grid:{ color:'rgba(255,255,255,.05)' } },
				y: { ticks:{ color:'#999', font:{ size:10 } }, grid:{ color:'rgba(255,255,255,.08)' }, min: Math.max(0, minZ - 10) },
			}
		}
	});
}

/** Z 프로파일 패널 닫기 */
function closeZPanel() {
	document.getElementById('z-profile-panel').style.display = 'none';
	document.getElementById('map').style.bottom = '0';
	if (zChartInstance) { zChartInstance.destroy(); zChartInstance = null; }
}

/** Z값 배열로 막대 배경색 생성 (낮을수록 파랑, 높을수록 빨강) */
function makeBarColors(zValues) {
	const max = Math.max(...zValues);
	const min = Math.min(...zValues);
	return zValues.map(v => {
		const t = max > min ? (v - min) / (max - min) : 0.5;
		return `rgba(${Math.round(180*t)},80,${Math.round(180*(1-t))},0.75)`;
	});
}

/** 드래그로 변경된 Z값을 feature properties에 반영 */
function updateFeatureZ(index, value) {
	if (!_activeFeatureId) return;
	const feature = draw.get(_activeFeatureId);
	if (!feature) return;

	const zValues = Array.isArray(feature.properties?.zValues)
		? [...feature.properties.zValues]
		: getZValues(feature);
	zValues[index] = value;

	draw.setFeatureProperty(_activeFeatureId, 'zValues', zValues);

	if (zChartInstance) {
		zChartInstance.data.datasets[0].data[index]            = value;
		zChartInstance.data.datasets[0].backgroundColor        = makeBarColors(zValues);
	}
}

/**
 * 지형 고도 자동조정 — hidden iframe에 좌표 전송,
 * postMessage로 결과 수신 후 Z값 일괄 업데이트
 */
function fetchTerrainElevation() {
	if (!_activeFeatureId) return;
	const feature = draw.get(_activeFeatureId);
	if (!feature) return;

	const coords = feature.geometry.type === 'Polygon'
		? feature.geometry.coordinates[0].slice(0, -1)
		: feature.geometry.coordinates;

	const requestId = Date.now().toString();
	document.getElementById('elevation-frame').contentWindow.postMessage(
		{ type: 'query_elevation', requestId, coords }, '*'
	);
	setStatus('지형 고도 조회 중...', 'warn');

	function onElevationResult(e) {
		if (!e.data || e.data.type !== 'elevation_result' || e.data.requestId !== requestId) return;
		window.removeEventListener('message', onElevationResult);

		const zValues = e.data.elevations;
		draw.setFeatureProperty(_activeFeatureId, 'zValues', zValues);

		if (zChartInstance) {
			zChartInstance.data.datasets[0].data            = zValues;
			zChartInstance.data.datasets[0].backgroundColor = makeBarColors(zValues);
			zChartInstance.update();
		}

		const max = Math.max(...zValues), min = Math.min(...zValues);
		document.getElementById('z-profile-title').textContent =
			`꼭짓점 ${zValues.length}개 · Z 범위 ${min} ~ ${max}`;
		setStatus(`지형 고도 자동조정 완료 — ${zValues.length}개 점 업데이트`, 'ok');
	}
	window.addEventListener('message', onElevationResult);
}

/**
 * 차트 바 하이라이트 (시작/끝 인덱스 표시)
 * @param {number} index - 꼭짓점 인덱스
 * @param {'start'|'end'} role
 */
function highlightZBar(index, role) {
	if (role === 'start') { _startIndex = index; _endIndex = null; }
	else                  { _endIndex   = index; }

	if (!zChartInstance) return;

	const zValues = zChartInstance.data.datasets[0].data;
	const from = _startIndex;
	const to   = _endIndex;

	zChartInstance.data.datasets[0].backgroundColor = zValues.map((v, i) => {
		const t = Math.max(...zValues) > Math.min(...zValues)
			? (v - Math.min(...zValues)) / (Math.max(...zValues) - Math.min(...zValues)) : 0.5;
		if (i === from)                                        return `rgba(255,220,0,0.95)`;   // 시작점: 노랑
		if (to !== null && i === to)                           return `rgba(0,220,180,0.95)`;   // 끝점: 청록
		if (to !== null && i > Math.min(from,to) && i < Math.max(from,to))
			                                                   return `rgba(180,180,255,0.6)`;  // 구간: 보라
		return `rgba(${Math.round(180*t)},80,${Math.round(180*(1-t))},0.75)`;
	});
	zChartInstance.update('none');

	const zVal = zValues[index];
	document.getElementById('z-profile-title').textContent = _endIndex === null
		? `시작점 #${from+1} · Z = ${zVal}  |  우클릭으로 끝점 선택`
		: `#${Math.min(from,to)+1} ~ #${Math.max(from,to)+1} · Z ${zValues[Math.min(from,to)]} → ${zValues[Math.max(from,to)]}`;
}

/** 선형보간 실행 — 시작~끝 인덱스 구간을 선형 보간 */
function applyLinearInterp() {
	if (!_activeFeatureId || _startIndex === null || _endIndex === null) return;
	const feature = draw.get(_activeFeatureId);
	if (!feature) return;

	const zValues = [...(feature.properties?.zValues || zChartInstance.data.datasets[0].data)];
	const from = Math.min(_startIndex, _endIndex);
	const to   = Math.max(_startIndex, _endIndex);
	const zFrom = zValues[from], zTo = zValues[to];
	const steps = to - from;

	for (let i = from; i <= to; i++) {
		zValues[i] = parseFloat((zFrom + (zTo - zFrom) * (i - from) / steps).toFixed(1));
	}

	draw.setFeatureProperty(_activeFeatureId, 'zValues', zValues);

	if (zChartInstance) {
		zChartInstance.data.datasets[0].data            = zValues;
		zChartInstance.data.datasets[0].backgroundColor = zValues.map((v, i) =>
			(i >= from && i <= to) ? `rgba(100,220,150,0.85)` : makeBarColors([v])[0]
		);
		zChartInstance.update('none');
	}

	document.getElementById('z-profile-title').textContent =
		`보간 완료 · #${from+1} ~ #${to+1} (${steps+1}개 점) · Z ${zFrom} → ${zTo}`;
	_startIndex = null; _endIndex = null;
	setStatus(`선형보간 완료 — #${from+1}~#${to+1} 구간 ${steps+1}개 점 업데이트`, 'ok');
}

function showInterpButtons(show) {
	document.getElementById('interp-buttons').style.display = show ? 'flex' : 'none';
}
function confirmInterp() { applyLinearInterp(); showInterpButtons(false); }
function cancelInterp() {
	_startIndex = null; _endIndex = null;
	showInterpButtons(false);
	if (!zChartInstance) return;
	const zVals = zChartInstance.data.datasets[0].data;
	zChartInstance.data.datasets[0].backgroundColor = makeBarColors(zVals);
	zChartInstance.update('none');
	document.getElementById('z-profile-title').textContent = `꼭짓점 ${zVals.length}개`;
	setStatus('보간 취소됨', '');
}

/** 좌클릭으로 선택한 시작점의 Z값으로 현재 폴리곤 전체 점 일괄 설정 */
function applyUniformZ() {
	if (!_activeFeatureId) { setStatus('폴리곤을 먼저 선택하세요.', 'warn'); return; }
	if (_startIndex === null) { setStatus('좌클릭으로 기준 높이 점을 먼저 선택하세요.', 'warn'); return; }
	const currentZ = zChartInstance.data.datasets[0].data[_startIndex];
	const zValues  = zChartInstance.data.datasets[0].data.map(() => currentZ);
	draw.setFeatureProperty(_activeFeatureId, 'zValues', zValues);
	if (zChartInstance) {
		zChartInstance.data.datasets[0].data            = zValues;
		zChartInstance.data.datasets[0].backgroundColor = zValues.map((_, i) =>
			i === _startIndex ? `rgba(255,220,0,0.95)` : `rgba(100,180,255,0.75)`
		);
		zChartInstance.update('none');
	}
	document.getElementById('z-profile-title').textContent =
		`전체 ${zValues.length}개 점 · Z = ${currentZ} 일괄 적용`;
	setStatus(`높이 일괄 지정 완료 — 모든 점 Z = ${currentZ}`, 'ok');
}

/** 입력값으로 현재 선택된 폴리곤 전체 점 Z값 일괄 설정 */
function applyUniformZInput() {
	if (!_activeFeatureId) { setStatus('폴리곤을 먼저 선택하세요.', 'warn'); return; }
	const input = prompt('이 폴리곤의 Z값을 입력하세요:', '0');
	if (input === null) return;
	const z = parseFloat(input);
	if (isNaN(z)) { setStatus('올바른 숫자를 입력하세요.', 'warn'); return; }
	const feature = draw.get(_activeFeatureId);
	if (!feature) return;
	const coords  = feature.geometry.type === 'Polygon'
		? feature.geometry.coordinates[0].slice(0, -1) : feature.geometry.coordinates;
	const zValues = coords.map(() => z);
	draw.setFeatureProperty(_activeFeatureId, 'zValues', zValues);
	if (zChartInstance) {
		zChartInstance.data.datasets[0].data            = zValues;
		zChartInstance.data.datasets[0].backgroundColor = zValues.map(() => `rgba(100,180,255,0.75)`);
		zChartInstance.update('none');
		document.getElementById('z-profile-title').textContent =
			`전체 ${zValues.length}개 점 · Z = ${z} 일괄 적용`;
	}
	setStatus(`높이 일괄 지정 완료 — 선택 폴리곤 Z = ${z}`, 'ok');
}

/** 모든 폴리곤의 Z값을 입력값으로 일괄 설정 */
function applyAllPolygonsZ() {
	const input = prompt('모든 폴리곤의 Z값을 입력하세요:', '0');
	if (input === null) return;
	const z = parseFloat(input);
	if (isNaN(z)) { setStatus('올바른 숫자를 입력하세요.', 'warn'); return; }

	const features = draw.getAll().features;
	if (features.length === 0) { setStatus('설정할 폴리곤이 없습니다.', 'warn'); return; }

	features.forEach(f => {
		const coords  = f.geometry.type === 'Polygon'
			? f.geometry.coordinates[0].slice(0, -1) : f.geometry.coordinates;
		draw.setFeatureProperty(f.id, 'zValues', coords.map(() => z));
	});

	if (zChartInstance && _activeFeatureId) {
		const zValues = zChartInstance.data.datasets[0].data.map(() => z);
		zChartInstance.data.datasets[0].data            = zValues;
		zChartInstance.data.datasets[0].backgroundColor = zValues.map(() => `rgba(100,180,255,0.75)`);
		zChartInstance.update('none');
		document.getElementById('z-profile-title').textContent =
			`전체 ${zValues.length}개 점 · Z = ${z} 일괄 적용`;
	}
	setStatus(`전체 높이 설정 완료 — ${features.length}개 폴리곤 Z = ${z}`, 'ok');
}


/* ════════════════════════════════════════════
   §17. 수면 RAW 생성 (65×65 Float32Array)
   ════════════════════════════════════════════ */
const WATER_VERT_SIZE = 65;   // 64×64 셀 + 경계 1
const WATER_MASK_SIZE = 64;

/**
 * 레이캐스팅 알고리즘으로 점이 다각형 내부인지 판별
 * @param {number} px - 픽셀 X
 * @param {number} py - 픽셀 Y
 * @param {{ x:number, y:number }[]} points - 다각형 꼭짓점 (픽셀 좌표)
 */
function pointInPolygon(px, py, points) {
	let inside = false;
	const n = points.length;
	for (let i = 0, j = n-1; i < n; j = i++) {
		const xi = points[i].x, yi = points[i].y;
		const xj = points[j].x, yj = points[j].y;
		const intersect = ((yi > py) !== (yj > py)) && (px < (xj-xi)*(py-yi)/(yj-yi)+xi);
		if (intersect) inside = !inside;
	}
	return inside;
}

/**
 * 현재 Draw 폴리곤들을 래스터화해 65×65 수면 높이 배열 생성.
 * 0인 셀은 인접 셀 평균으로 최대 8회 확산 채움.
 */
function generateWaterHeightRaw() {
	const t        = selectedCell.transform;
	const features = draw.getAll().features;
	const CELL_SIZE = 1024 / WATER_MASK_SIZE;  // 16px

	// 폴리곤을 픽셀 좌표 + 평균 Z값으로 변환
	const polygons = [];
	for (const f of features) {
		if (f.geometry.type !== 'Polygon') continue;
		const zValues = Array.isArray(f.properties?.zValues) ? f.properties.zValues : [];
		if (zValues.length === 0) continue;
		const avgZ  = zValues.reduce((s,v) => s+v, 0) / zValues.length;
		const ring  = f.geometry.coordinates[0].slice(0, -1);
		const points = ring.map(([lng, lat]) => { const [px,py] = ll2px(lng,lat,t); return {x:px, y:py}; });
		polygons.push({ avgZ, points });
	}

	// 65×65 heights 배열 초기화 및 래스터화
	const heights = new Float32Array(WATER_VERT_SIZE * WATER_VERT_SIZE);
	for (let vy = 0; vy < WATER_VERT_SIZE; vy++) {
		for (let vx = 0; vx < WATER_VERT_SIZE; vx++) {
			const px = vx * CELL_SIZE;
			const py = vy * CELL_SIZE;
			for (const poly of polygons) {
				if (pointInPolygon(px, py, poly.points)) {
					heights[vy * WATER_VERT_SIZE + vx] = poly.avgZ;
					break;
				}
			}
		}
	}

	// 0인 셀을 인접 셀 평균으로 확산 채움 (최대 8 패스)
	const filled = new Float32Array(heights);
	for (let pass = 0; pass < 8; pass++) {
		let changed = false;
		for (let vy = 0; vy < WATER_VERT_SIZE; vy++) {
			for (let vx = 0; vx < WATER_VERT_SIZE; vx++) {
				if (filled[vy*WATER_VERT_SIZE+vx] !== 0) continue;
				let sum = 0, cnt = 0;
				const neighbors = [
					[vx-1, vy], [vx+1, vy], [vx, vy-1], [vx, vy+1]
				];
				for (const [nx, ny] of neighbors) {
					if (nx >= 0 && nx < WATER_VERT_SIZE && ny >= 0 && ny < WATER_VERT_SIZE) {
						const v = filled[ny*WATER_VERT_SIZE+nx];
						if (v !== 0) { sum += v; cnt++; }
					}
				}
				if (cnt > 0) { filled[vy*WATER_VERT_SIZE+vx] = sum/cnt; changed = true; }
			}
		}
		if (!changed) break;
	}
	return filled;
}

/** 수면 RAW 생성 버튼 클릭 — 로컬 다운로드 + 서버 저장 */
document.getElementById('btn-water-raw').addEventListener('click', async () => {
	if (!selectedCell) return;

	const features = draw.getAll().features.filter(f => f.geometry.type === 'Polygon');
	if (features.length === 0) { setStatus('수면 RAW 생성 실패 — 폴리곤이 없습니다.', 'warn'); return; }
	if (!features.every(f => Array.isArray(f.properties?.zValues) && f.properties.zValues.length > 0)) {
		setStatus('수면 RAW 생성 실패 — Z값이 없는 폴리곤이 있습니다.', 'warn');
		return;
	}

	setStatus('수면 RAW 생성 중...', 'warn');
	const filled   = generateWaterHeightRaw();
	const filename = `water_height_${selectedCell.col}_${selectedCell.row}.raw`;

	// 1. 로컬 다운로드
	const blob = new Blob([filled.buffer], { type: 'application/octet-stream' });
	const url  = URL.createObjectURL(blob);
	const a    = document.createElement('a');
	a.href = url; a.download = filename; a.click();
	URL.revokeObjectURL(url);

	// 2. 서버 저장 (Base64 인코딩 전송)
	const bytes  = new Uint8Array(filled.buffer);
	let binary   = '';
	bytes.forEach(b => binary += String.fromCharCode(b));
	const base64 = btoa(binary);

	try {
		const res  = await fetch('save_water_height.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ region: CFG.region, type: CFG.type, col: selectedCell.col, row: selectedCell.row, data: base64 }),
		});
		const json = await res.json();
		setStatus(json.success ? `수면 RAW 저장 완료 — ${filename}` : `수면 RAW 서버 저장 실패: ${json.message}`, json.success ? 'ok' : 'warn');
	} catch (e) {
		setStatus(`수면 RAW 서버 저장 오류: ${e.message}`, 'warn');
	}
});
</script>


<script>
/* ════════════════════════════════════════════
   §18. UI 헬퍼
   ════════════════════════════════════════════ */

/** 하단 상태 메시지 설정 */
function setStatus(msg, type) {
	const el = document.getElementById('status');
	el.textContent = msg;
	el.className   = 'status' + (type ? ` ${type}` : '');
}
/** 로딩 오버레이 표시 */
function showLoader(msg = '처리 중...') {
	document.getElementById('loader-msg').textContent = msg;
	document.getElementById('loader').classList.add('show');
}
/** 로딩 오버레이 숨김 */
function hideLoader() {
	document.getElementById('loader').classList.remove('show');
}

</script>


<script>
function parseWaterwayCsv(csvText, t, widths) {
    const segMap = new Map();
    const lines  = csvText.split('\n');
    for (let i = 1; i < lines.length; i++) {
        const line  = lines[i].trim();
        if (!line) continue;
        const parts = line.split(',');
        if (parts.length < 4) continue;
        const id  = parseInt(parts[0]);
        const idx = parseInt(parts[1]);
        const x   = parseFloat(parts[2]);
        const y   = parseFloat(parts[3]);
        if (isNaN(id) || isNaN(x) || isNaN(y)) continue;
        if (!segMap.has(id)) segMap.set(id, []);
        segMap.get(id).push({ idx: isNaN(idx) ? 0 : idx, x, y });
    }
    if (segMap.size === 0) return [];

    const [lng0, lat0] = px2ll(0,   0, t);
    const [lng1, lat1] = px2ll(100, 0, t);
    const refDist = turf.distance(
        turf.point([lng0, lat0]),
        turf.point([lng1, lat1]),
        { units: 'kilometers' }
    ) / 100;

    const features = [];
    let widthIdx = 0;

    for (const [riverId, pts] of segMap.entries()) {
        pts.sort((a, b) => a.idx - b.idx);
        if (pts.length < 2) continue;

        const widthPx = widths.length === 1 ? widths[0] : widths[widthIdx % widths.length];
        widthIdx++;

        const widthKm = widthPx * refDist;
        const coords  = pts.map(p => px2ll(p.x, p.y, t));

        // ↓ 여기만 교체 (turf.buffer → buildFlatCapPolygon)
        const polygon = buildFlatCapPolygon(coords, widthKm);
        if (!polygon) continue;

        features.push({
            type: 'Feature',
            geometry: polygon,
            properties: { zValues: [], _waterwayId: riverId, _widthPx: widthPx },
        });
    }
    return features;
}

function flattenMultiPolygon(geom) {
    let bestRing = null, bestLength = 0;
    for (const poly of geom.coordinates) {
        if (poly[0].length > bestLength) {
            bestLength = poly[0].length;
            bestRing   = poly;
        }
    }
    return { type: 'Polygon', coordinates: bestRing };
}

/**
 * 폴리라인을 따라 양쪽으로 widthKm/2 오프셋한 뒤
 * 양 끝을 직선(flat)으로 닫는 폴리곤 생성
 */
function buildFlatCapPolygon(coords, widthKm) {
    if (coords.length < 2) return null;

    const halfKm = widthKm / 2;
    const left   = [];
    const right  = [];

    // 각 점마다 진행 방향의 법선 벡터로 좌/우 오프셋 점 계산
    for (let i = 0; i < coords.length; i++) {
        // 현재 점의 방향 벡터: 앞뒤 세그먼트 평균
        let dx = 0, dy = 0;

        if (i < coords.length - 1) {
            const a = coords[i], b = coords[i + 1];
            dx += b[0] - a[0];
            dy += b[1] - a[1];
        }
        if (i > 0) {
            const a = coords[i - 1], b = coords[i];
            dx += b[0] - a[0];
            dy += b[1] - a[1];
        }

        // 위경도 단위를 거리 비례로 정규화
        const len = Math.sqrt(dx * dx + dy * dy);
        if (len < 1e-12) continue;
        const nx = -dy / len;  // 법선 X (좌측)
        const ny =  dx / len;  // 법선 Y (좌측)

        // halfKm를 위경도 오프셋으로 환산
        // 위도 1도 ≈ 111.32km, 경도 1도 ≈ 111.32 * cos(lat) km
        const lat     = coords[i][1];
        const dLng    = halfKm / (111.32 * Math.cos(lat * Math.PI / 180));
        const dLat    = halfKm / 111.32;

        left.push ([coords[i][0] + nx * dLng, coords[i][1] + ny * dLat]);
        right.push([coords[i][0] - nx * dLng, coords[i][1] - ny * dLat]);
    }

    if (left.length < 2) return null;

    // 좌측 순방향 + 우측 역방향으로 링 완성 (끝은 직선으로 연결 = flat cap)
    const ring = [
        ...left,
        ...[...right].reverse(),
        left[0],   // 닫기
    ];

    return { type: 'Polygon', coordinates: [ring] };
}

/* ════════════════════════════════════════════
   §18. 수면 PNG 생성 (9216×9216, 강=파랑 / 배경=검정)
   ════════════════════════════════════════════ */

document.getElementById('btn-water-png').addEventListener('click', () => {
    if (!selectedCell) return;

    const features = draw.getAll().features.filter(f => f.geometry.type === 'Polygon');
    if (features.length === 0) {
        setStatus('PNG 생성 실패 — 폴리곤이 없습니다.', 'warn');
        return;
    }

    setStatus('수면 PNG 생성 중... (잠시 기다려주세요)', 'warn');

    // UI 블로킹 방지를 위해 setTimeout으로 한 프레임 뒤 실행
    setTimeout(() => {
        try {
            const dataUrl  = generateWaterPng(selectedCell.transform);
            const filename = `water_mask_${selectedCell.col}_${selectedCell.row}.png`;

            const a = document.createElement('a');
            a.href     = dataUrl;
            a.download = filename;
            a.click();

            setStatus(`수면 PNG 생성 완료 — ${filename}`, 'ok');
        } catch (e) {
            console.error('[water-png]', e);
            setStatus(`수면 PNG 생성 오류: ${e.message}`, 'warn');
        }
    }, 50);
});

/**
 * 현재 Draw 폴리곤을 9216×9216 캔버스에 래스터화
 * 강 내부 = 파란색(#0055ff), 배경 = 검정(#000000)
 */
function generateWaterPng(t) {
    const SIZE = 9216;

    const canvas  = document.createElement('canvas');
    canvas.width  = SIZE;
    canvas.height = SIZE;
    const ctx     = canvas.getContext('2d');

    // 배경 검정
    ctx.fillStyle = '#000000';
    ctx.fillRect(0, 0, SIZE, SIZE);

    const features = draw.getAll().features.filter(f => f.geometry.type === 'Polygon');
    const scale    = SIZE / 1081;  // CSV 픽셀 좌표 → 캔버스 픽셀

    ctx.fillStyle = '#0055ff';

    for (const f of features) {
        const ring   = f.geometry.coordinates[0];  // 외곽 링
        if (ring.length < 3) continue;

        ctx.beginPath();

        for (let i = 0; i < ring.length; i++) {
            const [px, py] = ll2px(ring[i][0], ring[i][1], t);
            const cx = px * scale;
            const cy = py * scale;
            if (i === 0) ctx.moveTo(cx, cy);
            else         ctx.lineTo(cx, cy);
        }

        ctx.closePath();
        ctx.fill();
    }

    return canvas.toDataURL('image/png');
}

/* ════════════════════════════════════════════
   §20. 수면 메시 생성 → ZIP 압축 다운로드
        576×576 비트맵 → 9×9 서브타일(64×64)
        각 타일: water_mesh.raw 포맷
        [Uint32 vertexCount][Uint32 indexCount]
        [Float32 x,y × vertexCount]
        [Uint32 × indexCount]
   ════════════════════════════════════════════ */

document.getElementById('btn-water-bitmap').addEventListener('click', () => {
    if (!selectedCell) return;

    const features = draw.getAll().features.filter(f => f.geometry.type === 'Polygon');
    if (features.length === 0) {
        setStatus('메시 생성 실패 — 폴리곤이 없습니다.', 'warn');
        return;
    }

    setStatus('수면 메시 생성 중... (잠시 기다려주세요)', 'warn');

    setTimeout(async () => {
        try {
            const { bitmap } = generateWaterBitmap(selectedCell.transform);

            const BITMAP_SIZE = 576;
            const GRID        = 9;
            const TILE_SIZE   = 64;
            const zip         = new JSZip();

            for (let tileRow = 0; tileRow < GRID; tileRow++) {
                for (let tileCol = 0; tileCol < GRID; tileCol++) {

					const flippedTileRow = tileRow; 

                    // 64×64 타일 비트맵 추출
                    const tile = new Uint8Array(TILE_SIZE * TILE_SIZE);
                    for (let y = 0; y < TILE_SIZE; y++) {
                        for (let x = 0; x < TILE_SIZE; x++) {
                            const srcX = tileCol        * TILE_SIZE + x;
                            const srcY = flippedTileRow * TILE_SIZE + y;
                            tile[y * TILE_SIZE + x] = bitmap[srcY * BITMAP_SIZE + srcX];
                        }
                    }

                    // 비트맵 → mesh RAW 변환
                    const raw = buildMeshRaw(tile, TILE_SIZE);
                    if (!raw) continue;

					const globalTileCol = (selectedCell.col - 4) * 9 + tileCol;
					const globalTileRow = (selectedCell.row - 4) * 9 + tileRow;

					// ── 메시 RAW ─────────────────────────────────────────
					const meshRaw = buildMeshRaw(tile, TILE_SIZE);
					if (meshRaw) {
						zip.file(`tile_${globalTileCol}_${globalTileRow}_water_mesh.raw`, meshRaw);
					}

					// ── 높이 RAW (65×65 Float32) ─────────────────────────
					const heightRaw = buildHeightRaw(tile, TILE_SIZE);
					if (heightRaw) {
						zip.file(`tile_${globalTileCol}_${globalTileRow}_water_height.raw`, heightRaw);
					}					
                }
            }

            setStatus('ZIP 압축 중...', 'warn');

            const zipBlob = await zip.generateAsync({
                type:               'blob',
                compression:        'DEFLATE',
                compressionOptions: { level: 6 },
            });

            const zipName = `water_mesh_${selectedCell.col}_${selectedCell.row}.zip`;
            const url     = URL.createObjectURL(zipBlob);
            const a       = document.createElement('a');
            a.href        = url;
            a.download    = zipName;
            a.click();
            URL.revokeObjectURL(url);

            setStatus(`메시 ZIP 완료 — ${zipName} (81개 타일)`, 'ok');

        } catch (e) {
            console.error('[water-mesh]', e);
            setStatus(`메시 생성 오류: ${e.message}`, 'warn');
        }
    }, 50);
});

/**
 * 64×64 비트맵(0/1) → water_mesh.raw 바이너리
 *
 * 강(1) 셀만 쿼드(2삼각형)로 생성
 * 정규화 좌표: x,y ∈ [0.0, 1.0]
 *
 * 포맷:
 *   [Uint32] vertexCount
 *   [Uint32] indexCount
 *   [Float32 x, Float32 y] × vertexCount
 *   [Uint32] × indexCount
 */
function buildMeshRaw(tile, size) {

    // ── 1. 강 셀 수집 ─────────────────────────────────────────
    const cells = [];
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            if (tile[y * size + x] === 1) cells.push({ x, y });
        }
    }

    if (cells.length === 0) {
        // 강 없는 타일도 빈 mesh로 생성 (헤더만)
        const buf = new ArrayBuffer(8);
        const dv  = new DataView(buf);
        dv.setUint32(0, 0, true);  // vertexCount = 0
        dv.setUint32(4, 0, true);  // indexCount  = 0
        return buf;
    }

    // ── 2. 버텍스 중복 제거 (공유 버텍스) ───────────────────────
    // 각 셀의 4개 꼭짓점을 키로 맵에 저장
    const vertMap  = new Map();   // "x,y" → index
    const verts    = [];          // [x, y, x, y, ...]  정규화 좌표
    const indices  = [];

    let vi = 0;

    function getVert(gx, gy) {
        const key = `${gx},${gy}`;
        if (vertMap.has(key)) return vertMap.get(key);
        vertMap.set(key, vi);
        verts.push(gx / size, gy / size);  // 0.0~1.0 정규화
        return vi++;
    }

    for (const { x, y } of cells) {
        //  (x,y)──(x+1,y)
        //    │         │
        //  (x,y+1)─(x+1,y+1)
        const i00 = getVert(x,     y    );
        const i10 = getVert(x + 1, y    );
        const i01 = getVert(x,     y + 1);
        const i11 = getVert(x + 1, y + 1);

        // 삼각형 1: 좌상-우상-좌하
        indices.push(i00, i10, i01);
        // 삼각형 2: 우상-우하-좌하
        indices.push(i10, i11, i01);
    }

    // ── 3. ArrayBuffer 직렬화 ─────────────────────────────────
    const vertexCount = vi;
    const indexCount  = indices.length;

    const bufSize = 4 + 4                    // 헤더
                  + vertexCount * 2 * 4      // Float32 x,y
                  + indexCount  * 4;         // Uint32 index

    const buf = new ArrayBuffer(bufSize);
    const dv  = new DataView(buf);
    let off   = 0;

    dv.setUint32(off, vertexCount, true); off += 4;
    dv.setUint32(off, indexCount,  true); off += 4;

    for (let i = 0; i < verts.length; i++) {
        dv.setFloat32(off, verts[i], true); off += 4;
    }
    for (let i = 0; i < indexCount; i++) {
        dv.setUint32(off, indices[i], true); off += 4;
    }

    return buf;
}

/**
 * 9216×9216 스캔라인 래스터화 → 16×16 블록 다운샘플 → 576×576 비트맵
 */
function generateWaterBitmap(t) {
    const FULL  = 9216;
    const BLOCK = 16;
    const OUT   = 576;   // FULL / BLOCK

    // ── 1. 9216×9216 마스크 생성 (강=1, 배경=0) ──────────────────
    const mask  = new Uint8Array(FULL * FULL);
    const scale = FULL / 1081;

    const features = draw.getAll().features.filter(f => f.geometry.type === 'Polygon');

    for (const f of features) {
        const ring = f.geometry.coordinates[0];
        if (ring.length < 3) continue;

        const px = ring.map(c => ll2px(c[0], c[1], t)[0] * scale);
        const py = ring.map(c => ll2px(c[0], c[1], t)[1] * scale);
        const n  = ring.length;

        const minY = Math.max(0,      Math.floor(Math.min(...py)));
        const maxY = Math.min(FULL-1, Math.ceil (Math.max(...py)));

        for (let y = minY; y <= maxY; y++) {
            const xIntersects = [];

            for (let i = 0, j = n-1; i < n; j = i++) {
                const yi = py[i], yj = py[j];
                const xi = px[i], xj = px[j];
                if ((yi > y) !== (yj > y)) {
                    xIntersects.push(xi + (y - yi) * (xj - xi) / (yj - yi));
                }
            }

            xIntersects.sort((a, b) => a - b);

            for (let k = 0; k + 1 < xIntersects.length; k += 2) {
                const x0 = Math.max(0,      Math.floor(xIntersects[k]));
                const x1 = Math.min(FULL-1, Math.ceil (xIntersects[k+1]));
                mask.fill(1, y * FULL + x0, y * FULL + x1 + 1);
            }
        }
    }

    // ── 2. 16×16 블록 다운샘플 → 576×576 ────────────────────────
    const bitmap = new Uint8Array(OUT * OUT);

    for (let by = 0; by < OUT; by++) {
        for (let bx = 0; bx < OUT; bx++) {
            let hit = false;
            outer:
            for (let dy = 0; dy < BLOCK; dy++) {
                for (let dx = 0; dx < BLOCK; dx++) {
                    if (mask[(by * BLOCK + dy) * FULL + (bx * BLOCK + dx)] === 1) {
                        hit = true;
                        break outer;
                    }
                }
            }
            bitmap[by * OUT + bx] = hit ? 1 : 0;
        }
    }

    // ── 3. 576×576 PNG 렌더링 (흰=1, 검=0) ──────────────────────
    const canvas  = document.createElement('canvas');
    canvas.width  = OUT;
    canvas.height = OUT;
    const ctx     = canvas.getContext('2d');
    const imgData = ctx.createImageData(OUT, OUT);

    for (let i = 0; i < bitmap.length; i++) {
        const v    = bitmap[i] === 1 ? 255 : 0;
        const base = i * 4;
        imgData.data[base]     = v;
        imgData.data[base + 1] = v;
        imgData.data[base + 2] = v;
        imgData.data[base + 3] = 255;
    }

    ctx.putImageData(imgData, 0, 0);

    return { canvas, bitmap };
}

/**
 * 64×64 비트맵(0/1) → water_height.raw 바이너리
 *
 * 65×65 Float32Array (64×64 셀 + 경계 1)
 * 강(1) 셀의 4개 꼭짓점 → 1.0
 * 배경(0)                → 0.0
 *
 * 포맷: Float32 × 65 × 65 = 16,900 bytes
 * (뷰어의 WATER_HEIGHT_BYTES = 65*65*4 와 일치)
 */
function buildHeightRaw(tile, size) {
    const VERT = size + 1;  // 65
    const heights = new Float32Array(VERT * VERT);

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            if (tile[y * size + x] !== 1) continue;

            // 셀(x,y)의 4개 꼭짓점에 1.0 기록
            heights[ y      * VERT + x    ] = 1.0;
            heights[ y      * VERT + x + 1] = 1.0;
            heights[(y + 1) * VERT + x    ] = 1.0;
            heights[(y + 1) * VERT + x + 1] = 1.0;
        }
    }

    return heights.buffer;
}

</script>
</body>
</html>