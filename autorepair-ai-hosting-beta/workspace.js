document.addEventListener('DOMContentLoaded',()=>{
 const root=document.querySelector('.aaihb-workspace[data-screen]');if(!root)return;
 const screen=root.dataset.screen;
 const descriptions={
  '月次価値レポート':['月の結果を見る','利用料と追加費用を引いた、時間価値の試算です。'],
  '定期診断':['毎日の診断を自動にする','診断頻度の設定は、必要なときだけ開きます。'],
  '作業記録':['実際の作業時間を記録','調査・対応に何分かかったかを入力します。'],
  '案件を完了して比較する':['従来の時間と比べる','同じ作業をツールなしで行う場合の時間と比べます。'],
  '引き継ぎ履歴（最新20件）':['これまでの対応を見る','誰が、いつ、何をしたかを確認できます。']
 };
 const headings=Array.from(root.children).filter(e=>e.tagName==='H2');
 headings.forEach((heading,index)=>{
   const title=heading.textContent.trim();
   let id=heading.id || 'workspace-section-'+index;
   const box=document.createElement('details');box.className='aaihb-fold';box.id=id;heading.removeAttribute('id');
   const summary=document.createElement('summary');
   const icon=document.createElement('span');icon.className='aaihb-fold-number';icon.textContent=String(index+1).padStart(2,'0');
   const words=document.createElement('span');const strong=document.createElement('strong');const small=document.createElement('small');
   let label=descriptions[title]||[title,'クリックして開く'];
   if(screen==='connect'){
     if(title.startsWith('1.'))label=['会社名・ロゴを整える','ブランド表示を変更したいときに開きます。'];
     if(title.startsWith('2.'))label=['診断されるサイト：接続情報を発行','対象サイト側で操作します。発行したトークンは管理側へ登録します。'];
     if(title.startsWith('3.'))label=['管理する側：サイトを登録','対象サイトで発行した接続先とトークンを貼り付けます。'];
     if(title.startsWith('4.'))label=['登録したサイトを確認・診断','登録済みのサイトと接続結果を確認します。'];
   }
   strong.textContent=label[0];small.textContent=label[1];words.append(strong,small);summary.append(icon,words);box.append(summary);
   const body=document.createElement('div');body.className='aaihb-fold-body';root.insertBefore(box,heading);
   let next=heading.nextSibling;heading.remove();
   while(next && !(next.nodeType===1&&next.tagName==='H2')){const n=next.nextSibling;body.append(next);next=n;}
   box.append(body);
   box.open=screen==='ops'||(screen==='value'&&index===0)||(screen==='connect'&&id==='connect-register');
   // A freshly issued one-time token must remain visible after the redirect.
   if(screen==='connect' && body.textContent.includes('今回だけ表示するトークン'))box.open=true;
 });
 root.querySelectorAll('[data-open]').forEach(link=>link.addEventListener('click',()=>{
   const section=document.getElementById(link.dataset.open);if(section){section.open=true;}
 }));
 const hash=location.hash.slice(1);const target=document.getElementById(hash);if(target&&target.matches('details.aaihb-fold'))target.open=true;
 // Select an actual case by its name instead of making the user retype its numeric ID.
 const options=[];const table=root.querySelector('table.aaihb-value-cases');
 if(table){for(const row of table.querySelectorAll('tbody tr')){const cell=row.cells[0];if(!cell)continue;const m=cell.textContent.match(/^#(\d+)\s+(.+)/);if(m)options.push({id:m[1],label:cell.textContent.trim()});}}
 root.querySelectorAll('form input[name="case"]').forEach(input=>{
   if(screen!=='value'||!options.length)return;
   const select=document.createElement('select');select.name=input.name;select.required=input.required;select.setAttribute('aria-label','記録する案件');
   const empty=document.createElement('option');empty.value=input.value==='0'?'0':'';empty.textContent=input.value==='0'?'共通作業（案件にひも付けない）':'案件を選んでください';select.append(empty);
   for(const item of options){const option=document.createElement('option');option.value=item.id;option.textContent=item.label;select.append(option);}
   // Keep a manual ID mode for historical cases outside the current 200-row display.
   const wrapper=document.createElement('span');input.replaceWith(wrapper);wrapper.append(select);
   const switcher=document.createElement('button');switcher.type='button';switcher.className='button';switcher.textContent='番号で指定する';wrapper.append(switcher);input.disabled=true;input.hidden=true;wrapper.append(input);
   switcher.addEventListener('click',()=>{const manual=input.hidden;input.hidden=!manual;input.disabled=!manual;select.hidden=manual;select.disabled=manual;switcher.textContent=manual?'名前から選ぶ':'番号で指定する';});
 });
});
