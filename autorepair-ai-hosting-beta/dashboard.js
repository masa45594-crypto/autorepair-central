document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.aaihb-home');
    if (!root) return;
    const cards = Array.from(root.querySelectorAll('[data-site]'));
    const labels = {critical:'重大な異常',warning:'確認が必要',healthy:'診断項目は正常',unknown:'状態を確認できず'};
    const checks = {home:'公開ページ',rest_api:'REST API',database:'データベース',https:'HTTPS',disk:'ディスク',filesystem:'書き込み権限',cron:'定期処理',maintenance:'メンテナンス',updates:'更新',debug_log:'エラーログ'};
    const search = root.querySelector('#aaihb-home-search');
    const all = root.querySelector('#aaihb-home-scan');
    const stopButton = root.querySelector('#aaihb-home-stop');
    const progress = root.querySelector('#aaihb-home-progress');
    const meter = root.querySelector('#aaihb-home-meter');
    let filter = 'all', busy = false, stop = false;
    function refresh() {
        const counts = {critical:0,warning:0,healthy:0,unknown:0};
        let visible = 0;
        for (const card of cards) {
            counts[card.dataset.state]++;
            card.hidden = !(filter === 'all' || card.dataset.state === filter) || !card.dataset.name.toLocaleLowerCase().includes(search.value.toLocaleLowerCase().trim());
            if (!card.hidden) visible++;
        }
        for (const [key,count] of Object.entries(counts)) {
            const target = root.querySelector('[data-count="'+key+'"]');
            target.replaceChildren(document.createTextNode(String(count)));
            const small = document.createElement('small');small.textContent='サイト';target.append(small);
        }
        root.querySelector('#aaihb-home-visible').textContent = visible+' / '+cards.length+' サイトを表示';
        root.querySelector('#aaihb-home-empty').hidden = visible !== 0;
        return counts;
    }
    root.querySelectorAll('[data-filter]').forEach(button => {button.onclick=()=>{
        filter=button.dataset.filter;
        root.querySelectorAll('[data-filter]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.filter===filter)));
        refresh();
    };});
    search.addEventListener('input',refresh);
    function state(card, type, message, report) {
        card.dataset.state=type;
        for(const key of Object.keys(labels))card.classList.toggle('tone-'+key,key===type);
        card.querySelector('.aaihb-status').textContent=labels[type];
        card.querySelector('.aaihb-site-score').textContent=type==='unknown'?'—':String(report.score);
        card.querySelector('.aaihb-score-caption').textContent=type==='unknown'?'新しい診断が必要です':'/ 100　診断スコア';
        card.querySelector('.aaihb-site-message').textContent=message;
        if(report){
            const area=card.querySelector('.aaihb-checks');area.replaceChildren();
            for(const c of report.checks){const p=document.createElement('p');p.append(document.createTextNode(checks[c.id]||c.id));const span=document.createElement('span');span.textContent={healthy:'正常',warning:'注意',critical:'重大'}[c.status];p.append(span);area.append(p);}
            card.querySelector('summary').textContent='診断項目を見る';
        }else {card.querySelector('summary').textContent='前回の結果を見る（今回の状態は未確認）';}
    }
    async function run(selected) {
        if(busy || !AAIHB_HOME.canScan)return;
        busy=true;stop=false;all.disabled=true;stopButton.disabled=false;
        const buttons=Array.from(root.querySelectorAll('.aaihb-home-one'));buttons.forEach(b=>b.disabled=true);
        meter.max=selected.length;meter.value=0;let done=0,failed=0;
        try {
            for(const card of selected){
                if(stop)break;
                progress.textContent=(done+1)+' / '+selected.length+'　'+card.dataset.name+' を診断中…';
                card.setAttribute('aria-busy','true');
                const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),55000);
                try{
                    const body=new URLSearchParams({action:'aaihb_scan',_ajax_nonce:AAIHB_HOME.nonce,id:card.dataset.site});
                    const response=await fetch(AAIHB_HOME.url,{method:'POST',credentials:'same-origin',body,signal:controller.signal});
                    const result=await response.json();
                    if(!result.success)throw new Error(typeof result.data==='string'?result.data:'接続を確認し、再ログインしてください。');
                    const report=result.data;let type='healthy';
                    if(!Number.isInteger(report.score)||!Array.isArray(report.checks))throw new Error('診断結果を読み取れませんでした。');
                    if(report.checks.some(c=>c.status==='critical'))type='critical';
                    else if(report.checks.some(c=>c.status==='warning'))type='warning';
                    if(!Number.isFinite(Date.parse(report.generated_at))||Date.parse(report.generated_at)<Date.now()-172800000)type='unknown';
                    state(card,type,'判定日時（UTC）：'+report.generated_at,report);
                }catch(e){failed++;state(card,'unknown',e.name==='AbortError'?'時間切れ。再読み込みして保存された結果を確認してください。':'診断できませんでした：'+e.message,null);}
                finally{clearTimeout(timer);card.removeAttribute('aria-busy');}
                done++;meter.value=done;refresh();
            }
        }finally{
            busy=false;all.disabled=false;stopButton.disabled=true;buttons.forEach(b=>b.disabled=false);
            progress.textContent=done+' / '+selected.length+' 件を処理しました。診断失敗 '+failed+' 件。'+(stop?'停止しました。':'気になるサイトの「対応を見る」へ進んでください。');
        }
    }
    if(all){all.onclick=()=>run(cards);stopButton.onclick=()=>{stop=true;stopButton.disabled=true;progress.textContent+=' 現在の診断が終わったら停止します。';};root.querySelectorAll('.aaihb-home-one').forEach(b=>{b.onclick=()=>run([b.closest('[data-site]')]);});}
    refresh();
});
