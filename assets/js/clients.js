// ═══════════════════════════════════════════
// Clients module
// WP Sales Pro v5 — Module: clients.js
// ═══════════════════════════════════════════

// ══════════════════════════════════════════════════
// MULTI-PRODUCT SALE
// ══════════════════════════════════════════════════
let _saleProducts = []; // [{product_id, name, price, qty}]

function initProductRows() {
  _saleProducts = [];
  document.getElementById('product-rows').innerHTML = '';
  addProductRow();
}

function addProductRow(prod=null) {
  const idx = _saleProducts.length;
  const row = {
    product_id: prod?.product_id || '',
    name: prod?.name || '',
    price: prod?.price || 0,
    qty: prod?.qty || 1,
  };
  _saleProducts.push(row);

  const div = document.createElement('div');
  div.id = `pr-row-${idx}`;
  div.style.cssText = 'margin-bottom:8px';

  const displayName = prod?.product_id ? (prod.name || (_p.find(p=>p.id==prod.product_id)?.name) || '') : '';

  div.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 90px 80px 32px;gap:6px;align-items:center">
      <div style="position:relative">
        <input type="hidden" id="pr-pid-${idx}" value="${prod?.product_id||''}">
        <input type="text" class="fi" id="pr-search-${idx}" placeholder="🔍 প্রোডাক্ট খুঁজুন..."
          value="${h(displayName)}"
          autocomplete="off"
          style="font-size:13px;width:100%"
          oninput="onRowProductSearch(${idx},this.value)"
          onfocus="if(this.value.length>=1)onRowProductSearch(${idx},this.value)">
        <div id="pr-dd-${idx}" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1000;
          background:var(--s1);border:1px solid rgba(99,102,241,.3);border-radius:10px;
          box-shadow:0 8px 24px rgba(0,0,0,.5);max-height:200px;overflow-y:auto;margin-top:3px"></div>
      </div>
      <input type="number" class="fi" id="pr-price-${idx}" placeholder="Price" value="${row.price||''}"
        style="font-size:13px;text-align:right" min="0" step="0.01"
        oninput="onRowPriceChange(${idx},this)">
      <input type="number" class="fi" id="pr-qty-${idx}" placeholder="Qty" value="${row.qty}"
        style="font-size:13px;text-align:center" min="1" step="1"
        oninput="onRowQtyChange(${idx},this)">
      <button type="button" onclick="removeProductRow(${idx})"
        style="background:var(--danger);border:none;border-radius:6px;color:#fff;cursor:pointer;font-size:14px;width:32px;height:32px;flex-shrink:0"
        ${idx===0?'disabled':''}${idx===0?' class="disabled-btn"':''}>✕</button>
    </div>`;

  document.getElementById('product-rows').appendChild(div);

  // Add mousedown listener for this row's dropdown
  const dd = div.querySelector(`#pr-dd-${idx}`);
  dd.addEventListener('mousedown', function(e) {
    e.preventDefault();
    const item = e.target.closest('.pd-ajax-item');
    if (item) {
      const pid = parseInt(item.dataset.pid);
      const pname = item.dataset.pname;
      const pprice = parseFloat(item.dataset.pprice);
      selectProductRow(idx, pid, pname, pprice);
    }
  });

  if (idx===0) {
    const btn = div.querySelector('button');
    if (btn) { btn.disabled=true; btn.style.opacity='.3'; btn.style.cursor='not-allowed'; }
  }

  updateMultiTotal();
}

function onRowProductSearch(idx, val) {
  const dd = document.getElementById(`pr-dd-${idx}`);
  // Clear selection when typing
  document.getElementById(`pr-pid-${idx}`).value = '';
  _saleProducts[idx].product_id = '';
  if (!val || val.length < 1) { dd.style.display='none'; return; }
  const q = val.toLowerCase();
  const matches = _p.filter(p => p.name.toLowerCase().includes(q) || p.type.toLowerCase().includes(q));
  if (!matches.length) {
    dd.innerHTML = '<div style="padding:12px;font-size:12px;color:var(--t3);text-align:center">কোনো প্রোডাক্ট পাওয়া যায়নি</div>';
    dd.style.display = 'block';
    return;
  }
  dd.innerHTML = matches.map(p => `
    <div class="pd-ajax-item" data-pid="${p.id}" data-pname="${h(p.name)}" data-pprice="${p.price}"
      style="padding:9px 12px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.05);pointer-events:auto"
      onmouseover="this.style.background='rgba(99,102,241,.1)'" onmouseout="this.style.background=''">
      <div style="pointer-events:none;display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--t1)">${h(p.name)}</div>
          <div style="font-size:11px;color:var(--t3)">${p.type}</div>
        </div>
        <div style="font-size:13px;font-weight:700;color:var(--ac3)">৳${p.price}</div>
      </div>
    </div>`).join('');
  dd.style.display = 'block';
}

function selectProductRow(idx, pid, pname, pprice) {
  document.getElementById(`pr-pid-${idx}`).value = pid;
  document.getElementById(`pr-search-${idx}`).value = pname;
  document.getElementById(`pr-price-${idx}`).value = pprice;
  document.getElementById(`pr-dd-${idx}`).style.display = 'none';
  _saleProducts[idx].product_id = pid;
  _saleProducts[idx].name = pname;
  _saleProducts[idx].price = pprice;
  updateMultiTotal();
  // Plugin check from first row
  if (idx === 0) {
    const selProd = _p.find(p => p.id == pid);
    const isPlugin = selProd?.type === 'Plugin';
    const wrap = document.getElementById('s-ac-wrap');
    if (wrap) wrap.style.display = isPlugin ? 'block' : 'none';
    if (isPlugin) autoExpPlugin();
    const sPd = document.getElementById('s-pd');
    if (sPd) sPd.value = pid;
  }
}

// Close product dropdowns when clicking outside
document.addEventListener('click', function(e) {
  document.querySelectorAll('[id^="pr-dd-"]').forEach(dd => {
    const idx = dd.id.replace('pr-dd-','');
    const inp = document.getElementById('pr-search-'+idx);
    if (inp && !dd.contains(e.target) && e.target !== inp) {
      dd.style.display = 'none';
    }
  });
});

function onRowProductChange(idx, sel) {
  const opt = sel.options[sel.selectedIndex];
  _saleProducts[idx].product_id = parseInt(sel.value) || '';
  _saleProducts[idx].name = opt.text;
  _saleProducts[idx].price = parseFloat(opt.dataset.price || 0);
  // Update price input
  const priceInput = document.getElementById(`pr-price-${idx}`);
  if (priceInput) priceInput.value = _saleProducts[idx].price || '';
  updateMultiTotal();
  // Auto set plugin expiry from first product
  if (idx === 0) {
    const selProd = _p.find(p => p.id == sel.value);
    const isPlugin = selProd?.type === 'Plugin';
    const wrap = document.getElementById('s-ac-wrap');
    if (wrap) wrap.style.display = isPlugin ? 'block' : 'none';
    if (isPlugin) autoExpPlugin();
    // Update hidden s-pd for compatibility
    const sPd = document.getElementById('s-pd');
    if (sPd) sPd.value = sel.value;
  }
}

function onRowPriceChange(idx, input) {
  _saleProducts[idx].price = parseFloat(input.value) || 0;
  updateMultiTotal();
}

function onRowQtyChange(idx, input) {
  _saleProducts[idx].qty = Math.max(1, parseInt(input.value) || 1);
  updateMultiTotal();
}

function removeProductRow(idx) {
  if (_saleProducts.length <= 1) return;
  _saleProducts.splice(idx, 1);
  // Re-render all rows
  const rows = document.getElementById('product-rows');
  rows.innerHTML = '';
  const temp = [..._saleProducts];
  _saleProducts = [];
  temp.forEach(p => addProductRow(p));
}

function updateMultiTotal() {
  // DOM থেকে সর্বশেষ value sync করো
  _saleProducts.forEach((p, idx) => {
    const priceEl = document.getElementById(`pr-price-${idx}`);
    const qtyEl = document.getElementById(`pr-qty-${idx}`);
    if (priceEl) p.price = parseFloat(priceEl.value) || 0;
    if (qtyEl) p.qty = Math.max(1, parseInt(qtyEl.value) || 1);
  });
  const total = _saleProducts.reduce((sum, p) => sum + (p.price * (p.qty||1)), 0);
  const el = document.getElementById('multi-total');
  if (el) el.textContent = '$ ' + total.toFixed(2);
  // Sync with price field
  document.getElementById('s-pr').value = total.toFixed(2);
  document.getElementById('s-fp').value = total.toFixed(2);
  document.getElementById('s-dis').value = document.getElementById('s-dis').value || 0;
  calcDisc();
}

async function saveSale(){
  const _saveBtn=document.querySelector('#m-sale .btn.bp');
  if(_saveBtn&&_saveBtn.disabled)return;
  if(_saveBtn){_saveBtn.disabled=true;_saveBtn.innerHTML='⏳ সংরক্ষণ...';}
  const _restoreBtn=()=>{if(_saveBtn){_saveBtn.disabled=false;_saveBtn.innerHTML='💾 Save';}};
  try {
    const id=document.getElementById('s-id').value;
    const _ps=document.getElementById('s-ps').value;
    const _ap=_ps==='paid'?parseFloat(document.getElementById('s-fp').value||document.getElementById('s-pr').value):(_ps==='partial'?parseFloat(document.getElementById('s-ap')?.value||0):0);
    const body={id:id?parseInt(id):null,sale_date:document.getElementById('s-dt').value,activated_at:document.getElementById('s-ac')?.value||document.getElementById('s-sd')?.value||document.getElementById('s-dt').value,expiry_date:document.getElementById('s-ex').value||null,client_id:parseInt(document.getElementById('s-cl').value),product_id:0,price:parseFloat(document.getElementById('s-fp').value||document.getElementById('s-pr').value),original_price:parseFloat(document.getElementById('s-pr').value),discount_amount:parseFloat(document.getElementById('s-dis').value)||0,promo_code_id:_promoId,site_url:document.getElementById('s-su').value.trim(),license_type:document.getElementById('s-li').value,payment_status:_ps,amount_paid:_ap,payment_method:document.getElementById('s-pm')?.value||'Cash',note:document.getElementById('s-no').value};
    // Validate required fields before sending
    if(!body.sale_date){toast('❌ Enter Date','var(--danger)');_restoreBtn();return;}
    if(!body.client_id||isNaN(body.client_id)){toast('❌ Select Client','var(--danger)');_restoreBtn();return;}
    // Multi-product validation
    const validProds = _saleProducts.filter(p => p.product_id && parseInt(p.product_id) > 0 && p.price > 0);
    if(!validProds.length){toast('❌ কমপক্ষে একটি প্রোডাক্ট সিলেক্ট করুন','var(--danger)');_restoreBtn();return;}
    body.products = validProds.map(p => ({...p, product_id: parseInt(p.product_id)}));
    body.product_id = parseInt(validProds[0].product_id);
    if(!body.product_id||isNaN(body.product_id)){toast('❌ প্রোডাক্ট সিলেক্ট করুন','var(--danger)');_restoreBtn();return;}
    if(!body.price||isNaN(body.price)||body.price<=0){toast('❌ Enter Price','var(--danger)');_restoreBtn();return;}
    
    const btn=document.querySelector('#m-sale .mf button.bp');
    if(btn){btn.disabled=true;btn.innerHTML='<span class="spin"></span> Saving...';}
    const r=await api(id?'update_sale':'add_sale','POST',body);
    if(btn){btn.disabled=false;btn.innerHTML='💾 Save';}
    
    if(r.success){
      toast('✅ '+(r.message||'Saved!')+(r.invoice_no?' · '+r.invoice_no:''));
      cm('sale');_c=[];_p=[];_s=[];
      loadSales();loadDashboard();
    } else {
      toast('❌ '+(r.error||'Save failed'),'var(--danger)');
      console.error('saveSale error:', r);
    }
  } catch(e){
    toast('❌ '+e.message,'var(--danger)');
    console.error('saveSale exception:', e);
  } finally {
    _restoreBtn();
  }
}

// CLIENTS
async function loadClients(){
  const q=document.getElementById('cq')?.value||'';
  const filter=document.getElementById('cqf')?.value||'';
  const r=await api('get_clients','GET',null,{q});
  if(!r.success)return;
  let data=r.data;
  // client-side extra filters
  if(filter==='active') data=data.filter(c=>parseInt(c.total_purchases||0)>0);
  if(filter==='portal') data=data.filter(c=>c.portal_active);
  _c=data; _clAll=data; _clPage=1;
  renderClients(1); // renderClients() handles pagination internally
}

// ══════════════════════════════════════════════════════════
// CLIENT CRUD FUNCTIONS
// ══════════════════════════════════════════════════════════
function openClientModal(id=null){
  document.getElementById('cl-id').value=id||'';
  document.getElementById('cl-ttl').textContent=id?'✏️ Edit Client':'👤 New Client';
  ['cl-nm','cl-em','cl-ph','cl-wa','cl-fb','cl-lo','cl-nt'].forEach(i=>{ const el=document.getElementById(i); if(el) el.value=''; });
  if(id){ editClient(id); } else { om('cl'); }
}
async function editClient(id){
  const r=await api('get_client','GET',null,{id});
  if(!r.success&&!r.id)return toast('❌ '+(r.error||'Failed'),'var(--danger)');
  const c=r.data||r;
  document.getElementById('cl-id').value=c.id;
  document.getElementById('cl-ttl').textContent='✏️ Edit Client';
  document.getElementById('cl-nm').value=h(c.name||'');
  document.getElementById('cl-em').value=h(c.email||'');
  document.getElementById('cl-ph').value=h(c.phone||'');
  document.getElementById('cl-wa').value=h(c.whatsapp||'');
  document.getElementById('cl-fb').value=h(c.facebook||'');
  document.getElementById('cl-lo').value=h(c.location||'');
  document.getElementById('cl-nt').value=h(c.note||'');
  om('cl');
}
async function saveClient(){
  const _saveBtn=document.querySelector('#m-cl .btn.bp');
  if(_saveBtn&&_saveBtn.disabled)return;
  if(_saveBtn){_saveBtn.disabled=true;_saveBtn.innerHTML='⏳ সংরক্ষণ...';}
  const _restoreBtn=()=>{if(_saveBtn){_saveBtn.disabled=false;_saveBtn.innerHTML='💾 Save';}};
  try {
    const id=document.getElementById('cl-id').value;
    const name=document.getElementById('cl-nm').value.trim();
    if(!name){toast('❌ Name is required','var(--danger)');_restoreBtn();return;}
    const body={
      id:id?parseInt(id):null,
      name,
      email:document.getElementById('cl-em').value.trim(),
      phone:document.getElementById('cl-ph').value.trim(),
      whatsapp:document.getElementById('cl-wa').value.trim(),
      facebook:document.getElementById('cl-fb').value.trim(),
      location:document.getElementById('cl-lo').value.trim(),
      note:document.getElementById('cl-nt').value.trim(),
    };
    const action=id?'update_client':'add_client';
    const _btn=document.querySelector('#m-cl .btn.bp,#m-cl .btn.bs');
    const _orig=_btn?_btn.innerHTML:'';
    if(_btn){_btn.disabled=true;_btn.innerHTML='⏳...';}
    const r=await api(action,'POST',body);
    if(_btn){_btn.disabled=false;_btn.innerHTML=_orig;}
    if(r.success){ toast('✅ '+(id?'Client updated':'Client added')); cm('cl'); loadClients(); }
    else toast('❌ '+(r.error||'Failed'),'var(--danger)');
  } catch(e){toast('❌ '+e.message,'var(--danger)');
  } finally { _restoreBtn(); }
}
async function delClient(id){
  if(!confirm('এই client মুছে ফেলবেন? সব related sales ও মুছে যাবে!'))return;
  const r=await api('delete_client','POST',{id});
  if(r.success){ toast('🗑️ Client deleted','var(--danger)'); loadClients(); loadDashboard(); }
  else toast('❌ '+(r.error||'Failed'),'var(--danger)');
}
async function togglePortal(id){
  const r=await api('toggle_portal','POST',{id});
  if(r.success){ toast('✅ '+(r.data?.portal_active?'Portal activated':'Portal closed')); loadClients(); }
  else toast('❌ '+(r.error||'Failed'),'var(--danger)');
}
async function viewClientDetail(id){
  const r=await api('get_client','GET',null,{id});
  if(!r.success&&!r.id)return toast('❌ '+(r.error||'Failed'),'var(--danger)');
  const c=r.data||r;
  // Navigate to clients tab and highlight
  nav('clients');
  setTimeout(()=>{
    const row=document.querySelector(`[onclick*="viewClientDetail(${id})"]`);
    if(row){ row.scrollIntoView({behavior:'smooth',block:'center'}); row.style.background='var(--ac)'; setTimeout(()=>row.style.background='',1500); }
  },300);
}

// ══════════════════════════════════════════════════════════
// PAGINATION SYSTEM
// ══════════════════════════════════════════════════════════
function buildPagination(containerId, current, total, onPageFn) {
  const el = document.getElementById(containerId);
  if (!el) return;
  if (total <= 1) { el.innerHTML = ''; el.style.display = 'none'; return; }
  el.style.display = 'block';
  const range = (from, to) => Array.from({length: to - from + 1}, (_, i) => from + i);
  let pages = [];
  if (total <= 7) {
    pages = range(1, total);
  } else if (current <= 4) {
    pages = [...range(1, 5), '…', total];
  } else if (current >= total - 3) {
    pages = [1, '…', ...range(total - 4, total)];
  } else {
    pages = [1, '…', current - 1, current, current + 1, '…', total];
  }
  const btn = (label, page, cls='') => {
    if (label === '…') return `<span class="pgn-dots">…</span>`;
    const isActive = page === current ? 'active' : '';
    const dis = (page < 1 || page > total) ? 'disabled' : '';
    return `<button class="pgn-btn ${cls} ${isActive}" ${dis} onclick="(${onPageFn.toString()})(${page})">${label}</button>`;
  };
  el.innerHTML = `<div class="pgn">
    <span class="pgn-info">Page ${current} / ${total}</span>
    ${btn('‹', current - 1, 'arrow')}
    ${pages.map(p => btn(p === '…' ? '…' : p, p)).join('')}
    ${btn('›', current + 1, 'arrow')}
  </div>`;
}

// ── 1. ACTIVE CLIENTS (Dashboard) ──────────────────────
let _acPage = 1, _acPer = 5, _acAll = [];
function renderActiveClients(page) {
  _acPage = page;
  const total = Math.ceil(_acAll.length / _acPer);
  const slice = _acAll.slice((page - 1) * _acPer, page * _acPer);
  document.getElementById('d-active-clients').innerHTML = slice.length ? slice.map(c => {
    const products = (c.products||'').split('||').filter(Boolean);
    const types    = (c.product_types||'').split('||').filter(Boolean);
    const sites    = (c.sites||'').split('||').filter(Boolean);
    const expiries = (c.expiry_dates||'').split('||').filter(Boolean);
    const saleIds  = (c.sale_ids||'').split('||').filter(Boolean);
    const minDays  = parseInt(c.min_days_left ?? 999);

    // Card urgency styling
    const isUrgent  = minDays <= 7;
    const isWarning = minDays <= 30 && minDays > 7;
    const cardBorder= isUrgent?'rgba(239,68,68,.4)':isWarning?'rgba(245,158,11,.35)':'rgba(255,255,255,.08)';
    const headerBg  = isUrgent?'rgba(239,68,68,.05)':isWarning?'rgba(245,158,11,.05)':'rgba(255,255,255,.02)';
    const avatarBg  = isUrgent?'linear-gradient(135deg,#dc2626,#f87171)':isWarning?'linear-gradient(135deg,#d97706,#fbbf24)':'linear-gradient(135deg,#6366f1,#8b5cf6)';
    const statusTxt = isUrgent?` · <span style="color:#f87171">মেয়াদ শেষ হচ্ছে!</span>`:isWarning?` · <span style="color:#fbbf24">নবায়ন করুন</span>`:'';

    // Contact links
    const contacts = [
      c.whatsapp ? `<a href="https://wa.me/${(c.whatsapp||'').replace(/\D/g,'')}" target="_blank"
        style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:7px;font-size:11px;font-weight:600;text-decoration:none;background:rgba(37,211,102,.1);border:1px solid rgba(37,211,102,.25);color:#4ade80">
        💬 WA</a>` : '',
      c.facebook ? `<a href="${h(c.facebook)}" target="_blank"
        style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:7px;font-size:11px;font-weight:600;text-decoration:none;background:rgba(24,119,242,.1);border:1px solid rgba(24,119,242,.25);color:#60a5fa">
        👤 FB</a>` : '',
      c.email ? `<a href="mailto:${h(c.email)}"
        style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:7px;font-size:11px;font-weight:600;text-decoration:none;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--t2)">
        ✉️ Mail</a>` : '',
    ].filter(Boolean).join('');

    // Product rows
    const productRows = products.map((pr,i) => {
      const exp  = expiries[i]||'';
      const days = exp ? Math.ceil((new Date(exp)-new Date())/86400000) : null;
      const isPlugin  = (types[i]||'').includes('Plugin');
      const isExpired = days !== null && days <= 0;
      const isWarn30  = days !== null && days <= 30 && days > 7;
      const isWarn7   = days !== null && days <= 7 && days > 0;
      const isOk      = days === null || days > 30;

      const expiryBg     = isExpired?'rgba(239,68,68,.1)':isWarn7?'rgba(239,68,68,.08)':isWarn30?'rgba(245,158,11,.08)':'rgba(16,185,129,.06)';
      const expiryBorder = isExpired?'rgba(239,68,68,.35)':isWarn7?'rgba(239,68,68,.3)':isWarn30?'rgba(245,158,11,.25)':'rgba(16,185,129,.2)';
      const expiryColor  = isExpired?'#f87171':isWarn7?'#fca5a5':isWarn30?'#fbbf24':'#6ee7b7';
      const expiryLabel  = days===null?'—':isExpired?'মেয়াদ শেষ':days+' দিন';
      const iconBg       = isPlugin?'rgba(99,102,241,.12)':'rgba(16,185,129,.1)';
      const siteClean    = (sites[i]||'').replace(/^https?:\/\//,'').replace(/\/$/,'').substring(0,30);

      return `<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);border-radius:9px;margin-bottom:6px;transition:background .15s" onmouseover="this.style.background='rgba(99,102,241,.06)'" onmouseout="this.style.background='rgba(255,255,255,.025)'">
        <div style="width:32px;height:32px;border-radius:8px;background:${iconBg};display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0">${isPlugin?'🔌':'🎨'}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;font-weight:700;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${h(pr)}</div>
          ${siteClean?`<div style="font-size:10px;color:#818cf8;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">🌐 ${siteClean}</div>`:''}
        </div>
        <div style="flex-shrink:0;background:${expiryBg};border:1px solid ${expiryBorder};border-radius:20px;padding:4px 11px;text-align:center;min-width:70px">
          <div style="font-size:9px;color:var(--t3);font-weight:600;letter-spacing:.4px;text-transform:uppercase">মেয়াদ</div>
          <div style="font-size:11px;font-weight:800;color:${expiryColor};margin-top:1px;white-space:nowrap">${expiryLabel}</div>
        </div>
        ${(isExpired||isWarn7||isWarn30)&&(c.whatsapp||c.facebook)?`<button onclick="openMsgModal('${h(c.name).replace(/'/g,"\\'")}','${h(pr).replace(/'/g,"\\'")}','${(sites[i]||'').replace(/'/g,"\\'")}','${days}','${(c.whatsapp||'').replace(/\D/g,'')}','${h(c.facebook||'').replace(/'/g,"\\'")}');event.stopPropagation()" title="মেসেজ পাঠান" style="flex-shrink:0;background:rgba(37,211,102,.12);border:1px solid rgba(37,211,102,.3);color:#4ade80;border-radius:7px;padding:5px 9px;font-size:13px;cursor:pointer;transition:all .15s" onmouseover="this.style.background='rgba(37,211,102,.22)'" onmouseout="this.style.background='rgba(37,211,102,.12)'">💬</button>`:''}
        ${saleIds[i]?`<button onclick="viewInvoice(${saleIds[i]});event.stopPropagation()" title="Invoice দেখুন" style="flex-shrink:0;background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.3);color:#a5b4fc;border-radius:7px;padding:5px 9px;font-size:13px;cursor:pointer;transition:all .15s" onmouseover="this.style.background='rgba(99,102,241,.25)'" onmouseout="this.style.background='rgba(99,102,241,.12)'">🧾</button>`:''}
        ${saleIds[i]?`<button onclick="openRenewModal(${saleIds[i]});event.stopPropagation()" title="নবায়ন করুন" style="flex-shrink:0;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--t2);border-radius:7px;padding:5px 8px;font-size:11px;cursor:pointer;transition:all .15s" onmouseover="this.style.background='rgba(99,102,241,.15)';this.style.color='#a5b4fc'" onmouseout="this.style.background='rgba(255,255,255,.05)';this.style.color='var(--t2)'">🔄</button>`:''}
      </div>`;
    }).join('');

    return `<div style="border:1px solid ${cardBorder};background:rgba(12,15,26,.88);border-radius:14px;margin-bottom:12px;overflow:hidden;backdrop-filter:blur(10px);transition:all .2s">
      
      <div style="display:flex;align-items:center;gap:11px;padding:13px 15px;background:${headerBg};border-bottom:1px solid rgba(255,255,255,.05)">
        <div style="width:38px;height:38px;border-radius:10px;background:${avatarBg};display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#fff;flex-shrink:0;box-shadow:0 3px 12px rgba(0,0,0,.3)">${(c.name||'?').charAt(0).toUpperCase()}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13.5px;font-weight:800;color:#a5b4fc;cursor:pointer;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" onclick="viewClientDetail(${c.id})">${h(c.name)}</div>
          <div style="font-size:10px;color:var(--t3);margin-top:2px">🛒 ${c.product_count} টি পণ্য সক্রিয়${statusTxt}</div>
        </div>
        <div style="display:flex;gap:5px;flex-shrink:0">${contacts}</div>
      </div>
      
      <div style="padding:10px 12px 6px">${productRows || `<div style="font-size:11px;color:var(--t3);padding:6px 4px;text-align:center">কোনো পণ্য নেই</div>`}</div>
    </div>`;
  }).join('') : '<div class="empty" style="padding:22px"><div class="empty-ico">👥</div>কোনো সক্রিয় ক্লায়েন্ট নেই</div>';
  buildPagination('d-ac-pg', page, total, p => renderActiveClients(p));
}

// ── 2. PRODUCTS ─────────────────────────────────────────
let _prodPage = 1, _prodPer = 9;
function renderProducts(page) {
  _prodPage = page;
  const total = Math.ceil(_p.length / _prodPer);
  const slice = _p.slice((page - 1) * _prodPer, page * _prodPer);
  document.getElementById('prod-grid').innerHTML = slice.length
    ? slice.map(p => `<div class="pc">
        <div style="position:absolute;top:11px;right:11px">${tType(p.type)}</div>
        <div style="font-size:13px;font-weight:700;margin-bottom:4px;padding-right:70px">${h(p.name)}</div>
        <div style="font-size:19px;font-weight:700;font-family:'JetBrains Mono';color:var(--ac3);margin-bottom:3px">${fmt(p.price)}</div>
        <div style="font-size:11px;color:var(--t3);margin-bottom:10px">📊 ${p.sales_count} times · ${fmt(p.total_revenue)} · v${h(p.version)}</div>
        <div style="font-size:11px;color:var(--t3);margin-bottom:10px">${h(p.description||'')}</div>
        <div style="display:flex;gap:5px">
          ${canEdit()?`<button class="btn bg bsm" onclick="editProduct(${p.id})">✏️</button>`:''}
          ${isSup()?`<button class="btn bg bsm" onclick="delProduct(${p.id})" style="color:var(--danger)">🗑</button>`:''}
        </div>
      </div>`).join('')
    : '<div class="empty" style="grid-column:span 3"><div class="empty-ico">📦</div>No products</div>';
  buildPagination('prod-pg', page, total, p => renderProducts(p));
}

// ── 3. CLIENTS TABLE ─────────────────────────────────────
let _clPage = 1, _clPer = 15, _clAll = [];
function renderClients(page) {
  _clPage = page;
  const total = Math.ceil(_clAll.length / _clPer);
  const slice = _clAll.slice((page - 1) * _clPer, page * _clPer);
  document.getElementById('clients-tb').innerHTML = slice.map(c => {
    const fbLink = c.facebook
      ? `<a href="${(c.facebook||'').replace(/^(?!https?:)/i,'https://')}" target="_blank" rel="noopener noreferrer" style="color:#1877F2;font-size:11px;text-decoration:none">🔵 ${h(c.facebook.replace(/^https?:\/\/(?:www\.)?facebook\.com\//,'').replace(/\/$/,'').substring(0,18)||'Facebook')}</a>` : '—';
    return `<tr>
      <td class="bold" style="cursor:pointer;color:var(--ac)" onclick="viewClientDetail(${c.id})">${h(c.name)}</td>
      <td>
        ${c.phone?`<div style="font-size:12px">📞 ${c.phone}</div>`:''}
        ${c.whatsapp?`<div style="font-size:11px;color:#25D366">💬 ${c.whatsapp}</div>`:''}
        ${!c.phone&&!c.whatsapp?'—':''}
      </td>
      <td>${fbLink}</td>
      <td style="text-align:center">${c.active_sites>0?`<span style="color:var(--ac3);font-weight:700">${c.active_sites}</span>`:'<span style="color:var(--t3)">0</span>'}</td>
      <td style="text-align:center">${c.total_purchases||0}</td>
      <td class="mono">${fmt(c.total_spent||0)}</td>
      <td style="text-align:center">${c.portal_active
        ?`<span class="tag t-on" style="cursor:pointer" onclick="togglePortal(${c.id})" title="Click to close">✅ Active</span>`
        :`<span class="tag t-off" style="cursor:pointer" onclick="togglePortal(${c.id})" title="Click to activate">❌ Off</span>`}</td>
      <td><div style="display:flex;gap:3px">
        ${canEdit()?`<button class="btn bg bxs" onclick="viewClientDetail(${c.id})" title="View">👁</button>
          <button class="btn bg bxs" onclick="togglePortal(${c.id})">${c.portal_active?'Close':'Active'}</button>
          <button class="btn bg bxs" onclick="editClient(${c.id})">✏️</button>`:''}
        ${isSup()?`<button class="btn bg bxs" onclick="delClient(${c.id})" style="color:var(--danger)">🗑</button>`:''}
      </div></td>
    </tr>`;
  }).join('');
  buildPagination('clients-pg', page, total, p => renderClients(p));
}

// PRODUCTS
async function loadProducts(){const r=await api('get_products');if(!r.success)return;_p=r.data;_prodPage=1;renderProducts(1);}
function openProductModal(){['id','nm','pr','vr','dc'].forEach(k=>{const el=document.getElementById('pd-'+k);if(el)el.value='';});populateSelect('pd-tp',_CFG.productType,'Plugin');document.getElementById('pd-ttl').textContent='📦 New Product';om('pd');}
function editProduct(id){const p=_p.find(x=>x.id==id);if(!p)return;document.getElementById('pd-id').value=p.id;document.getElementById('pd-nm').value=p.name;document.getElementById('pd-pr').value=p.price;document.getElementById('pd-vr').value=p.version||'';document.getElementById('pd-dc').value=p.description||'';document.getElementById('pd-tp').value=p.type;document.getElementById('pd-ttl').textContent='✏️ Edit Product';om('pd');}

// ══════════════════════════════════════════════════════════
// SALE MODAL — CLIENT AJAX SEARCH
