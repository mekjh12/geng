<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<script src="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.js"></script>
<link href="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.css" rel="stylesheet"/>
<script src="js/config.js" onerror="console.warn('config.js 없음')"></script>
<style>
  html, body, #map { margin:0; padding:0; width:100%; height:100%; }
</style>
</head>
<body>
<div id="map"></div>
<script>
mapboxgl.accessToken = (typeof MAPBOX_TOKEN !== 'undefined') ? MAPBOX_TOKEN : '';

const map = new mapboxgl.Map({
    container: 'map',
    style: 'mapbox://styles/mapbox/satellite-v9',
    center: [110.51, 24.91],
    zoom: 14,
    fadeDuration: 0,
});

// terrain 소스 + 3D 활성화
map.on('load', () => {
    map.addSource('mapbox-dem', {
        type: 'raster-dem',
        url: 'mapbox://mapbox.mapbox-terrain-dem-v1',
        tileSize: 512,
        maxzoom: 14,
    });
    map.setTerrain({ source: 'mapbox-dem', exaggeration: 1 });
});

// ★ 부모로부터 좌표 배열 수신
window.addEventListener('message', e => {
    if (!e.data || e.data.type !== 'query_elevation') return;

    const coords = e.data.coords; // [[lng, lat], ...]
    const requestId = e.data.requestId;

    // 지도를 폴리곤 중심으로 이동 후 타일 로딩 기다렸다가 쿼리
    const lngs = coords.map(c => c[0]);
    const lats = coords.map(c => c[1]);
    const centerLng = (Math.min(...lngs) + Math.max(...lngs)) / 2;
    const centerLat = (Math.min(...lats) + Math.max(...lats)) / 2;

    map.once('idle', () => {
        const elevations = coords.map(([lng, lat]) => {
            const elev = map.queryTerrainElevation([lng, lat], { exaggerated: false });
            return elev !== null ? Math.round(elev * 10) / 10 : 0;
        });

        // ★ 부모에게 결과 반환
        window.parent.postMessage({
            type: 'elevation_result',
            requestId,
            elevations,
        }, '*');
    });

    map.jumpTo({ center: [centerLng, centerLat], zoom: 14 });
});
</script>
</body>
</html>