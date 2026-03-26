// ═══════════════════════════════════════════
// Due clients module
// WP Sales Pro v5 — Module: due.js
// ═══════════════════════════════════════════

// ══════════════════════════════════════════════════════
// DUE CLIENTS
// ══════════════════════════════════════════════════════
let _dueData = [], _dueTab = '', _dueAllData = [];

async function loadDueClients() {
  document.getElementById('due-cards').innerHTML = '<div class="empty"><div class="spin" style="width:28px;height:28px;margin:0 auto 10px"></div><div>লোড হচ্ছে...</div></div>';
  const sort = document.getElementById('due-sort')?.value || 'desc';
  const r = await api('get_due_clients','GET',null,{sort});
  if (!r.success) { toast('❌ Due clients লোড হয়নি','var(--danger)'); return; }

  _dueAllData = r.data || [];
  _dueTab = '';
  // Reset tab UI to "সব"
  ['all','pending','partial'].forEach(t => {
    const el = document.getElementById('due-tab-' + t);
    if (el) el.classList.toggle('active', t === 'all');
  });

  // Update stat cards
  document.getElementById('due-total-amt').textContent = fmt(r.total_due || 0);
  document.getElementById('due-total-sub').textContent = `${r.total_count} টি sale বাকি`;
  document.getElementById('due-pending-cnt').textContent = r.pending_count || 0;
  document.getElementById('due-partial-cnt').textContent = r.partial_count || 0;

  // Update sidebar badge
  const badge = document.getElementById('nb-due');
  if (badge) {
    const cnt = r.total_count || 0;
    badge.textContent = cnt;
    badge.style.display = cnt > 0 ? '' : 'none';
  }

  filterDueClients();
}

function setDueTab(tab) {
  _dueTab = tab;
  ['all','pending','partial'].forEach(t => {
    const el = document.getElementById('due-tab-' + t);
    if (el) el.classList.toggle('active', t === (tab || 'all'));
  });
  filterDueClients();
}

function filterDueClients() {
  const q = (document.getElementById('due-q')?.value || '').toLowerCase();
  let list = _dueAllData;
  if (_dueTab) list = list.filter(x => x.payment_status === _dueTab);
  if (q) list = list.filter(x =>
    (x.client_name||'').toLowerCase().includes(q) ||
    (x.product_name||'').toLowerCase().includes(q) ||
    (x.invoice_no||'').toLowerCase().includes(q) ||
    (x.site_url||'').toLowerCase().includes(q)
  );
  renderDueCards(list);
}

function renderDueCards(list) {
  const el = document.getElementById('due-cards');
  if (!list.length) {
    el.innerHTML = '<div class="empty"><div class="empty-ico">🎉</div><div>কোনো বকেয়া নেই!</div></div>';
    return;
  }
  el.innerHTML = list.map(c => {
    const pct  = Math.min(100, Math.round((c.total_paid / Math.max(c.price, 1)) * 100));
    const isPending = c.payment_status === 'pending';
    const cardBorder = isPending ? 'rgba(239,68,68,.35)' : 'rgba(245,158,11,.35)';
    const badgeBg    = isPending ? 'rgba(239,68,68,.12)' : 'rgba(245,158,11,.1)';
    const badgeColor = isPending ? '#f87171' : '#fbbf24';
    const badgeBorder= isPending ? 'rgba(239,68,68,.3)' : 'rgba(245,158,11,.3)';
    const badgeLabel = isPending ? '⏳ Pending' : '🔶 Partial';
    const barColor   = isPending ? '#ef4444' : '#f59e0b';
    const avatarBg   = isPending ? 'linear-gradient(135deg,#dc2626,#f87171)' : 'linear-gradient(135deg,#d97706,#fbbf24)';
    const siteClean  = h((c.site_url||'').replace(/^https?:\/\//,'').replace(/\/$/,'').substring(0,30));
    const safeWaNum  = (c.client_whatsapp||'').replace(/\D/g,'');
    const waMsg      = encodeURIComponent('আসসালামু আলাইকুম ' + (c.client_name||'') + ', আপনার ' + (c.product_name||'') + ' এর বাকি পেমেন্ট ' + fmt(c.remaining) + ' পরিশোধ করুন। ধন্যবাদ।');
    const avatarChar = h((c.client_name||'?').charAt(0).toUpperCase());

    return `<div style="background:rgba(12,15,26,.9);border:1px solid ${cardBorder};border-radius:14px;margin-bottom:12px;overflow:hidden;transition:all .2s;backdrop-filter:blur(10px)" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">

      <div style="display:flex;align-items:center;gap:12px;padding:13px 16px;background:rgba(255,255,255,.02);border-bottom:1px solid rgba(255,255,255,.05)">
        <div style="width:40px;height:40px;border-radius:10px;background:${avatarBg};display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff;flex-shrink:0">${avatarChar}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:800;color:var(--t1);cursor:pointer" onclick="viewClientDetail(${c.client_id})">${h(c.client_name||'—')}</div>
          <div style="font-size:10px;color:var(--t3);margin-top:2px">${h(c.product_name||'—')} ${c.product_type?`· <span style="background:rgba(139,92,246,.12);color:#c4b5fd;padding:1px 6px;border-radius:4px;font-size:9px">${h(c.product_type)}</span>`:''}</div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
          <span style="font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;background:${badgeBg};color:${badgeColor};border:1px solid ${badgeBorder}">${badgeLabel}</span>
          <span style="font-size:10px;color:var(--t3);font-family:'JetBrains Mono'">${h(c.invoice_no||'')}</span>
        </div>
      </div>

      <div style="padding:12px 16px">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px">
          <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:9px;padding:9px 11px">
            <div style="font-size:9px;color:var(--t3);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">মোট দাম</div>
            <div style="font-size:14px;font-weight:800;color:var(--t1);font-family:'JetBrains Mono'">${fmt(c.price)}</div>
          </div>
          <div style="background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.15);border-radius:9px;padding:9px 11px">
            <div style="font-size:9px;color:#059669;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">দিয়েছে</div>
            <div style="font-size:14px;font-weight:800;color:#6ee7b7;font-family:'JetBrains Mono'">${fmt(c.total_paid)}</div>
          </div>
          <div style="background:${isPending?'rgba(239,68,68,.08)':'rgba(245,158,11,.08)'};border:1px solid ${isPending?'rgba(239,68,68,.2)':'rgba(245,158,11,.2)'};border-radius:9px;padding:9px 11px">
            <div style="font-size:9px;color:${badgeColor};font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">বাকি</div>
            <div style="font-size:14px;font-weight:800;color:${badgeColor};font-family:'JetBrains Mono'">${fmt(c.remaining)}</div>
          </div>
        </div>

        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--t3);margin-bottom:5px">
            <span>পরিশোধ অগ্রগতি</span>
            <span style="font-weight:700;color:${barColor}">${pct}%</span>
          </div>
          <div class="prog"><div class="prog-bar" style="width:${pct}%;background:${barColor}"></div></div>
        </div>

        ${siteClean ? `<div style="font-size:11px;color:#818cf8;margin-bottom:10px">🌐 ${siteClean}</div>` : ''}
      </div>

      <div style="display:flex;gap:6px;padding:10px 16px;background:rgba(255,255,255,.02);border-top:1px solid rgba(255,255,255,.04);flex-wrap:wrap">
        <button class="btn bs bsm" onclick="openPayModal(${c.id});event.stopPropagation()" style="flex:1;justify-content:center;min-width:90px">💳 Payment</button>
        ${c.client_whatsapp ? `<button class="btn bwa bsm" onclick="window.open('https://wa.me/${safeWaNum}?text=${waMsg}','_blank');event.stopPropagation()" style="flex:1;justify-content:center;min-width:90px">💬 WhatsApp</button>` : ''}
        <button class="btn bg bsm" onclick="viewInvoice(${c.id});event.stopPropagation()" style="flex:1;justify-content:center;min-width:90px">🧾 Invoice</button>
      </div>
    </div>`;
  }).join('');
}

// REPORT
async function loadReport(){
  const year=document.getElementById('ry')?.value||new Date().getFullYear();
  const r=await api('get_report','GET',null,{year});if(!r.success)return;
  const now=new Date();const tm=String(now.getMonth()+1).padStart(2,'0');
  const tmD=(r.monthly||[]).find(m=>m.month===tm);
  document.getElementById('r-m').textContent=fmt(tmD?.revenue||0);
  document.getElementById('r-th').textContent=(r.type_stats?.Theme?.cnt||0)+' items';
  document.getElementById('r-pl').textContent=(r.type_stats?.Plugin?.cnt||0)+' items';
  const cd=Array.from({length:12},(_,i)=>{const m=String(i+1).padStart(2,'0');const f=(r.monthly||[]).find(x=>x.month===m);return{l:MN[i],v:parseFloat(f?.revenue||0),o:parseInt(f?.orders||0)};});
  const mx=Math.max(...cd.map(x=>x.v),1);
  document.getElementById('r-chart').innerHTML=cd.map(x=>`<div class="bcol"><div class="bar" style="height:${Math.max((x.v/mx)*85,2)}px" title="${x.l}: ${fmt(x.v)} (${x.o} items)"></div><div class="bll">${x.l}</div></div>`).join('');
  document.getElementById('r-tp').innerHTML=(r.top_products||[]).map((p,i)=>`<div style="display:flex;align-items:center;gap:8px;padding:8px;background:var(--s2);border-radius:7px;margin-bottom:6px"><span>${['🥇','🥈','🥉','4️⃣','5️⃣'][i]}</span><div style="flex:1"><div style="font-size:12px;font-weight:600">${h(p.name)}</div><div style="font-size:10px;color:var(--t3)">${p.sales_count} times ${tType(p.type)}</div></div><div style="font-family:'JetBrains Mono';font-size:11px;color:var(--ac3);font-weight:700">${fmt(p.revenue)}</div></div>`).join('')||'<div class="empty">No data</div>';
  document.getElementById('r-tc').innerHTML=(r.top_clients||[]).map((c,i)=>`<div style="display:flex;align-items:center;gap:8px;padding:8px;background:var(--s2);border-radius:7px;margin-bottom:6px"><span>${['🥇','🥈','🥉','4️⃣','5️⃣'][i]}</span><div style="flex:1"><div style="font-size:12px;font-weight:600">${h(c.name)}</div><div style="font-size:10px;color:var(--t3)">${c.purchases} purchases</div></div><div style="font-family:'JetBrains Mono';font-size:11px;color:var(--ac3);font-weight:700">${fmt(c.spent)}</div></div>`).join('')||'<div class="empty">No data</div>';
  document.getElementById('r-promo').innerHTML=(r.promo_stats||[]).map(p=>`<tr><td class="mono" style="color:var(--ac3)">${h(p.code||'—')}</td><td>${p.type==='percent'?'%':'$'}</td><td>${h(p.value||"")}</td><td>${p.used_count}</td><td class="mono" style="color:var(--danger)">${fmt(p.total_discount)}</td></tr>`).join('')||'<tr><td colspan="5"><div class="empty">No data</div></td></tr>';
}

// FORECAST
async function loadForecast(){
  const r=await api('get_forecast');if(!r.success)return;
  const mn=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  document.getElementById('forecast-months').innerHTML=(r.upcoming||[]).slice(0,3).map(m=>`<div class="forecast-card">
    <div style="font-size:11px;color:var(--t3);margin-bottom:5px">📅 ${m.ym}</div>
    <div style="font-size:20px;font-weight:700;font-family:'JetBrains Mono';color:var(--ac)">${fmt(m.potential)}</div>
    <div style="font-size:11px;color:var(--t3);margin-top:3px">${m.cnt} possible renewals</div>
  </div>`).join('')||'<div class="empty" style="grid-column:span 3"><div class="empty-ico">🔮</div>No upcoming expiries</div>';
  document.getElementById('fc-renew').innerHTML=(r.renewal_rate||[]).map(m=>`<div style="margin-bottom:9px">
    <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px"><span style="color:var(--t2)">${m.ym}</span><span style="font-weight:700;color:var(--ac3)">${m.rate||0}%</span></div>
    <div class="prog"><div class="prog-bar" style="width:${m.rate||0}%;background:var(--ac3)"></div></div>
    <div style="font-size:10px;color:var(--t3)">${m.renewed||0} / ${m.total||0} Renew</div>
  </div>`).join('')||'<div class="empty">No data</div>';
  const totPotential=(r.upcoming||[]).reduce((s,x)=>s+parseFloat(x.potential||0),0);
  const avgRate=(r.renewal_rate||[]).reduce((s,x)=>s+parseFloat(x.rate||0),0)/Math.max((r.renewal_rate||[]).length,1);
  document.getElementById('fc-summary').innerHTML=`
    <div style="background:var(--s2);border-radius:8px;padding:11px;margin-bottom:8px"><div style="font-size:10px;color:var(--t3);margin-bottom:3px">Expected Revenue (Next 3 Months)</div><div style="font-size:20px;font-weight:700;color:var(--ac3);font-family:'JetBrains Mono'">${fmt(totPotential)}</div></div>
    <div style="background:var(--s2);border-radius:8px;padding:11px;margin-bottom:8px"><div style="font-size:10px;color:var(--t3);margin-bottom:3px">Average Renewal Rate</div><div style="font-size:20px;font-weight:700;color:var(--ac);font-family:'JetBrains Mono'">${Math.round(avgRate)}%</div></div>
    <div style="font-size:11px;color:var(--t3)">💡 ${avgRate>=70?'Excellent! Renewal rate is good.':avgRate>=50?'Average. Increase outreach.':'Low renewal rate. Send WhatsApp reminders.'}</div>`;
}

// ADMINS
async function loadAdmins(){const r=await api('get_admins');if(!r.success)return;_admins=r.data||[];document.getElementById('admins-tb').innerHTML=_admins.map(a=>`<tr><td><div style="display:flex;align-items:center;gap:8px"><div style="width:30px;height:30px;border-radius:7px;background:linear-gradient(135deg,var(--ac2),var(--ac));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px">${h((a.full_name||a.username).charAt(0))}</div><div><div style="font-size:12px;font-weight:600">${h(a.full_name||'—')}</div><div style="font-size:10px;color:var(--t3)">@${h(a.username)}</div></div></div></td><td>${tRole(a.role)}</td><td>${a.is_active?'<span class="tag t-on">✅</span>':'<span class="tag t-off">❌</span>'}</td><td style="font-size:11px">${a.last_login?fmtD(a.last_login?.split(' ')[0]):'—'}</td><td style="font-size:10px;color:var(--t3)">${a.last_ip||'—'}</td><td><div style="display:flex;gap:3px">${a.id!==USER?.id?`<button class="btn bg bxs" onclick="toggleAdm(${a.id})">${a.is_active?'🚫':'✅'}</button><button class="btn bg bxs" onclick="editAdm(${a.id})">✏️</button><button class="btn bg bxs" onclick="delAdm(${a.id})" style="color:var(--danger)">🗑</button>`:'<span style="font-size:10px;color:var(--t3)">(You)</span>'}</div></td></tr>`).join('');}
function openAdminModal(){['adm-id','adm-fn','adm-un','adm-pw'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});document.getElementById('adm-rl').value='admin';document.getElementById('adm-ufg').style.display='';document.getElementById('adm-pfg').style.display='';document.getElementById('adm-ttl').textContent='🛡️ New Admin';om('adm');}
function editAdm(id){const a=_admins.find(x=>x.id==id);if(!a)return;document.getElementById('adm-id').value=a.id;document.getElementById('adm-fn').value=a.full_name||'';document.getElementById('adm-rl').value=a.role||'admin';document.getElementById('adm-ufg').style.display='none';document.getElementById('adm-pfg').style.display='none';document.getElementById('adm-ttl').textContent='✏️ Edit Admin';om('adm');}
async function saveAdmin(){
  const id=document.getElementById('adm-id').value;
  const body={id:id?parseInt(id):null,username:document.getElementById('adm-un').value,password:document.getElementById('adm-pw').value,full_name:document.getElementById('adm-fn').value,role:document.getElementById('adm-rl').value};
  const btn=document.querySelector('#m-adm .btn.bp');const orig=btn?btn.innerHTML:'';
  if(btn){btn.disabled=true;btn.innerHTML='⏳...';}
  try{
    const r=await api(id?'update_admin':'add_admin','POST',body);
    if(r.success){toast('✅ '+r.message);cm('adm');loadAdmins();}else toast('❌ '+r.error,'var(--danger)');
  }finally{if(btn){btn.disabled=false;btn.innerHTML=orig;}}
}
async function toggleAdm(id){const r=await api('toggle_admin','POST',{id});if(r.success){toast(r.message);loadAdmins();}else toast('❌ '+r.error,'var(--danger)');}
async function delAdm(id){if(!confirm('Delete?'))return;const r=await api('delete_admin','POST',{id});if(r.success){toast('🗑️ '+r.message,'var(--danger)');loadAdmins();}else toast('❌ '+r.error,'var(--danger)');}

// SESSIONS & AUDIT
async function loadSessions(){const r=await api('get_sessions');if(!r.success)return;document.getElementById('sess-body').innerHTML=(r.data||[]).map(s=>`<div style="background:var(--s2);border-radius:8px;padding:11px 13px;margin-bottom:7px;display:flex;align-items:center;gap:11px"><div style="width:7px;height:7px;border-radius:50%;background:var(--ac3);box-shadow:0 0 8px rgba(16,185,129,.5);flex-shrink:0"></div><div style="flex:1"><div style="font-size:12px;font-weight:600">${h(s.full_name||s.username)} ${tRole(s.role)}</div><div style="font-size:10px;color:var(--t3)">🌐 ${h(s.ip_address)} · ${h((s.user_agent||"").substring(0,40))}</div></div><button class="btn bd2 bxs" onclick="killSess('${h(s.id)}')">⚡ Cancel</button></div>`).join('')||'<div class="empty">No active sessions</div>';}
async function killSess(id){if(!confirm('Cancel?'))return;const r=await api('kill_session','POST',{session_id:id});if(r.success){toast('⚡ '+r.message);loadSessions();}else toast('❌ '+r.error,'var(--danger)');}
async function loadAudit(){const r=await api('get_audit_log','GET',null,{action:document.getElementById('aq')?.value||''});if(!r.success)return;_auditAll=r.data||[];_auditPage=1;renderAudit(1);}

// SETTINGS
async function loadSettings(){
  const r=await api('get_settings');
  if(!r.data)return;
  const d=r.data;
  // Company fields
  const cm={cn:'company_name',em:'company_email',ph:'company_phone',ip:'invoice_prefix',ad:'company_address',cu:'currency',rd:'remind_days'};
  Object.entries(cm).forEach(([k,v])=>{const el=document.getElementById('set-'+k);if(el)el.value=d[v]||'';});
  // Payment numbers
  const bk=document.getElementById('set-bkash'); if(bk)bk.value=d.bkash_number||'';
  const ng=document.getElementById('set-nagad'); if(ng)ng.value=d.nagad_number||'';
  const cf=document.getElementById('set-cellfin'); if(cf)cf.value=d.cellfin_number||'';
  const rk=document.getElementById('set-rocket'); if(rk)rk.value=d.rocket_number||'';
  // Logo
  if(d.company_logo){
    const prev=document.getElementById('logo-preview');
    if(prev){prev.innerHTML=`<img src="${d.company_logo}" style="width:100%;height:100%;object-fit:contain;border-radius:8px">`;};
    const inp=document.getElementById('set-logo'); if(inp)inp.value=d.company_logo;
    const rb=document.getElementById('logo-remove-btn'); if(rb)rb.style.display='';
  }
  // Theme
  const th=d.invoice_theme||'indigo';
  const thInp=document.getElementById('set-theme'); if(thInp)thInp.value=th;
  setTheme(th,true);
  // SMTP fields
  const se=document.getElementById('set-sh'); if(se)se.value=d.smtp_host||'';
  const po=document.getElementById('set-po'); if(po)po.value=d.smtp_port||'587';
  const su=document.getElementById('set-su'); if(su)su.value=d.smtp_user||'';
  const sw=document.getElementById('set-sw'); if(sw)sw.value=d.smtp_pass||'';
  const sf=document.getElementById('set-sf'); if(sf)sf.value=d.smtp_from||'';
  const st=document.getElementById('set-st'); if(st)st.value=d.site_title||'';
  if(d.currency_symbol)_CFG.currencySymbol=d.currency_symbol;
  // SMS fields
  const sp=document.getElementById('set-sp'); if(sp)sp.value=d.sms_provider||'ssl';
  const ak=document.getElementById('set-ak'); if(ak)ak.value=d.sms_api_key||'';
  const si=document.getElementById('set-si'); if(si)si.value=d.sms_sender_id||'';
}

function setTheme(t, silent=false){
  document.querySelectorAll('.theme-opt').forEach(el=>{
    el.style.border = el.id==='theme-'+t ? '2px solid #a5b4fc' : '2px solid transparent';
    el.style.transform = el.id==='theme-'+t ? 'scale(1.1)' : 'scale(1)';
  });
  const inp=document.getElementById('set-theme'); if(inp)inp.value=t;
}

function handleLogoUpload(input){
  const file=input.files[0]; if(!file)return;
  if(file.size>512*1024){toast('Logo সর্বোচ্চ 512KB হতে হবে','var(--warn)');return;}
  const reader=new FileReader();
  reader.onload=e=>{
    const b64=e.target.result;
    const prev=document.getElementById('logo-preview');
    if(prev)prev.innerHTML=`<img src="${b64}" style="width:100%;height:100%;object-fit:contain;border-radius:8px">`;
    const inp=document.getElementById('set-logo'); if(inp)inp.value=b64;
    const rb=document.getElementById('logo-remove-btn'); if(rb)rb.style.display='';
    toast('✅ Logo লোড হয়েছে — Save করুন');
  };
  reader.readAsDataURL(file);
}

function removeLogo(){
  const prev=document.getElementById('logo-preview'); if(prev)prev.innerHTML='🏢';
  const inp=document.getElementById('set-logo'); if(inp)inp.value='';
  const rb=document.getElementById('logo-remove-btn'); if(rb)rb.style.display='none';
  const fu=document.getElementById('logo-upload'); if(fu)fu.value='';
}
}
async function saveSettings(){
  const g=id=>document.getElementById(id)?.value||'';
  const body={
    company_name:g('set-cn'), company_email:g('set-em'),
    company_phone:g('set-ph'), invoice_prefix:g('set-ip')||'INV',
    company_address:g('set-ad'), currency:g('set-cu')||'BDT',
    remind_days:g('set-rd')||'7',
    bkash_number:g('set-bkash'),
    nagad_number:g('set-nagad'),
    cellfin_number:g('set-cellfin'),
    rocket_number:g('set-rocket'),
    site_title:g('set-st'),
    smtp_host:g('set-sh'), smtp_port:g('set-po')||'587',
    smtp_user:g('set-su'), smtp_pass:g('set-sw'),
    smtp_from:g('set-sf'),
    sms_provider:g('set-sp')||'ssl',
    sms_api_key:g('set-ak'), sms_sender_id:g('set-si'),
  };
  // B04 Fix: Double-submit guard
  const _sBtn=document.querySelector('#page-settings .btn.bp,[onclick*=saveSettings]');
  const _sOrig=_sBtn?_sBtn.innerHTML:'';
  if(_sBtn){_sBtn.disabled=true;_sBtn.innerHTML='⏳ সংরক্ষণ হচ্ছে...';}
  try{
    const r=await api('save_settings','POST',body);
    if(r.success)toast('✅ '+r.message);else toast('❌ '+r.error,'var(--danger)');
  }finally{if(_sBtn){_sBtn.disabled=false;_sBtn.innerHTML=_sOrig;}}
}

// CSV EXPORT
function exportExcelFile(){
  window.open('api/?action=export_excel','_blank');
}
function exportClientsSheet(){
  window.open('api/?action=export_clients_sheet','_blank');
  toast('📊 Google Sheets File download starting...');
}
function exportYearlyPlugins(){
  const year=document.getElementById('sfy')?.value||new Date().getFullYear();
  window.open(`api/?action=export_yearly_plugins&year=${year}`,'_blank');
  toast('🔌 Yearly Plugin Downloading report ('+year+')...');
}
function fillYears(){
  const sel=document.getElementById('sfy');
  if(!sel||sel.options.length>1)return;
  const y=new Date().getFullYear();
  for(let i=y;i>=y-4;i--) sel.innerHTML+=`<option value="${i}">${i}</option>`;
}
function exportCSV(){if(!_s.length){toast('No data','var(--warn)');return;}const rows=[['Invoice','Date','Client','Products','Type','Site','Expired','Price','Discount','Final','Payment']];_s.forEach(s=>rows.push([s.invoice_no||s.invoice_number||'',s.sale_date,s.client_name,s.product_name,s.product_type,s.site_url,s.expiry_date||'',s.original_price||s.price,s.discount_amount||0,s.price,s.payment_status]));const csv=rows.map(r=>r.map(c=>`"${c}"`).join(',')).join('\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'}));a.download='sales-'+new Date().toISOString().split('T')[0]+'.csv';a.click();}

