<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Heightmap Processor</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@300;400;600;700&family=Bebas+Neue&display=swap');

:root {
  --bg: #060608;
  --surface: #0d0d12;
  --card: #111118;
  --border: #1c1c28;
  --border2: #252535;
  --accent: #c8ff00;
  --accent2: #00c8ff;
  --accent3: #ff6b35;
  --text: #dddde8;
  --muted: #4a4a65;
  --muted2: #6a6a85;
  --danger: #ff4455;
  --success: #00e87a;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: 'IBM Plex Mono', monospace;
  min-height: 100vh;
  overflow-x: hidden;
}

/* ── 배경 그리드 ── */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(200,255,0,0.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(200,255,0,0.025) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
  z-index: 0;
}

/* ── 상단 헤더 ── */
header {
  position: relative;
  z-index: 1;
  padding: 32px 40px 24px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: flex-end;
  gap: 24px;
}

.logo {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 42px;
  letter-spacing: 4px;
  line-height: 1;
  color: var(--accent);
  text-shadow: 0 0 40px rgba(200,255,0,0.3);
}

.logo span {
  color: var(--muted2);
}

.header-sub {
  font-size: 10px;
  letter-spacing: 3px;
  text-transform: uppercase;
  color: var(--muted);
  padding-bottom: 6px;
}

.header-badge {
  margin-left: auto;
  display: flex;
  gap: 8px;
  align-items: center;
  padding-bottom: 4px;
}

.badge {
  font-size: 9px;
  letter-spacing: 2px;
  text-transform: uppercase;
  padding: 4px 10px;
  border-radius: 2px;
  border: 1px solid;
}

.badge-green { border-color: var(--accent); color: var(--accent); background: rgba(200,255,0,0.05); }
.badge-blue  { border-color: var(--accent2); color: var(--accent2); background: rgba(0,200,255,0.05); }
.badge-orange { border-color: var(--accent3); color: var(--accent3); background: rgba(255,107,53,0.05); }

/* ── 메인 레이아웃 ── */
.main {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: 360px 1fr;
  gap: 0;
  min-height: calc(100vh - 97px);
}

/* ── 사이드 패널 ── */
.sidebar {
  border-right: 1px solid var(--border);
  padding: 28px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.section-title {
  font-size: 9px;
  letter-spacing: 3px;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.section-title::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}

/* ── 드롭존 ── */
.dropzone {
  border: 1px dashed var(--border2);
  border-radius: 4px;
  padding: 28px 20px;
  text-align: center;
  cursor: pointer;
  transition: all 0.25s;
  background: var(--surface);
  position: relative;
  overflow: hidden;
}

.dropzone::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(200,255,0,0.03) 0%, transparent 60%);
  pointer-events: none;
}

.dropzone:hover, .dropzone.drag-over {
  border-color: var(--accent);
  background: rgba(200,255,0,0.04);
}

.dropzone.drag-over { border-style: solid; }

.drop-icon {
  font-size: 28px;
  margin-bottom: 10px;
  opacity: 0.5;
}

.drop-text {
  font-size: 11px;
  color: var(--muted2);
  line-height: 1.6;
}

.drop-text strong {
  color: var(--accent);
  display: block;
  font-size: 12px;
  margin-bottom: 4px;
}

.drop-hint {
  font-size: 9px;
  color: var(--muted);
  margin-top: 10px;
  letter-spacing: 1px;
}

/* ── 파일 목록 ── */
.file-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 200px;
  overflow-y: auto;
}

.file-list::-webkit-scrollbar { width: 3px; }
.file-list::-webkit-scrollbar-track { background: var(--border); }
.file-list::-webkit-scrollbar-thumb { background: var(--muted); border-radius: 2px; }

.file-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 3px;
  font-size: 10px;
  transition: border-color 0.2s;
}

.file-item.active { border-color: var(--accent); }
.file-item.error  { border-color: var(--danger); }
.file-item.done   { border-color: var(--success); }

.file-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--muted);
  flex-shrink: 0;
}

.file-item.active .file-dot { background: var(--accent2); animation: pulse 0.8s infinite alternate; }
.file-item.error  .file-dot { background: var(--danger); }
.file-item.done   .file-dot { background: var(--success); }

@keyframes pulse { to { opacity: 0.3; } }

.file-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--text);
}

.file-size {
  color: var(--muted);
  font-size: 9px;
  flex-shrink: 0;
}

.file-remove {
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  font-size: 12px;
  padding: 0 2px;
  transition: color 0.2s;
  flex-shrink: 0;
}

.file-remove:hover { color: var(--danger); }

/* ── 설정 ── */
.setting-group {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.setting-row {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.setting-label {
  font-size: 9px;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--muted2);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.setting-value {
  font-size: 11px;
  color: var(--accent);
  font-weight: 600;
}

.slider {
  -webkit-appearance: none;
  appearance: none;
  width: 100%;
  height: 2px;
  background: var(--border2);
  border-radius: 1px;
  outline: none;
  cursor: pointer;
}

.slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 12px; height: 12px;
  border-radius: 50%;
  background: var(--accent);
  cursor: pointer;
  box-shadow: 0 0 8px rgba(200,255,0,0.5);
}

.slider::-moz-range-thumb {
  width: 12px; height: 12px;
  border-radius: 50%;
  background: var(--accent);
  border: none;
  cursor: pointer;
}

/* 토글 스위치 */
.toggle-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 10px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 3px;
}

.toggle-label {
  font-size: 10px;
  color: var(--muted2);
  letter-spacing: 1px;
}

.toggle {
  position: relative;
  width: 32px;
  height: 18px;
}

.toggle input { display: none; }

.toggle-track {
  position: absolute;
  inset: 0;
  background: var(--border2);
  border-radius: 9px;
  cursor: pointer;
  transition: background 0.2s;
}

.toggle input:checked + .toggle-track { background: var(--accent); }

.toggle-thumb {
  position: absolute;
  left: 3px;
  top: 3px;
  width: 12px; height: 12px;
  border-radius: 50%;
  background: var(--muted);
  transition: all 0.2s;
  pointer-events: none;
}

.toggle input:checked ~ .toggle-thumb {
  left: 17px;
  background: var(--bg);
}

/* 선택 버튼 그룹 */
.select-group {
  display: flex;
  gap: 4px;
}

.select-btn {
  flex: 1;
  padding: 7px;
  border: 1px solid var(--border2);
  border-radius: 3px;
  background: var(--surface);
  color: var(--muted2);
  font-family: 'IBM Plex Mono', monospace;
  font-size: 9px;
  letter-spacing: 1px;
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
}

.select-btn:hover { border-color: var(--muted); color: var(--text); }
.select-btn.active { border-color: var(--accent); color: var(--accent); background: rgba(200,255,0,0.06); }

/* ── 처리 버튼 ── */
.process-btn {
  width: 100%;
  padding: 16px;
  border: none;
  border-radius: 3px;
  background: var(--accent);
  color: var(--bg);
  font-family: 'Bebas Neue', sans-serif;
  font-size: 18px;
  letter-spacing: 3px;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
  overflow: hidden;
}

.process-btn::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0.2);
  opacity: 0;
  transition: opacity 0.2s;
}

.process-btn:hover::after { opacity: 1; }
.process-btn:active { transform: scale(0.99); }

.process-btn:disabled {
  background: var(--border) !important;
  color: var(--muted) !important;
  cursor: not-allowed;
}

/* ── 콘텐츠 영역 ── */
.content {
  padding: 28px;
  display: flex;
  flex-direction: column;
  gap: 24px;
  overflow-y: auto;
}

/* ── 처리 상태 카드 ── */
.status-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 20px;
  display: none;
}

.status-card.visible { display: block; }

.status-top {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.status-indicator {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--accent2);
  animation: pulse 0.6s infinite alternate;
  flex-shrink: 0;
}

.status-indicator.done { background: var(--success); animation: none; }
.status-indicator.err  { background: var(--danger); animation: none; }

.status-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--text);
}

.status-detail {
  font-size: 10px;
  color: var(--muted2);
  margin-left: auto;
}

/* 프로그레스 바 */
.prog-bars {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.prog-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.prog-name {
  font-size: 9px;
  letter-spacing: 1px;
  color: var(--muted2);
  width: 80px;
  flex-shrink: 0;
  text-transform: uppercase;
}

.prog-track {
  flex: 1;
  height: 2px;
  background: var(--border);
  border-radius: 1px;
  overflow: hidden;
}

.prog-fill {
  height: 100%;
  width: 0%;
  border-radius: 1px;
  transition: width 0.4s;
}

.prog-fill.green  { background: var(--accent); }
.prog-fill.blue   { background: var(--accent2); }
.prog-fill.orange { background: var(--accent3); }

.prog-pct {
  font-size: 9px;
  color: var(--muted);
  width: 30px;
  text-align: right;
  flex-shrink: 0;
}

/* ── 프리뷰 그리드 ── */
.preview-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
}

.preview-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 4px;
  overflow: hidden;
  transition: border-color 0.2s;
}

.preview-card:hover { border-color: var(--border2); }

.preview-header {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.preview-title {
  font-size: 10px;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--muted2);
}

.preview-meta {
  font-size: 9px;
  color: var(--muted);
}

.preview-canvas-wrap {
  position: relative;
  background: #000;
  aspect-ratio: 1;
  overflow: hidden;
}

.preview-canvas {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
  image-rendering: pixelated;
}

.preview-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(6,6,8,0.85);
  font-size: 11px;
  color: var(--muted2);
  letter-spacing: 1px;
  text-transform: uppercase;
}

.preview-overlay.hidden { display: none; }

.preview-footer {
  padding: 10px 16px;
  border-top: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.preview-info {
  font-size: 9px;
  color: var(--muted);
}

.dl-btn {
  padding: 5px 14px;
  border: 1px solid var(--border2);
  border-radius: 2px;
  background: none;
  color: var(--muted2);
  font-family: 'IBM Plex Mono', monospace;
  font-size: 9px;
  letter-spacing: 1px;
  cursor: pointer;
  transition: all 0.2s;
  text-transform: uppercase;
}

.dl-btn:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.dl-btn:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

/* ── 빈 상태 ── */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 400px;
  gap: 16px;
  color: var(--muted);
  text-align: center;
}

.empty-icon {
  font-size: 48px;
  opacity: 0.2;
}

.empty-text {
  font-size: 11px;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--muted);
}

.empty-sub {
  font-size: 10px;
  color: var(--muted);
  opacity: 0.6;
  line-height: 1.8;
}

/* ── 로그 패널 ── */
.log-panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 0;
  overflow: hidden;
}

.log-header {
  padding: 10px 16px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 8px;
}

.log-dot {
  width: 5px; height: 5px;
  border-radius: 50%;
  background: var(--success);
}

.log-title {
  font-size: 9px;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--muted);
}

.log-body {
  padding: 12px 16px;
  max-height: 120px;
  overflow-y: auto;
  font-size: 10px;
  color: var(--muted2);
  line-height: 1.8;
}

.log-body::-webkit-scrollbar { width: 3px; }
.log-body::-webkit-scrollbar-track { background: transparent; }
.log-body::-webkit-scrollbar-thumb { background: var(--muted); border-radius: 2px; }

.log-line { display: flex; gap: 10px; }
.log-time { color: var(--muted); flex-shrink: 0; }
.log-msg.ok { color: var(--success); }
.log-msg.err { color: var(--danger); }
.log-msg.info { color: var(--accent2); }

/* ── 결과 ZIP 버튼 ── */
.zip-btn {
  width: 100%;
  padding: 14px;
  border: 1px solid var(--accent3);
  border-radius: 3px;
  background: rgba(255,107,53,0.08);
  color: var(--accent3);
  font-family: 'Bebas Neue', sans-serif;
  font-size: 16px;
  letter-spacing: 3px;
  cursor: pointer;
  transition: all 0.2s;
  display: none;
}

.zip-btn.visible { display: block; }
.zip-btn:hover { background: rgba(255,107,53,0.15); }

/* ── 스크롤바 ── */
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: var(--border); }
::-webkit-scrollbar-thumb { background: var(--muted); border-radius: 2px; }

input[type=file] { display: none; }

/* ── 타일 이름 정보 ── */
.tile-info-strip {
  padding: 8px 16px 10px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.tile-coord-tag {
  font-size: 9px;
  padding: 2px 7px;
  border-radius: 2px;
  border: 1px solid var(--border2);
  color: var(--muted2);
  letter-spacing: 1px;
}

/* 높이값 표 */
.height-strip {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 1px;
  background: var(--border);
  border: 1px solid var(--border);
  border-radius: 3px;
  overflow: hidden;
}

.h-cell {
  background: var(--surface);
  padding: 8px 10px;
}

.h-lbl {
  font-size: 8px;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 3px;
}

.h-val {
  font-size: 12px;
  font-weight: 600;
  color: var(--text);
}

.h-val.green { color: var(--accent); }
.h-val.blue  { color: var(--accent2); }
.h-val.orange{ color: var(--accent3); }
</style>
</head>
<body>

<header>
  <div>
    <div class="logo">HEIGHT<span>MAP</span> PROC</div>
    <div class="header-sub">Normal Map · Low-Res Generator · RAW → RAW Pipeline</div>
  </div>
  <div class="header-badge">
    <span class="badge badge-green">RAW INPUT</span>
    <span class="badge badge-blue">_normal.raw</span>
    <span class="badge badge-orange">_low.raw · _normal_low.raw</span>
  </div>
</header>

<div class="main">

  <!-- ── 사이드바 ── -->
  <aside class="sidebar">

    <!-- 파일 업로드 -->
    <div>
      <div class="section-title">Input Files</div>
      <div class="dropzone" id="dropzone" onclick="document.getElementById('file-input').click()">
        <div class="drop-icon">⬡</div>
        <div class="drop-text">
          <strong>RAW 파일 드롭</strong>
          클릭하거나 드래그하여<br>RAW 높이맵 파일 선택
        </div>
        <div class="drop-hint">*.raw · 24-bit RGB · tile_col_row.raw</div>
      </div>
      <input type="file" id="file-input" accept=".raw" multiple>
    </div>

    <!-- 파일 목록 -->
    <div id="file-list-wrap" style="display:none;">
      <div class="section-title">Loaded Files</div>
      <div class="file-list" id="file-list"></div>
    </div>

    <!-- 타일 설정 -->
    <div>
      <div class="section-title">Tile Settings</div>
      <div class="setting-group">
        <div class="setting-row">
          <div class="setting-label">
            입력 해상도 (px)
            <span class="setting-value" id="val-input-res">1024</span>
          </div>
          <div class="select-group" id="res-group">
            <button class="select-btn active" data-val="512">512</button>
            <button class="select-btn active-on" data-val="1024">1024</button>
            <button class="select-btn" data-val="2048">2048</button>
            <button class="select-btn" data-val="4096">4096</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 노말맵 설정 -->
    <div>
      <div class="section-title">Normal Map</div>
      <div class="setting-group">
        <div class="setting-row">
          <div class="setting-label">
            강도 (Strength)
            <span class="setting-value" id="val-strength">3.0</span>
          </div>
          <input type="range" class="slider" id="sl-strength" min="0.5" max="10" step="0.1" value="3.0"
            oninput="document.getElementById('val-strength').textContent=parseFloat(this.value).toFixed(1)">
        </div>

        <div class="setting-row">
          <div class="setting-label">
            스케일 (m/unit)
            <span class="setting-value" id="val-scale">1.0</span>
          </div>
          <input type="range" class="slider" id="sl-scale" min="0.1" max="5.0" step="0.1" value="1.0"
            oninput="document.getElementById('val-scale').textContent=parseFloat(this.value).toFixed(1)">
        </div>

        <div class="toggle-row">
          <span class="toggle-label">부드럽게 (Smooth)</span>
          <label class="toggle">
            <input type="checkbox" id="chk-smooth" checked>
            <div class="toggle-track"></div>
            <div class="toggle-thumb"></div>
          </label>
        </div>
      </div>
    </div>

    <!-- LOD 설정 (고정) -->
    <div>
      <div class="section-title">LOD Output</div>
      <div class="toggle-row" style="opacity:0.6; cursor:default;">
        <span class="toggle-label">저해상도 고정 — 128 × 128</span>
        <span class="badge badge-orange" style="font-size:8px; padding:2px 8px;">FIXED</span>
      </div>
    </div>

    <!-- 처리 버튼 -->
    <button class="process-btn" id="process-btn" onclick="startProcess()" disabled>
      PROCESS FILES
    </button>

    <button class="zip-btn" id="zip-btn" onclick="downloadAllZip()">
      ↓ ZIP ALL RESULTS
    </button>

  </aside>

  <!-- ── 콘텐츠 ── -->
  <main class="content">

    <!-- 빈 상태 -->
    <div class="empty-state" id="empty-state">
      <div class="empty-icon">◈</div>
      <div class="empty-text">RAW 높이맵 대기 중</div>
      <div class="empty-sub">
        왼쪽에서 RAW 파일을 불러오세요<br>
        heightmap_RAW_NxN_lat_lng.zip 안의<br>
        tile_col_row.raw 파일을 사용합니다
      </div>
    </div>

    <!-- 처리 상태 -->
    <div class="status-card" id="status-card">
      <div class="status-top">
        <div class="status-indicator" id="status-dot"></div>
        <div class="status-title" id="status-title">처리 중...</div>
        <div class="status-detail" id="status-detail"></div>
      </div>
      <div class="prog-bars">
        <div class="prog-item">
          <div class="prog-name">읽기</div>
          <div class="prog-track"><div class="prog-fill blue" id="prog-read"></div></div>
          <div class="prog-pct" id="pct-read">0%</div>
        </div>
        <div class="prog-item">
          <div class="prog-name">노말맵</div>
          <div class="prog-track"><div class="prog-fill green" id="prog-normal"></div></div>
          <div class="prog-pct" id="pct-normal">0%</div>
        </div>
        <div class="prog-item">
          <div class="prog-name">LOD</div>
          <div class="prog-track"><div class="prog-fill orange" id="prog-lod"></div></div>
          <div class="prog-pct" id="pct-lod">0%</div>
        </div>
      </div>
    </div>

    <!-- 프리뷰 -->
    <div class="preview-grid" id="preview-grid"></div>

    <!-- 로그 -->
    <div class="log-panel" id="log-panel" style="display:none;">
      <div class="log-header">
        <div class="log-dot"></div>
        <div class="log-title">Process Log</div>
      </div>
      <div class="log-body" id="log-body"></div>
    </div>

  </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
'use strict';

// ══════════════════════════════════════════════
// 상태
// ══════════════════════════════════════════════
let loadedFiles  = [];   // { file, name }
let results      = [];   // { name, heightMap, normalCanvas, lodCanvases, meta }
let inputRes     = 1024;

// ── 선택된 해상도 버튼 ──────────────────────
document.querySelectorAll('#res-group .select-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#res-group .select-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    inputRes = parseInt(btn.dataset.val, 10);
    document.getElementById('val-input-res').textContent = inputRes;
  });
});

// 기본 1024 선택
document.querySelectorAll('#res-group .select-btn').forEach(btn => {
  btn.classList.toggle('active', btn.dataset.val === '1024');
});

// ── 드롭존 ──────────────────────────────────
const dropzone = document.getElementById('dropzone');

dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag-over'));
dropzone.addEventListener('drop', e => {
  e.preventDefault();
  dropzone.classList.remove('drag-over');
  addFiles([...e.dataTransfer.files]);
});

document.getElementById('file-input').addEventListener('change', e => {
  addFiles([...e.target.files]);
  e.target.value = '';
});

function addFiles(files) {
  files.forEach(f => {
    if (!f.name.endsWith('.raw')) return;
    if (loadedFiles.find(x => x.name === f.name)) return;
    loadedFiles.push({ file: f, name: f.name, status: 'idle' });
  });
  renderFileList();
  updateProcessBtn();
}

function removeFile(idx) {
  loadedFiles.splice(idx, 1);
  renderFileList();
  updateProcessBtn();
}

function renderFileList() {
  const wrap = document.getElementById('file-list-wrap');
  const list = document.getElementById('file-list');
  wrap.style.display = loadedFiles.length ? 'block' : 'none';

  list.innerHTML = loadedFiles.map((f, i) => `
    <div class="file-item ${f.status}" id="fi-${i}">
      <div class="file-dot"></div>
      <div class="file-name">${f.name}</div>
      <div class="file-size">${formatBytes(f.file.size)}</div>
      <button class="file-remove" onclick="removeFile(${i})">✕</button>
    </div>
  `).join('');
}

function formatBytes(b) {
  if (b < 1024) return b + 'B';
  if (b < 1024*1024) return (b/1024).toFixed(0) + 'KB';
  return (b/1024/1024).toFixed(1) + 'MB';
}

function updateProcessBtn() {
  document.getElementById('process-btn').disabled = loadedFiles.length === 0;
  document.getElementById('empty-state').style.display = loadedFiles.length ? 'none' : 'flex';
}

// ══════════════════════════════════════════════
// RAW 파일 파싱
// heightmap 값: height_m = (-10000 + (R*65536 + G*256 + B)) / 10
// ══════════════════════════════════════════════
async function parseRaw(file, res) {
  const buf   = await file.arrayBuffer();
  const bytes = new Uint8Array(buf);
  const total = res * res;

  // 3채널(RGB)로 추정 vs 파일 크기 검증
  const expected3 = total * 3;
  const expected1 = total * 2; // 16-bit grayscale fallback

  let mode = '3ch';
  if (bytes.length === expected1) mode = '16bit';
  else if (bytes.length !== expected3) {
    // 크기 자동 감지
    const px = Math.round(Math.sqrt(bytes.length / 3));
    if (px * px * 3 !== bytes.length) throw new Error(`파일 크기 불일치: ${bytes.length}bytes`);
  }

  const hm = new Float32Array(total);
  let minH = Infinity, maxH = -Infinity;

  if (mode === '3ch') {
    for (let i = 0; i < total; i++) {
      const R = bytes[i*3], G = bytes[i*3+1], B = bytes[i*3+2];
      const h = (-10000 + (R*65536 + G*256 + B)) / 10;
      hm[i] = h;
      if (h < minH) minH = h;
      if (h > maxH) maxH = h;
    }
  } else {
    // 16-bit little-endian grayscale fallback
    const view = new DataView(buf);
    for (let i = 0; i < total; i++) {
      const v = view.getUint16(i*2, true);
      const h = v / 65535 * 8848;
      hm[i] = h;
      if (h < minH) minH = h;
      if (h > maxH) maxH = h;
    }
  }

  return { hm, minH, maxH, res: res };
}

// ══════════════════════════════════════════════
// 가우시안 블러 (소볼용 전처리)
// ══════════════════════════════════════════════
function gaussianBlur(hm, res, radius = 1) {
  const out = new Float32Array(hm.length);
  const kernel = [0.0625, 0.25, 0.375, 0.25, 0.0625];
  const tmp    = new Float32Array(hm.length);

  // 수평
  for (let y = 0; y < res; y++) {
    for (let x = 0; x < res; x++) {
      let sum = 0;
      for (let k = -2; k <= 2; k++) {
        const xx = Math.max(0, Math.min(res-1, x+k));
        sum += hm[y*res+xx] * kernel[k+2];
      }
      tmp[y*res+x] = sum;
    }
  }
  // 수직
  for (let y = 0; y < res; y++) {
    for (let x = 0; x < res; x++) {
      let sum = 0;
      for (let k = -2; k <= 2; k++) {
        const yy = Math.max(0, Math.min(res-1, y+k));
        sum += tmp[yy*res+x] * kernel[k+2];
      }
      out[y*res+x] = sum;
    }
  }
  return out;
}

// ══════════════════════════════════════════════
// 노말맵 생성 (Sobel)
// ══════════════════════════════════════════════
function generateNormalMap(hm, res, strength, scale, smooth) {
  const src    = smooth ? gaussianBlur(hm, res) : hm;
  const pixels = new Uint8ClampedArray(res * res * 4);

  function h(x, y) {
    x = Math.max(0, Math.min(res-1, x));
    y = Math.max(0, Math.min(res-1, y));
    return src[y*res+x] * scale;
  }

  for (let y = 0; y < res; y++) {
    for (let x = 0; x < res; x++) {
      // Sobel 3×3
      const dX = (
        -h(x-1, y-1) + h(x+1, y-1)
        -2*h(x-1, y) + 2*h(x+1, y)
        -h(x-1, y+1) + h(x+1, y+1)
      ) * strength;

      const dY = (
        -h(x-1, y-1) - 2*h(x, y-1) - h(x+1, y-1)
        +h(x-1, y+1) + 2*h(x, y+1) + h(x+1, y+1)
      ) * strength;

      // 정규화
      const len = Math.sqrt(dX*dX + dY*dY + 1);
      const nx  = -dX / len;
      const ny  = -dY / len;
      const nz  = 1   / len;

      const idx = (y*res+x)*4;
      pixels[idx]   = Math.round((nx * 0.5 + 0.5) * 255);
      pixels[idx+1] = Math.round((ny * 0.5 + 0.5) * 255);
      pixels[idx+2] = Math.round((nz * 0.5 + 0.5) * 255);
      pixels[idx+3] = 255;
    }
  }

  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = res;
  const ctx = canvas.getContext('2d');
  const img = ctx.createImageData(res, res);
  img.data.set(pixels);
  ctx.putImageData(img, 0, 0);
  return canvas;
}

// ══════════════════════════════════════════════
// 높이맵 PNG 생성 (그레이스케일)
// ══════════════════════════════════════════════
function generateHeightPng(hm, res, minH, maxH) {
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = res;
  const ctx = canvas.getContext('2d');
  const img = ctx.createImageData(res, res);
  const range = maxH - minH || 1;

  for (let i = 0; i < res*res; i++) {
    const v   = Math.round((hm[i] - minH) / range * 255);
    const idx = i*4;
    img.data[idx]   = v;
    img.data[idx+1] = v;
    img.data[idx+2] = v;
    img.data[idx+3] = 255;
  }
  ctx.putImageData(img, 0, 0);
  return canvas;
}

// ══════════════════════════════════════════════
// 다운샘플 (LOD)
// ══════════════════════════════════════════════
function downsampleCanvas(src, outSize) {
  const dst = document.createElement('canvas');
  dst.width = dst.height = outSize;
  const ctx = dst.getContext('2d');
  ctx.drawImage(src, 0, 0, outSize, outSize);
  return dst;
}

// ══════════════════════════════════════════════
// 로그
// ══════════════════════════════════════════════
function log(msg, cls = '') {
  const body = document.getElementById('log-body');
  const now  = new Date();
  const t    = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')}`;
  const line = document.createElement('div');
  line.className = 'log-line';
  line.innerHTML = `<span class="log-time">${t}</span><span class="log-msg ${cls}">${msg}</span>`;
  body.appendChild(line);
  body.scrollTop = body.scrollHeight;
}

function setProgress(id, pct) {
  document.getElementById('prog-' + id).style.width = pct + '%';
  document.getElementById('pct-' + id).textContent  = pct + '%';
}

// ══════════════════════════════════════════════
// 메인 처리
// ══════════════════════════════════════════════
async function startProcess() {
  if (!loadedFiles.length) return;

  results = [];
  document.getElementById('preview-grid').innerHTML = '';
  document.getElementById('status-card').classList.add('visible');
  document.getElementById('empty-state').style.display = 'none';
  document.getElementById('log-panel').style.display   = 'block';
  document.getElementById('zip-btn').classList.remove('visible');
  document.getElementById('process-btn').disabled = true;

  const strength = parseFloat(document.getElementById('sl-strength').value);
  const scale    = parseFloat(document.getElementById('sl-scale').value);
  const smooth   = document.getElementById('chk-smooth').checked;
  const LOD_SIZE = 128;

  const dot   = document.getElementById('status-dot');
  const title = document.getElementById('status-title');
  const detail= document.getElementById('status-detail');

  dot.className = 'status-indicator';
  title.textContent = '처리 중...';

  log(`처리 시작 — ${loadedFiles.length}개 파일`, 'info');
  log(`설정: 강도=${strength}, 스케일=${scale}, 스무스=${smooth}`, '');
  log(`LOD: 128×128 (고정)`, '');

  for (let fi = 0; fi < loadedFiles.length; fi++) {
    const entry = loadedFiles[fi];
    entry.status = 'active';
    renderFileList();

    // tile_col_row.raw → tile_col_row (확장자 제거)
    const fname = entry.name.replace(/\.raw$/i, '');
    detail.textContent = `${fi+1}/${loadedFiles.length} — ${entry.name}`;
    log(`▶ ${entry.name} 읽는 중...`, 'info');

    setProgress('read', 0);
    setProgress('normal', 0);
    setProgress('lod', 0);

    try {
      // ── 1. RAW 파싱 ──
      setProgress('read', 20);
      const { hm, minH, maxH, res: parsedRes } = await parseRaw(entry.file, inputRes);
      setProgress('read', 100);
      log(`  해상도: ${parsedRes}×${parsedRes}, 높이 범위: ${minH.toFixed(1)}m ~ ${maxH.toFixed(1)}m`, 'ok');

      // ── 2. 노말맵 생성 (풀 해상도) ──
      setProgress('normal', 10);
      const normalCanvas = generateNormalMap(hm, inputRes, strength, scale, smooth);
      setProgress('normal', 100);
      log(`  노말맵 생성 완료 (${inputRes}×${inputRes})`, 'ok');
      await yieldFrame();

      // ── 3. 높이맵 PNG (프리뷰용) ──
      const heightCanvas = generateHeightPng(hm, inputRes, minH, maxH);
      await yieldFrame();

      // ── 4. LOD 128px ──
      setProgress('lod', 30);
      // 저해상도 높이맵: hm을 128로 다운샘플 후 RAW 바이트 생성
      const hmLow = downsampleHm(hm, inputRes, LOD_SIZE);
      const lowHeightCanvas = generateHeightPng(hmLow, LOD_SIZE, minH, maxH);
      setProgress('lod', 60);
      // 저해상도 노말맵
      const lowNormalCanvas = generateNormalMap(hmLow, LOD_SIZE, strength, scale, smooth);
      setProgress('lod', 100);
      log(`  LOD 128px 생성 완료`, 'ok');
      await yieldFrame();

      // ── RAW 바이트 생성 ──
      // 원본 높이맵 RAW (R·G·B 3바이트/px)
      const rawHeightBytes  = hmToRawBytes(hm, inputRes);
      // 노말맵 RAW (R·G·B 3바이트/px, canvas 픽셀에서 추출)
      const rawNormalBytes  = canvasToRawBytes(normalCanvas, inputRes);
      // 저해상도 높이맵 RAW
      const rawLowBytes     = hmToRawBytes(hmLow, LOD_SIZE);
      // 저해상도 노말맵 RAW
      const rawNormalLowBytes = canvasToRawBytes(lowNormalCanvas, LOD_SIZE);

      const result = {
        name: fname,
        heightCanvas, normalCanvas, lowHeightCanvas, lowNormalCanvas,
        rawHeightBytes, rawNormalBytes, rawLowBytes, rawNormalLowBytes,
        minH, maxH, res: inputRes
      };
      results.push(result);

      renderPreview(result, fi);
      entry.status = 'done';
      renderFileList();

    } catch (e) {
      entry.status = 'error';
      renderFileList();
      log(`  오류: ${e.message}`, 'err');
      console.error(e);
    }
  }

  dot.className   = 'status-indicator done';
  title.textContent = `처리 완료 — ${results.length}/${loadedFiles.length}개 성공`;
  detail.textContent = '';
  log(`완료! ${results.length}개 처리됨`, 'ok');

  document.getElementById('process-btn').disabled = false;
  if (results.length > 0) document.getElementById('zip-btn').classList.add('visible');
}

function yieldFrame() {
  return new Promise(r => requestAnimationFrame(() => setTimeout(r, 0)));
}

// ══════════════════════════════════════════════
// 프리뷰 렌더
// ══════════════════════════════════════════════
function renderPreview(result, fi) {
  const grid = document.getElementById('preview-grid');

  // 높이맵 (원본)
  grid.appendChild(makePreviewCard(
    `HEIGHT — ${result.name}.raw`,
    `${result.res}×${result.res}`,
    result.heightCanvas,
    `min ${result.minH.toFixed(1)}m  /  max ${result.maxH.toFixed(1)}m`,
    () => downloadRaw(result.rawHeightBytes, `${result.name}.raw`)
  ));

  // 노말맵
  grid.appendChild(makePreviewCard(
    `NORMAL — ${result.name}_normal.raw`,
    `${result.res}×${result.res}`,
    result.normalCanvas,
    `Sobel · RGB 노말맵`,
    () => downloadRaw(result.rawNormalBytes, `${result.name}_normal.raw`)
  ));

  // 저해상도 높이맵
  grid.appendChild(makePreviewCard(
    `LOW — ${result.name}_low.raw`,
    `128×128`,
    result.lowHeightCanvas,
    `저해상도 높이맵 128px`,
    () => downloadRaw(result.rawLowBytes, `${result.name}_low.raw`)
  ));

  // 저해상도 노말맵
  grid.appendChild(makePreviewCard(
    `NORMAL LOW — ${result.name}_normal_low.raw`,
    `128×128`,
    result.lowNormalCanvas,
    `저해상도 노말맵 128px`,
    () => downloadRaw(result.rawNormalLowBytes, `${result.name}_normal_low.raw`)
  ));
}

function makePreviewCard(title, meta, canvas, info, onDl) {
  const card = document.createElement('div');
  card.className = 'preview-card';

  const img = document.createElement('img');
  img.className = 'preview-canvas';
  img.src = canvas.toDataURL('image/png');

  card.innerHTML = `
    <div class="preview-header">
      <div class="preview-title">${title}</div>
      <div class="preview-meta">${meta}</div>
    </div>
    <div class="preview-canvas-wrap"></div>
    <div class="preview-footer">
      <div class="preview-info">${info}</div>
      <button class="dl-btn">↓ RAW</button>
    </div>
  `;

  card.querySelector('.preview-canvas-wrap').appendChild(img);
  card.querySelector('.dl-btn').addEventListener('click', onDl);
  return card;
}

function downloadCanvas(canvas, filename) {
  canvas.toBlob(blob => {
    const a  = document.createElement('a');
    a.href   = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
  }, 'image/png');
}

function downloadRaw(bytes, filename) {
  const blob = new Blob([bytes], { type: 'application/octet-stream' });
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  URL.revokeObjectURL(a.href);
}

// hm Float32Array를 지정 크기로 다운샘플
function downsampleHm(src, srcRes, dstRes) {
  const dst = new Float32Array(dstRes * dstRes);
  const ratio = srcRes / dstRes;
  for (let y = 0; y < dstRes; y++) {
    for (let x = 0; x < dstRes; x++) {
      const sy = Math.min(Math.floor(y * ratio), srcRes - 1);
      const sx = Math.min(Math.floor(x * ratio), srcRes - 1);
      dst[y * dstRes + x] = src[sy * srcRes + sx];
    }
  }
  return dst;
}

// hm Float32Array → RGB 3바이트/px RAW
// height_m = (-10000 + (R*65536 + G*256 + B)) / 10
// val = height_m * 10 + 100000
function hmToRawBytes(hm, res) {
  const bytes = new Uint8Array(res * res * 3);
  for (let i = 0; i < res * res; i++) {
    const val = Math.round(hm[i] * 10 + 100000);
    const clamped = Math.max(0, Math.min(0xFFFFFF, val));
    bytes[i*3]   = (clamped >> 16) & 0xFF;
    bytes[i*3+1] = (clamped >>  8) & 0xFF;
    bytes[i*3+2] =  clamped        & 0xFF;
  }
  return bytes;
}

// canvas 픽셀 → RGB 3바이트/px RAW (노말맵용)
function canvasToRawBytes(canvas, res) {
  const ctx   = canvas.getContext('2d');
  const data  = ctx.getImageData(0, 0, res, res).data;
  const bytes = new Uint8Array(res * res * 3);
  for (let i = 0; i < res * res; i++) {
    bytes[i*3]   = data[i*4];
    bytes[i*3+1] = data[i*4+1];
    bytes[i*3+2] = data[i*4+2];
  }
  return bytes;
}

// ══════════════════════════════════════════════
// ZIP 전체 다운로드 — 플랫 구조, 파일명 규칙 적용
// tile_col_row.raw
// tile_col_row_normal.raw
// tile_col_row_low.raw
// tile_col_row_normal_low.raw
// ══════════════════════════════════════════════
async function downloadAllZip() {
  if (!results.length) return;
  const btn = document.getElementById('zip-btn');
  btn.textContent = '⏳ ZIP 생성 중...';
  btn.disabled = true;

  const zip = new JSZip();

  for (const r of results) {
    zip.file(`${r.name}_normal.raw`,     r.rawNormalBytes);
    zip.file(`${r.name}_low.raw`,        r.rawLowBytes);
    zip.file(`${r.name}_normal_low.raw`, r.rawNormalLowBytes);
  }

  const content = await zip.generateAsync({ type: 'blob' });
  const a  = document.createElement('a');
  a.href   = URL.createObjectURL(content);
  a.download = `heightmap_processed_${Date.now()}.zip`;
  a.click();
  URL.revokeObjectURL(a.href);

  btn.textContent = '↓ ZIP ALL RESULTS';
  btn.disabled = false;
}
</script>
</body>
</html>