// ════════════════════════════════════════════
// config.js — Water GIS 설정 파일
// 이 파일은 웹 루트에 두고 직접 수정하세요.
// ════════════════════════════════════════════

// Mapbox 액세스 토큰
const MAPBOX_TOKEN = 'YOUR_MAPBOX_TOKEN_HERE';

// 기본 지역명 (DB region 컬럼과 일치해야 함)
const DEFAULT_REGION = 'sejong';

// 기본 데이터 타입
const DEFAULT_TYPE = 'water';

// 초기 지도 중심 좌표 및 줌
const STATE_LNG  = 127.2895;
const STATE_LAT  = 36.4801;
const STATE_ZOOM = 10;

// 그리드 설정
const TILE_SIZE_KM = 1.024;  // 서브타일 1개 크기 (km)
const GRID_N       = 9;      // 서브타일 행/열 수 (서브타일 단위)
const OV_GRID      = 9;      // 대형 그리드 행/열 수