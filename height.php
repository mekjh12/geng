<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>High-Res Heightmap (R+G Encoding)</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <style>
        :root { --inspector-width: 320px; --bg-color: #0f0f0f; --text-color: #e0e0e0; --accent-color: #ff4444; }
        body { font-family: 'Pretendard', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; display: flex; height: 100vh; overflow: hidden; }
        #main-content { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px; overflow: auto; }
        #grid-container { display: grid; grid-template-columns: repeat(9, 1fr); gap: 2px; background: #333; }
        canvas { background: #000; width: 95px; height: 95px; display: block; }
        #inspector { width: var(--inspector-width); background: #1a1a1a; border-left: 1px solid #2a2a2a; padding: 25px; display: flex; flex-direction: column; overflow-y: auto; }
        .control-group { margin-bottom: 25px; }
        .label-text { font-size: 0.8rem; color: #666; margin-bottom: 10px; display: block; }
        .btn { width: 100%; padding: 12px; background: #2a2a2a; border: 1px solid #444; color: #eee; border-radius: 4px; cursor: pointer; }
        .btn-primary { background: var(--accent-color); border: none; font-weight: bold; }
        .stats-card { background: #000; padding: 15px; border-radius: 4px; font-size: 0.85rem; color: #888; border: 1px solid #2a2a2a; line-height: 1.5; }
        .sub-status { font-size: 0.75rem; color: #4a9eff; margin-top: 6px; min-height: 1em; }
        hr { border: none; border-top: 1px solid #2a2a2a; margin: 5px 0 20px; }
		
		#legend-container {    width: 871px;    margin-bottom: 15px;}
		#height-legend-bar {    box-shadow: inset 0 0 5px rgba(0,0,0,0.5);}
    </style>
</head>
<body>

    <?php include 'menu.php'; ?>
	<main id="main-content">
		<div id="legend-container" style="width: 800px; margin-bottom: 10px;">
			<div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px; color: #aaa;">
				<span>Low (0m)</span>
				<span>Height Gradient (R+G Encoding)</span>
				<span>High (Max)</span>
			</div>
			<div id="height-legend-bar" style="width: 100%; height: 12px; border-radius: 2px; background: linear-gradient(to right, #00ff00, #ff0000); border: 1px solid #333;"></div>
		</div>
		
		<div id="grid-container"></div>
	</main>

    <aside id="inspector">
        <h4 style="margin-top:0;">16-BIT R+G EXPORTER</h4>

        <div class="stats-card" id="statusBox" style="margin-bottom: 20px;">
            Encoding: <strong>R(High) + G(Low)</strong><br>
            Origin: <strong>Bottom-Left (0_0)</strong>
        </div>

        <!-- ── Heightmap ── -->
        <div class="control-group">
            <span class="label-text">SOURCE HEIGHTMAP</span>
            <input type="file" id="heightInput" accept="image/png">
        </div>

        <hr>

        <!-- ── Road CSV ── -->
        <div class="control-group">
            <span class="label-text">ROAD CSV (road_X_Y.csv) — Blue Ch.</span>
            <input type="file" id="roadInput" accept=".csv">
            <div id="roadStatus" class="sub-status"></div>
        </div>

        <!-- ── Water CSV ── -->
        <div class="control-group">
            <span class="label-text">Water CSV (water_X_Y.csv) — Blue Ch.</span>
            <input type="file" id="waterInput" accept=".csv">
            <div id="waterStatus" class="sub-status"></div>
        </div>

        <hr>

        <!-- ── Button ── -->
        <div class="control-group">
            <button id="downloadZip" class="btn btn-primary" disabled>DOWNLOAD 16-BIT ZIP</button>
        </div>

        <div class="control-group">
            <button id="downloadFeature" class="btn btn-primary" disabled>DOWNLOAD Feature</button>
        </div>

		<div class="control-group">
			<button id="downloadFarTerrain" class="btn btn-primary" disabled>DOWNLOAD FAR TERRAIN PNG</button>
		</div>

        <div class="control-group">
            <button id="initCanvasBtn" class="btn btn-primary">Canvas 초기화</button>
        </div>
    </aside>
	
    <script>
        // ── 상수 ────────────────────────────────────────────────────────
        const GRID_COUNT       = 9;
        const SOURCE_FULL_SIZE = 1081;
        const OUTPUT_TILE_SIZE = 1024;
        const SOURCE_TILE_SIZE = SOURCE_FULL_SIZE / GRID_COUNT;
        const FULL_OUTPUT_SIZE = OUTPUT_TILE_SIZE * GRID_COUNT; // 9216

        // ── DOM ─────────────────────────────────────────────────────────
        const heightInput   = document.getElementById('heightInput');
        const intensityInput= document.getElementById('noiseIntensity');
        const intensityVal  = document.getElementById('intensityVal');
        const gridContainer = document.getElementById('grid-container');
        const downloadBtn   = document.getElementById('downloadZip');
		const downloadFeature   = document.getElementById('downloadFeature');
        const statusBox     = document.getElementById('statusBox');
        const roadInput     = document.getElementById('roadInput');
        const roadStatus    = document.getElementById('roadStatus');
        const waterInput     = document.getElementById('waterInput');
        const waterStatus    = document.getElementById('waterStatus');
		const initCanvasBtn    = document.getElementById('initCanvasBtn');
		const downloadFarBtn = document.getElementById('downloadFarTerrain');


        // ── 상태 ────────────────────────────────────────────────────────
        let globalOffsetX = 0;
        let globalOffsetY = 0;
        let currentImg    = null;
        let roadSegments  = []; // [{points:[{px,py},...]}]

        // 타일별 ImageData 보관 (도로 레이어 재적용을 위해)
        const tileImageDatas = new Array(GRID_COUNT * GRID_COUNT).fill(null);

        // ── 초기 캔버스 그리드 생성 ──────────────────────────────────────
        const canvases = [];
		for (let i = 0; i < GRID_COUNT * GRID_COUNT; i++) {
			const cvs = document.createElement('canvas');
			cvs.width  = OUTPUT_TILE_SIZE;
			cvs.height = OUTPUT_TILE_SIZE;
			cvs.style.cursor = 'pointer';

			cvs.addEventListener('click', function() {
				const win = window.open('', '_blank', 'width=1024,height=1024');
				win.document.write(`
					<html>
					<head>
						<meta charset='UTF-8'>
						<title>Tile Preview</title>
						<style>
							body { margin:0; background:#000; display:flex; align-items:center; justify-content:center; height:100vh; }
							img { max-width:100%; max-height:100%; image-rendering:pixelated; }
						</style>
					</head>
					<body>
						<img src="${cvs.toDataURL('image/png')}">
					</body>
					</html>
				`);
				win.document.close();
			});

			gridContainer.appendChild(cvs);
			canvases.push(cvs);
		}
		
		
	</script>
	
	<script src="./height_split.js?t=<?=time()?>"></script>
	<script src="./height_road.js?t=<?=time()?>"></script>
	<script src="./height_water.js?t=<?=time()?>"></script>
	<script src="./height_download.js?t=<?=time()?>"></script>
	<script src="./height_feature_download.js?t=<?=time()?>"></script>	
	
	<script> // ── initCanvas ───────────────────────────────────────────────
	initCanvasBtn.onclick = async function() {
		this.innerText = "초기화 완료됨!";
		this.disabled = true;
		initCanvases();
	};

	function initCanvases() {
		
		const img = new Image();
		currentImg = img;
					
		// 원본 픽셀 데이터를 1081x1081로 읽기
		const srcCanvas = document.createElement('canvas');
		srcCanvas.width  = SOURCE_FULL_SIZE;
		srcCanvas.height = SOURCE_FULL_SIZE;
		const srcCtx = srcCanvas.getContext('2d');
		srcCtx.drawImage(currentImg, 0, 0, SOURCE_FULL_SIZE, SOURCE_FULL_SIZE);
		const srcData = srcCtx.getImageData(0, 0, SOURCE_FULL_SIZE, SOURCE_FULL_SIZE).data;

		for (let r = 0; r < GRID_COUNT; r++) {
			for (let c = 0; c < GRID_COUNT; c++) {
				const idx = r * GRID_COUNT + c;
				const imageData = canvases[idx].getContext('2d')
					.createImageData(OUTPUT_TILE_SIZE, OUTPUT_TILE_SIZE);
				const data = imageData.data;

				// 이 타일의 원본 시작 픽셀 (SOURCE_TILE_SIZE = 1081/9 = 120.11...)
				const tileOriginX = c * SOURCE_TILE_SIZE;
				const tileOriginY = r * SOURCE_TILE_SIZE;

				for (let py = 0; py < OUTPUT_TILE_SIZE; py++) {
					for (let px = 0; px < OUTPUT_TILE_SIZE; px++) {
						// 출력 픽셀 → 원본 좌표 매핑
						// OUTPUT_TILE_SIZE(1024) 픽셀이 SOURCE_TILE_SIZE(120.11) 구간을 커버
						const u = tileOriginX + (px / (OUTPUT_TILE_SIZE - 1)) * SOURCE_TILE_SIZE;
						const v = tileOriginY + (py / (OUTPUT_TILE_SIZE - 1)) * SOURCE_TILE_SIZE;
											
						const i = (py * OUTPUT_TILE_SIZE + px) * 4; 
						data[i + 0] = 0;
						data[i + 1] = 0;
						data[i + 2] = 0;
						data[i + 3] = 255;
					}
				}

				tileImageDatas[idx] = imageData;
				canvases[idx].getContext('2d').putImageData(imageData, 0, 0);
			}
		}

		downloadBtn.disabled = false;
		downloadFeature.disabled = false;
		downloadFarBtn.disabled = false; 
		
		this.innerText = "canvas 초기화";
	}
	</script>
	
	
	<script> // ── Heightmap 로드 ───────────────────────────────────────────────
	heightInput.addEventListener('change', function(e) {
		const file = e.target.files[0];
		if (!file) return;

		const match = file.name.match(/(\d+)x(\d+)/);
		if (match) {
			globalOffsetX = parseInt(match[1]) * 9;
			globalOffsetY = parseInt(match[2]) * 9;
			statusBox.innerHTML = `<strong>Offset Detected</strong><br>X: +${globalOffsetX}, Y: +${globalOffsetY}<br>Mode: R+G Encoding`;
		}

		const reader = new FileReader();
		reader.onload = (ev) => {
			const img = new Image();
			img.onload = () => { 
				currentImg = img; 
				processHeightmap(img); 
			};
			img.src = ev.target.result;
		};
		reader.readAsDataURL(file);
	});

	</script>
	

</body>
</html>