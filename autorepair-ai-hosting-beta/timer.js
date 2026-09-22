/* Client-side stopwatch only. The saved minutes still go through the normal
   server-validated work form; this just reduces manual arithmetic. */
document.addEventListener('DOMContentLoaded', () => {
    const start = document.getElementById('aaihb-timer-start');
    const stop = document.getElementById('aaihb-timer-stop');
    const display = document.getElementById('aaihb-timer-display');
    const minutes = document.getElementById('aaihb-timer-minutes');
    const caseField = document.getElementById('aaihb-timer-case');
    if (!start || !stop || !display || !minutes) return;
    const KEY = 'aaihb_timer_started_at';
    let tick = null;

    function format(ms) {
        const total = Math.floor(ms / 1000);
        const m = String(Math.floor(total / 60)).padStart(2, '0');
        const s = String(total % 60).padStart(2, '0');
        return m + ':' + s;
    }
    function render() {
        const at = Number(localStorage.getItem(KEY) || 0);
        if (!at) { display.textContent = '未計測'; return; }
        display.textContent = '計測中… ' + format(Date.now() - at);
    }
    function running() { return !!Number(localStorage.getItem(KEY) || 0); }
    function setButtons() {
        start.disabled = running();
        stop.disabled = !running();
    }
    start.onclick = () => {
        if (running()) return;
        localStorage.setItem(KEY, String(Date.now()));
        setButtons();
        tick = setInterval(render, 1000);
        render();
    };
    stop.onclick = () => {
        const at = Number(localStorage.getItem(KEY) || 0);
        if (!at) return;
        const elapsedMinutes = Math.max(1, Math.round((Date.now() - at) / 60000));
        minutes.value = String(elapsedMinutes);
        localStorage.removeItem(KEY);
        if (tick) { clearInterval(tick); tick = null; }
        display.textContent = '記録：' + elapsedMinutes + '分を下の欄に入力しました。保存前に内容を確認してください。';
        setButtons();
        minutes.focus();
    };
    setButtons();
    if (running()) { tick = setInterval(render, 1000); render(); }
    // Reading a "case=" query parameter lets other screens hand off a specific case id.
    const params = new URLSearchParams(location.search);
    const presetCase = params.get('timer_case');
    if (presetCase && caseField) { caseField.value = presetCase; }
});
