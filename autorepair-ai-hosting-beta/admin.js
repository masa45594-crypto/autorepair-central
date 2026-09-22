/* Sequential requests: one site per request, no raw HTML from remote reports. */
document.addEventListener('DOMContentLoaded', () => {
    const all = document.getElementById('aaihb-all');
    if (!all) return;
    const buttons = Array.from(document.querySelectorAll('.aaihb-scan'));
    const progress = document.getElementById('aaihb-progress');
    let busy = false, stop = false;
    document.getElementById('aaihb-stop').onclick = () => { stop = true; };
    async function run(selected) {
        if (busy) return;
        busy = true; stop = false;
        all.disabled = true; buttons.forEach(b => { b.disabled = true; });
        let done = 0;
        try {
            for (const button of selected) {
                if (stop) break;
                const output = document.getElementById('result-' + button.dataset.id);
                const previous = output.textContent;
                output.textContent = '診断中…';
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 55000);
                try {
                    const body = new URLSearchParams({action:'aaihb_scan', _ajax_nonce:AAIHB.nonce, id:button.dataset.id});
                    const response = await fetch(AAIHB.url, {method:'POST', credentials:'same-origin', body, signal:controller.signal});
                    const result = await response.json();
                    if (!result.success) throw new Error(typeof result.data === 'string' ? result.data : '再ログインしてお試しください。');
                    output.textContent = result.data.score + '/100 · ' + result.data.generated_at + '\n' + result.data.checks.map(c => c.id + ': ' + c.status).join(' / ');
                } catch (error) {
                    output.textContent = '診断失敗：' + (error.name === 'AbortError' ? '時間切れ。再読み込みして結果を確認してください。' : error.message) + ' ｜ 前回表示：' + previous;
                } finally { clearTimeout(timeout); }
                done++;
                progress.textContent = done + '/' + selected.length + ' 件の処理完了';
            }
        } finally {
            busy = false; all.disabled = false; buttons.forEach(b => { b.disabled = false; });
            progress.textContent += stop ? '（停止）' : '（終了）';
        }
    }
    all.onclick = () => run(buttons);
    buttons.forEach(b => { b.onclick = () => run([b]); });
});
