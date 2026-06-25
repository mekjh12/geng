<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Water Mesh Viewer</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: #0a0e14;
    color: #c8d0dc;
    font-family: 'Consolas', 'Menlo', monospace;
    height: 100vh;
    display: flex;
    flex-direction: column;
  }

  header {
    padding: 10px 16px;
    border-bottom: 1px solid #1e2a38;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    flex-wrap: wrap;
  }

  header h1 {
    font-size: 13px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: #4a9eff;
    font-weight: 400;
    white-space: nowrap;
  }

  .url-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1;
    min-width: 300px;
  }
  .url-row input[type=text] {
    flex: 1;
    background: #0f1820;
    border: 1px solid #2a3a4e;
    border-radius: 3px;
    color: #8ab8d8;
    font-family: inherit;
    font-size: 11px;
    padding: 4px 8px;
    outline: none;
    transition: border-color 0.15s;
  }
  .url-row input[type=text]:focus { border-color: #4a9eff; }
  .url-row button {
    background: #1a3a6a;
    border: 1px solid #2a5a9a;
    border-radius: 3px;
    color: #4a9eff;
    font-family: inherit;
    font-size: 11px;
    padding: 4px 10px;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s;
  }
  .url-row button:hover { background: #1e4a8a; }

  .drop-zone {
    border: 1px dashed #2a3a4e;
    border-radius: 4px;
    padding: 5px 12px;
    font-size: 11px;
    color: #5a7a9a;
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s;
    white-space: nowrap;
    flex-shrink: 0;
  }
  .drop-zone:hover, .drop-zone.drag-over {
    border-color: #4a9eff;
    color: #4a9eff;
  }
  .drop-zone input { display: none; }

  .stats {
    display: flex;
    gap: 14px;
    font-size: 11px;
    color: #3a5a7a;
    flex-shrink: 0;
    margin-left: auto;
  }
  .stats span { color: #6a9aba; }
  .stats b { color: #c8d0dc; }

  /* ── 메인 레이아웃 ── */
  .main {
    flex: 1;
    display: flex;
    overflow: hidden;
  }

  /* ── 왼쪽 파일 브라우저 ── */
  .file-browser {
    width: 200px;
    border-right: 1px solid #1e2a38;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    overflow: hidden;
    background: #080c12;
  }

  .fb-header {
    padding: 10px 12px 8px;
    border-bottom: 1px solid #1e2a38;
    display: flex;
    flex-direction: column;
    gap: 7px;
  }

  .fb-title {
    font-size: 10px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #3a5a7a;
  }

  .fb-open-btn {
    background: #0f1c2e;
    border: 1px dashed #2a4a6a;
    border-radius: 3px;
    color: #4a8abe;
    font-family: inherit;
    font-size: 10px;
    padding: 5px 8px;
    cursor: pointer;
    text-align: center;
    transition: border-color 0.15s, color 0.15s, background 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
  }
  .fb-open-btn:hover {
    border-color: #4a9eff;
    color: #4a9eff;
    background: #0f2040;
  }
  .fb-open-btn .icon { font-size: 12px; }

  .fb-folder-name {
    font-size: 10px;
    color: #4a6a8a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 2px;
    display: none;
  }
  .fb-folder-name.visible { display: block; }

  .fb-count {
    font-size: 10px;
    color: #2a4a6a;
    display: none;
  }
  .fb-count.visible { display: block; }

  .fb-filter {
    padding: 6px 10px;
    border-bottom: 1px solid #1e2a38;
    display: none;
  }
  .fb-filter.visible { display: block; }
  .fb-filter input {
    width: 100%;
    background: #0f1820;
    border: 1px solid #1e2e40;
    border-radius: 3px;
    color: #8ab8d8;
    font-family: inherit;
    font-size: 10px;
    padding: 3px 7px;
    outline: none;
  }
  .fb-filter input:focus { border-color: #4a9eff; }
  .fb-filter input::placeholder { color: #2a4a6a; }

  .fb-list {
    flex: 1;
    overflow-y: auto;
    padding: 4px 0;
  }
  .fb-list::-webkit-scrollbar { width: 4px; }
  .fb-list::-webkit-scrollbar-track { background: #080c12; }
  .fb-list::-webkit-scrollbar-thumb { background: #1e2a38; border-radius: 2px; }

  .fb-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    font-size: 10px;
    color: #5a8aaa;
    cursor: pointer;
    transition: background 0.1s, color 0.1s;
    border-left: 2px solid transparent;
    white-space: nowrap;
    overflow: hidden;
  }
  .fb-item:hover {
    background: #0f1c2e;
    color: #8ab8d8;
    border-left-color: #2a5a8a;
  }
  .fb-item.active {
    background: #0f2040;
    color: #4a9eff;
    border-left-color: #4a9eff;
  }
  .fb-item .fi-icon { font-size: 11px; flex-shrink: 0; }
  .fb-item .fi-name {
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
  }
  .fb-item .fi-badge {
    font-size: 8px;
    padding: 1px 4px;
    border-radius: 2px;
    flex-shrink: 0;
    letter-spacing: 0.05em;
  }
  .fb-item .fi-badge.mesh   { background: #0a2040; color: #2a6abf; }
  .fb-item .fi-badge.height { background: #0a2820; color: #2a9a7a; }
  .fb-item .fi-badge.unknown { background: #1e1e1e; color: #4a4a4a; }

  .fb-empty {
    padding: 20px 12px;
    font-size: 10px;
    color: #1e3a4e;
    text-align: center;
    line-height: 1.8;
  }

  /* ── 캔버스 영역 ── */
  .canvas-wrap {
    flex: 1;
    position: relative;
    overflow: hidden;
  }

  canvas#view {
    width: 100%;
    height: 100%;
    display: block;
    cursor: crosshair;
  }

  .empty-msg {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: #2a3a4e;
    pointer-events: none;
  }
  .empty-msg .icon { font-size: 48px; opacity: 0.4; }
  .empty-msg p { font-size: 12px; letter-spacing: 0.1em; }

  #loading-overlay {
    position: absolute;
    inset: 0;
    background: rgba(10,14,20,0.75);
    display: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 10px;
    font-size: 12px;
    color: #4a9eff;
  }
  #loading-overlay.visible { display: flex; }
  .spinner {
    width: 24px; height: 24px;
    border: 2px solid #1e2a38;
    border-top-color: #4a9eff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  #error-banner {
    display: none;
    position: absolute;
    top: 10px; left: 50%; transform: translateX(-50%);
    background: #3a0a0a;
    border: 1px solid #8a2020;
    border-radius: 4px;
    padding: 6px 14px;
    font-size: 11px;
    color: #ff8a8a;
    white-space: nowrap;
  }

  /* ── 오른쪽 패널 ── */
  .side {
    width: 220px;
    border-left: 1px solid #1e2a38;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    overflow-y: auto;
  }

  .panel-section {
    padding: 12px 14px;
    border-bottom: 1px solid #1e2a38;
  }

  .panel-section label {
    display: block;
    font-size: 10px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #3a5a7a;
    margin-bottom: 8px;
  }

  .toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 7px;
    font-size: 11px;
    color: #8aa8c0;
  }

  .toggle {
    width: 32px; height: 16px;
    background: #1e2a38;
    border-radius: 8px;
    position: relative;
    cursor: pointer;
    transition: background 0.15s;
    border: none;
    flex-shrink: 0;
  }
  .toggle.on { background: #1a4a8a; }
  .toggle::after {
    content: '';
    position: absolute;
    width: 10px; height: 10px;
    background: #3a5a7a;
    border-radius: 50%;
    top: 3px; left: 3px;
    transition: left 0.15s, background 0.15s;
  }
  .toggle.on::after { left: 19px; background: #4a9eff; }

  .color-swatch {
    width: 20px; height: 20px;
    border-radius: 3px;
    border: 1px solid #2a3a4e;
    cursor: pointer;
    flex-shrink: 0;
  }

  .slider-row { margin-bottom: 7px; }
  .slider-row .slider-label {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: #5a7a9a;
    margin-bottom: 3px;
  }
  .slider-row input[type=range] {
    width: 100%;
    accent-color: #4a9eff;
    height: 3px;
  }

  .info-list { display: flex; flex-direction: column; gap: 5px; }
  .info-row { display: flex; justify-content: space-between; font-size: 11px; }
  .info-row .key { color: #3a5a7a; }
  .info-row .val { color: #8ab8d8; }

  #height-legend {
    display: none;
    padding: 10px 14px;
    border-bottom: 1px solid #1e2a38;
  }
  #height-legend label {
    display: block;
    font-size: 10px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #3a5a7a;
    margin-bottom: 8px;
  }
  .legend-bar {
    width: 100%;
    height: 12px;
    border-radius: 3px;
    background: linear-gradient(to right, rgb(10,42,74), rgb(30,162,154));
    margin-bottom: 4px;
  }
  .legend-labels {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: #5a7a9a;
  }

  #coord-overlay {
    position: absolute;
    bottom: 10px; left: 12px;
    font-size: 10px;
    color: #3a5a7a;
    pointer-events: none;
  }

  .format-badge {
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 3px;
    font-weight: bold;
    letter-spacing: 0.05em;
  }
  .format-badge.mesh   { background: #1a3a6a; color: #4a9eff; }
  .format-badge.height { background: #0a3a2a; color: #4affcc; }

  /* ── 리사이즈 핸들 ── */
  .resize-handle {
    width: 4px;
    background: transparent;
    cursor: col-resize;
    flex-shrink: 0;
    transition: background 0.15s;
    position: relative;
  }
  .resize-handle:hover { background: #2a4a6a; }
  .resize-handle::after {
    content: '';
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 2px; height: 30px;
    background: #1e2a38;
    border-radius: 1px;
  }
</style>
</head>
<body>

<header>
  <h1>Water Mesh</h1>

  <div class="url-row">
    <input type="text" id="url-input"
      value="https://mekjh12.ivyro.net/geng/data/tile_4_4_water_mesh.raw"
      placeholder="https://.../_water_mesh.raw  또는  _water_height.raw">
    <button id="btn-load-url">로드</button>
  </div>

  <div class="drop-zone" id="drop-zone">
    <input type="file" id="file-input" accept=".raw">
    <span>▶ 파일 열기</span>
  </div>

  <div class="stats">
    <span id="format-wrap"></span>
    <span>V <b id="stat-vert">—</b></span>
    <span>I <b id="stat-idx">—</b></span>
    <span>Q <b id="stat-quad">—</b></span>
    <span><b id="stat-size">—</b></span>
  </div>
</header>

<div class="main">

  <!-- ── 왼쪽 파일 브라우저 ── -->
  <div class="file-browser" id="file-browser">
    <div class="fb-header">
      <div class="fb-title">📁 파일 목록</div>
      <button class="fb-open-btn" id="fb-open-btn">
        <span class="icon">🗂</span> 폴더 선택
      </button>
      <div class="fb-folder-name" id="fb-folder-name"></div>
      <div class="fb-count" id="fb-count"></div>
    </div>
    <div class="fb-filter" id="fb-filter">
      <input type="text" id="fb-search" placeholder="파일 검색...">
    </div>
    <div class="fb-list" id="fb-list">
      <div class="fb-empty" id="fb-empty">
        폴더를 선택하면<br>.raw 파일 목록이<br>여기에 표시됩니다
      </div>
    </div>
  </div>

  <!-- ── 캔버스 ── -->
  <div class="canvas-wrap">
    <canvas id="view"></canvas>
    <div class="empty-msg" id="empty-msg">
      <div class="icon">🌊</div>
      <p>URL 로드 또는 .raw 파일 드래그</p>
      <p style="font-size:10px; margin-top:4px; color:#1e3a4e;">water_mesh.raw · water_height.raw 모두 지원</p>
    </div>
    <div id="loading-overlay">
      <div class="spinner"></div>
      <span id="loading-msg">로드 중...</span>
    </div>
    <div id="error-banner"></div>
    <div id="coord-overlay">uv — —</div>
  </div>

  <!-- ── 오른쪽 설정 패널 ── -->
  <div class="side">
    <div class="panel-section">
      <label>표시</label>
      <div class="toggle-row"><span>삼각형 채우기</span><button class="toggle on" id="tog-fill"></button></div>
      <div class="toggle-row"><span>와이어프레임</span><button class="toggle on" id="tog-wire"></button></div>
      <div class="toggle-row"><span>버텍스 점</span><button class="toggle" id="tog-verts"></button></div>
      <div class="toggle-row"><span>64×64 그리드</span><button class="toggle on" id="tog-grid"></button></div>
    </div>

    <div class="panel-section">
      <label>색상</label>
      <div class="toggle-row"><span>채우기</span><input type="color" class="color-swatch" id="col-fill" value="#0a2a4a"></div>
      <div class="toggle-row"><span>와이어</span><input type="color" class="color-swatch" id="col-wire" value="#4a9eff"></div>
      <div class="toggle-row"><span>그리드</span><input type="color" class="color-swatch" id="col-grid" value="#1e2a38"></div>
      <div class="toggle-row"><span>버텍스</span><input type="color" class="color-swatch" id="col-vert" value="#ff6a4a"></div>
    </div>

    <div class="panel-section">
      <label>뷰</label>
      <div class="slider-row">
        <div class="slider-label"><span>채우기 투명도</span><span id="lbl-alpha">0.5</span></div>
        <input type="range" id="sld-alpha" min="0" max="1" step="0.05" value="0.5">
      </div>
      <div class="slider-row">
        <div class="slider-label"><span>와이어 두께</span><span id="lbl-wire">1</span></div>
        <input type="range" id="sld-wire" min="0.5" max="4" step="0.5" value="1">
      </div>
      <div class="slider-row">
        <div class="slider-label"><span>버텍스 크기</span><span id="lbl-vsize">3</span></div>
        <input type="range" id="sld-vsize" min="1" max="8" step="1" value="3">
      </div>
    </div>

    <div id="height-legend">
      <label>높이 범례</label>
      <div class="legend-bar"></div>
      <div class="legend-labels">
        <span id="legend-min">— m</span>
        <span id="legend-max">— m</span>
      </div>
    </div>

    <div class="panel-section" style="flex:1">
      <label>정보</label>
      <div class="info-list" id="info-list">
        <div class="info-row"><span class="key">상태</span><span class="val" id="info-status">대기 중</span></div>
      </div>
    </div>
  </div>
</div>

<script>
let mesh = null;
let pan  = { x: 0, y: 0 };
let zoom = 1.0;
let isDragging = false;
let dragStart  = { x: 0, y: 0 };
let panStart   = { x: 0, y: 0 };

// 파일 브라우저 상태
let fbFiles     = [];     // { name, handle, type }
let fbFiltered  = [];
let fbActive    = null;   // 현재 선택된 파일명

const canvas = document.getElementById('view');
const ctx    = canvas.getContext('2d');

const opts = {
  fill: true, wire: true, verts: false, grid: true,
  fillColor: '#0a2a4a', wireColor: '#4a9eff',
  gridColor: '#1e2a38', vertColor: '#ff6a4a',
  alpha: 0.5, wireW: 1, vertSize: 3,
};

// ── 로딩 UI ──────────────────────────────────────────────────
function showLoading(msg) {
  document.getElementById('loading-msg').textContent = msg || '로드 중...';
  document.getElementById('loading-overlay').classList.add('visible');
  document.getElementById('error-banner').style.display = 'none';
}
function hideLoading() {
  document.getElementById('loading-overlay').classList.remove('visible');
}
function showError(msg) {
  hideLoading();
  const el = document.getElementById('error-banner');
  el.textContent = '⚠ ' + msg;
  el.style.display = 'block';
  document.getElementById('info-status').textContent = '실패';
  setTimeout(() => { el.style.display = 'none'; }, 5000);
}

// ── RAW 파싱 진입점 ──────────────────────────────────────────
function loadRaw(buffer, label) {
  try {
    const WATER_HEIGHT_BYTES = 65 * 65 * 4;
    if (buffer.byteLength === WATER_HEIGHT_BYTES) {
      loadHeightRaw(buffer, label);
    } else {
      loadMeshRaw(buffer, label);
    }
  } catch(e) {
    showError('파싱 오류: ' + e.message);
  }
}

// ── water_height.raw 파싱 ────────────────────────────────────
function loadHeightRaw(buffer, label) {
  const VERT_SIZE = 65;
  const heights   = new Float32Array(buffer);

  let minH = Infinity, maxH = -Infinity;
  for (let i = 0; i < heights.length; i++) {
    if (heights[i] === 0) continue;
    if (heights[i] < minH) minH = heights[i];
    if (heights[i] > maxH) maxH = heights[i];
  }
  if (minH === Infinity) { minH = 0; maxH = 0; }

  mesh = { type: 'height', heights, vertSize: VERT_SIZE, minH, maxH };

  document.getElementById('stat-vert').textContent  = (VERT_SIZE * VERT_SIZE).toLocaleString();
  document.getElementById('stat-idx').textContent   = '—';
  document.getElementById('stat-quad').textContent  = ((VERT_SIZE - 1) * (VERT_SIZE - 1)).toLocaleString();
  document.getElementById('stat-size').textContent  = (buffer.byteLength / 1024).toFixed(1) + ' KB';
  document.getElementById('info-status').textContent = '로드 완료';
  document.getElementById('format-wrap').innerHTML = '<span class="format-badge height">water_height</span>';
  document.getElementById('height-legend').style.display = 'block';
  document.getElementById('legend-min').textContent = minH.toFixed(1) + ' m';
  document.getElementById('legend-max').textContent = maxH.toFixed(1) + ' m';

  clearInfoList();
  setInfo('파일',   label || '');
  setInfo('포맷',   'water_height (Float32)');
  setInfo('그리드', VERT_SIZE + '×' + VERT_SIZE);
  setInfo('최소 Z', minH.toFixed(2) + ' m');
  setInfo('최대 Z', maxH.toFixed(2) + ' m');
  setInfo('크기',   (buffer.byteLength / 1024).toFixed(1) + ' KB');

  document.getElementById('empty-msg').style.display = 'none';
  hideLoading();
  resetView();
  draw();
}

// ── water_mesh.raw 파싱 ──────────────────────────────────────
function loadMeshRaw(buffer, label) {
  const view = new DataView(buffer);
  let off = 0;
  const vertexCount = view.getUint32(off, true); off += 4;
  const indexCount  = view.getUint32(off, true); off += 4;

  if (buffer.byteLength < 8 + vertexCount * 8 + indexCount * 4) {
    showError('파일 크기 불일치 — 포맷 확인 필요');
    return;
  }

  const vertices = new Float32Array(vertexCount * 2);
  for (let i = 0; i < vertexCount * 2; i++) {
    vertices[i] = view.getFloat32(off, true); off += 4;
  }
  const indices = new Uint32Array(indexCount);
  for (let i = 0; i < indexCount; i++) {
    indices[i] = view.getUint32(off, true); off += 4;
  }

  mesh = { type: 'mesh', vertices, indices, vertexCount, indexCount };

  document.getElementById('stat-vert').textContent  = vertexCount.toLocaleString();
  document.getElementById('stat-idx').textContent   = indexCount.toLocaleString();
  document.getElementById('stat-quad').textContent  = (indexCount / 6).toLocaleString();
  document.getElementById('stat-size').textContent  = (buffer.byteLength / 1024).toFixed(1) + ' KB';
  document.getElementById('info-status').textContent = '로드 완료';
  document.getElementById('format-wrap').innerHTML = '<span class="format-badge mesh">water_mesh</span>';
  document.getElementById('height-legend').style.display = 'none';

  clearInfoList();
  setInfo('파일',   label || '');
  setInfo('포맷',   'water_mesh');
  setInfo('버텍스', vertexCount);
  setInfo('인덱스', indexCount);
  setInfo('삼각형', indexCount / 3);
  setInfo('쿼드',   indexCount / 6);
  setInfo('크기',   (buffer.byteLength / 1024).toFixed(1) + ' KB');

  document.getElementById('empty-msg').style.display = 'none';
  hideLoading();
  resetView();
  draw();
}

// ── 정보 패널 ────────────────────────────────────────────────
function clearInfoList() {
  const list = document.getElementById('info-list');
  list.innerHTML = '<div class="info-row"><span class="key">상태</span><span class="val" id="info-status">로드 완료</span></div>';
}
function setInfo(key, val) {
  let el = document.querySelector(`.info-val[data-key="${key}"]`);
  if (!el) {
    const row = document.createElement('div');
    row.className = 'info-row';
    row.innerHTML = `<span class="key">${key}</span><span class="val info-val" data-key="${key}">${typeof val === 'number' ? val.toLocaleString() : val}</span>`;
    document.getElementById('info-list').appendChild(row);
  } else {
    el.textContent = typeof val === 'number' ? val.toLocaleString() : val;
  }
}

// ── URL 로드 ─────────────────────────────────────────────────
function loadFromURL(url) {
  if (!url) return;
  showLoading(url.split('/').pop() + ' 로드 중...');
  fbActive = null;
  renderFileList();
  fetch(url)
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status + ' ' + r.statusText);
      return r.arrayBuffer();
    })
    .then(buf => loadRaw(buf, url.split('/').pop()))
    .catch(e => showError(e.message));
}

// ── 파일 브라우저 ─────────────────────────────────────────────

// 파일 타입 감지 (이름 기반)
function guessFileType(name) {
  const lower = name.toLowerCase();
  if (lower.includes('water_mesh') || lower.includes('_mesh'))     return 'mesh';
  if (lower.includes('water_height') || lower.includes('_height')) return 'height';
  return 'unknown';
}

// 폴더 선택 (File System Access API)
document.getElementById('fb-open-btn').addEventListener('click', async () => {
  try {
    // showDirectoryPicker 지원 여부 확인
    if (!window.showDirectoryPicker) {
      // 폴백: <input type="file" webkitdirectory>
      openFolderFallback();
      return;
    }
    const dirHandle = await window.showDirectoryPicker({ mode: 'read' });
    await scanDirectory(dirHandle);
  } catch (e) {
    if (e.name !== 'AbortError') showError('폴더 열기 실패: ' + e.message);
  }
});

// 폴백: webkitdirectory input
function openFolderFallback() {
  const inp = document.createElement('input');
  inp.type = 'file';
  inp.multiple = true;
  inp.accept = '.raw';
  inp.setAttribute('webkitdirectory', '');
  inp.addEventListener('change', () => {
    const files = Array.from(inp.files).filter(f => f.name.toLowerCase().endsWith('.raw'));
    if (files.length === 0) { showError('.raw 파일이 없습니다'); return; }
    fbFiles = files.map(f => ({ name: f.name, file: f, type: guessFileType(f.name) }));
    fbFiles.sort((a, b) => a.name.localeCompare(b.name));
    const folderName = files[0].webkitRelativePath.split('/')[0] || '선택된 폴더';
    updateFolderUI(folderName, fbFiles.length);
    fbFiltered = [...fbFiles];
    renderFileList();
  });
  inp.click();
}

// File System Access API로 폴더 스캔
async function scanDirectory(dirHandle) {
  fbFiles = [];
  for await (const entry of dirHandle.values()) {
    if (entry.kind === 'file' && entry.name.toLowerCase().endsWith('.raw')) {
      fbFiles.push({ name: entry.name, handle: entry, type: guessFileType(entry.name) });
    }
  }
  fbFiles.sort((a, b) => a.name.localeCompare(b.name));
  updateFolderUI(dirHandle.name, fbFiles.length);
  fbFiltered = [...fbFiles];
  renderFileList();
}

function updateFolderUI(folderName, count) {
  const folderEl = document.getElementById('fb-folder-name');
  const countEl  = document.getElementById('fb-count');
  const filterEl = document.getElementById('fb-filter');
  folderEl.textContent = '📂 ' + folderName;
  folderEl.classList.add('visible');
  countEl.textContent  = count + '개 파일';
  countEl.classList.add('visible');
  filterEl.classList.add('visible');
}

// 파일 목록 렌더링
function renderFileList() {
  const list  = document.getElementById('fb-list');
  const empty = document.getElementById('fb-empty');

  if (fbFiltered.length === 0) {
    list.innerHTML = '';
    if (fbFiles.length === 0) {
      empty.style.display = '';
      list.appendChild(empty);
    } else {
      const noMatch = document.createElement('div');
      noMatch.className = 'fb-empty';
      noMatch.textContent = '검색 결과 없음';
      list.appendChild(noMatch);
    }
    return;
  }

  list.innerHTML = '';
  fbFiltered.forEach(item => {
    const el = document.createElement('div');
    el.className = 'fb-item' + (item.name === fbActive ? ' active' : '');

    const icon  = item.type === 'height' ? '🏔' : item.type === 'mesh' ? '🌊' : '📄';
    const badge = item.type !== 'unknown'
      ? `<span class="fi-badge ${item.type}">${item.type === 'mesh' ? 'MESH' : 'HT'}</span>`
      : '';

    el.innerHTML = `
      <span class="fi-icon">${icon}</span>
      <span class="fi-name" title="${item.name}">${item.name}</span>
      ${badge}
    `;
    el.addEventListener('click', () => openFbFile(item));
    list.appendChild(el);
  });
}

// 파일 아이템 클릭 → 로드
async function openFbFile(item) {
  fbActive = item.name;
  renderFileList();
  showLoading(item.name + ' 읽는 중...');

  try {
    let buffer;
    if (item.handle) {
      // File System Access API
      const file = await item.handle.getFile();
      buffer = await file.arrayBuffer();
    } else if (item.file) {
      // 폴백 File 객체
      buffer = await item.file.arrayBuffer();
    } else {
      throw new Error('파일 핸들 없음');
    }
    loadRaw(buffer, item.name);
  } catch (e) {
    showError(item.name + ' 읽기 실패: ' + e.message);
  }
}

// 검색 필터
document.getElementById('fb-search').addEventListener('input', e => {
  const q = e.target.value.trim().toLowerCase();
  fbFiltered = q ? fbFiles.filter(f => f.name.toLowerCase().includes(q)) : [...fbFiles];
  renderFileList();
});

// ── 뷰 ───────────────────────────────────────────────────────
function resetView() {
  const s = Math.min(canvas.width, canvas.height) * 0.85;
  zoom = s;
  pan  = { x: canvas.width / 2 - zoom / 2, y: canvas.height / 2 - zoom / 2 };
}

function toScreen(nx, ny) {
  return { x: pan.x + nx * zoom, y: pan.y + ny * zoom };
}

// ── 렌더 ─────────────────────────────────────────────────────
function draw() {
  const W = canvas.width, H = canvas.height;
  ctx.clearRect(0, 0, W, H);
  ctx.fillStyle = '#0a0e14';
  ctx.fillRect(0, 0, W, H);

  if (opts.grid) {
    ctx.strokeStyle = opts.gridColor;
    ctx.lineWidth   = 0.5;
    ctx.globalAlpha = 0.6;
    for (let i = 0; i <= 64; i++) {
      const t = i / 64;
      const a = toScreen(t, 0), b = toScreen(t, 1);
      const c = toScreen(0, t), d = toScreen(1, t);
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(c.x, c.y); ctx.lineTo(d.x, d.y); ctx.stroke();
    }
    ctx.globalAlpha = 1;
  }

  if (!mesh) return;

  if (mesh.type === 'height') {
    const { heights, vertSize, minH, maxH } = mesh;
    const range  = maxH - minH || 1;
    const cellPx = zoom / (vertSize - 1);
    const LABEL_THRESHOLD = 28;
    const showLabel = cellPx >= LABEL_THRESHOLD;
    const fontSize  = Math.max(8, Math.min(13, Math.floor(cellPx * 0.28)));

    for (let vy = 0; vy < vertSize - 1; vy++) {
      for (let vx = 0; vx < vertSize - 1; vx++) {
        const h00 = heights[ vy      * vertSize + vx    ];
        const h10 = heights[ vy      * vertSize + vx + 1];
        const h01 = heights[(vy + 1) * vertSize + vx    ];
        const h11 = heights[(vy + 1) * vertSize + vx + 1];
        const avgH   = (h00 + h10 + h01 + h11) / 4;
        const isBlank = (h00 === 0 && h10 === 0 && h01 === 0 && h11 === 0);

        const nx0 =  vx      / (vertSize - 1);
        const nx1 = (vx + 1) / (vertSize - 1);
        const ny0 =  vy      / (vertSize - 1);
        const ny1 = (vy + 1) / (vertSize - 1);

        const p00 = toScreen(nx0, ny0), p10 = toScreen(nx1, ny0);
        const p01 = toScreen(nx0, ny1), p11 = toScreen(nx1, ny1);

        if (opts.fill) {
          let fillStyle;
          if (isBlank) {
            fillStyle = '#0f141c';
          } else {
            const t = (avgH - minH) / range;
            let r, g, b;
            if (t < 0.5) {
              const u = t * 2;
              r = Math.round(10 + u * 20); g = Math.round(40 + u * 140); b = Math.round(120 + u * 60);
            } else {
              const u = (t - 0.5) * 2;
              r = Math.round(30 + u * 200); g = Math.round(180 + u * 60); b = Math.round(180 - u * 160);
            }
            fillStyle = `rgb(${r},${g},${b})`;
          }
          ctx.globalAlpha = isBlank ? 0.15 : opts.alpha;
          ctx.fillStyle   = fillStyle;
          ctx.beginPath();
          ctx.moveTo(p00.x, p00.y); ctx.lineTo(p10.x, p10.y);
          ctx.lineTo(p11.x, p11.y); ctx.lineTo(p01.x, p01.y);
          ctx.closePath(); ctx.fill();
          ctx.globalAlpha = 1;
        }

        if (opts.wire && !isBlank) {
          ctx.strokeStyle = opts.wireColor;
          ctx.lineWidth   = opts.wireW;
          ctx.globalAlpha = 0.4;
          ctx.beginPath();
          ctx.moveTo(p00.x, p00.y); ctx.lineTo(p10.x, p10.y);
          ctx.lineTo(p11.x, p11.y); ctx.lineTo(p01.x, p01.y);
          ctx.closePath(); ctx.stroke();
          ctx.globalAlpha = 1;
        }

        if (showLabel && !isBlank) {
          const cx = (p00.x + p11.x) / 2, cy = (p00.y + p11.y) / 2;
          ctx.font = `${fontSize}px Consolas, monospace`;
          ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
          ctx.shadowColor = 'rgba(0,0,0,0.9)'; ctx.shadowBlur = 3;
          ctx.fillStyle = '#ffffff'; ctx.globalAlpha = 0.9;
          ctx.fillText(avgH.toFixed(1), cx, cy);
          ctx.shadowBlur = 0; ctx.globalAlpha = 1;
        }
      }
    }

    if (opts.verts) {
      ctx.fillStyle = opts.vertColor; ctx.globalAlpha = 0.9;
      for (let vy = 0; vy < vertSize; vy++) {
        for (let vx = 0; vx < vertSize; vx++) {
          const h = heights[vy * vertSize + vx];
          if (h === 0) continue;
          const p = toScreen(vx / (vertSize - 1), vy / (vertSize - 1));
          ctx.beginPath(); ctx.arc(p.x, p.y, opts.vertSize, 0, Math.PI * 2); ctx.fill();
        }
      }
      ctx.globalAlpha = 1;
    }
    return;
  }

  const { vertices, indices, indexCount } = mesh;

  if (opts.fill) {
    ctx.globalAlpha = opts.alpha; ctx.fillStyle = opts.fillColor;
    ctx.beginPath();
    for (let i = 0; i < indexCount; i += 3) {
      const i0 = indices[i]*2, i1 = indices[i+1]*2, i2 = indices[i+2]*2;
      const p0 = toScreen(vertices[i0], vertices[i0+1]);
      const p1 = toScreen(vertices[i1], vertices[i1+1]);
      const p2 = toScreen(vertices[i2], vertices[i2+1]);
      ctx.moveTo(p0.x, p0.y); ctx.lineTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y); ctx.closePath();
    }
    ctx.fill(); ctx.globalAlpha = 1;
  }

  if (opts.wire) {
    ctx.strokeStyle = opts.wireColor; ctx.lineWidth = opts.wireW; ctx.globalAlpha = 0.8;
    ctx.beginPath();
    for (let i = 0; i < indexCount; i += 3) {
      const i0 = indices[i]*2, i1 = indices[i+1]*2, i2 = indices[i+2]*2;
      const p0 = toScreen(vertices[i0], vertices[i0+1]);
      const p1 = toScreen(vertices[i1], vertices[i1+1]);
      const p2 = toScreen(vertices[i2], vertices[i2+1]);
      ctx.moveTo(p0.x, p0.y); ctx.lineTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y); ctx.closePath();
    }
    ctx.stroke(); ctx.globalAlpha = 1;
  }

  if (opts.verts) {
    ctx.fillStyle = opts.vertColor; ctx.globalAlpha = 0.9;
    const r = opts.vertSize, vc = mesh.vertexCount;
    for (let i = 0; i < vc; i++) {
      const p = toScreen(vertices[i*2], vertices[i*2+1]);
      ctx.beginPath(); ctx.arc(p.x, p.y, r, 0, Math.PI*2); ctx.fill();
    }
    ctx.globalAlpha = 1;
  }
}

// ── 리사이즈 ─────────────────────────────────────────────────
function resize() {
  canvas.width  = canvas.offsetWidth;
  canvas.height = canvas.offsetHeight;
  if (mesh) resetView();
  draw();
}
window.addEventListener('resize', resize);
resize();

// ── 마우스 ───────────────────────────────────────────────────
canvas.addEventListener('mousedown', e => {
  isDragging = true;
  dragStart = { x: e.clientX, y: e.clientY };
  panStart  = { ...pan };
});
canvas.addEventListener('mousemove', e => {
  const rect = canvas.getBoundingClientRect();
  const nx = ((e.clientX - rect.left - pan.x) / zoom).toFixed(3);
  const ny = ((e.clientY - rect.top  - pan.y) / zoom).toFixed(3);
  document.getElementById('coord-overlay').textContent = `uv  ${nx}  ${ny}`;
  if (!isDragging) return;
  pan.x = panStart.x + (e.clientX - dragStart.x);
  pan.y = panStart.y + (e.clientY - dragStart.y);
  draw();
});
canvas.addEventListener('mouseup',    () => { isDragging = false; });
canvas.addEventListener('mouseleave', () => { isDragging = false; });
canvas.addEventListener('wheel', e => {
  e.preventDefault();
  const rect = canvas.getBoundingClientRect();
  const mx = e.clientX - rect.left, my = e.clientY - rect.top;
  const d  = e.deltaY > 0 ? 0.85 : 1.18;
  pan.x = mx + (pan.x - mx) * d;
  pan.y = my + (pan.y - my) * d;
  zoom *= d;
  draw();
}, { passive: false });
canvas.addEventListener('dblclick', () => { if (mesh) { resetView(); draw(); } });

// ── 파일 드래그 앤 드롭 ──────────────────────────────────────
const dropZone  = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');

dropZone.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', e => {
  const f = e.target.files[0];
  if (!f) return;
  fbActive = null; renderFileList();
  showLoading(f.name + ' 읽는 중...');
  f.arrayBuffer().then(buf => loadRaw(buf, f.name));
});
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('drag-over');
  const f = e.dataTransfer.files[0];
  if (!f) return;
  fbActive = null; renderFileList();
  showLoading(f.name + ' 읽는 중...');
  f.arrayBuffer().then(buf => loadRaw(buf, f.name));
});

// ── URL 버튼 ─────────────────────────────────────────────────
document.getElementById('btn-load-url').addEventListener('click', () => {
  loadFromURL(document.getElementById('url-input').value.trim());
});
document.getElementById('url-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') loadFromURL(document.getElementById('url-input').value.trim());
});

// ── 자동 로드 ────────────────────────────────────────────────
loadFromURL(document.getElementById('url-input').value.trim());

// ── 토글 ─────────────────────────────────────────────────────
function bindToggle(id, key) {
  const btn = document.getElementById(id);
  btn.addEventListener('click', () => {
    opts[key] = !opts[key];
    btn.classList.toggle('on', opts[key]);
    draw();
  });
}
bindToggle('tog-fill',  'fill');
bindToggle('tog-wire',  'wire');
bindToggle('tog-verts', 'verts');
bindToggle('tog-grid',  'grid');

function bindColor(id, key) {
  document.getElementById(id).addEventListener('input', e => { opts[key] = e.target.value; draw(); });
}
bindColor('col-fill', 'fillColor');
bindColor('col-wire', 'wireColor');
bindColor('col-grid', 'gridColor');
bindColor('col-vert', 'vertColor');

function bindSlider(id, lblId, key) {
  const sl = document.getElementById(id), lb = document.getElementById(lblId);
  sl.addEventListener('input', () => { opts[key] = parseFloat(sl.value); lb.textContent = sl.value; draw(); });
}
bindSlider('sld-alpha', 'lbl-alpha', 'alpha');
bindSlider('sld-wire',  'lbl-wire',  'wireW');
bindSlider('sld-vsize', 'lbl-vsize', 'vertSize');
</script>
</body>
</html>