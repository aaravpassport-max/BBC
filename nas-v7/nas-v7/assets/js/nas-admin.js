/* =============================================================================
   NAS Super Combo v3 — Admin JS v3.1
   Button feedback, ripples, confirm dialogs, toasts.
   ============================================================================= */
'use strict';

/* ── Toast ──────────────────────────────────────────────────────────────── */
window.nasAdminToast = function(msg, type, duration) {
  type = type || 'info'; duration = duration || 3500;
  const icons = {success:'✅',error:'❌',warning:'⚠️',info:'ℹ️'};
  const el = document.getElementById('nas-admin-toast');
  if (!el) return;
  el.innerHTML = (icons[type]||'') + ' ' + msg;
  el.className = 'nas-admin-toast nas-toast-' + type;
  void el.offsetWidth;
  el.classList.add('nas-toast-show');
  clearTimeout(el._t);
  el._t = setTimeout(()=>el.classList.remove('nas-toast-show'), duration);
};

/* ── Button loading state ──────────────────────────────────────────────── */
window.nasSetBtnLoading = function(btn, loading) {
  if (!btn) return;
  if (loading) {
    if (!btn.dataset.origHtml) btn.dataset.origHtml = btn.innerHTML;
    btn.innerHTML = '<span class="nas-btn-spinner"></span> <span>' + (btn.dataset.loadingText||'Saving…') + '</span>';
    btn.classList.add('nas-btn-loading');
    btn.disabled = true;
  } else {
    if (btn.dataset.origHtml) btn.innerHTML = btn.dataset.origHtml;
    btn.classList.remove('nas-btn-loading');
    btn.disabled = false;
  }
};

/* ── Custom confirm dialog ─────────────────────────────────────────────── */
window.nasConfirm = function(opts) {
  return new Promise(resolve => {
    document.getElementById('nas-confirm-overlay')?.remove();
    const ov = document.createElement('div');
    ov.id = 'nas-confirm-overlay';
    ov.className = 'nas-confirm-overlay';
    ov.innerHTML =
      '<div class="nas-confirm-box">' +
        '<div class="nas-confirm-icon">'+(opts.icon||'❓')+'</div>' +
        '<div class="nas-confirm-body"><h4>'+(opts.title||'Are you sure?')+'</h4><p>'+(opts.message||'')+'</p></div>' +
        '<div class="nas-confirm-foot">' +
          '<button class="nas-btn nas-btn-outline nas-btn-sm" id="nc-cancel">'+(opts.cancelText||'Cancel')+'</button>' +
          '<button class="nas-btn nas-btn-'+(opts.type||'primary')+' nas-btn-sm" id="nc-ok">'+(opts.confirmText||'Confirm')+'</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(ov);
    const done = v => { ov.remove(); resolve(v); };
    ov.querySelector('#nc-ok').onclick = ()=>done(true);
    ov.querySelector('#nc-cancel').onclick = ()=>done(false);
    ov.onclick = e => { if(e.target===ov) done(false); };
  });
};

/* ── Filter table ────────────────────────────────────────────────────────── */
window.nasFilterTable = function(tableId, q) {
  const q2 = (q||'').toLowerCase();
  document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
    tr.style.display = (tr.dataset.search||tr.textContent||'').toLowerCase().includes(q2)?'':'none';
  });
};

/* ── Modal helpers ───────────────────────────────────────────────────────── */
window.nasCloseModal = function(id) {
  const el = document.getElementById(id);
  if(el){el.style.display='none';document.body.style.overflow='';}
};
document.addEventListener('keydown', e=>{
  if(e.key==='Escape'){
    document.querySelectorAll('.nas-modal').forEach(m=>{m.style.display='none';});
    document.getElementById('nas-confirm-overlay')?.remove();
  }
});

/* ── Copy ────────────────────────────────────────────────────────────────── */
window.nasCopy = function(text) {
  navigator.clipboard?.writeText(text).then(()=>nasAdminToast('Copied!','success'));
};

/* ── Ripple effect on all buttons ─────────────────────────────────────────── */
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.nas-btn,.nas-status-btn,.nas-page-btn');
  if (!btn || btn.disabled) return;
  const r = document.createElement('span');
  const rect = btn.getBoundingClientRect();
  const size = Math.max(rect.width, rect.height) * 2;
  r.className = 'nas-btn-ripple';
  r.style.cssText = 'width:'+size+'px;height:'+size+'px;left:'+(e.clientX-rect.left-size/2)+'px;top:'+(e.clientY-rect.top-size/2)+'px;';
  if(getComputedStyle(btn).position==='static') btn.style.position='relative';
  btn.appendChild(r);
  setTimeout(()=>r.remove(), 600);
}, true);

/* ── Pricing calc ───────────────────────────────────────────────────────── */
document.addEventListener('input', function(e) {
  if(!['nrd-client-price','nrd-vendor-cost','nrd-gst-pct'].includes(e.target.id)) return;
  const cp=parseFloat(document.getElementById('nrd-client-price')?.value)||0;
  const vc=parseFloat(document.getElementById('nrd-vendor-cost')?.value)||0;
  const gp=parseFloat(document.getElementById('nrd-gst-pct')?.value)||18;
  const profit=cp-vc, gst=cp*(gp/100), total=cp+gst;
  const margin=cp>0?((profit/cp)*100).toFixed(1):0;
  const fmt=n=>'₹'+n.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
  const set=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v;};
  set('nrd-calc-profit',fmt(profit));set('nrd-calc-gst',fmt(gst));
  set('nrd-calc-total',fmt(total));set('nrd-calc-margin',margin+'%');
  const c=document.getElementById('nrd-pricing-calc');
  if(c) c.className='nas-pricing-calc'+(profit<0?' nas-calc-loss':'');
});

/* ── DOMContentLoaded ───────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {

  // Sidebar toggle
  const toggle=document.getElementById('nas-sidebar-toggle');
  const shell=document.getElementById('nas-admin-shell');
  if(toggle && shell){
    toggle.addEventListener('click',()=>{
      if(window.innerWidth<=768){
        shell.classList.toggle('nas-mobile-open');
      } else {
        shell.classList.toggle('nas-sidebar-collapsed');
        try{localStorage.setItem('nas-sc',shell.classList.contains('nas-sidebar-collapsed'));}catch(e){}
      }
    });
    try{if(window.innerWidth>768&&localStorage.getItem('nas-sc')==='true') shell.classList.add('nas-sidebar-collapsed');}catch(e){}
  }

  // Mobile overlay click-outside
  const ov=shell?.querySelector('.nas-sidebar-overlay');
  if(ov) ov.addEventListener('click',()=>shell.classList.remove('nas-mobile-open'));

  // Global search
  const searchEl=document.getElementById('nas-admin-search');
  if(searchEl){
    let timer;
    searchEl.addEventListener('input',function(){
      clearTimeout(timer);
      timer=setTimeout(()=>{
        const q=this.value.trim();
        const res=document.getElementById('nas-search-results');
        if(!res) return;
        if(q.length<2){res.style.display='none';return;}
        const cfg=window._nasAdminCfg||{};
        const fd=new FormData();
        fd.append('action','nas_admin_get_bookings');fd.append('nonce',cfg.nonce);
        fd.append('search',q);fd.append('page','1');
        const ctrl=new AbortController();setTimeout(()=>ctrl.abort(),30000);
        fetch(cfg.ajaxUrl,{method:'POST',body:fd,signal:ctrl.signal}).then(r=>r.json()).then(data=>{
          if(!data.success||!data.data?.bookings?.length){res.style.display='none';return;}
          const base=cfg.adminUrl||'';
          res.innerHTML=data.data.bookings.slice(0,6).map(b=>
            '<a href="'+(base.includes('?')?base+'&':'base?')+'nas_admin=request&id='+b.id+'" class="nas-sr-item">'+
            '<strong>'+(b.uid||'#'+b.id)+'</strong>'+
            '<span>'+(b.client_name||'')+' · '+(b.np_name||'')+' · '+(b.status||'')+'</span></a>'
          ).join('');
          res.style.display='block';
        }).catch(()=>{res.style.display='none';});
      },250);
    });
    document.addEventListener('click',e=>{
      if(!document.querySelector('.nas-admin-search-wrap')?.contains(e.target)){
        const res=document.getElementById('nas-search-results');
        if(res) res.style.display='none';
      }
    });
  }

  // Active nav
  const pg=new URLSearchParams(window.location.search).get('nas_admin')||'dashboard';
  document.querySelectorAll('.nas-nav-item').forEach(item=>{
    const href=item.getAttribute('href')||'';
    const ip=new URLSearchParams(href.split('?')[1]||'').get('nas_admin')||'dashboard';
    item.classList.toggle('active',ip===pg);
  });

  // Add status icons to status buttons
  // TRACE: Map canonical status keys → emoji icons for status buttons in admin template.
  //        Precondition: buttons rendered by request-detail.php with canonical data-status attributes.
  //        Postcondition: each button's innerHTML prefixed with its icon if not already present.
  const icons={
    under_review:'🔍', quotation_sent:'💰', ready_to_process:'✅',
    documents_received:'📄', payment_received:'💳', ad_processing:'🎨',
    proof_ready:'🖼️', submitted_to_pub:'📨', published:'📰',
    completed:'🎉', rejected:'❌', not_able_to_process:'⛔'
  };
  document.querySelectorAll('.nas-status-btn[data-status]').forEach(btn=>{
    const s=btn.dataset.status;
    if(s&&icons[s]&&!btn.innerHTML.includes(icons[s])){
      btn.innerHTML=icons[s]+' '+btn.textContent.trim();
    }
  });

  // Chart.js lazy load
  if(!window.Chart&&document.querySelector('canvas')){
    const s=document.createElement('script');
    s.src='https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js';
    document.head.appendChild(s);
  }
});

// Config init
(function(){
  const el=document.getElementById('nas-admin-config');
  if(el){try{window._nasAdminCfg=JSON.parse(el.textContent);}catch(e){}}
})();
