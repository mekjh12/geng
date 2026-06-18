<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>Feature RAW Visualizer (1025/129)</title>
    <style>
        :root { --inspector-width: 350px; --bg-color: #0f0f0f; --text-color: #e0e0e0; --accent-color: #4a9eff; }
        body { font-family: 'Pretendard', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; display: flex; height: 100vh; overflow: hidden; }
        
        /* 메인 콘텐츠 영역 */
        #main-content { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; overflow: auto; }
        
        /* 뷰어 컨테이너 (1025x1025의 거대한 크기를 고려하여 max-size 설정) */
        #viewer-container { background: #000; border: 1px solid #333; box-shadow: 0 0 30px rgba(0,0,0,0.7); position: relative; line-height: 0; }
        canvas { display: block; image-rendering: pixelated; max-width: 85vh; max-height: 85vh; }

        /* 사이드바 인스펙터 */
        #inspector { width: var(--inspector-width); background: #1a1a1a; border-left: 1px solid #2a2a2a; padding: 25px; display: flex; flex-direction: column; overflow-y: auto; }
        .control-group { margin-bottom: 20px; }
        .label-text { font-size: 0.75rem; color: #888; margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: 1px; }
        
        .stats-card { background: #000; padding: 15px; border-radius: 4px; font-size: 0.85rem; color: #aaa; border: 1px solid #2a2a2a; line-height: 1.6; }
        
        /* 레이어 리스트 스타일 */
        .layer-item { 
            display: flex; align-items: center; padding: 10px; background: #222; border: 1px solid #333; 
            margin-bottom: 5px; border-radius: 4px; cursor: pointer; transition: 0.2s;
        }
        .layer-item:hover { background: #2a2a2a; }
        .layer-item input { margin-right: 12px; transform: scale(1.2); cursor: pointer; }
        .color-dot { width: 12px; height: 12px; border-radius: 2px; margin-right: 10px; }
        
        hr { border: none; border-top: 1px solid #2a2a2a; margin: 15px 0 20px; }
        .btn-upload { position: relative; width: 100%; padding: 15px; background: #2a2a2a; border: 1px dashed #555; color: #eee; border-radius: 4px; text-align: center; cursor: pointer; }
        .btn-upload:hover { background: #333; border-color: var(--accent-color); }
        #rawInput { position: absolute; left: 0; top: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
        
        .status-msg { font-size: 0.8rem; margin-top: 15px; color: var(--accent-color); font-family: monospace; }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>

    <main id="main-content">
        <div id="viewer-container">
            <!-- 1025x1025 데이터를 그릴 캔버스 -->
            <canvas id="v-canvas"></canvas>
        </div>
        <div id="statusInfo" class="status-msg">READY: 1025x1025 RAW 파일을 업로드하세요.</div>
    </main>

    <aside id="inspector">
        <h4 style="margin-top:0; color:var(--accent-color); letter-spacing: -0.5px;">FEATURE RAW DEBUGGER</h4>

        <div class="stats-card">
            Target Resolution: <strong>1025 x 1025</strong><br>
            Expected Size: <strong>1,050,625 Bytes</strong>
        </div>

        <hr>

        <div class="control-group">
            <span class="label-text">Source RAW File</span>
            <div class="btn-upload">
                <span>파일 선택 (.raw)</span>
                <input type="file" id="rawInput" accept=".raw">
            </div>
        </div>

        <div class="control-group">
            <span class="label-text">Bitmask Layers</span>
            <div id="layerList">
                <!-- 비트별 레이어 동적 생성 -->
            </div>
        </div>

        <hr>
        
        <div class="stats-card" style="font-size: 0.75rem; color: #666;">
            * <strong>1025 모드:</strong> 1024 타일의 경계 픽셀을 포함한 상태로 시각화합니다.<br>
            * 하단 테두리 노이즈 발생 시 파일 바이트 크기를 확인하세요.
        </div>
    </aside>

    <script>
        const canvas = document.getElementById('v-canvas');
        const ctx = canvas.getContext('2d');
        const rawInput = document.getElementById('rawInput');
        const layerList = document.getElementById('layerList');
        const statusInfo = document.getElementById('statusInfo');

		let TARGET_SIZE = 0; // 변수로 변경하여 동적 할당
        let rawBuffer = null;

        // 비트 정의 (사용자 로직과 동기화)
        const layers = [
            { bit: 0, name: "강 (River)", color: "#00a2ff" },
            { bit: 1, name: "길 (Road)", color: "#ffee00" },
            { bit: 2, name: "초원 (Meadow)", color: "#44ff44" },
            { bit: 3, name: "숲 (Forest)", color: "#007700" },
            { bit: 4, name: "설원 (Snow)", color: "#ffffff" },
            { bit: 5, name: "도시 (City)", color: "#cc44ff" },
            { bit: 6, name: "암석 (Rock)", color: "#888888" },
            { bit: 7, name: "Spare", color: "#ff4400" }
        ];

        // 레이어 UI 생성
        layers.forEach(layer => {
            const label = document.createElement('label');
            label.className = 'layer-item';
            label.innerHTML = `
                <input type="checkbox" checked data-bit="${layer.bit}">
                <div class="color-dot" style="background:${layer.color}"></div>
                <span style="flex:1; font-size:0.85rem;">Bit ${layer.bit}: ${layer.name}</span>
            `;
            label.querySelector('input').addEventListener('change', render);
            layerList.appendChild(label);
        });

        // 🔄 [수정] 파일 로드
        rawInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;

            const buffer = await file.arrayBuffer();
            rawBuffer = new Uint8Array(buffer);
            
            // ⭐ 파일 크기(바이트)의 제곱근을 구하여 정사각형 크기 자동 계산
            TARGET_SIZE = Math.sqrt(rawBuffer.length);

            // 완벽한 정사각형으로 떨어지지 않을 경우 예외 처리
            if (!Number.isInteger(TARGET_SIZE)) {
                TARGET_SIZE = Math.floor(TARGET_SIZE); 
                statusInfo.style.color = "#ff4444";
                statusInfo.innerText = `WARNING: 정밀 정사각형이 아닙니다. (${TARGET_SIZE}x${TARGET_SIZE} 근사치 렌더링)`;
            } else {
                statusInfo.style.color = "#4a9eff";
                statusInfo.innerText = `LOADED: ${file.name} (${TARGET_SIZE.toLocaleString()} x ${TARGET_SIZE.toLocaleString()})`;
            }
			
			// 💡 [추가] 사이드바 UI 텍스트 정보 동적 업데이트
			const uiResolution = document.getElementById('ui-resolution');
			const uiExpectedSize = document.getElementById('ui-expected-size');
			
			if (uiResolution && uiExpectedSize) {
				uiResolution.innerText = `${TARGET_SIZE.toLocaleString()} x ${TARGET_SIZE.toLocaleString()}`;
				uiExpectedSize.innerText = rawBuffer.length.toLocaleString();
			}
	
            render();
        });

        // 🔄 [수정] 렌더링 함수
        function render() {
            if (!rawBuffer || TARGET_SIZE === 0) return;

            // 계산된 동적 크기를 캔버스에 적용
            canvas.width = TARGET_SIZE;
            canvas.height = TARGET_SIZE;
            const imgData = ctx.createImageData(TARGET_SIZE, TARGET_SIZE);
            const pixels = imgData.data;

            const activeBits = Array.from(document.querySelectorAll('#layerList input:checked'))
                                    .map(input => parseInt(input.dataset.bit));

            // 이미지 버퍼 크기만큼만 안전하게 루프 돌도록 조건 수정
            const loopLimit = Math.min(rawBuffer.length, TARGET_SIZE * TARGET_SIZE);

            for (let i = 0; i < loopLimit; i++) {
                const val = rawBuffer[i];
                const outIdx = i * 4;

                let r = 0, g = 0, b = 0, alpha = 20;

                for (const bitIdx of activeBits) {
                    if ((val >> bitIdx) & 1) {
                        const colorHex = layers[bitIdx].color;
                        r = parseInt(colorHex.slice(1, 3), 16);
                        g = parseInt(colorHex.slice(3, 5), 16);
                        b = parseInt(colorHex.slice(5, 7), 16);
                        alpha = 255;
                    }
                }

                pixels[outIdx] = r;
                pixels[outIdx + 1] = g;
                pixels[outIdx + 2] = b;
                pixels[outIdx + 3] = alpha;
            }

            ctx.putImageData(imgData, 0, 0);
        }
    </script>
</body>
</html>