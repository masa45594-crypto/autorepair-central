(()=>{'use strict';
 const root=document.querySelector('.aaihb-selfserve');if(!root)return;
 root.querySelectorAll('[data-copy]').forEach(button=>button.addEventListener('click',async()=>{
 const field=document.getElementById(button.dataset.copy),status=document.getElementById('ss-copy-status');
 try{await navigator.clipboard.writeText(field.value);status.textContent='コピーしました。貼り付け先へ進んでください。';}
 catch(e){field.focus();field.select();status.textContent='自動コピーできませんでした。選択された文字をコピーしてください。';}
 }));
 const pack=document.getElementById('ss-import'),preview=document.getElementById('ss-import-preview');
 if(pack)pack.addEventListener('input',()=>{if(!pack.value.trim()){preview.textContent='';return;}try{const d=JSON.parse(pack.value),url=new URL(d.endpoint);if(d.format!=='aaihb-connect-1'||url.protocol!=='https:'||typeof d.name!=='string'||!/^[a-f0-9]{64}$/.test(d.token))throw Error();preview.textContent='接続予定：'+d.name+'（'+url.hostname+'）／登録時に接続を検証します。';}catch(e){preview.textContent='情報を読み取れません。対象サイトでコピーした内容をそのまま貼り付けてください。';}});
 root.querySelectorAll('form').forEach(f=>f.addEventListener('submit',()=>{f.querySelectorAll('[type="submit"]').forEach(b=>{b.disabled=true;b.value='処理中…';});}));
 const search=document.getElementById('ss-search'),items=[...root.querySelectorAll('.ss-faq details')],count=document.getElementById('ss-help-count');
 function filter(){const term=search.value.trim().toLowerCase();let n=0;items.forEach(i=>{i.hidden=!i.textContent.toLowerCase().includes(term);if(!i.hidden)n++;});count.textContent=n?n+'件の案内があります':'該当する案内がありません。短い言葉で検索するか、下の確認用メモをご利用ください。';}
 function anchor(){const item=items.find(i=>'#'+i.id===location.hash);if(item){search.value='';filter();item.open=true;item.scrollIntoView({block:'center'});}}
 if(search){search.addEventListener('input',filter);window.addEventListener('hashchange',anchor);filter();anchor();}
})();
