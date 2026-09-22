/* Optional one-step-at-a-time overlay on top of workspace.js's fold sections.
   Runs after workspace.js (declared as a script dependency), which has
   already turned the H2 headings into <details id="..."> boxes. */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.aaihb-workspace[data-screen="connect"]');
    if (!root) return;
    const stepIds = ['connect-agent', 'connect-register', 'connect-test'];
    const stepLabels = ['① 対象サイトの準備（接続情報を発行）', '② 管理する側へ登録', '③ 接続テスト（診断を実行）'];

    const nav = document.querySelector('nav.aaihb-nav');
    const toggle = document.createElement('button');
    toggle.type = 'button'; toggle.className = 'button button-primary'; toggle.id = 'aaihb-wizard-toggle';
    toggle.style.margin = '12px 0';
    toggle.textContent = '初めての接続ガイドを開く';
    (nav || root.firstElementChild).after(toggle);

    const bar = document.createElement('div');
    bar.id = 'aaihb-wizard-nav'; bar.hidden = true;
    bar.setAttribute('role', 'status'); bar.setAttribute('aria-live', 'polite');
    toggle.after(bar);

    let active = false, current = 0;
    function sections() { return stepIds.map(id => document.getElementById(id)).filter(Boolean); }

    function render() {
        const list = sections();
        if (!list.length) return;
        root.querySelectorAll('details.aaihb-fold').forEach(box => { box.hidden = active; });
        if (!active) { bar.hidden = true; toggle.textContent = '初めての接続ガイドを開く'; return; }
        toggle.textContent = 'ガイドを閉じてすべて表示する';
        list.forEach((box, i) => { box.hidden = i !== current; if (i === current) box.open = true; });
        bar.hidden = false; bar.innerHTML = '';
        const status = document.createElement('p');
        status.textContent = 'ステップ ' + (current + 1) + ' / ' + list.length + '：' + stepLabels[current];
        bar.append(status);
        const back = document.createElement('button');
        back.type = 'button'; back.className = 'button'; back.textContent = '← 前へ'; back.disabled = current === 0;
        back.onclick = () => { current = Math.max(0, current - 1); render(); list[current].scrollIntoView({behavior:'smooth', block:'start'}); };
        const forward = document.createElement('button');
        forward.type = 'button'; forward.className = 'button button-primary';
        forward.textContent = current === list.length - 1 ? 'ガイドを完了する' : '次へ →';
        forward.style.marginLeft = '8px';
        forward.onclick = () => {
            if (current === list.length - 1) { active = false; render(); return; }
            current = Math.min(list.length - 1, current + 1); render();
            list[current].scrollIntoView({behavior:'smooth', block:'start'});
        };
        bar.append(back, forward);
    }
    toggle.onclick = () => { active = !active; if (active) { current = 0; } render(); };
    render();
});
