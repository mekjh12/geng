'use strict';

// ════════════════════════════════════════════════════════
const MAPBOX_TOKEN   = "pk.eyJ1Ijoia2ltamFlaG8iLCJhIjoiY2pwc2VwMzlrMWs1ejN4cGd2dnU2b2dwcyJ9.-qJSvsEkAH9_OxjhFdfQqg";
const TILE_SIZE_KM   = 1.024;
const OUTPUT_PX      = 1024;
const FETCH_ZOOM     = 14;
const OVERVIEW_ZOOM  = 10;
const OVERVIEW_SIZE  = 512;
const MAPBOX_TILE_PX = 512;
const OV_GRID        = 9;

const EARTH_CIRCUMFERENCE = 40075016.686;
const ZOOM14_TILES = Math.pow(2, 14);
const METERS_PER_PIXEL_BY_LAT = Array.from({ length: 91 }, (_, lat) =>
  (EARTH_CIRCUMFERENCE * Math.cos(lat * Math.PI / 180)) / (ZOOM14_TILES * MAPBOX_TILE_PX)
);
// ════════════════════════════════════════════════════════

let GRID_N       = 9;
let FULL_SIZE_KM = TILE_SIZE_KM * GRID_N;

let state = {
  lng: 110.51317,
  lat: 24.91867,
  zoom: 9.5,
  globalMin: null,
  globalMax: null,
};

// ─── 디버그용 buf2 캐시 ───────────────────────────────────
let _debugBuf2     = null;
let _debugBuf2Size = 0;
let _debugMpp      = 1;