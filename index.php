<!DOCTYPE html>
<html lang="ko">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Heightmap Downloader</title>
	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

	<!-- CSS -->
	<link href="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.css" rel="stylesheet" />
	<link href="https://unpkg.com/mapbox-gl-geocoder@4.7.2/dist/mapbox-gl-geocoder.css" rel="stylesheet" />
	<link rel="stylesheet" href="style.css" />
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css" />

	<!-- 외부 라이브러리 -->
	<script src="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.js"></script>
	<script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v4.7.0/mapbox-gl-geocoder.min.js"></script>
	
	<!-- turf 추가 -->
	<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
	
	<!-- lib.js (VectorTile, Protobuf 전역 등록) -->
	<script src="./js/lib.js"></script>
</head>

<script>
/**
 * 지도상의 모든 그리드 관련 레이어(메인 그리드, 오버뷰 박스, 라벨)를 보이거나 숨깁니다.
 * @param {boolean} isVisible - 체크박스의 체크 여부
 */
function toggleGrid(isVisible) {
	const visibility = isVisible ? 'visible' : 'none';
	
	const layersToToggle = [
		'layer-cells-fill',
		'layer-cells-line',
		'layer-overview-fill',
		'layer-overview-line',
		'layer-overview-labels'
	];

	layersToToggle.forEach(layerId => {
		if (map.getLayer(layerId)) {
			map.setLayoutProperty(layerId, 'visibility', visibility);
		}
	});

	const hint = document.getElementById('hint');
	if (hint) {
		hint.innerText = isVisible ? "그리드가 표시됨" : "그리드가 숨겨짐";
		hint.classList.remove('hide');
		setTimeout(() => hint.classList.add('hide'), 2000);
	}
}
</script> 

<body>

	<!-- ── 즐겨찾기  ── -->
	<div class="fav-section">
		<button class="fav-add-btn" onclick="addFavorite()">
			<i class="ti ti-bookmark-plus" aria-hidden="true"></i>
			현재 위치 즐겨찾기 추가
		</button>
		<div class="fav-list" id="fav-list"></div>
	</div>

	<!-- ── 지도 스타일 ── -->
	<div class="map-style-section">
		<div class="field-label">Map Style</div>
		<div class="btn-row">
			<button class="download-btn btn-style-active" id="btn-style-streets" onclick="changeMapStyle('streets')">
				🗺️ STREETS
			</button>
			<button class="download-btn btn-style-inactive" id="btn-style-satellite" onclick="changeMapStyle('satellite')">
				🛰️ SATELLITE
			</button>
		</div>

		<!-- 그리드 토글 스위치 -->
		<div class="grid-toggle-row">
			<span class="grid-toggle-label">지도 그리드 표시</span>
			<label class="switch">
				<input type="checkbox" id="grid-toggle" checked onchange="toggleGrid(this.checked)">
				<span class="slider"></span>
			</label>
		</div>

		<div class="status status-result" id="status-result">지도를 클릭하여 중심 위치를 선택하세요</div>

		<!-- Mega Map -->
		<div class="section-divider">
			<div class="field-label">Mega Map (9216×9216 · 보라색 그리드)</div>
			<button class="btn-mega-map" id="dl-btn-mega" onclick="startMegaMapDownload()">
				↓ Mega Map PNG
			</button>
			<div class="status-small" id="status-mega">
				9,216m × 9,216m · 3×3 그리드 선 포함
			</div>
		</div>
		
		<!-- Vector Data 다운로드 -->
		<div class="section-divider">
			<div class="field-label">Vector Data (도로·수계)</div>
			<button class="btn-vector" id="dl-btn-vector" onclick="startVectorDownload()">
				↓ Road / River CSV
			</button>
			<div class="status-small" id="status-vector">
				roads.csv · water.csv · waterway.csv
			</div>
		</div>
	</div>

	<!-- ── 지도 ── -->
	<div id="map"></div>
	<div class="geocoder-wrap" id="geocoder"></div>

	<!-- ── 패널 ── -->
	<div class="panel">
		<div class="panel-header">Export Settings</div>
		
		<div id="debug-panel" style="
					position:fixed; bottom:12px; right:12px;
					background:#1a1a2e; color:#eee;
					border-radius:8px; padding:16px;
					font-size:12px; z-index:9999;
					display:flex; flex-direction:column; gap:10px;
					box-shadow:0 4px 16px rgba(0,0,0,0.4);
					min-width:260px;">

			<div style="font-weight:bold; color:#AFA9EC;">🛠 buf2 디버그</div>

			<!-- 덤프 -->
			<div style="display:flex; flex-direction:column; gap:4px;">
				<div style="color:#888; font-size:11px;">① 다운로드 실행 후 buf2 덤프 저장</div>
				<button onclick="saveBuf2Dump()" style="
					padding:6px 10px; background:#534AB7; color:#fff;
					border:none; border-radius:6px; cursor:pointer;">
					💾 buf2 덤프 저장 (.raw + _meta.txt)
				</button>
			</div>

			<hr style="border-color:#333; margin:0;">

			<!-- 로드 후 재개 -->
			<div style="display:flex; flex-direction:column; gap:6px;">
				<div style="color:#888; font-size:11px;">② 덤프 파일 로드 후 파이프라인 재개</div>

				<label style="display:flex; flex-direction:column; gap:2px;">
					<span style="color:#aaa;">buf2 raw 파일</span>
					<input type="file" id="dbg-buf2-raw" accept=".raw"
						   style="font-size:11px; color:#eee;">
				</label>

				<label style="display:flex; flex-direction:column; gap:2px;">
					<span style="color:#aaa;">meta txt 파일</span>
					<input type="file" id="dbg-buf2-meta" accept=".txt"
						   style="font-size:11px; color:#eee;">
				</label>

				<div style="display:flex; gap:6px; margin-top:2px;">
					<button onclick="loadBuf2AndResume('raw')" style="
						flex:1; padding:6px; background:#2e7d32; color:#fff;
						border:none; border-radius:6px; cursor:pointer;">
						▶ RAW 재개
					</button>
					<button onclick="loadBuf2AndResume('png')" style="
						flex:1; padding:6px; background:#1565c0; color:#fff;
						border:none; border-radius:6px; cursor:pointer;">
						▶ PNG 재개
					</button>
				</div>
			</div>

			<!-- 닫기 -->
			<button onclick="document.getElementById('debug-panel').style.display='none'" style="
				padding:4px; background:#333; color:#aaa;
				border:none; border-radius:6px; cursor:pointer; font-size:11px;">
				닫기
			</button>
		</div>

		<div class="panel-body">

			<div class="coord-row">
				<div class="coord-item">
					<div class="coord-label">LNG</div>
					<div class="coord-value" id="disp-lng">—</div>
				</div>
				<div class="coord-item">
					<div class="coord-label">LAT</div>
					<div class="coord-value" id="disp-lat">—</div>
				</div>
			</div>

			<!-- 좌표 직접 입력 -->
			<div class="coord-input-section">
				<div class="field-label">▸ 좌표 직접 입력</div>
				<div class="coord-input-row">
					<div class="coord-input-group">
						<div class="coord-input-label">위도 (LAT)</div>
						<input class="coord-input" id="input-lat" type="number" step="any" min="-90" max="90" placeholder="-90 ~ 90" />
					</div>
					<div class="coord-input-group">
						<div class="coord-input-label">경도 (LNG)</div>
						<input class="coord-input" id="input-lng" type="number" step="any" min="-180" max="180" placeholder="-180 ~ 180" />
					</div>
				</div>
				<button class="coord-go-btn" onclick="applyCoordInput()">↗ 이동 &amp; 선택</button>
				<div class="coord-error-msg" id="coord-error"></div>
			</div>

			<!-- Output 정보 -->
			<div>
				<div class="field-label">Output</div>
				<div class="info-box">
					<div class="coord-label">3 × 3 tiles &nbsp;|&nbsp; 1024 × 1024 px / tile</div>
					<div class="coord-value" id="info-total-km">9.216 km × 9.216 km</div>
				</div>
			</div>

			<!-- 높이 미리보기 -->
			<div class="height-info" id="height-info-wrap">
				<div class="height-item">
					<div class="h-label">Min Height</div>
					<div class="h-value" id="disp-min">— m</div>
				</div>
				<div class="height-item">
					<div class="h-label">Max Height</div>
					<div class="h-value" id="disp-max">— m</div>
				</div>
			</div>
			
			<!-- Height Offset 보정 -->
			<div class="height-offset-section">
				<div class="coord-label">Height Offset (m)</div>
				<div class="height-offset-row">
					<input
						id="input-height-offset"
						class="coord-input"
						type="number"
						step="1"
						value="0"
						placeholder="예: -300 또는 +100"
					/>
					<div class="height-offset-preview" id="offset-preview">±0 m</div>
				</div>
				<div class="coord-error-msg" id="offset-desc">
					다운로드 시 모든 픽셀 높이에 적용됩니다 (RAW 전용)
				</div>
			</div>

			<div class="height-preview-status" id="height-preview-status"></div>

			<!-- 진행 바 -->
			<div class="progress-wrap" id="progress-wrap">
				<div class="progress-label" id="progress-label">처리 중...</div>
				<div class="progress-bar-bg">
					<div class="progress-bar-fill" id="progress-fill"></div>
				</div>
			</div>

			<!-- 다운로드 버튼 -->
			<!--
			<div class="btn-row">
				<button class="download-btn btn-png" id="dl-btn-png" onclick="startDownload('png')">↓ PNG ZIP</button>
				<button class="download-btn btn-raw" id="dl-btn-raw" onclick="startDownload('raw')">↓ RAW ZIP</button>
			</div>
			
			<div class="status" id="status-msg">
				PNG: Mapbox RGB 재인코딩 &nbsp;|&nbsp; RAW: Float32 고도(m) · GL_R32F
			</div>
			-->
			
			<!-- Overview -->
			<div class="section-divider">
				<div class="field-label">Overview (512×512 · 1px=1mpp)</div>
				<button class="download-btn btn-overview" id="dl-btn-overview" onclick="startOverviewDownload()">
					↓ Overview PNG
				</button>
				<div class="status" id="status-overview">
					중심 타일 1장 · elev + normal
				</div>
			</div>
			
			<!-- 비트마스크보기 -->
			<div class="section-divider">
				<div class="field-label">비트마스크보기 열기</div>
				<button class="download-btn btn-overview" id="dl-btn-overview" onclick="window.open('https://mekjh12.ivyro.net/geng/bitmask_view.php');">
					비트마스크보기
				</button>
			</div>

			<!-- 디버그 패널 토글 (개발 중에만 사용) -->
			<div class="section-divider">

				<button onclick="window.open('https://mekjh12.ivyro.net/geng/river_edit.php');"
					style="bottom:12px; left:12px; z-index:9998;
						   background:#1a1a2e; color:#AFA9EC; border:1px solid #534AB7;
						   border-radius:6px; padding:6px 10px; cursor:pointer; font-size:12px;"> 
					지도폴리곤 편집
				</button>

				<button onclick="document.getElementById('debug-panel').style.display='flex'"
					style="bottom:12px; left:12px; z-index:9998;
						   background:#1a1a2e; color:#AFA9EC; border:1px solid #534AB7;
						   border-radius:6px; padding:6px 10px; cursor:pointer; font-size:12px;"> 
					🛠 디버그
				</button>
			</div>			
			

		</div>
	</div>

	<!-- ── 범례 ── -->
	<div class="legend">
		<div class="legend-item">
			<div class="legend-color" style="background:#888888;"></div>전체 영역
		</div>
		<div class="legend-item">
			<div class="legend-color" style="background:#00cc44;"></div>플레이 영역
		</div>
		<div class="legend-item">
			<div class="legend-color" style="background:#4464e1;"></div>시작 영역
		</div>
	</div>

	<!-- ── 힌트 ── -->
	<div class="hint" id="hint">지도를 클릭하거나 좌표를 입력하여 위치 선택</div>

	<!-- ── 확인 모달 ── -->
	<div id="confirm-modal">
		<div class="confirm-box">
			<div class="confirm-title" id="confirm-title">RAW 다운로드 확인</div>
			<div class="confirm-desc" id="confirm-desc"></div>
			<div class="confirm-btns">
				<button class="confirm-btn confirm-btn-cancel" id="confirm-cancel">취소</button>
				<button class="confirm-btn confirm-btn-ok" id="confirm-ok">↓ 다운로드</button>
			</div>
		</div>
	</div>
	
	<!-- 작업 선택 모달 -->
	<div id="choice-modal">
		<div class="choice-modal-inner">
			<div class="confirm-box">
				<div class="confirm-title" id="choice-title">작업 선택</div>
				<div class="confirm-desc" id="choice-desc"></div>

				<div class="confirm-btns">
					<button class="btn-choice-csv" id="choice-csv">↓ Road CSV</button>
					<button class="btn-choice-csv" id="choice-river">↓ River CSV</button>  <!-- ← 추가 -->
					<button class="confirm-btn confirm-btn-ok" id="choice-raw">↓ RAW ZIP</button>
					<button class="confirm-btn confirm-btn-cancel" id="choice-cancel">취소</button>
				</div>
			</div>
		</div>
	</div>
	
	<!-- ── RAW 확인 모달 ── -->
	<div id="raw-confirm-modal">
		<div class="confirm-box raw-confirm-box">
			<div class="raw-confirm-header">
				<div class="confirm-title">RAW 다운로드</div>
				<div class="confirm-desc">Float32 고도 데이터를 ZIP으로 내보냅니다.</div>
			</div>

			<div class="raw-file-status">
				<div class="raw-file-status-icon" id="raw-file-icon">
					<i class="ti ti-file-x" aria-hidden="true"></i>
				</div>
				<div class="raw-file-status-info">
					<div class="raw-file-label" id="raw-file-label">도로 평탄화 CSV 없음</div>
					<div class="raw-file-sub" id="raw-file-sub">평탄화를 적용하려면 파일을 첨부하세요</div>
				</div>
				<label class="raw-attach-btn" for="input-road-csv-raw">
					<i class="ti ti-paperclip" aria-hidden="true"></i>
					<span id="raw-attach-label">첨부</span>
				</label>
				<input type="file" id="input-road-csv-raw" accept=".csv" style="display:none;" onchange="onRawCsvSelected(this)" />
			</div>

			<!-- 기존 water CSV 블록 다음에 추가 -->
			<div class="raw-file-status">
				<div class="raw-file-status-icon" id="raw-waterway-file-icon">
					<i class="ti ti-file-x" aria-hidden="true"></i>
				</div>
				<div class="raw-file-status-info">
					<div class="raw-file-label" id="raw-waterway-file-label">강 중심선 CSV 없음</div>
					<div class="raw-file-sub" id="raw-waterway-file-sub">강 침식을 적용하려면 waterway 파일을 첨부하세요</div>
				</div>
				<label class="raw-attach-btn" for="input-waterway-csv-raw">
					<i class="ti ti-paperclip" aria-hidden="true"></i>
					<span id="raw-waterway-attach-label">첨부</span>
				</label>
				<input type="file" id="input-waterway-csv-raw" accept=".csv" style="display:none;" onchange="onRawWaterwayCsvSelected(this)" />
			</div>
			
			<div class="raw-file-status">
				<div class="raw-file-status-icon" id="raw-water-file-icon">
					<i class="ti ti-file-x" aria-hidden="true"></i>
				</div>
				<div class="raw-file-status-info">
					<div class="raw-file-label" id="raw-water-file-label">강 비트마스크 CSV 없음</div>
					<div class="raw-file-sub" id="raw-water-file-sub">강 영역을 비트마스크 처리하려면 파일을 첨부하세요</div>
				</div>
				<label class="raw-attach-btn" for="input-water-csv-raw">
					<i class="ti ti-paperclip" aria-hidden="true"></i>
					<span id="raw-water-attach-label">첨부</span>
				</label>
				<input type="file" id="input-water-csv-raw" accept=".csv" style="display:none;" onchange="onRawWaterCsvSelected(this)" />
			</div>
			<div class="raw-confirm-btns">
				<button type="button" id="btn-auto-test" style="background: #e74c3c; color: white; padding: 5px 10px; border-radius: 4px;">
					⚡ 테스트 파일 자동 세팅
				</button>
				<button class="raw-btn raw-btn-cancel" id="raw-confirm-cancel">
					<i class="ti ti-x" aria-hidden="true"></i> 취소
				</button>
				<button class="raw-btn raw-btn-ok" id="raw-confirm-ok">
					<i class="ti ti-download" aria-hidden="true"></i> 시작
				</button>
			</div>
		</div>
	</div>

	<!-- ── 로딩 오버레이 ── -->
	<div id="loading-overlay">
		<div class="loading-box">
			<div class="loading-spinner"></div>
			<div class="loading-text" id="loading-text">하이트맵 생성 중</div>
			<div id="loading-format-badge"></div>

			<div class="pipeline-steps">
				<div class="pipe-step" id="pipe-step-1">
					<div class="pipe-dot"></div>
					<div class="pipe-label">버퍼1 <span class="pipe-desc">전체영역 다운로드 → crop → 1024×1024</span></div>
				</div>
				<div class="pipe-step" id="pipe-step-2">
					<div class="pipe-dot"></div>
					<div class="pipe-label">버퍼2 <span class="pipe-desc">bicubic 업샘플 → 9216×9216</span></div>
				</div>
				<div class="pipe-step" id="pipe-step-3">
					<div class="pipe-dot"></div>
					<div class="pipe-label">버퍼3 <span class="pipe-desc">Gaussian blur</span></div>
				</div>
				<div class="pipe-step" id="pipe-step-4">
					<div class="pipe-dot"></div>
					<div class="pipe-label">도로 평탄화 <span class="pipe-desc" id="pipe-flatten-desc">도로 CSV 없음 · 스킵</span></div>
				</div>
				<div class="pipe-step" id="pipe-step-4-1">
					<div class="pipe-dot"></div>
					<div class="pipe-label">강 평탄화 <span class="pipe-desc" id="pipe-river-flatten-desc">강 CSV 없음 · 스킵</span></div>
				</div>
				<div class="pipe-step" id="pipe-step-5">
					<div class="pipe-dot"></div>
					<div class="pipe-label">출력 <span class="pipe-desc">타일 추출 → ZIP</span></div>
				</div>
			</div>

			<div class="load-progress-wrap">
				<div class="load-progress-bg">
					<div class="load-progress-fill" id="load-progress-fill"></div>
				</div>
				<div class="load-progress-label" id="load-progress-label">0%</div>
			</div>

			<div class="loading-sub" id="loading-sub">잠시만 기다려주세요...</div>

			<div class="tile-grid-wrap">
				<div class="tile-grid" id="tile-progress-grid"></div>
			</div>
		</div>
	</div>

	<!-- ── JS ── -->
	<script src="js/config.js"></script>
	<script src="js/map.js"></script>
	<script src="js/my.js"></script>
	<script src="js/pbf.js"></script>
	<script src="js/process.js"></script>
	<script src="js/road.js"></script>
	<script src="js/river.js"></script>
	<script src="js/river_utils.js"></script>
	<script src="js/ui.js"></script>
	
<script>

document.getElementById('btn-auto-test').addEventListener('click', () => loadWebTestFiles());

document.getElementById('debug-panel').style.display='none';

async function loadWebTestFiles() {
    try {
        // 1. 웹에 올려둔 파일의 실제 URL 주소 정의 (예시 주소이므로 실제 URL로 변경하세요)
		const fileUrls = {
			// 상대 경로를 사용하면 자동으로 HTTPS 프로토콜과 도메인이 유지되어 Mixed Content 에러가 해결됩니다.
			'input-road-csv-raw': './data/roads_4_4.csv',
			'input-waterway-csv-raw': './data/waterway_4_4.csv',
			'input-water-csv-raw': './data/water_4_4.csv'
		};
		
        console.log("⏳ 웹에서 실제 파일을 다운로드 중...");

        // 2. 오브젝트를 돌면서 순서대로 처리
        for (const [inputId, url] of Object.entries(fileUrls)) {
            const inputEl = document.getElementById(inputId);
            if (!inputEl) continue;

            // 웹 URL로부터 파일 데이터 가져오기 (fetch)
            const response = await fetch(url);
            if (!response.ok) throw new Error(`파일 로드 실패: ${url}`);

            const blob = await response.blob();
            
            // URL에서 파일명 추출 (예: road_actual.csv)
            const fileName = url.substring(url.lastIndexOf('/') + 1);

            // 진짜 데이터가 담긴 File 객체 생성
            const realFile = new File([blob], fileName, { type: "text/csv" });

            // input 태그에 파일 강제 주입
            const dt = new DataTransfer();
            dt.items.add(realFile);
            inputEl.files = dt.files;

            // 기존에 HTML에 정의된 onchange 함수(onRawCsvSelected 등) 실행!
            inputEl.dispatchEvent(new Event('change'));
        }

        //alert("🌐 웹 서버의 실제 파일 3개가 모두 성공적으로 로드되었습니다!");
    } catch (error) {
        console.error("❌ 파일 로드 중 오류 발생:", error);
        alert("파일을 불러오는데 실패했습니다. 콘솔 창(F12)을 확인하세요.");
    }
}

</script>
	
	
</body>
</html>