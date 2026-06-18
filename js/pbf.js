// PBF URL fetch → Protobuf 파싱 → VectorTile 반환 (실패 시 true 반환) [async]
async function downloadPbfToTile(url) {
    try {
        const response = await fetch(url);
        if (!response.ok) throw new Error('HTTP ' + response.status);
        let data = await response.arrayBuffer();
        let tile = new VectorTile(new Protobuf(new Uint8Array(data)));
        return tile;
    } catch (e) {
        console.warn('PBF 다운로드 실패:', e.message);
        return true;
    }
}

