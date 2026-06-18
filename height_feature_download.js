downloadFeature.onclick = async function()
{
    this.innerText = "Processing RAWs...";
    const zip = new JSZip();
	const RAW_SIZE = OUTPUT_TILE_SIZE + 1; // 1025

    // 1. 모든 타일 ImageData 캐시
    const allPixels = canvases.map(cvs =>
        cvs.getContext('2d').getImageData(0, 0, RAW_SIZE, RAW_SIZE).data
    );

    for (let r = 0; r < GRID_COUNT; r++) {
        for (let c = 0; c < GRID_COUNT; c++) {
            
            // 타일 좌표 계산 (기존 Flip 로직 유지)
            const x = c + globalOffsetX;
            const y = ((GRID_COUNT - 1) - r) + globalOffsetY;

            // 해당 타일의 원본 픽셀 데이터 (RGBA 순서)
            const sourceData = allPixels[r * GRID_COUNT + c];
            
            // RAW RGB 파일 생성 (각 픽셀당 1바이트: R)
            const rawRGBBuffer = new Uint8Array(RAW_SIZE * RAW_SIZE * 1);

            for (let i = 0; i < RAW_SIZE * RAW_SIZE; i++) {
                const srcIdx = i * 4; // 원본 RGBA 인덱스
                const outIdx = i * 1; // 출력 RGB 인덱스
                
                const bValue = sourceData[srcIdx + 2]; // B 채널 추출
				rawRGBBuffer[outIdx] = bValue;
            }

            // ZIP에 .raw 파일로 추가
            zip.file(`tile_${x}_${y}_feature.raw`, rawRGBBuffer);
        }
    }

    // 2. ZIP 생성 및 다운로드
    const content = await zip.generateAsync({ type: "blob" });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(content);
    link.download = `feature_raw_tiles_${globalOffsetX}x${globalOffsetY}.zip`;
    link.click();

    this.innerText = "DOWNLOAD FEATURE RAW";
};