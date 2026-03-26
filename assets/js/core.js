// ═══════════════════════════════════════════
// Core: API, globals, formatting, UI utils, auth, nav, dashboard
// WP Sales Pro v5 — Module: core.js
// ═══════════════════════════════════════════


// ══ Global Error Handler ══
window.onerror = function(msg, src, line, col, err) {
  console.error('[Global Error]', msg, 'at', src, line+':'+col, err);
  // Only show toast for non-network errors
  if (msg && !msg.includes('Script error') && typeof toast === 'function') {
    toast('⚠️ JS Error: ' + String(msg).substring(0,80), 'var(--warn)');
  }
  return false;
};
window.addEventListener('unhandledrejection', function(e) {
  console.error('[Unhandled Promise]', e.reason);
  if (typeof toast === 'function' && e.reason) {
    const msg = e.reason?.message || String(e.reason);
    toast('⚠️ Error: ' + msg.substring(0,80), 'var(--warn)');
  }
});

// ══════════════════════════════════
// GLOBALS & API
// ══════════════════════════════════
const A='api/index.php';
let CSRF='',USER=null,_c=[],_p=[],_s=[],_admins=[];
const MN=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const MNF=['January','February','Mar','April','May','Jun','July','August','September','October','November','December'];

// ══════════════════════════════════
// XSS ESCAPE — Send all user data here
// ══════════════════════════════════
const _esc=document.createElement('div');
function h(str){
  if(str===null||str===undefined)return'—';
  _esc.textContent=String(str);
  return _esc.innerHTML;
}

async function api(action,method='GET',body=null,params={},download=false){
  // LiteSpeed cache bypass — unique timestamp per request
  let url=A+'?action='+action+'&_='+Date.now();
  Object.entries(params).forEach(([k,v])=>v&&(url+=`&${k}=${encodeURIComponent(v)}`));
  const opts={
    method,
    credentials:'include',
    headers:{
      'Content-Type':'application/json',
      'X-CSRF-Token':CSRF,
      'Cache-Control':'no-cache, no-store',
      'Pragma':'no-cache',
      'X-Requested-With':'XMLHttpRequest',
    }
  };
  if(body&&method!=='GET'){body._csrf=CSRF;opts.body=JSON.stringify(body);}
  if(download){window.location.href=url;return {};}
  // 15-second timeout — prevents infinite loading if server is down
  const controller=new AbortController();
  const timeoutId=setTimeout(()=>controller.abort(),15000);
  opts.signal=controller.signal;
  let r;
  try{
    r=await fetch(url,opts);
    clearTimeout(timeoutId);
  }catch(err){
    clearTimeout(timeoutId);
    if(err.name==='AbortError'){
      console.error('API timeout:',action);
      return {success:false,error:'Request timed out (15s). Server সাড়া দিচ্ছে না।'};
    }
    console.error('Network error:',err);
    return {success:false,error:'Network error. Please check your internet connection.'};
  }
  if(r.status===401&&action!=='check_auth'&&action!=='login'){showLogin();return {};}
  if(r.status===403){const errJ=await r.clone().json().catch(()=>({}));if((errJ.error||'').toLowerCase().includes('token')){toast('⚠️ Session expired. Please refresh the page.','var(--danger)');return {};}}
  const text=await r.text();
  if(!text||!text.trim()){
    console.error('Empty response for action:',action,'HTTP:',r.status);
    return {success:false,error:'No response received. (HTTP '+r.status+')'};
  }
  try{
    return JSON.parse(text);
  }catch(e){
    console.error('API parse error ['+action+'] HTTP:'+r.status, text.substring(0,500));
    return {success:false,error:'Server error: '+text.substring(0,80)};
  }
}

// ══════════════════════════════════
// FORMATTING
// ══════════════════════════════════
const fmt=n=>(_CFG.currencySymbol||'৳')+Number(n||0).toLocaleString('en-BD');
const fmtD=d=>{if(!d)return'—';const dt=new Date(d+'T00:00:00');return`${dt.getDate()} ${MNF[dt.getMonth()]}, ${dt.getFullYear()}`};
const tType=t=>t==='Theme'?'<span class="tag t-theme">🎨 Theme</span>':'<span class="tag t-plugin">🔌 Plugin</span>';
const tPay=s=>s==='paid'?'<span class="tag t-paid">✅ Paid</span>':s==='partial'?'<span class="tag t-partial">💳 Partial</span>':'<span class="tag t-pending">⏳ Pending</span>';
const tRen=r=>r==='renewed'?'<span class="tag t-renewed">🔄 Renewed</span>':r==='expired'?'<span class="tag t-expired">❌ Expired</span>':'<span class="tag t-active">✅ Active</span>';
const tPri=p=>({low:'<span class="tag t-low">Low</span>',medium:'<span class="tag t-medium">Medium</span>',high:'<span class="tag t-high">High</span>',urgent:'<span class="tag t-urgent">🚨 Urgent</span>'})[p]||p;
const tSt=s=>({open:'<span class="tag t-open">Open</span>',in_progress:'<span class="tag t-in_progress">🔧 In Progress</span>',resolved:'<span class="tag t-resolved">✅ Resolved</span>',closed:'<span class="tag t-closed">Closed</span>'})[s]||s;
const tRole=r=>({super_admin:'<span class="tag t-super">👑 super_admin</span>',admin:'<span class="tag t-admin">🛡 admin</span>',viewer:'<span class="tag t-viewer">👁 viewer</span>'})[r]||r;
function exTag(exp,dl){if(!exp)return'—';dl=parseInt(dl);if(dl<0)return`<span class="tag t-expired">🚨 ${Math.abs(dl)}d Before</span>`;if(dl<=7)return`<span class="tag t-soon">⏰ ${dl}d Remaining</span>`;return`<span style="font-size:11px;color:var(--t3)">${fmtD(exp)}</span>`;}

// ══════════════════════════════════
// UI UTILS
// ══════════════════════════════════
function toast(m,c='var(--ac3)'){const e=document.createElement('div');e.className='toast';e.style.borderLeftColor=c;e.textContent=m;document.getElementById('toasts').appendChild(e);setTimeout(()=>e.remove(),3200);}
function loader(v){document.getElementById('loader').classList.toggle('hide',!v);}
function om(n){document.getElementById('m-'+n).classList.add('open');}
function cm(n){document.getElementById('m-'+n).classList.remove('open');}
document.querySelectorAll('.mo').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('open');}));
function togglePw(id,el){const i=document.getElementById(id);i.type=i.type==='password'?'text':'password';el.textContent=i.type==='password'?'👁':'🙈';}
function pwStr(input){const pw=input.value;let sc=0;if(pw.length>=8)sc++;if(/[A-Z]/.test(pw))sc++;if(/[0-9]/.test(pw))sc++;if(/[^A-Za-z0-9]/.test(pw))sc++;const colors=['','var(--danger)','var(--warn)','var(--ac)','var(--ac3)'];const mid=input.id==='pw-n'?'pm2':input.id==='pw-c'?'pm2':'pm3';const m=document.getElementById(mid);if(m){m.style.background=colors[sc]||'var(--bd)';m.style.width=(sc*25)+'%';}}
function di(l,v,col=''){return`<div style="background:var(--s2);border-radius:7px;padding:9px 11px;${col?'grid-column:'+col:''}"><div style="font-size:9px;color:var(--t3);margin-bottom:2px;text-transform:uppercase;letter-spacing:.4px">${h(String(l))}</div><div style="font-size:12px;font-weight:600">${h(String(v))}</div></div>`;}
function diH(l,v,col=''){return`<div style="background:var(--s2);border-radius:7px;padding:9px 11px;${col?'grid-column:'+col:''}"><div style="font-size:9px;color:var(--t3);margin-bottom:2px;text-transform:uppercase;letter-spacing:.4px">${h(String(l))}</div><div style="font-size:12px;font-weight:600">${v}</div></div>`;}
const isSup=()=>USER?.role==='super_admin';
const canEdit=()=>['super_admin','admin'].includes(USER?.role);

// ══════════════════════════════════
// NAV
// ══════════════════════════════════
const PAGES=['dashboard','notify','sales','clients','products','promos','tickets','tasks','reminders','due','report','forecast','admins','sessions','audit','settings'];
function go(p){
  PAGES.forEach(x=>{const e=document.getElementById('page-'+x);if(e)e.style.display=x===p?'block':'none';});
  PAGES.forEach(x=>{const e=document.getElementById('n-'+x);if(e)e.classList.toggle('active',x===p);});
  if(p==='dashboard')initAllSelects();
  loadDashboard();
  if(p==='notify')loadNotify();
  if(p==='sales'){loadSales();fillMonths();fillYears();}
  if(p==='clients')loadClients();
  if(p==='products')loadProducts();
  if(p==='promos')loadPromos();
  if(p==='tickets')loadTickets();
  if(p==='tasks')loadTasks();
  if(p==='due')loadDueClients();
  if(p==='reminders'){loadReminders();loadSMSLog();}
  if(p==='report')loadReport();
  if(p==='forecast')loadForecast();
  if(p==='admins')loadAdmins();
  if(p==='sessions')loadSessions();
  if(p==='audit')loadAudit();
  if(p==='settings')loadSettings();
}

// ══════════════════════════════════
// AUTH
// ══════════════════════════════════
function showLogin(){document.getElementById('lp').style.display='flex';document.getElementById('app').style.display='none';}
function showApp(){document.getElementById('lp').style.display='none';document.getElementById('app').style.display='block';}
function applyUser(u){
  USER=u;
  document.getElementById('s-nm').textContent=u.full_name||u.username;
  document.getElementById('s-av').textContent=(u.full_name||u.username).charAt(0).toUpperCase();
  const rb=document.getElementById('s-rl');rb.textContent=u.role;rb.className='rb'+(u.role==='super_admin'?' super':u.role==='viewer'?' viewer':'');
  // Super admin: show all security sections
  ['n-admins','n-sessions','n-audit'].forEach(id=>{const e=document.getElementById(id);if(e)e.style.display=isSup()?'flex':'none';});
  // All admins (not viewer) can see settings
  const setEl=document.getElementById('n-settings');if(setEl)setEl.style.display=canEdit()?'flex':'none';
  // Hide write-action buttons for viewer role
  const btnNP=document.getElementById('btn-new-product');if(btnNP)btnNP.style.display=canEdit()?'':'none';
}

async function doLogin(){
  const btn=document.getElementById('lbtn'),le=document.getElementById('le');
  const att=document.getElementById('l-attempts');
  const u=document.getElementById('lu').value.trim(),p=document.getElementById('lp2').value;
  if(!u){le.textContent='Please enter username.';le.style.display='block';document.getElementById('lu').focus();return;}
  if(!p){le.textContent='Please enter password.';le.style.display='block';document.getElementById('lp2').focus();return;}
  btn.disabled=true;btn.innerHTML='<span class="spin"></span>&nbsp; Verifying...';le.style.display='none';
  if(att)att.style.display='none';
  const r=await api('login','POST',{username:u,password:p});
  btn.disabled=false;btn.innerHTML='🔐 &nbsp;Login';
  if(r.success){CSRF=r.csrf;applyUser(r.user);showApp();loadDashboard();toast('✅ Welcome, '+(r.user.full_name||r.user.username));}
  else{
    // Safe DOM — no innerHTML with server data
    le.textContent='';
    const errTxt=document.createTextNode(r.error||'Login failed.');
    le.appendChild(errTxt);
    if(r.remaining_attempts!==undefined){
      const sm=document.createElement('small');
      sm.style.opacity='.8';sm.style.display='block';
      sm.textContent='More '+r.remaining_attempts+' attempts remaining.';
      le.appendChild(sm);
    }
    le.style.display='block';
    document.getElementById('lp2').value='';
    document.getElementById('lp2').focus();
    // Shake animation on card
    const card=document.querySelector('.lc');
    card.style.animation='none';
    requestAnimationFrame(()=>{card.style.animation='shake .4s ease';});
  }
}
async function doLogout(){await api('logout','POST',{});CSRF='';USER=null;showLogin();toast('Logged out successfully.');}
function openChangePw(){['pw-c','pw-n','pw-cn'].forEach(id=>document.getElementById(id).value='');om('pw');}
async function doChangePw(){
  const btn=document.querySelector('#m-pw .btn.bp');const orig=btn?btn.innerHTML:'';
  if(btn){btn.disabled=true;btn.innerHTML='⏳...';}
  try{
    const r=await api('change_password','POST',{current_password:document.getElementById('pw-c').value,new_password:document.getElementById('pw-n').value,confirm_password:document.getElementById('pw-cn').value});
    if(r.success){toast('✅ '+r.message);cm('pw');}else toast('❌ '+r.error,'var(--danger)');
  }finally{if(btn){btn.disabled=false;btn.innerHTML=orig;}}
}

// ══════════════════════════════════
// DASHBOARD
// ══════════════════════════════════

// ══════════════════════════════════════════════════════════
// DYNAMIC CONFIG — All dropdowns populated from here
// ══════════════════════════════════════════════════════════
const _CFG = {
  paymentStatus:   ['paid','partial','pending'],
  paymentMethod:   ['bKash Personal','Nagad Personal','Rocket','Upay','bKash Payment','Cellfin','Bank','Other'],
  currencySymbol: '৳',
  licenseType:     ['Single Site','5 Sites','Unlimited'],
  productType:     ['Plugin','Theme','Service','Other'],
  renewalStatus:   ['active','expired','renewed'],
  priority:        ['urgent','high','medium','low'],
  taskPriority:    ['high','medium','low'],
  ticketStatus:    ['open','in_progress','resolved','closed'],
  adminRole:       ['admin','viewer'],
  reminderChannel: ['whatsapp','email','sms','manual'],
  promoType:       ['percent','fixed'],
  smsProvider:     ['ssl','twilio','nexmo'],
  clientFilter:    ['active','portal'],
};

const _LABEL = {
  // Payment
  paid:'Paid', partial:'Partial', pending:'Pending',
  // Status
  active:'Active', expired:'Expired', renewed:'Renewed',
  portal:'Portal',
  // Priority
  urgent:'Urgent', high:'High', medium:'Medium', low:'Low',
  // Ticket
  open:'Open', in_progress:'In Progress', waiting:'Waiting',
  resolved:'Resolved', closed:'Close',
  // Promo
  percent:'Percentage (%)', fixed:'Fixed ($)',
  // Channel
  whatsapp:'WhatsApp', email:'Email', sms:'SMS', manual:'Manual',
  // Role
  admin:'Admin', viewer:'Viewer',
  // Product type
  Plugin:'Plugin', Theme:'Theme', Service:'Service', Other:'Others',
  // License
  'Single Site':'Single Site', '5 Sites':'5 Sites', Unlimited:'Unlimited',
  // Payment method
  'bKash Personal':'bKash Personal (01919052413)', 'Nagad Personal':'Nagad Personal (01919052413)', Rocket:'Rocket & Upay (01919052410)', Upay:'Upay (01919052410)', 'bKash Payment':'bKash Payment (01919052411)', Cellfin:'Cellfin (01919052411)', Bank:'Bank (IBBL / Bank Asia / Rocket / Binance)',
  // SMS provider
  ssl:'SSL Wireless', twilio:'Twilio', nexmo:'Nexmo/Vonage',
};

function lbl(v){ return _LABEL[v] || v; }

function populateSelect(id, items, selected='', emptyLabel='', customLabels={}) {
  const el = document.getElementById(id);
  if (!el) return;
  const prev = selected || el.value;
  el.innerHTML = '';
  if (emptyLabel) {
    const opt = document.createElement('option');
    opt.value = ''; opt.textContent = emptyLabel;
    el.appendChild(opt);
  }
  items.forEach(v => {
    const opt = document.createElement('option');
    opt.value = v;
    opt.textContent = customLabels[v] || lbl(v);
    if (String(prev) === String(v)) opt.selected = true;
    el.appendChild(opt);
  });
}

function initAllSelects() {
  // ── Sale modal ──
  populateSelect('s-ps', _CFG.paymentStatus,   'paid');
  populateSelect('s-li', _CFG.licenseType,     'Single Site');
  populateSelect('s-pm', _CFG.paymentMethod,   'bKash Personal');
  // ── Product modal ──
  populateSelect('pd-tp', _CFG.productType,    'Plugin');
  // ── Promo modal ──
  populateSelect('pr-tp', _CFG.promoType,      'percent');
  populateSelect('pr-ac', ['1','0'],           '1', '', {'1':'Active','0':'Inactive'});
  // ── Ticket modal ──
  populateSelect('tk-pr', _CFG.priority,       'medium');
  populateSelect('tk-st', _CFG.ticketStatus,   'open');
  // ── Ticket detail modal ──
  populateSelect('tkd-st', _CFG.ticketStatus,  'open');
  // ── Task modal ──
  populateSelect('ta-pr', _CFG.taskPriority,   'medium');
  // ── Reminder modal ──
  populateSelect('rem-ch', _CFG.reminderChannel, 'whatsapp');
  // ── Admin modal ──
  populateSelect('adm-rl', _CFG.adminRole,     'admin');
  // ── Settings ──
  populateSelect('set-sp', _CFG.smsProvider,   'ssl');
  // ── Filter dropdowns ──
  populateSelect('sfs', _CFG.paymentStatus,    '', 'All Status');
  populateSelect('sfr', _CFG.renewalStatus,    '', 'All Renewals');
  populateSelect('tfs', _CFG.ticketStatus,     '', 'All Status');
  populateSelect('tfp', _CFG.priority,         '', 'All Priority');
  populateSelect('cqf', _CFG.clientFilter,     '', 'All');
}

async function loadDashboard(){
  const d=await api('dashboard');if(!d.success)return;
  // Stat cards
  document.getElementById('d-rev').textContent=fmt(d.total_revenue);
  document.getElementById('d-mrev').textContent='This Month: '+fmt(d.month_revenue);
  document.getElementById('d-part').textContent=fmt(d.partial_revenue);
  document.getElementById('d-pend2').textContent=fmt(d.pending_amt)+' Pending';
  document.getElementById('d-co').textContent=d.total_clients+' / '+d.total_orders;
  document.getElementById('d-ot').textContent=(d.overdue_tasks||0)+' Overdue Tasks';
  document.getElementById('d-tk').textContent=d.open_tickets||0;
  const tot=(d.expiring_soon||0)+(d.already_expired||0);
  document.getElementById('d-ex').textContent=tot;
  document.getElementById('d-ex-sub').textContent=`Expired: ${d.already_expired||0} · Upcoming: ${d.expiring_soon||0}`;
  // Navbar badges
  const nb=document.getElementById('nb-exp');
  if(tot>0){nb.textContent=tot;nb.style.display='';}else nb.style.display='none';
  document.getElementById('nb-tk').textContent=d.open_tickets||0;
  document.getElementById('nb-ta').textContent=d.pending_tasks||0;
  document.getElementById('nb-s').textContent=d.total_orders||0;
  document.getElementById('nb-c').textContent=d.total_clients||0;
  // Due clients badge — from dashboard data (no extra API call)
  const dueBadge=document.getElementById('nb-due');
  if(dueBadge){const cnt=parseInt(d.due_count||0);dueBadge.textContent=cnt;dueBadge.style.display=cnt>0?'':'none';}

  // 🚨 Plugin 7-day Final Notice Banner
  const pw=d.plugin_final_notice||[];
  const pn=document.getElementById('plugin-notice');
  if(pw.length>0){
    pn.style.display='block';
    document.getElementById('plugin-notice-list').innerHTML=pw.map(s=>`
      <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:8px 11px;min-width:200px;max-width:280px">
        <div style="font-weight:700;font-size:12px;color:#f87171">${h(s.client_name||"—")}</div>
        <div style="font-size:11px;color:var(--t2);margin:2px 0">🔌 ${h(s.product_name||"—")}</div>
        <div style="font-size:11px;color:var(--t3)">${(s.site_url||'').replace(/^https?:\/\//,'').substring(0,28)||'—'}</div>
        <div style="font-size:11px;margin-top:4px;font-weight:600;color:#fca5a5">⏱️ ${s.days_left<=0?'Expires Today!':s.days_left+' days remaining'} · ${fmtD(s.expiry_date)}</div>
        <div style="display:flex;gap:4px;margin-top:6px">
          ${s.whatsapp?`<button class="btn bwa bxs" onclick="window.open('https://wa.me/${(s.whatsapp||'').replace(/\D/g,'')}?text='+encodeURIComponent('Dear '+(s.client_name||'')+', Your '+(s.product_name||'')+' License '+fmtD(s.expiry_date)+' is expiring. Please Renew.'),'_blank')" title="WhatsApp">💬</button>`:''}
          ${s.facebook?`<button class="btn bfb bxs" onclick="window.open(this.dataset.fb,'_blank')" data-fb="${h(s.facebook||'')}" title="Facebook">👤</button>`:''}
          <button class="btn bg bxs" onclick="openRenewModal(${s.id})" style="font-size:10px">🔄 Renew</button>
        </div>
      </div>`).join('');
  } else pn.style.display='none';

  // ⏰ General expiry banner
  const bn=document.getElementById('banner');
  if(tot>0){bn.style.display='flex';document.getElementById('bann-t').textContent=`${d.already_expired||0} expired, ${d.expiring_soon||0} expiring within 7 days.`;}
  else bn.style.display='none';

  // 🟢 Active Clients with their products
  const ac=d.active_clients||[];
  _acAll = ac; _acPage = 1;
  renderActiveClients(1);

  // Recent sales table
  document.getElementById('d-rec').innerHTML=(d.recent_sales||[]).map(s=>`<tr>
    <td class="bold" style="cursor:pointer;color:var(--ac)" onclick="viewClientDetail(${s.client_id})">${h(s.client_name||'—')}</td>
    <td>${h(s.product_name||'—')} ${tType(s.product_type)}</td>
    <td>${exTag(s.expiry_date,s.days_left)}</td>
    <td class="mono">${fmt(s.price)}</td><td>${tPay(s.payment_status)}</td>
  </tr>`).join('')||'<tr><td colspan="5"><div class="empty"><div class="empty-ico">📋</div>No data available</div></td></tr>';
  document.getElementById('d-ticks').innerHTML=(d.recent_tickets||[]).map(t=>`<div class="tick-card" onclick="viewTicket(${t.id})" style="margin-bottom:7px">
    <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px"><span style="font-size:12px;font-weight:700">${t.ticket_no}</span>${tPri(t.priority)}${tSt(t.status)}</div>
    <div style="font-size:12px;color:var(--t2)">${h(t.subject||"—")}</div><div style="font-size:10px;color:var(--t3)">${h(t.client_name||'—')}</div>
  </div>`).join('')||'<div class="empty" style="padding:14px"><div class="empty-ico">✅</div>No open tickets</div>';
  document.getElementById('d-tasks').innerHTML=(d.due_tasks||[]).map(t=>`<div class="task-card" style="margin-bottom:7px">
    <div class="task-check ${t.status==='done'?'done':''}" onclick="toggleTask(${t.id})">${t.status==='done'?'✓':''}</div>
    <div style="flex:1"><div style="font-size:12px;font-weight:600">${h(t.title||"—")}</div>
    <div style="font-size:10px;color:var(--t3)">${t.due_date?'📅 '+fmtD(t.due_date.split(' ')[0]):''} ${t.client_name?'· '+h(t.client_name):''}</div></div>
    ${tPri(t.priority)}
  </div>`).join('')||'<div class="empty" style="padding:14px"><div class="empty-ico">✅</div>No tasks</div>';
}


// ══════════════════════════════════════════════════════════
// EXPIRY CARD RENDERER
// ══════════════════════════════════════════════════════════
function ecCard(s, cls){
  const dl=parseInt(s.days_left??999);
  const isExpired=dl<0||s.renewal_status==='expired';
  const isUrgent=dl>=0&&dl<=7;
  const cardCls=isExpired||isUrgent?'danger':'warn';
  const daysTxt=dl<0?`${Math.abs(dl)} days ago`:dl===0?'Expires Today!':isUrgent?`${dl} days remaining`:`${dl} days remaining`;
  const wa=(s.client_whatsapp||'').replace(/\D/g,'');
  return `<div class="ec ${cardCls}" data-cname="${h(s.client_name||'')}" data-wa="${wa}" data-fb="${h(s.client_facebook||'')}">
    <div class="ech">
      <div class="ecav">${h((s.client_name||'?').charAt(0).toUpperCase())}</div>
      <div class="eci">
        <div class="ecn">${h(s.client_name||'—')}</div>
        <div class="ecp">${h(s.product_name||'—')} ${s.product_type==='Plugin'?'🔌':'🎨'}</div>
        <span class="ecd">${daysTxt}</span>
      </div>
    </div>
    <div class="ecb">
      <div class="ecr"><span class="ll2">🌐 Site:</span><a href="${(s.site_url||'#').replace(/^(?!https?:|#)/i,'https://')}" target="_blank" rel="noopener noreferrer">${(s.site_url||'—').replace(/^https?:\/\//,'').substring(0,35)}</a></div>
      <div class="ecr"><span class="ll2">📅 Expiry:</span><span>${fmtD(s.expiry_date)}</span></div>
      <div class="ecr"><span class="ll2">💰 Price:</span><span>${fmt(s.price)}</span></div>
      ${s.license_type?`<div class="ecr"><span class="ll2">🔑 License:</span><span>${s.license_type}</span></div>`:''}
    </div>
    <div class="ecc">
      ${wa?`<button class="btn bwa bxs" onclick="window.open('https://wa.me/${wa}','_blank')">💬 WA</button>`:''}
      ${s.client_facebook?`<button class="btn bfb bxs" onclick="window.open(this.dataset.fb,'_blank')" data-fb="${h(s.client_facebook)}">👤 FB</button>`:''}
      <button class="btn bg bxs" onclick="openContactModal(${s.client_id},this.closest('.ec').dataset.cname||'',this.closest('.ec').dataset.wa||'',this.closest('.ec').dataset.fb||'')">✉️</button>
    </div>
    <div class="eca">
      ${s.renewal_status!=='renewed'?`<button class="btn bs bxs" onclick="openRenewModal(${s.id})">🔄 Renew</button>`:''}
      <button class="btn bg bxs" onclick="viewSaleDetail(${s.id})">👁 Details</button>
      <button class="btn bp bxs" onclick="openReminderModal2(${s.id},this.closest('.ec').dataset.cname||'',this.closest('.ec').dataset.wa||'')">📲 Reminders</button>
    </div>
  </div>`;
}

function openContactModal(clientId, name, wa, fb) {
  document.getElementById('cnt-info').innerHTML = '<b>'+h(name)+'</b>'+(wa?' · 💬 +'+wa:'')+(fb?' · 🔵 Facebook':'');
  document.getElementById('cnt-msg').value = 'Dear '+name+',\n\nYour license is about to expire. Please contact us to renew.\n\nThank you.';
  const btns = [];
  if(wa){
    const waMsg = encodeURIComponent('Dear '+name+', Your license is about to expire.');
    btns.push('<a class="btn bwa bsm" id="wa-link-'+clientId+'" href="https://wa.me/'+wa+'?text='+waMsg+'" target="_blank">💬 WhatsApp</a>');
  }
  if(fb) btns.push('<a class="btn bfb bsm" href="'+fb+'" target="_blank">👤 Facebook</a>');
  btns.push('<button class="btn bml bsm" onclick="sendSMSNow('+clientId+')">📱 SMS Send</button>');
  document.getElementById('cnt-btns').innerHTML = btns.join('');
  om('cnt');
}


function updateWALink(el, wa) {
  const msg = document.getElementById('cnt-msg').value;
  el.href = 'https://wa.me/' + wa + '?text=' + encodeURIComponent(msg);
}

async function openReminderModal2(saleId, clientName, wa) {
  await openReminderModal();
  const r = await api('get_sale','GET',null,{id:saleId});
  if(r.id && r.client_id){
    const sel=document.getElementById('rem-cl');
    for(let o of sel.options){if(o.value==r.client_id){o.selected=true;break;}}
    document.getElementById('rem-msg').value='Dear '+clientName+',\n\nYour '+r.product_name+' license expires on '+fmtD(r.expiry_date)+' has expired. Please renew quickly.\n\nContact us.';
  }
}


// ══════════════════════════════════
// NOTIFY
// ══════════════════════════════════
let _notify={expiring_soon:[],already_expired:[],plugin_7day:[],stale:[]};
async function loadNotify(){
  document.getElementById('n-soon').innerHTML='<div class="empty"><div class="spin" style="width:24px;height:24px;border-width:2px"></div></div>';
  const r=await api('get_expiring');
  if(!r.success)return;
  const nd=r.data||r||{};
  const soon=nd.expiring_soon||r.expiring_soon||[];
  const plugin7=nd.plugin_7day||r.plugin_7day||[];
  const expired=nd.already_expired||r.already_expired||[];
  const stale=nd.stale||r.stale||[];
  _notify={expiring_soon:soon,plugin_7day:plugin7,already_expired:expired,stale};
  document.getElementById('nc-s').textContent=soon.length;
  document.getElementById('nc-p').textContent=plugin7.length;
  document.getElementById('nc-e').textContent=expired.length;
  document.getElementById('nc-st').textContent=stale.length;
  // Plugin tab badge - highlight if any
  const ptab=document.getElementById('nt-p');
  if(plugin7.length>0) ptab.style.background='rgba(239,68,68,.15)';
  _notify_data={soon,plugin:plugin7,exp:expired,stale};
  _notify_page={soon:1,plugin:1,exp:1,stale:1};
  renderNotifyTab('soon',1);
  renderNotifyTab('plugin',1);
  renderNotifyTab('exp',1);
  renderNotifyTab('stale',1);
}
function ntab(t){
  ['s','p','e','st'].forEach(x=>{
    const tabKey=x==='s'?'soon':x==='p'?'plugin':x==='e'?'exp':'stale';
    document.getElementById('nt-'+x)?.classList.toggle('active',x===t);
    const el=document.getElementById('n-'+tabKey);
    const pg=document.getElementById('pg-'+tabKey);
    if(el) el.style.display=x===t?'grid':'none';
    if(pg) pg.style.display=x===t?'block':'none';
  });
}


async function sendSMSNow(cid){
  const r=await api('send_sms','POST',{client_id:cid,message:document.getElementById('cnt-msg').value});
  if(r.success)toast('✅ '+r.message);else toast('❌ '+r.error,'var(--danger)');cm('cnt');
}

// ══════════════════════════════════
// SALES
// ══════════════════════════════════
let _sqTimer=null;
function onSalesSearch(val){
  // Show AJAX dropdown for short queries
  if(val.length>=2) ajaxSearch(val);
  else hideAjaxResults();
  // Debounce full table reload
  clearTimeout(_sqTimer);
  _sqTimer=setTimeout(()=>loadSales(),400);
}
function hideAjaxResults(){
  const d=document.getElementById('ajax-results');
  if(d){d.style.display='none';d.innerHTML='';}
}
async function ajaxSearch(q){
  const spin=document.getElementById('sq-spin');
  if(spin) spin.style.display='inline';
  const r=await api('search','GET',null,{q});
  if(spin) spin.style.display='none';
  const box=document.getElementById('ajax-results');
  if(!box) return;
  const {clients=[],sales=[],products=[]}=r.data||{};
  if(!clients.length&&!sales.length&&!products.length){hideAjaxResults();return;}
  let html='';
  if(clients.length){
    html+=`<div style="padding:8px 12px;font-size:10px;color:var(--t3);font-weight:700;border-bottom:1px solid var(--bd)">👥 Client</div>`;
    html+=clients.map(c=>`<div class="ajax-item" onclick="filterByClient(${c.id},this.getAttribute('data-name'))" data-name="${h(c.name)}">
      <div style="font-weight:600;font-size:12px">${h(c.name)}</div>
      <div style="font-size:11px;color:var(--t3)">${c.phone||''} ${c.whatsapp?'· 💬'+c.whatsapp:''} ${c.email?'· '+c.email:''}</div>
    </div>`).join('');
  }
  if(sales.length){
    html+=`<div style="padding:8px 12px;font-size:10px;color:var(--t3);font-weight:700;border-bottom:1px solid var(--bd);border-top:1px solid var(--bd)">🛒 Sales</div>`;
    html+=sales.map(s=>`<div class="ajax-item" onclick="viewSaleFromSearch(${s.id})">
      <div style="font-weight:600;font-size:12px">${h(s.client_name||"—")} — ${h(s.product_name||"—")}</div>
      <div style="font-size:11px;color:var(--t3)">${s.invoice_no||''} · ${(s.site_url||'').replace(/^https?:\/\//,'').substring(0,30)} · <span style="color:var(--ac3)">${fmt(s.price)}</span></div>
    </div>`).join('');
  }
  if(products.length){
    html+=`<div style="padding:8px 12px;font-size:10px;color:var(--t3);font-weight:700;border-bottom:1px solid var(--bd);border-top:1px solid var(--bd)">📦 Products</div>`;
    html+=products.map(p=>`<div class="ajax-item" onclick="filterByProduct(this.getAttribute('data-pname'))" data-pname="${h(p.name||'')}">
      <div style="font-weight:600;font-size:12px">${p.name} ${p.type=='Plugin'?'🔌':'🎨'}</div>
      <div style="font-size:11px;color:var(--t3)">${fmt(p.price)}</div>
    </div>`).join('');
  }
  box.innerHTML=html+'<div style="padding:7px 12px;text-align:center;font-size:10px;color:var(--t3);border-top:1px solid var(--bd)">Enter Press to see all results</div>';
  box.style.display='block';
}
function filterByClient(id,name){
  hideAjaxResults();
  document.getElementById('sq').value=name;
  loadSales();
}
function filterByProduct(name){
  hideAjaxResults();
  document.getElementById('sq').value=name;
  loadSales();
}
function viewSaleFromSearch(id){
  hideAjaxResults();
  viewSaleDetail(id);
}
function clearFilters(){
  ['sq','sft','sfs','sfm','sfy','sfr','sfex'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  hideAjaxResults();
  loadSales();
}
document.addEventListener('click',e=>{if(!e.target.closest('#ajax-results')&&!e.target.closest('#sq'))hideAjaxResults();});

