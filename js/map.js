mapboxgl.accessToken = MAPBOX_TOKEN;

const map = new mapboxgl.Map({
  container: 'map',
  style: 'mapbox://styles/mapbox/streets-v11',
  center: [state.lng, state.lat],
  zoom: state.zoom,
  preserveDrawingBuffer: true,
});

map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

map.on('click', e => {
  // overview-box 셀 위 클릭이면 좌표 이동 무시
  const ovFeatures = map.queryRenderedFeatures(e.point, { layers: ['layer-overview-fill'] });
  if (ovFeatures.length > 0) return;

  // 이전 마커 초기화
  if (map.getSource('height-markers')) {
    map.getSource('height-markers').setData({ type: 'FeatureCollection', features: [] });
  }

  state.lng = e.lngLat.lng;
  state.lat = e.lngLat.lat;
  state.globalMin = null;
  state.globalMax = null;

  updateGrid();
  updateDisplay();
  syncInputFields();

  previewHeights();

  document.getElementById('hint').classList.add('hide');
  setStatus('위치 선택 완료. 다운로드 버튼을 누르세요.', 'ok');
});

const geocoder = new MapboxGeocoder({
  accessToken: MAPBOX_TOKEN,
  mapboxgl: mapboxgl,
  marker: false,
  placeholder: '장소 검색...',
});
document.getElementById('geocoder').appendChild(geocoder.onAdd(map));

geocoder.on('result', e => {
	
	 // 이전 마커 초기화
    if (map.getSource('height-markers')) {
        map.getSource('height-markers').setData({ type: 'FeatureCollection', features: [] });
    }
	
  state.lng = e.result.center[0];
  state.lat = e.result.center[1];
  updateGrid();
  updateDisplay();
  syncInputFields();
  previewHeights();

});

map.on('style.load', () => {
  if (!map.getSource('mapbox-dem')) {
    map.addSource('mapbox-dem', {
      'type': 'raster-dem',
      'url': 'mapbox://mapbox.mapbox-terrain-dem-v1',
      'tileSize': 512
    });
  }
  map.setTerrain({ 'source': 'mapbox-dem', 'exaggeration': 1.5 });

  map.setFog({
    'range': [0.5, 10],
    'color': '#ffffff',
    'high-color': '#245cdf',
    'space-color': '#000000',
    'horizon-blend': 0.02
  });

  if (!map.getSource('grid-cells')) {
    map.addSource('grid-cells', { type: 'geojson', data: makeGridCells() });
    map.addLayer({
      id: 'layer-cells-fill', type: 'fill', source: 'grid-cells',
      paint: {
        'fill-color': ['get', 'fillColor'],
        'fill-opacity': ['get', 'fillOpacity'],
      }
    });
    map.addLayer({
      id: 'layer-cells-line', type: 'line', source: 'grid-cells',
      paint: {
        'line-color': ['get', 'lineColor'],
        'line-width': 0.8,
        'line-opacity': 0.7,
      }
    });
  }

  if (!map.getSource('contours')) {
    map.addSource('contours', { type: 'vector', url: 'mapbox://mapbox.mapbox-terrain-v2' });
    map.addLayer({
      id: 'contours', type: 'line', source: 'contours', 'source-layer': 'contour',
      layout: { 'line-join': 'round', 'line-cap': 'round' },
      paint: { 'line-color': '#877b59', 'line-width': 0.25 }
    });
  }

  if (!map.getSource('overview-box')) {
    map.addSource('overview-box', { type: 'geojson', data: makeOverviewBox() });
    map.addLayer({
      id: 'layer-overview-fill', type: 'fill', source: 'overview-box',
      paint: { 'fill-color': '#8800ff', 'fill-opacity': 0.30 }
    });
    map.addLayer({
      id: 'layer-overview-line', type: 'line', source: 'overview-box',
      paint: { 'line-color': '#cc77ff', 'line-width': 2.0 }
    });
  }

  if (!map.getSource('overview-labels')) {
    map.addSource('overview-labels', { type: 'geojson', data: makeOverviewLabels() });
    map.addLayer({
      id: 'layer-overview-labels', type: 'symbol', source: 'overview-labels',
      layout: {
        'text-field': ['get', 'label'],
        'text-font': ['DIN Offc Pro Bold', 'Arial Unicode MS Bold'],
        'text-size': 13,
        'text-allow-overlap': true,
        'text-ignore-placement': true,
      },
      paint: {
        'text-color': '#ffffff',
        'text-halo-color': '#330066',
        'text-halo-width': 2.5,
      }
    });
  }

	// 최고/최저 높이 마커
	if (!map.getSource('height-markers')) {
		map.addSource('height-markers', {
			type: 'geojson',
			data: { type: 'FeatureCollection', features: [] }
		});

		// 원 (채우기)
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

		// 텍스트 라벨
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

  updateDisplay();
  syncInputFields();
  updateGrid();
  onGridSizeChange(GRID_N);
});

// ─── 그리드 셀 GeoJSON ───────────────────────────────────
function makeGridCells() {
  const features  = [];
  const centerPt  = turf.point([state.lng, state.lat]);
  const totalKm   = TILE_SIZE_KM * GRID_N;
  const halfTotal = totalKm / 2;

  const gridNLat = turf.destination(centerPt, halfTotal,   0, { units: 'kilometers' }).geometry.coordinates[1];
  const gridWLng = turf.destination(centerPt, halfTotal, 270, { units: 'kilometers' }).geometry.coordinates[0];

  const lats = [], lngs = [];
  for (let i = 0; i <= GRID_N; i++) {
    lats.push(turf.destination(turf.point([state.lng, gridNLat]), TILE_SIZE_KM * i, 180, { units: 'kilometers' }).geometry.coordinates[1]);
    lngs.push(turf.destination(turf.point([gridWLng, state.lat]), TILE_SIZE_KM * i,  90, { units: 'kilometers' }).geometry.coordinates[0]);
  }

  const startDist    = 0;
  const playableDist = Math.floor(GRID_N * 2 / 9);
  const half         = (GRID_N - 1) / 2;

  for (let row = 0; row < GRID_N; row++) {
    for (let col = 0; col < GRID_N; col++) {
      const n = lats[row], s = lats[row+1];
      const w = lngs[col], e = lngs[col+1];
      const dist = Math.max(Math.abs(row - half), Math.abs(col - half));

      let fillColor, lineColor, fillOpacity;
      if (dist <= startDist) {
        fillColor = '#4464e1'; lineColor = '#6688ff'; fillOpacity = 0.25;
      } else if (dist <= playableDist + startDist) {
        fillColor = '#00cc44'; lineColor = '#00ff66'; fillOpacity = 0.18;
      } else {
        fillColor = '#888888'; lineColor = '#aaaaaa'; fillOpacity = 0.10;
      }

      features.push({
        type: 'Feature',
        properties: { row, col, fillColor, lineColor, fillOpacity },
        geometry: { type: 'Polygon', coordinates: [[[w,n],[e,n],[e,s],[w,s],[w,n]]] }
      });
    }
  }
  return { type: 'FeatureCollection', features };
}

function makeOverviewBox() {
  const regionKm = TILE_SIZE_KM * GRID_N;
  const halfKm   = regionKm * OV_GRID / 2;
  const centerPt = turf.point([state.lng, state.lat]);

  const nwLat = turf.destination(centerPt, halfKm,   0, { units: 'kilometers' }).geometry.coordinates[1];
  const nwLng = turf.destination(centerPt, halfKm, 270, { units: 'kilometers' }).geometry.coordinates[0];

  const lats = [], lngs = [];
  for (let i = 0; i <= OV_GRID; i++) {
    lats.push(turf.destination(turf.point([state.lng, nwLat]), regionKm * i, 180, { units: 'kilometers' }).geometry.coordinates[1]);
    lngs.push(turf.destination(turf.point([nwLng, state.lat]), regionKm * i,  90, { units: 'kilometers' }).geometry.coordinates[0]);
  }

  const features = [];
  for (let row = 0; row < OV_GRID; row++) {
    for (let col = 0; col < OV_GRID; col++) {
      const n = lats[row], s = lats[row+1];
      const w = lngs[col], e = lngs[col+1];
      features.push({
        type: 'Feature',
        geometry: { type: 'Polygon', coordinates: [[[w,n],[e,n],[e,s],[w,s],[w,n]]] },
        properties: { col, row, label: '(' + col + ',' + (OV_GRID - 1 - row) + ')' }
      });
    }
  }
  return { type: 'FeatureCollection', features };
}

function makeOverviewLabels() {
  const regionKm = TILE_SIZE_KM * GRID_N;
  const halfKm   = regionKm * OV_GRID / 2;
  const centerPt = turf.point([state.lng, state.lat]);

  const nwLat = turf.destination(centerPt, halfKm,   0, { units: 'kilometers' }).geometry.coordinates[1];
  const nwLng = turf.destination(centerPt, halfKm, 270, { units: 'kilometers' }).geometry.coordinates[0];

  const lats = [], lngs = [];
  for (let i = 0; i <= OV_GRID; i++) {
    lats.push(turf.destination(turf.point([state.lng, nwLat]), regionKm * i, 180, { units: 'kilometers' }).geometry.coordinates[1]);
    lngs.push(turf.destination(turf.point([nwLng, state.lat]), regionKm * i,  90, { units: 'kilometers' }).geometry.coordinates[0]);
  }

  const features = [];
  for (let row = 0; row < OV_GRID; row++) {
    for (let col = 0; col < OV_GRID; col++) {
      const n = lats[row], s = lats[row+1];
      const w = lngs[col], e = lngs[col+1];
      features.push({
        type: 'Feature',
        geometry: { type: 'Point', coordinates: [(w+e)/2, (n+s)/2] },
        properties: { label: '(' + col + ',' + (OV_GRID - 1 - row) + ')' }
      });
    }
  }
  return { type: 'FeatureCollection', features };
}

function updateGrid() {
  if (!map.getSource('grid-cells')) return;
  map.getSource('grid-cells').setData(makeGridCells());
  if (map.getSource('overview-box'))    map.getSource('overview-box').setData(makeOverviewBox());
  if (map.getSource('overview-labels')) map.getSource('overview-labels').setData(makeOverviewLabels());
}

// ─── Overview 셀 클릭 → 단일 타일 RAW 다운로드 ──────────
// overview-box 레이어의 셀을 클릭하면 해당 셀의 (col, row)를 읽어
// 그 셀 중심 좌표를 기반으로 GRID_N×GRID_N 하이트맵을 생성하고
// 정중앙 타일(GRID_N가 홀수면 floor(GRID_N/2) 위치)만 RAW ZIP으로 저장한다.
map.on('click', 'layer-overview-fill', function(e) {
  const props  = e.features[0].properties;
  const ovCol  = props.col;                    // 서→동 (0~8)
  const ovRow  = props.row;                    // 북→남 (0=맨위)

  // 라벨 좌표계(남→북 y축)로 변환
  const labelCol = ovCol;
  const labelRow = OV_GRID - 1 - ovRow;

  // 서브타일 번호: 중앙 셀(4,4) = (0,0) 기준, 한 칸 = GRID_N씩
  const CENTER_OV = Math.floor(OV_GRID / 2);  // 4
  const tileX     = (labelCol - CENTER_OV) * GRID_N;
  const tileY     = (labelRow - CENTER_OV) * GRID_N;
  const tileXend  = tileX + GRID_N - 1;
  const tileYend  = tileY + GRID_N - 1;

  // 클릭된 셀의 지리적 중심 좌표 계산 (state는 변경하지 않음)
  const regionKm = TILE_SIZE_KM * GRID_N;
  const halfKm   = regionKm * OV_GRID / 2;
  const centerPt = turf.point([state.lng, state.lat]);

  const nwLat = turf.destination(centerPt, halfKm,   0, { units: 'kilometers' }).geometry.coordinates[1];
  const nwLng = turf.destination(centerPt, halfKm, 270, { units: 'kilometers' }).geometry.coordinates[0];

  const lats = [], lngs = [];
  for (let i = 0; i <= OV_GRID; i++) {
    lats.push(turf.destination(turf.point([state.lng, nwLat]), regionKm * i, 180, { units: 'kilometers' }).geometry.coordinates[1]);
    lngs.push(turf.destination(turf.point([nwLng, state.lat]), regionKm * i,  90, { units: 'kilometers' }).geometry.coordinates[0]);
  }

  const cellCenterLng = (lngs[ovCol] + lngs[ovCol + 1]) / 2;
  const cellCenterLat = (lats[ovRow] + lats[ovRow + 1]) / 2;

  const cellOffset = {
    ox: labelCol - CENTER_OV,
    oy: labelRow - CENTER_OV,
  };

	// 변경
	showChoiceModal(
		'(' + labelCol + ',' + labelRow + ') 작업 선택',
		'중심 좌표: ' + cellCenterLat.toFixed(5) + ', ' + cellCenterLng.toFixed(5) + '<br>' +
		'타일 범위: tile_' + tileX + '_' + tileY + ' ~ tile_' + tileXend + '_' + tileYend,
		function() { startTileRoadCsvDownload(cellCenterLng, cellCenterLat, labelCol, labelRow); },
		function() { startTileRiverCsvDownload(cellCenterLng, cellCenterLat, labelCol, labelRow); },  // ← 추가
		function() { startDownload('raw', null, cellOffset, cellCenterLng, cellCenterLat); }
	);
});

// overview 셀 위에서 커서 포인터로 변경
map.on('mouseenter', 'layer-overview-fill', function() {
  map.getCanvas().style.cursor = 'pointer';
});
map.on('mouseleave', 'layer-overview-fill', function() {
  map.getCanvas().style.cursor = '';
});



// ─── 지도 스타일 전환 ────────────────────────────────────
const mapStyles = {
  streets:   'mapbox://styles/mapbox/streets-v11',
  satellite: 'mapbox://styles/mapbox/satellite-streets-v11'
};

function changeMapStyle(styleKey) {
  map.setStyle(mapStyles[styleKey]);

  map.once('style.load', () => {
    if (!map.getSource('mapbox-dem')) {
      map.addSource('mapbox-dem', {
        'type': 'raster-dem',
        'url': 'mapbox://mapbox.mapbox-terrain-rgb',
        'tileSize': 512
      });
    }
    map.setTerrain({ 'source': 'mapbox-dem', 'exaggeration': 1.5 });
	
	// 높이 마커 재생성
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
  });

  const sBtn = document.getElementById('btn-style-streets');
  const sSat = document.getElementById('btn-style-satellite');
  if (styleKey === 'streets') {
    sBtn.style.background  = 'var(--border)';  sBtn.style.borderColor = 'var(--accent2)'; sBtn.style.color = 'var(--text)';
    sSat.style.background  = 'var(--surface)'; sSat.style.borderColor = 'var(--border)';  sSat.style.color = 'var(--muted)';
  } else {
    sSat.style.background  = 'var(--border)';  sSat.style.borderColor = 'var(--accent2)'; sSat.style.color = 'var(--text)';
    sBtn.style.background  = 'var(--surface)'; sBtn.style.borderColor = 'var(--border)';  sBtn.style.color = 'var(--muted)';
  }
  
	// changeMapStyle 함수 내부의 map.once('style.load', ...) 마지막 부분에 추가 권장
	const gridCheckbox = document.getElementById('grid-toggle');
	if (gridCheckbox) {
		toggleGrid(gridCheckbox.checked);
	}
}