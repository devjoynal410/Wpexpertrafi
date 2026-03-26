// ═══════════════════════════════════════════
// Sales module: list, create, edit, invoice, payments
// WP Sales Pro v5 — Module: sales.js
// ═══════════════════════════════════════════

// ══════════════════════════════════════════════════════════
// PAGINATION — SALES
// ══════════════════════════════════════════════════════════
let _salesPage=1, _salesPer=20, _salesAll=[];
function renderSales(page){
  _salesPage=page;
  const total=Math.ceil(_salesAll.length/_salesPer);
  const slice=_salesAll.slice((page-1)*_salesPer, page*_salesPer);
  const tb=document.getElementById('sales-tb');
  if(!slice.length){tb.innerHTML='<tr><td colspan="9"><div class="empty"><div class="empty-ico">🔍</div>No results found</div></td></tr>';buildPagination('sales-pg',1,0,()=>{});return;}
  tb.innerHTML=slice.map(s=>`<tr>
    <td class="mono" style="cursor:pointer;color:var(--ac)" onclick="viewInvoice(${s.id})">${s.invoice_no||'—'}</td>
    <td>${fmtD(s.sale_date)}</td>
    <td class="bold" style="cursor:pointer;color:var(--ac)" onclick="viewClientDetail(${s.client_id})">${h(s.client_name||'—')}</td>
    <td>${h(s.product_name||'—')} ${tType(s.product_type)}</td>
    <td><a href="${(s.site_url||'').replace(/^(?!https?:)/i,'https://')}" target="_blank" rel="noopener noreferrer" style="color:var(--ac);font-size:11px;text-decoration:none">${h((s.site_url||'').replace(/^https?:\/\//,'').substring(0,30))}</a></td>
    <td>${exTag(s.expiry_date,s.days_left)}</td>
    <td class="mono">${fmt(s.price)}${s.discount_amount>0?`<br><span style="font-size:10px;color:var(--ac3)">-${fmt(s.discount_amount)}</span>`:''}</td>
    <td>${tPay(s.payment_status)}</td>
    <td><div class="act-btns">
      <button class="btn bg bxs" onclick="viewInvoice(${s.id});event.stopPropagation()" title="Invoice">🧾</button>
      <button class="btn bg bxs" onclick="viewSaleDetail(${s.id});event.stopPropagation()" title="Details">👁</button>
      ${canEdit()?`<button class="btn bg bxs" onclick="editSale(${s.id});event.stopPropagation()" title="Edit">✏️</button><button class="btn bg bxs" onclick="openPayModal(${s.id});event.stopPropagation()" title="Payment">💳</button>`:''}
      ${isSup()?`<button class="btn bg bxs" onclick="delSale(${s.id});event.stopPropagation()" style="color:var(--danger)" title="Delete">🗑</button>`:''}
    </div></td>
  </tr>`).join('');
  buildPagination('sales-pg',page,total,p=>renderSales(p));
}

// ══════════════════════════════════════════════════════════
// PAGINATION — TICKETS
// ══════════════════════════════════════════════════════════
let _tkPage=1, _tkPer=15, _tkAll=[];
function renderTickets(page){
  _tkPage=page;
  const total=Math.ceil(_tkAll.length/_tkPer);
  const slice=_tkAll.slice((page-1)*_tkPer, page*_tkPer);
  document.getElementById('tickets-list').innerHTML=slice.length?slice.map(t=>`<div class="tick-card">
    <div style="display:flex;align-items:center;gap:7px;margin-bottom:4px;cursor:pointer" onclick="viewTicket(${t.id})">
      <span style="font-size:11px;font-weight:700;font-family:'JetBrains Mono'">${t.ticket_no}</span>${tPri(t.priority)}${tSt(t.status)}
      <span style="margin-left:auto;display:flex;align-items:center;gap:6px">
        <span style="font-size:10px;color:var(--t3)">${fmtD(t.created_at?.split(' ')[0])}</span>
        ${canEdit()?`<button class="btn bg bxs" onclick="event.stopPropagation();delTicket(${t.id})" title="Delete" style="padding:2px 7px;font-size:11px">🗑️</button>`:''}
      </span>
    </div>
    <div style="font-size:13px;font-weight:600;margin-bottom:2px;cursor:pointer" onclick="viewTicket(${t.id})">${h(t.subject||'—')}</div>
    <div style="font-size:11px;color:var(--t3)">👤 ${h(t.client_name||'—')} · 💬 ${t.msg_count||0}</div>
  </div>`).join(''):'<div class="empty" style="padding:20px"><div class="empty-ico">🎫</div>No tickets</div>';
  buildPagination('tickets-pg',page,total,p=>renderTickets(p));
}

// ══════════════════════════════════════════════════════════
// PAGINATION — TASKS
// ══════════════════════════════════════════════════════════
let _tpPage=1, _tpPer=10, _tpAll=[];
let _tdPage=1, _tdPer=10, _tdAll=[];
function renderTasksPend(page){
  _tpPage=page;
  const total=Math.ceil(_tpAll.length/_tpPer);
  const slice=_tpAll.slice((page-1)*_tpPer, page*_tpPer);
  document.getElementById('tasks-pend').innerHTML=slice.map(taskCard).join('')||'<div class="empty" style="padding:14px"><div class="empty-ico">✅</div>All tasks completed!</div>';
  buildPagination('tasks-pend-pg',page,total,p=>renderTasksPend(p));
}
function renderTasksDone(page){
  _tdPage=page;
  const total=Math.ceil(_tdAll.length/_tdPer);
  const slice=_tdAll.slice((page-1)*_tdPer, page*_tdPer);
  document.getElementById('tasks-done').innerHTML=slice.map(taskCard).join('')||'<div class="empty" style="padding:14px"><div class="empty-ico">📋</div>No completed tasks</div>';
  buildPagination('tasks-done-pg',page,total,p=>renderTasksDone(p));
}

// ══════════════════════════════════════════════════════════
// PAGINATION — REMINDERS
// ══════════════════════════════════════════════════════════
let _remPage=1, _remPer=15, _remAll=[];
function renderReminders(page){
  _remPage=page;
  const total=Math.ceil(_remAll.length/_remPer);
  const slice=_remAll.slice((page-1)*_remPer, page*_remPer);
  document.getElementById('rem-tb').innerHTML=slice.map(rm=>`<tr>
    <td class="bold">${rm.client_name||'—'}</td>
    <td><span class="tag t-${rm.type}">${rm.type}</span></td>
    <td><span class="tag t-${rm.channel}">${rm.channel}</span></td>
    <td style="font-size:11px">${fmtD(rm.scheduled_at?.split(' ')[0])}</td>
    <td><span class="tag t-${rm.status}">${rm.status}</span></td>
    <td>${rm.status==='pending'?`<button class="btn bwa bxs" onclick="markSent(${rm.id})">✅ Sent</button>`:''}</td>
  </tr>`).join('')||'<tr><td colspan="6"><div class="empty">No reminders</div></td></tr>';
  buildPagination('rem-pg',page,total,p=>renderReminders(p));
}

// ══════════════════════════════════════════════════════════
// PAGINATION — SMS LOG
// ══════════════════════════════════════════════════════════
let _smsPage=1, _smsPer=15, _smsAll=[];
function renderSMSLog(page){
  _smsPage=page;
  const total=Math.ceil(_smsAll.length/_smsPer);
  const slice=_smsAll.slice((page-1)*_smsPer, page*_smsPer);
  document.getElementById('sms-log-tb').innerHTML=slice.length?slice.map(s=>`<tr>
    <td class="bold">${h(s.client_name||'—')}</td>
    <td class="mono" style="font-size:11px">${s.phone||'—'}</td>
    <td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${h(s.message||'')}">${h((s.message||'').substring(0,50))}${(s.message||'').length>50?'…':''}</td>
    <td><span class="tag t-${s.status||'pending'}">${s.status||'pending'}</span></td>
    <td style="font-size:11px">${fmtD(s.created_at?.split(' ')[0])}</td>
  </tr>`).join(''):'<tr><td colspan="5" style="text-align:center;color:var(--t3);padding:20px">📭 No SMS logs</td></tr>';
  buildPagination('sms-pg',page,total,p=>renderSMSLog(p));
}

// ══════════════════════════════════════════════════════════
// PAGINATION — AUDIT LOG
// ══════════════════════════════════════════════════════════
let _auditPage=1, _auditPer=20, _auditAll=[];
function renderAudit(page){
  _auditPage=page;
  const total=Math.ceil(_auditAll.length/_auditPer);
  const slice=_auditAll.slice((page-1)*_auditPer, page*_auditPer);
  const icons={'CREATE':'🟢','UPDATE':'🔵','DELETE':'🔴','LOGIN':'🟣','LOGOUT':'⚫','CHANGE':'🟡','CSRF':'🔴','UNAUTHORIZED':'🔴','TOGGLE':'🟡'};
  const gi=a=>{for(const[k,v] of Object.entries(icons))if(a.startsWith(k))return v;return'⚪';};
  document.getElementById('audit-body').innerHTML=slice.map(l=>`<div style="display:flex;align-items:flex-start;gap:10px;padding:9px 13px;border-bottom:1px solid rgba(26,39,68,.4)">
    <span style="font-size:14px">${gi(l.action)}</span>
    <div style="flex:1">
      <div style="font-size:12px;font-weight:600">${h(l.action)} <span style="font-size:10px;color:var(--t3)">${h(l.table_name||'')} ${l.record_id?'#'+l.record_id:''}</span></div>
      <div style="font-size:10px;color:var(--t3)">@${h(l.username||'sys')} · ${h(l.ip_address||'—')} · ${fmtD(l.created_at?.split(' ')[0])}</div>
    </div>
  </div>`).join('')||'<div class="empty">No logs</div>';
  buildPagination('audit-pg',page,total,p=>renderAudit(p));
}

// ══════════════════════════════════════════════════════════
// PAGINATION — EXPIRY TABS (soon/plugin/exp/stale)
// ══════════════════════════════════════════════════════════
let _notifyPer=12;
let _notify_data={soon:[],plugin:[],exp:[],stale:[]};
let _notify_page={soon:1,plugin:1,exp:1,stale:1};
function renderNotifyTab(tab,page){
  _notify_page[tab]=page;
  const all=_notify_data[tab]||[];
  const total=Math.ceil(all.length/_notifyPer);
  const slice=all.slice((page-1)*_notifyPer,page*_notifyPer);
  const idMap={soon:'n-soon',plugin:'n-plugin',exp:'n-exp',stale:'n-stale'};
  const pgMap={soon:'pg-soon',plugin:'pg-plugin',exp:'pg-exp',stale:'pg-stale'};
  const emptyMsg={soon:'No upcoming expiries',plugin:'No upcoming plugin notices',exp:'No expired items',stale:'No old records'};
  const typeMap={soon:'warn',plugin:'plugin',exp:'warn',stale:'warn'};
  const el=document.getElementById(idMap[tab]);
  if(!el)return;
  if(tab==='stale'&&slice.length){
    el.innerHTML=`<div style="padding:10px 14px;background:rgba(100,100,100,.1);border-radius:8px;margin-bottom:10px;font-size:12px;color:var(--t3)">⚠️ These sales have not been renewed for 90+ days after expiry.</div>`+slice.map(s=>ecCard(s,typeMap[tab])).join('');
  } else {
    el.innerHTML=slice.length?slice.map(s=>ecCard(s,typeMap[tab])).join(''):`<div class="empty"><div class="empty-ico">✅</div>${emptyMsg[tab]}</div>`;
  }
  buildPagination(pgMap[tab],page,total,p=>renderNotifyTab(tab,p));
}

async function loadSales(){
  const r=await api('get_sales','GET',null,{
    q:document.getElementById('sq')?.value||'',
    type:document.getElementById('sft')?.value||'',
    status:document.getElementById('sfs')?.value||'',
    month:document.getElementById('sfm')?.value||'',
    year:document.getElementById('sfy')?.value||'',
    renewal:document.getElementById('sfr')?.value||'',
    expiry_days:document.getElementById('sfex')?.value??''
  });
  if(!r.success)return; _s=r.data; _salesAll=r.data; _salesPage=1;
  const tot=_s.reduce((s,x)=>s+parseFloat(x.price||0),0);
  const paid=_s.filter(x=>x.payment_status==='paid').reduce((s,x)=>s+parseFloat(x.price||0),0);
  const disc=_s.reduce((s,x)=>s+parseFloat(x.discount_amount||0),0);
  if(_s.length) document.getElementById('sales-sum').innerHTML=`Total <b style="color:var(--t1)">${_s.length}</b> items &nbsp;|&nbsp; <b style="color:var(--ac)">${fmt(tot)}</b> &nbsp;|&nbsp; Paid: <b style="color:var(--ac3)">${fmt(paid)}</b> &nbsp;|&nbsp; Total Discount: <b style="color:var(--warn)">${fmt(disc)}</b>`;
  else document.getElementById('sales-sum').textContent='';
  renderSales(1);
}
function fillMonths(){const sel=document.getElementById('sfm');if(sel.options.length>1)return;const n=new Date();for(let i=0;i<18;i++){const d=new Date(n.getFullYear(),n.getMonth()-i,1);const v=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;sel.innerHTML+=`<option value="${v}">${MNF[d.getMonth()]} ${d.getFullYear()}</option>`;}}
async function editSale(id){const r=await api('get_sale','GET',null,{id});if(r.id)openSaleModal(r);}
async function delSale(id){
  if(!confirm('এই sale মুছে ফেলবেন? এটা undo করা যাবে না।'))return;
  const r=await api('delete_sale','POST',{id});
  if(r.success){toast('🗑️ '+r.message,'var(--danger)');loadSales();loadDashboard();}
  else toast('❌ '+r.error,'var(--danger)');
}
async function viewSaleDetail(id){
  let s=_s.find(x=>x.id==id)||(_notify.expiring_soon||[]).find(x=>x.id==id)||(_notify.already_expired||[]).find(x=>x.id==id)||(_notify.plugin_7day||[]).find(x=>x.id==id);
  if(!s){const r2=await api('get_sale','GET',null,{id});if(r2.id)s=r2;else return;}
  document.getElementById('dt-ttl').textContent='🔍 Sale #'+s.id+' — '+(s.invoice_no||s.invoice_number||'');

  // Load payments dynamically
  const pr = await api('get_payments','GET',null,{sale_id:id});
  const payments = pr.data || [];
  const payHtml = payments.length
    ? payments.map(p=>`<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--bd)">
        <span>${fmtD(p.paid_at||p.created_at)} · ${p.method||'bKash Personal'}</span>
        <b style="color:var(--ac3)">${fmt(p.amount)}</b>
      </div>`).join('')
    : '<p style="color:var(--t2);font-size:12px">কোনো payment নেই</p>';

  // Payment status color map
  const payColors = {paid:'var(--ac3)',partial:'var(--warn)',pending:'var(--danger)'};
  const payBg     = {paid:'rgba(16,185,129,.12)',partial:'rgba(245,158,11,.12)',pending:'rgba(239,68,68,.12)'};
  const payBorder = {paid:'rgba(16,185,129,.3)',partial:'rgba(245,158,11,.3)',pending:'rgba(239,68,68,.3)'};

  document.getElementById('dt-body').innerHTML=`<div class="fg2" style="gap:8px">
    ${di('Date',fmtD(s.sale_date))} ${di('Client',s.client_name||'—')}
    ${di('Products',s.product_name)} ${diH('Type',tType(s.product_type))}
    ${diH('Site URL',s.site_url?`<a href="${(s.site_url||'#').replace(/^(?!https?:|#)/i,'https://')}" target="_blank" rel="noopener noreferrer" rel="noopener noreferrer" style="color:var(--ac)">${h(s.site_url)}</a>`:'—','span 2')}
    ${di('Base Price',fmt(s.original_price||s.price))} ${di('Discount',fmt(s.discount_amount||0))}
    <div class="fg" style="gap:4px">
      <label style="font-size:9.5px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.8px">Final Price</label>
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:9px;padding:9px 12px;font-size:15px;font-weight:700;color:var(--ac3);font-family:'JetBrains Mono'">${fmt(s.price)}</div>
    </div>
    <div class="fg" style="gap:4px" id="dt-pay-fg">
      <label style="font-size:9.5px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.8px">Payment Status</label>
      <select id="dt-pay-sel" class="fs2" onchange="quickUpdatePayStatus(${s.id},this.value)" style="background:${payBg[s.payment_status]||'var(--s2)'};border:1px solid ${payBorder[s.payment_status]||'var(--bd)'};color:${payColors[s.payment_status]||'var(--t1)'};font-weight:700;border-radius:9px;padding:9px 12px;font-size:13px;cursor:pointer">
        <option value="pending" ${s.payment_status==='pending'?'selected':''}>⏳ Pending</option>
        <option value="partial" ${s.payment_status==='partial'?'selected':''}>🔶 Partial</option>
        <option value="paid"    ${s.payment_status==='paid'?'selected':''}>✅ Paid</option>
      </select>
    </div>
    ${diH('Expiry',tRen(s.renewal_status))} ${di('Expired',fmtD(s.expiry_date))}
    ${di('License',s.license_type||'—')} ${di('Promo',s.promo_code||'—')}
    ${s.note?di('Note',s.note,'span 2'):''}
  </div>
  ${payments.length?`<div style="margin-top:14px;border-top:1px solid var(--bd);padding-top:12px">
    <div style="font-size:10px;font-weight:800;color:var(--t3);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px">Payment History</div>
    ${payHtml}
  </div>`:''}
  <div style="margin-top:14px;display:flex;gap:7px;flex-wrap:wrap">
    <button class="btn bp bsm" onclick="openInvoiceFromDetail(${s.id})">🧾 Invoice</button>
    ${canEdit()?`<button class="btn bg bsm" onclick="openPayFromDetail(${s.id})">💳 Add Payment</button>`:''}
    ${canEdit()?`<button class="btn bw bsm" onclick="cm('dt');editSale(${s.id})">✏️ Edit</button>`:''}
  </div>`;
  om('dt');
}

// ── Quick Payment Status Update from Detail Modal ──
async function quickUpdatePayStatus(saleId, newStatus) {
  const sel = document.getElementById('dt-pay-sel');
  if (sel) { sel.disabled = true; }
  // Need full sale data to satisfy updateSale validation
  const s = await api('get_sale','GET',null,{id:saleId});
  if (!s || !s.id) {
    toast('❌ Sale লোড হয়নি','var(--danger)');
    if (sel) sel.disabled = false;
    return;
  }
  const r = await api('update_sale','POST',{
    id:        saleId,
    sale_date: s.sale_date,
    client_id: s.client_id,
    product_id:s.product_id,
    price:     s.price,
    original_price: s.original_price || s.price,
    discount_amount: s.discount_amount || 0,
    site_url:  s.site_url || '',
    license_type: s.license_type || 'Single Site',
    payment_status: newStatus,
    renewal_status: s.renewal_status || 'active',
    expiry_date: s.expiry_date || '',
    activated_at: s.activated_at || '',
    note: s.note || '',
  });
  if (sel) sel.disabled = false;
  if (r.success || r.message) {
    // Update select color
    const payColors = {paid:'var(--ac3)',partial:'var(--warn)',pending:'var(--danger)'};
    const payBg     = {paid:'rgba(16,185,129,.12)',partial:'rgba(245,158,11,.12)',pending:'rgba(239,68,68,.12)'};
    const payBorder = {paid:'rgba(16,185,129,.3)',partial:'rgba(245,158,11,.3)',pending:'rgba(239,68,68,.3)'};
    if (sel) {
      sel.style.background = payBg[newStatus]  || 'var(--s2)';
      sel.style.borderColor= payBorder[newStatus] || 'var(--bd)';
      sel.style.color      = payColors[newStatus] || 'var(--t1)';
    }
    toast('✅ Payment status → ' + newStatus.toUpperCase());
    loadSales(); loadDashboard();
    if (document.getElementById('page-due')?.style.display !== 'none') loadDueClients();
  } else {
    toast('❌ ' + (r.error||'Update হয়নি'),'var(--danger)');
  }
}

// ── Invoice from Detail Modal (close dt first, then open inv) ──
function openInvoiceFromDetail(id) {
  cm('dt');
  setTimeout(() => viewInvoice(id), 120);
}

// ── Payment Modal from Detail Modal ──
function openPayFromDetail(id) {
  cm('dt');
  setTimeout(() => openPayModal(id), 120);
}


let _currentInvoiceData = null;

function buildInvoiceHTML(r) {
  const s = r, st = r.settings || {};
  const pays = r.payments || [];
  const totalPaid = r.total_paid || 0;
  const remaining = r.remaining || 0;
  const sym = st.currency_symbol || '৳';
  const company = h(st.company_name || st.site_title || 'Wp Theme Bazar - Joynal Abdin');
  const fmtM = v => sym + parseFloat(v||0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

  // ── Theme colors ────────────────────────────────────
  const themes = {
    indigo: { h1:'#0f172a', h2:'#1e3a5f', accent:'#6366f1', stripe:'linear-gradient(90deg,#0f172a 0%,#4f46e5 40%,#6366f1 70%,#818cf8 100%)' },
    emerald:{ h1:'#052e16', h2:'#14532d', accent:'#10b981', stripe:'linear-gradient(90deg,#052e16 0%,#065f46 40%,#059669 70%,#34d399 100%)' },
    rose:   { h1:'#4c0519', h2:'#881337', accent:'#f43f5e', stripe:'linear-gradient(90deg,#4c0519 0%,#9f1239 40%,#e11d48 70%,#fb7185 100%)' },
    amber:  { h1:'#451a03', h2:'#78350f', accent:'#f59e0b', stripe:'linear-gradient(90deg,#451a03 0%,#92400e 40%,#d97706 70%,#fbbf24 100%)' },
    slate:  { h1:'#0f172a', h2:'#1e293b', accent:'#64748b', stripe:'linear-gradient(90deg,#0f172a 0%,#1e293b 40%,#334155 70%,#64748b 100%)' },
  };
  const theme = themes[st.invoice_theme] || themes.indigo;
  const logo = st.company_logo || '';

  const isPaid = s.payment_status === 'paid';
  const isPartial = s.payment_status === 'partial';
  const statusBg = isPaid ? 'linear-gradient(135deg,#059669,#10b981)' : isPartial ? 'linear-gradient(135deg,#d97706,#f59e0b)' : 'linear-gradient(135deg,#dc2626,#ef4444)';
  const statusLabel = isPaid ? '✓ PAID' : isPartial ? '◑ PARTIAL' : '✕ UNPAID';

  const payRows = pays.map((p,i) => `
    <tr style="background:${i%2===0?'#f8fafc':'#ffffff'}">
      <td style="padding:10px 16px;font-size:12px;color:#374151;border-bottom:1px solid #f1f5f9">${h(fmtD(p.paid_at?.split(' ')[0]))}</td>
      <td style="padding:10px 16px;font-size:12px;color:#374151;border-bottom:1px solid #f1f5f9">
        <span style="background:rgba(99,102,241,.15);color:#a5b4fc;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600">${h(p.method||'—')}</span>
      </td>
      <td style="padding:10px 16px;font-size:11px;color:#6b7280;border-bottom:1px solid #f1f5f9;font-family:'Courier New',monospace">${h(p.trx_id||'—')}</td>
      <td style="padding:10px 16px;font-size:13px;color:#059669;font-weight:700;text-align:right;border-bottom:1px solid #f1f5f9">${fmtM(p.amount)}</td>
    </tr>`).join('');

  return `
<div id="invoice-sheet" style="background:#ffffff;width:794px;min-height:1123px;margin:0 auto;font-family:'Plus Jakarta Sans','Segoe UI',Arial,sans-serif;color:#1e293b;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.15)">

  
  <div style="height:5px;background:${theme.stripe}"></div>

  
  <div style="padding:36px 48px 32px;background:${theme.h1};position:relative;overflow:hidden">
    
    <div style="position:absolute;top:-60px;right:-60px;width:260px;height:260px;border-radius:50%;background:rgba(255,255,255,.05)"></div>
    <div style="position:absolute;bottom:-80px;right:140px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.03)"></div>
    <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:repeating-linear-gradient(45deg,transparent,transparent 40px,rgba(255,255,255,.01) 40px,rgba(255,255,255,.01) 41px)"></div>

    <div style="display:flex;justify-content:space-between;align-items:flex-start;position:relative;z-index:1">
      
      <div>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          ${logo
            ? `<img src="${logo}" style="width:52px;height:52px;border-radius:12px;object-fit:contain;background:#fff;padding:4px;flex-shrink:0" alt="logo">`
            : `<div style="width:44px;height:44px;background:${theme.accent};border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;opacity:.9">🛍</div>`
          }
          <div>
            <div style="font-size:20px;font-weight:800;color:#ffffff;letter-spacing:-0.5px">${company}</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:1px;letter-spacing:.5px">WP THEME BAZAR</div>
          </div>
        </div>
        <div style="margin-top:8px;display:flex;flex-direction:column;gap:4px">
          <div style="font-size:11px;color:#94a3b8;display:flex;align-items:center;gap:5px">
            <span style="opacity:.6">📍</span>
            ${st.company_address ? h(st.company_address) : 'Nonni, Nalithabari, Sherpur'}
          </div>
          <div style="font-size:11px;color:#4ade80;display:flex;align-items:center;gap:5px">
            <span>💬</span>
            <span>${st.company_phone ? h(st.company_phone) : '+8801919052411'}</span>
            <span style="color:#64748b;font-size:10px">( 8 AM – 11 PM )</span>
          </div>
          ${st.company_email ? `<div style="font-size:11px;color:#94a3b8;display:flex;align-items:center;gap:5px"><span style="opacity:.6">✉</span> ${h(st.company_email)}</div>` : ''}
        </div>
      </div>

      
      <div style="text-align:right">
        <div style="font-size:42px;font-weight:900;color:rgba(255,255,255,.06);letter-spacing:4px;line-height:1;margin-bottom:4px">INVOICE</div>
        <div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.5px">${h(s.invoice_no||'—')}</div>
        <div style="margin-top:10px;display:inline-flex;flex-direction:column;align-items:flex-end;gap:4px">
          <div style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:8px 14px">
            <div style="font-size:9px;font-weight:700;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px">Issue Date</div>
            <div style="font-size:13px;font-weight:700;color:#e2e8f0">${h(fmtD(s.sale_date))}</div>
          </div>
          ${s.expiry_date ? `<div style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:8px 14px">
            <div style="font-size:9px;font-weight:700;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px">Expires</div>
            <div style="font-size:13px;font-weight:700;color:#fbbf24">${h(fmtD(s.expiry_date))}</div>
          </div>` : ''}
        </div>
        
        <div style="margin-top:10px">
          <span style="background:${statusBg};color:#fff;font-size:11px;font-weight:800;padding:6px 18px;border-radius:30px;letter-spacing:1.5px;display:inline-block">${statusLabel}</span>
        </div>
      </div>
    </div>
  </div>

  
  <div style="display:grid;grid-template-columns:1fr 1fr;background:#f8fafc;border-bottom:2px solid #e2e8f0">
    
    <div style="padding:26px 48px;border-right:1px solid #e2e8f0">
      <div style="font-size:9px;font-weight:800;color:#94a3b8;letter-spacing:2.5px;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:6px">
        <span style="width:20px;height:1px;background:#cbd5e1;display:inline-block"></span>BILL TO<span style="width:20px;height:1px;background:#cbd5e1;display:inline-block"></span>
      </div>
      <div style="font-size:19px;font-weight:800;color:#0f172a;margin-bottom:10px">${h(s.client_name||'—')}</div>
      ${s.client_email ? `<div style="font-size:12px;color:#475569;margin-top:5px;display:flex;align-items:center;gap:7px"><span style="width:28px;height:28px;background:#eff6ff;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:13px">✉</span>${h(s.client_email)}</div>` : ''}
      ${s.client_phone ? `<div style="font-size:12px;color:#475569;margin-top:5px;display:flex;align-items:center;gap:7px"><span style="width:28px;height:28px;background:#f0fdf4;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:13px">📞</span>${h(s.client_phone)}</div>` : ''}
      ${s.client_whatsapp ? `<div style="font-size:12px;color:#475569;margin-top:5px;display:flex;align-items:center;gap:7px"><span style="width:28px;height:28px;background:#f0fdf4;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:13px">💬</span>${h(s.client_whatsapp)}</div>` : ''}
    </div>
    
    <div style="padding:26px 48px">
      <div style="font-size:9px;font-weight:800;color:#94a3b8;letter-spacing:2.5px;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:6px">
        <span style="width:20px;height:1px;background:#cbd5e1;display:inline-block"></span>SERVICE DETAILS<span style="width:20px;height:1px;background:#cbd5e1;display:inline-block"></span>
      </div>
      <div style="display:grid;gap:7px">
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:10px;color:#94a3b8;width:72px;flex-shrink:0;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Product</span>
          <span style="font-size:13px;font-weight:700;color:#0f172a">${h(s.product_name||'—')}</span>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:10px;color:#94a3b8;width:72px;flex-shrink:0;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Type</span>
          <span style="background:${s.product_type==='Plugin'?'#dbeafe':'#dcfce7'};color:${s.product_type==='Plugin'?'#1d4ed8':'#15803d'};font-size:11px;font-weight:700;padding:3px 11px;border-radius:5px">${h(s.product_type||'—')}</span>
        </div>
        ${s.site_url ? `<div style="display:flex;align-items:flex-start;gap:10px">
          <span style="font-size:10px;color:#94a3b8;width:72px;flex-shrink:0;font-weight:600;text-transform:uppercase;letter-spacing:.5px;padding-top:1px">Website</span>
          <span style="font-size:12px;font-weight:600;color:#1d4ed8;word-break:break-all">${h(s.site_url)}</span>
        </div>` : ''}
        ${s.license_type ? `<div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:10px;color:#94a3b8;width:72px;flex-shrink:0;font-weight:600;text-transform:uppercase;letter-spacing:.5px">License</span>
          <span style="font-size:12px;font-weight:600;color:#374151">${h(s.license_type)}</span>
        </div>` : ''}
      </div>
    </div>
  </div>

  
  <div style="padding:32px 48px 0">
    <div style="font-size:9px;font-weight:800;color:#94a3b8;letter-spacing:2.5px;text-transform:uppercase;margin-bottom:14px">INVOICE ITEMS</div>
    <table style="width:100%;border-collapse:collapse;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">
      <thead>
        <tr style="background:linear-gradient(135deg,#0f172a,#1e3a5f)">
          <th style="padding:12px 18px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase">#</th>
          <th style="padding:12px 18px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase">Description</th>
          <th style="padding:12px 18px;text-align:center;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase">Category</th>
          <th style="padding:12px 18px;text-align:right;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase">Unit Price</th>
          ${s.discount_amount > 0 ? `<th style="padding:12px 18px;text-align:right;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase">Discount</th>` : ''}
          <th style="padding:12px 18px;text-align:right;font-size:10px;font-weight:700;color:#60a5fa;letter-spacing:1.5px;text-transform:uppercase">Amount</th>
        </tr>
      </thead>
      <tbody>
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:16px 18px;font-size:13px;font-weight:700;color:#94a3b8">01</td>
          <td style="padding:16px 18px">
            <div style="font-weight:700;font-size:14px;color:#0f172a">${h(s.product_name||'—')}</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:3px">${h(s.license_type||'')}${s.site_url?' · <span style="color:#1d4ed8">'+h(s.site_url)+'</span>':''}</div>
          </td>
          <td style="padding:16px 18px;text-align:center">
            <span style="background:${s.product_type==='Plugin'?'#dbeafe':'#dcfce7'};color:${s.product_type==='Plugin'?'#1d4ed8':'#15803d'};font-size:10px;font-weight:700;padding:4px 12px;border-radius:20px;letter-spacing:.5px">${h(s.product_type||'—')}</span>
          </td>
          <td style="padding:16px 18px;text-align:right;font-size:13px;font-weight:600;color:#374151">${fmtM(s.original_price||s.price)}</td>
          ${s.discount_amount > 0 ? `<td style="padding:16px 18px;text-align:right;font-size:13px;font-weight:600;color:#059669">−${fmtM(s.discount_amount)}${s.promo_code?'<div style="font-size:10px;color:#94a3b8;margin-top:2px">('+h(s.promo_code)+')</div>':''}</td>` : ''}
          <td style="padding:16px 18px;text-align:right;font-size:15px;font-weight:800;color:#818cf8">${fmtM(s.price)}</td>
        </tr>
      </tbody>
    </table>
  </div>

  
  <div style="display:flex;justify-content:flex-end;padding:0 48px 28px">
    <div style="width:300px;margin-top:16px">
      <div style="background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden">
        <div style="padding:12px 18px;display:flex;justify-content:space-between;border-bottom:1px solid #e2e8f0">
          <span style="font-size:12px;color:#64748b;font-weight:500">Subtotal</span>
          <span style="font-size:12px;font-weight:700;color:#374151">${fmtM(s.original_price||s.price)}</span>
        </div>
        ${s.discount_amount > 0 ? `<div style="padding:12px 18px;display:flex;justify-content:space-between;border-bottom:1px solid #e2e8f0">
          <span style="font-size:12px;color:#64748b;font-weight:500">Discount${s.promo_code?' ('+h(s.promo_code)+')':''}</span>
          <span style="font-size:12px;font-weight:700;color:#059669">−${fmtM(s.discount_amount)}</span>
        </div>` : ''}
        ${(()=>{
          const taxPct = parseFloat(st.invoice_tax_pct||0);
          const taxLbl = st.invoice_tax_label || 'Tax';
          if(taxPct > 0){
            const taxAmt = parseFloat(s.price||0) * taxPct / 100;
            return `<div style="padding:12px 18px;display:flex;justify-content:space-between;border-bottom:1px solid #e2e8f0">
              <span style="font-size:12px;color:#64748b;font-weight:500">${h(taxLbl)} (${taxPct}%)</span>
              <span style="font-size:12px;font-weight:700;color:#d97706">+${fmtM(taxAmt)}</span>
            </div>`;
          }
          return '';
        })()}
        
        <div style="padding:16px 18px;background:linear-gradient(135deg,#0f172a,#1e3a5f);display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:13px;font-weight:800;color:#94a3b8;letter-spacing:.5px">GRAND TOTAL</span>
          <span style="font-size:20px;font-weight:900;color:#ffffff">${(()=>{const tp=parseFloat(st.invoice_tax_pct||0);const base=parseFloat(s.price||0);return fmtM(tp>0?base+(base*tp/100):base);})()}</span>
        </div>
        ${(()=>{
          const dueDays = parseInt(st.invoice_due_days||0);
          if(dueDays > 0 && s.sale_date){
            const dueDate = new Date(s.sale_date);
            dueDate.setDate(dueDate.getDate() + dueDays);
            const dueFmt = dueDate.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
            return `<div style="padding:10px 18px;display:flex;justify-content:space-between;border-bottom:1px solid #e2e8f0;background:#fffbeb">
              <span style="font-size:11px;color:#92400e;font-weight:600">⏰ Due Date</span>
              <span style="font-size:11px;font-weight:800;color:#92400e">${dueFmt}</span>
            </div>`;
          }
          return '';
        })()}
        <div style="padding:12px 18px;display:flex;justify-content:space-between;border-bottom:${remaining>0?'1px solid #e2e8f0':'none'}">
          <span style="font-size:12px;color:#64748b;font-weight:500">Amount Paid</span>
          <span style="font-size:13px;font-weight:700;color:#059669">${fmtM(totalPaid)}</span>
        </div>
        ${remaining > 0 ? `<div style="padding:12px 18px;display:flex;justify-content:space-between;background:#fff5f5">
          <span style="font-size:12px;color:#dc2626;font-weight:600">Balance Due</span>
          <span style="font-size:13px;font-weight:800;color:#dc2626">${fmtM(remaining)}</span>
        </div>` : ''}
      </div>
    </div>
  </div>

  
  ${pays.length ? `
  <div style="padding:0 48px 28px">
    <div style="font-size:9px;font-weight:800;color:#94a3b8;letter-spacing:2.5px;text-transform:uppercase;margin-bottom:14px">PAYMENT HISTORY</div>
    <table style="width:100%;border-collapse:collapse;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">
      <thead>
        <tr style="background:#f8fafc">
          <th style="padding:10px 16px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;border-bottom:1px solid #e2e8f0">Date</th>
          <th style="padding:10px 16px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;border-bottom:1px solid #e2e8f0">Method</th>
          <th style="padding:10px 16px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;border-bottom:1px solid #e2e8f0">Transaction ID</th>
          <th style="padding:10px 16px;text-align:right;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;border-bottom:1px solid #e2e8f0">Amount</th>
        </tr>
      </thead>
      <tbody>${payRows}</tbody>
    </table>
  </div>` : ''}

  
  ${s.note ? `
  <div style="padding:0 48px 24px">
    <div style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;padding:14px 18px;border-radius:0 8px 8px 0">
      <div style="font-size:9px;font-weight:800;color:#92400e;letter-spacing:2px;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:6px">📝 NOTE</div>
      <div style="font-size:12px;color:#78350f;line-height:1.6">${h(s.note)}</div>
    </div>
  </div>` : ''}

  ${st.invoice_terms ? `
  <div style="padding:0 48px 20px">
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px">
      <div style="font-size:9px;font-weight:800;color:#475569;letter-spacing:2px;text-transform:uppercase;margin-bottom:8px">📋 TERMS & CONDITIONS</div>
      <div style="font-size:11px;color:#64748b;line-height:1.7;white-space:pre-line">${h(st.invoice_terms)}</div>
    </div>
  </div>` : ''}

  ${st.invoice_footer_note ? `
  <div style="margin:0 48px 20px;text-align:center;padding:12px 18px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #bbf7d0;border-radius:10px">
    <div style="font-size:12px;color:#15803d;font-weight:600">${h(st.invoice_footer_note)}</div>
  </div>` : ''}

  
  <!-- Payment Methods with Official Logos -->
  ${(st.bkash_number||st.nagad_number||st.cellfin_number||st.rocket_number) ? `
  <div style="margin:0 48px 20px">
    <div style="font-size:9px;font-weight:800;color:#475569;letter-spacing:2px;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:8px">
      <span style="flex:1;height:1px;background:#e2e8f0;display:inline-block"></span>
      পেমেন্ট করুন
      <span style="flex:1;height:1px;background:#e2e8f0;display:inline-block"></span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px">
      ${st.bkash_number ? `
      <div style="background:#fdf2f8;border:1.5px solid #f9a8d4;border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px">
        <img src="assets/images/payment/bkash.svg" width="38" height="38" alt="bKash" style="border-radius:50%">
        <div>
          <div style="font-size:8px;font-weight:800;color:#9d174d;letter-spacing:.8px;text-transform:uppercase;margin-bottom:2px">বিকাশ Personal</div>
          <div style="font-size:14px;font-weight:900;color:#831843;font-family:'Courier New',monospace">${h(st.bkash_number)}</div>
        </div>
      </div>` : ''}
      ${st.nagad_number ? `
      <div style="background:#fff7ed;border:1.5px solid #fdba74;border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px">
        <img src="assets/images/payment/nagad.svg" width="38" height="38" alt="Nagad" style="border-radius:50%">
        <div>
          <div style="font-size:8px;font-weight:800;color:#9a3412;letter-spacing:.8px;text-transform:uppercase;margin-bottom:2px">নগদ Personal</div>
          <div style="font-size:14px;font-weight:900;color:#7c2d12;font-family:'Courier New',monospace">${h(st.nagad_number)}</div>
        </div>
      </div>` : ''}
      ${st.cellfin_number ? `
      <div style="background:#eff6ff;border:1.5px solid #93c5fd;border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px">
        <img src="assets/images/payment/cellfin.svg" width="38" height="38" alt="Cellfin" style="border-radius:50%">
        <div>
          <div style="font-size:8px;font-weight:800;color:#1e40af;letter-spacing:.8px;text-transform:uppercase;margin-bottom:2px">Cellfin</div>
          <div style="font-size:14px;font-weight:900;color:#1e3a8a;font-family:'Courier New',monospace">${h(st.cellfin_number)}</div>
        </div>
      </div>` : ''}
      ${st.rocket_number ? `
      <div style="background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px">
        <img src="assets/images/payment/rocket.svg" width="38" height="38" alt="Rocket" style="border-radius:50%">
        <div>
          <div style="font-size:8px;font-weight:800;color:#5b21b6;letter-spacing:.8px;text-transform:uppercase;margin-bottom:2px">Rocket</div>
          <div style="font-size:14px;font-weight:900;color:#4c1d95;font-family:'Courier New',monospace">${h(st.rocket_number)}</div>
        </div>
      </div>` : ''}
    </div>
    <div style="margin-top:10px;font-size:10px;color:#94a3b8;text-align:center">💡 Send Money করার পর Transaction ID সহ আমাদের জানান</div>
  </div>` : ''}

  
  <div style="margin:0 48px 24px;background:linear-gradient(135deg,#eff6ff,#f0f9ff);border-radius:12px;padding:18px 24px;text-align:center;border:1px solid #bfdbfe">
    <div style="font-size:15px;font-weight:800;color:#1e3a5f;margin-bottom:4px">Thank you for choosing us! 🙏</div>
    <div style="font-size:11px;color:#64748b">We appreciate your trust and look forward to serving you again.</div>
  </div>

  
  <div style="padding:14px 48px;background:#0f172a;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div>
      <div style="font-size:11px;color:#475569;font-weight:700">${company}</div>
      <div style="font-size:10px;color:#334155;margin-top:2px">📍 Nonni, Nalithabari, Sherpur &nbsp;·&nbsp; 💬 +8801919052411 (8 AM–11 PM)</div>
    </div>
    <div style="font-size:10px;color:#334155;font-family:'Courier New',monospace;background:rgba(255,255,255,.05);padding:4px 10px;border-radius:4px">${h(s.invoice_no||'')} · Wp Theme Bazar - Joynal Abdin</div>
  </div>
</div>`;
}

let _invoiceLoading = false;
async function viewInvoice(id) {
  if (!id) { toast('❌ Sale ID পাওয়া যায়নি','var(--danger)'); return; }
  if (_invoiceLoading) return;
  _invoiceLoading = true;
  toast('⏳ Invoice লোড হচ্ছে...');
  try {
    const r = await api('get_invoice','GET',null,{id});
    if (!r || !r.id) {
      toast('❌ Invoice লোড হয়নি।','var(--danger)');
      console.error('viewInvoice failed:', r);
      return;
    }
    _currentInvoiceData = r;
    const html = buildInvoiceHTML(r);
    document.getElementById('inv-body').innerHTML = `<div style="overflow-x:auto;overflow-y:visible;-webkit-overflow-scrolling:touch;padding:12px 0 4px">${html}</div>`;
    document.getElementById('inv-print').innerHTML = html;
    om('inv');
  } finally {
    _invoiceLoading = false;
  }
}

function printInvoice() { window.print(); }

async function shareInvoiceLink() {
  if (!_currentInvoiceData) { toast('❌ আগে invoice খুলুন','var(--danger)'); return; }
  const btn = event.currentTarget;
  const orig = btn.innerHTML;
  btn.innerHTML = '⏳...'; btn.disabled = true;
  try {
    const r = await api('get_invoice_share','GET',null,{id:_currentInvoiceData.id});
    if (!r || r.success === false) {
      toast('❌ ' + (r.error || 'Share link তৈরি হয়নি'), 'var(--danger)');
      return;
    }
    if (!r.link) {
      toast('❌ Share link পাওয়া যায়নি', 'var(--danger)');
      return;
    }
    // Copy to clipboard
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(r.link);
      } else {
        const ta = document.createElement('textarea');
        ta.value = r.link; ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      toast('🔗 Share link কপি হয়েছে! Client-কে পাঠান।');
    } catch(copyErr) {
      // If copy fails, show the link so user can manually copy
      prompt('Share link (কপি করুন):', r.link);
    }
  } catch(e) {
    toast('❌ Error: ' + (e.message || 'অজানা সমস্যা'), 'var(--danger)');
    console.error('shareInvoiceLink error:', e);
  } finally {
    btn.innerHTML = orig; btn.disabled = false;
  }
}

async function downloadInvoicePDF() {
  if (!_currentInvoiceData) return;
  // html2canvas + jsPDF approach via CDN
  const btn = event.currentTarget;
  const orig = btn.innerHTML;
  btn.innerHTML = '⏳ ...'; btn.disabled = true;
  try {
    // Load libraries if not loaded
    if (!window.html2canvas) {
      await loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js');
    }
    if (!window.jspdf) {
      await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
    }
    const el = document.getElementById('invoice-sheet');
    const canvas = await html2canvas(el, { scale: 2, useCORS: true, backgroundColor: '#fff' });
    const imgData = canvas.toDataURL('image/png');
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const pdfW = pdf.internal.pageSize.getWidth();
    const pdfH = (canvas.height * pdfW) / canvas.width;
    pdf.addImage(imgData, 'PNG', 0, 0, pdfW, pdfH);
    pdf.save(`Invoice-${_currentInvoiceData.invoice_no||_currentInvoiceData.id}.pdf`);
    toast('✅ PDF ডাউনলোড হয়েছে!');
  } catch(e) {
    toast('❌ PDF error: '+e.message,'var(--danger)');
  } finally {
    btn.innerHTML = orig; btn.disabled = false;
  }
}

async function downloadInvoiceImage() {
  if (!_currentInvoiceData) return;
  const btn = event.currentTarget;
  const orig = btn.innerHTML;
  btn.innerHTML = '⏳ ...'; btn.disabled = true;
  try {
    if (!window.html2canvas) {
      await loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js');
    }
    const el = document.getElementById('invoice-sheet');
    const canvas = await html2canvas(el, { scale: 2, useCORS: true, backgroundColor: '#fff' });
    const link = document.createElement('a');
    link.download = `Invoice-${_currentInvoiceData.invoice_no||_currentInvoiceData.id}.png`;
    link.href = canvas.toDataURL('image/png');
    link.click();
    toast('✅ Image ডাউনলোড হয়েছে!');
  } catch(e) {
    toast('❌ Image error: '+e.message,'var(--danger)');
  } finally {
    btn.innerHTML = orig; btn.disabled = false;
  }
}

function loadScript(src) {
  return new Promise((res, rej) => {
    if (document.querySelector(`script[src="${src}"]`)) { res(); return; }
    const s = document.createElement('script');
    s.src = src; s.onload = res; s.onerror = rej;
    document.head.appendChild(s);
  });
}

// PAYMENT
async function openPayModal(saleId){
  if(!saleId){toast('❌ Sale ID নেই','var(--danger)');return;}
  const r=await api('get_sale','GET',null,{id:saleId});
  if(!r||!r.id){toast('❌ Sale তথ্য লোড হয়নি','var(--danger)');return;}
  document.getElementById('py-sid').value=saleId;
  const remain=r.remaining||0;
  document.getElementById('py-info').innerHTML=`<b>${h(r.client_name||'—')}</b> · ${h(r.product_name||'—')}<br>Total: <b>${fmt(r.price)}</b> · Paid: <b style="color:var(--ac3)">${fmt(r.total_paid||0)}</b> · Due: <b style="color:var(--danger)">${fmt(remain)}</b>
  <div class="prog" style="margin-top:7px"><div class="prog-bar" style="width:${Math.min(100,Math.round((r.total_paid||0)/Math.max(r.price,1)*100))}%;background:var(--ac3)"></div></div>`;
  const hist=r.payments||[];
  document.getElementById('py-hist').innerHTML=hist.length?`<div style="font-size:10px;color:var(--t3);margin-bottom:5px">আগের Payment:</div>${hist.map(p=>`<div style="display:flex;justify-content:space-between;font-size:11px;background:var(--s2);border-radius:5px;padding:5px 8px;margin-bottom:4px"><span>${fmtD(p.paid_at?.split(' ')[0])} · ${h(p.method||'—')}</span><span style="color:var(--ac3);font-weight:600">${fmt(p.amount)}</span></div>`).join('')}`:'';
  document.getElementById('py-amt').value=remain>0?remain:'';
  document.getElementById('py-trx').value='';
  document.getElementById('py-nt').value='';
  document.getElementById('py-dt').value=new Date().toISOString().slice(0,16);
  populateSelect('py-mtd',_CFG.paymentMethod,'bKash Personal');
  om('pay');
}
async function savePayment(){
  const saleId = parseInt(document.getElementById('py-sid').value);
  const amount = parseFloat(document.getElementById('py-amt').value);
  const method = document.getElementById('py-mtd').value;
  const trxId  = document.getElementById('py-trx').value;
  const paidAt = document.getElementById('py-dt').value;
  const note   = document.getElementById('py-nt').value;

  if (!saleId) { toast('❌ Sale ID নেই','var(--danger)'); return; }
  if (!amount || amount <= 0) { toast('❌ সঠিক পরিমাণ লিখুন','var(--danger)'); return; }
  if (!method) { toast('❌ Payment method বেছে নিন','var(--danger)'); return; }

  const btn = document.querySelector('#m-pay .btn.bs');
  const orig = btn ? btn.innerHTML : '';
  if (btn) { btn.innerHTML = '⏳ ...'; btn.disabled = true; }

  const r = await api('add_payment','POST',{
    sale_id: saleId, amount, method,
    trx_id: trxId, paid_at: paidAt, note
  });

  if (btn) { btn.innerHTML = orig; btn.disabled = false; }

  if (r.success) {
    toast('✅ Payment যোগ হয়েছে। Status: ' + (r.new_status||''));
    cm('pay');
    loadSales(); loadDashboard();
    if (document.getElementById('page-due')?.style.display !== 'none') loadDueClients();
  } else {
    toast('❌ ' + (r.error || 'Payment যোগ হয়নি'), 'var(--danger)');
    console.error('savePayment error:', r);
  }
}

// PROMO APPLY
let _promoId=null;
async function applyPromo(){
  const code=document.getElementById('s-pc').value;const price=parseFloat(document.getElementById('s-pr').value)||0;
  if(!code)return;
  const r=await api('validate_promo','POST',{code,amount:price});
  document.getElementById('promo-result').textContent=r.message;
  document.getElementById('promo-result').style.color=r.valid?'var(--ac3)':'var(--danger)';
  if(r.valid){_promoId=r.promo.id;document.getElementById('s-dis').value=r.discount;document.getElementById('s-fp').value=r.final_price;}
  else{_promoId=null;calcDisc();}
}
function calcDisc(){const pr=parseFloat(document.getElementById('s-pr').value)||0;if(!_promoId){document.getElementById('s-dis').value=0;}const dis=parseFloat(document.getElementById('s-dis').value)||0;document.getElementById('s-fp').value=Math.max(0,pr-dis);}

// SALE MODAL
async function openSaleModal(sale=null){
  // প্রতিবার fresh load — যাতে নতুন product/client miss না হয়
  try {
    const[cr,pr]=await Promise.all([api('get_clients'),api('get_products')]);_c=cr.data||[];_p=pr.data||[];
  } catch(e) {
    console.error('Failed to load clients/products:', e);
    _c = _c || [];
    _p = _p || [];
    toast('⚠️ Failed to load data. Modal opened with cached data.', 'var(--warn)');
  }
  const cSel=document.getElementById('s-cl'),pSel=document.getElementById('s-pd');
  pSel.innerHTML='<option value="">Select Product...</option>';
  _p.forEach(p=>pSel.innerHTML+=`<option value="${p.id}" data-price="${p.price}" ${sale?.product_id==p.id?'selected':''}>${h(p.name)} (${p.type})</option>`);
  // AJAX client search reset
  document.getElementById('s-cl').value = '';
  document.getElementById('s-cl-search').value = '';
  document.getElementById('s-cl-lbl').style.display = 'none';
  document.getElementById('s-cl-clear').style.display = 'none';
  document.getElementById('s-cl-dropdown').style.display = 'none';
  // If editing: pre-fill client name
  if(sale?.client_id && sale?.client_name){
    document.getElementById('s-cl').value = sale.client_id;
    document.getElementById('s-cl-search').value = sale.client_name;
    document.getElementById('s-cl-lbl').style.display = 'inline';
    document.getElementById('s-cl-clear').style.display = 'inline';
  }
  document.getElementById('s-id').value=sale?.id||'';
  document.getElementById('s-dt').value=sale?.sale_date||new Date().toISOString().split('T')[0];
  document.getElementById('s-sd').value=sale?.sale_date||new Date().toISOString().split('T')[0];
  document.getElementById('s-ex').value=sale?.expiry_date||'';
  if(document.getElementById('s-ac')) document.getElementById('s-ac').value=sale?.activated_at||'';
  if(document.getElementById('s-ex-hint')) document.getElementById('s-ex-hint').style.display='none';
  // Show plugin field if editing a plugin
  setTimeout(()=>onProductChange(),100);
  document.getElementById('s-pr').value=sale?.price||sale?.original_price||'';
  document.getElementById('s-dis').value=sale?.discount_amount||0;
  document.getElementById('s-fp').value=sale?.price||'';
  document.getElementById('s-su').value=sale?.site_url||'';
  document.getElementById('s-li').value=sale?.license_type||'Single Site';
  document.getElementById('s-ps').value=sale?.payment_status||'paid';
  document.getElementById('s-no').value=sale?.note||'';
  document.getElementById('s-pc').value=sale?.promo_code||'';
  _promoId=sale?.promo_code_id||null;
  document.getElementById('sale-ttl').textContent=sale?'✏️ Edit Sale':'🛒 New Sale';
  document.getElementById('promo-result').textContent='';
  // Re-populate selects with dynamic config
  populateSelect('s-ps', _CFG.paymentStatus, sale?.payment_status||'paid');
  populateSelect('s-li', _CFG.licenseType,   sale?.license_type||'Single Site');
  populateSelect('s-pm', _CFG.paymentMethod, sale?.payment_method||'bKash Personal');
  // Init multi-product rows
  initProductRows();
  if(sale){
    // Edit mode: load existing product
    _saleProducts=[];
    document.getElementById('product-rows').innerHTML='';
    addProductRow({product_id:sale.product_id,name:sale.product_name||'',price:sale.price||sale.original_price||0,qty:1});
  }
  om('sale');
}
function autoPrice(){const o=document.getElementById('s-pd').options[document.getElementById('s-pd').selectedIndex];if(o?.dataset.price){document.getElementById('s-pr').value=o.dataset.price;document.getElementById('s-fp').value=o.dataset.price;document.getElementById('s-dis').value=0;_promoId=null;document.getElementById('promo-result').textContent='';document.getElementById('s-pc').value='';onProductChange();}}
function autoExp(){
  const d=document.getElementById('s-sd').value||document.getElementById('s-dt').value;
  // Check if product is Plugin - show activation date field
  const pdSel=document.getElementById('s-pd');
  const selProd=_p.find(p=>p.id==pdSel?.value);
  const isPlugin=selProd?.type==='Plugin';
  const wrap=document.getElementById('s-ac-wrap');
  if(wrap) wrap.style.display=isPlugin?'block':'none';
  if(!isPlugin && d && !document.getElementById('s-ex').value){
    const dt=new Date(d);dt.setFullYear(dt.getFullYear()+1);
    document.getElementById('s-ex').value=dt.toISOString().split('T')[0];
  }
  if(isPlugin) autoExpPlugin();
}
function autoExpPlugin(){
  const ac=document.getElementById('s-ac')?.value||document.getElementById('s-sd')?.value||'';
  if(!ac) return;
  const dt=new Date(ac); dt.setFullYear(dt.getFullYear()+1);
  document.getElementById('s-ex').value=dt.toISOString().split('T')[0];
  const hint=document.getElementById('s-ex-hint');
  if(hint) hint.style.display='block';
}
function onProductChange(){
  // When product changes, check if Plugin and show/hide activation field
  const pdSel=document.getElementById('s-pd');
  const selProd=_p.find(p=>p.id==parseInt(pdSel?.value));
  const isPlugin=selProd?.type==='Plugin';
  const wrap=document.getElementById('s-ac-wrap');
  if(wrap) wrap.style.display=isPlugin?'block':'none';
  const hint=document.getElementById('s-ex-hint');
  if(!isPlugin && hint) hint.style.display='none';
  if(isPlugin) autoExpPlugin();
}
function toggleAmtPaid(){
  const ps=document.getElementById('s-ps')?.value;
  const wrap=document.getElementById('s-ap-wrap');
  if(wrap) wrap.style.display=ps==='partial'?'':'none';
}

