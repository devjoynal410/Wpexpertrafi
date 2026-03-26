// ═══════════════════════════════════════════
// Settings, WAF, backup, admins module
// WP Sales Pro v5 — Module: settings.js
// ═══════════════════════════════════════════

// ══════════════════════════════════
// MOBILE SIDEBAR
// ══════════════════════════════════
function toggleSidebar(){
  const sb=document.getElementById('sidebar');
  const ov=document.getElementById('sb-overlay');
  const hm=document.getElementById('hmb');
  sb.classList.toggle('open');
  ov.classList.toggle('open');
  hm.classList.toggle('open');
}
function closeSidebar(){
  document.getElementById('sidebar')?.classList.remove('open');
  document.getElementById('sb-overlay')?.classList.remove('open');
  document.getElementById('hmb')?.classList.remove('open');
}
// Close sidebar on nav click (mobile)
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.nv').forEach(el=>{
    el.addEventListener('click',()=>{if(window.innerWidth<=768)closeSidebar();});
  });
});

// INIT
async function init(){
  loader(true);
  // Populate year dropdowns
  const ry=document.getElementById('ry');
  if(ry){const ny=new Date().getFullYear();for(let y=ny;y>=ny-4;y--)ry.innerHTML+=`<option value="${y}">${y}</option>`;}
  try{
    const r=await api('check_auth');
    if(!r || r.success === false){
      // Server responded but with error (DB connection issue, etc.)
      showLogin();
      if(r && r.error && r.error.includes('unavailable')){
        toast('❌ Database সংযোগ হয়নি। config.php-তে DB credentials চেক করুন।','var(--danger)');
      } else {
        toast('❌ Server error: '+(r?.error||'Unknown error'),'var(--danger)');
      }
      return;
    }
    if(r.logged_in&&r.user){
      CSRF=r.csrf;
      applyUser(r.user);
      showApp();
      loadDashboard();
    } else {
      showLogin();
    }
  } catch(e) {
    console.error('Init error:',e);
    showLogin();
    const msg = e?.message||'';
    if(msg.includes('NetworkError')||msg.includes('fetch')||msg.includes('Failed to fetch')){
      toast('❌ Server-এ পৌঁছানো যাচ্ছে না। PHP server চালু আছে কি?','var(--danger)');
    } else {
      toast('❌ Connection failed! Page reload করুন।','var(--danger)');
    }
  } finally {
    loader(false);
  }
}
// ── version label: config.php APP_VERSION থেকে নিয়ে দেখায় ──
async function _setVersionLabel(){try{const vi=await api('init_ver');if(vi&&vi.v){const el=document.getElementById('app-ver-label');if(el)el.textContent='v'+vi.v+' · Wp Theme Bazar';const sv=document.getElementById('sidebar-ver');if(sv)sv.textContent='v'+vi.v+' · Secured';}}catch(_){}}
init();_setVersionLabel();

// ══════════════════════════════════════════════════════
// BACKUP & RESTORE SYSTEM
// ══════════════════════════════════════════════════════
function openBackup(){
  om('backup');
  loadBackupList();
}

function showBackupTab(tab){
  ['create','list','restore'].forEach(t=>{
    document.getElementById('bk-'+t).style.display = t===tab?'':'none';
    const btn = document.getElementById('btab-'+t);
    if(btn){ btn.className = t===tab?'btn bp bsm':'btn bsm'; btn.style.background = t===tab?'':'var(--s2)'; }
  });
  if(tab==='list'||tab==='restore') loadBackupList();
}

async function doCreateBackup(){
  const btn=document.getElementById('bk-create-btn');
  const msg=document.getElementById('bk-create-msg');
  btn.disabled=true; btn.textContent='⏳ Creating...';
  msg.innerHTML='';
  const r=await api('create_backup','POST',{});
  btn.disabled=false; btn.textContent='📦 Create Backup';
  if(r.success){
    msg.innerHTML=`<div style="background:rgba(16,185,129,.15);border:1px solid var(--ac3);border-radius:8px;padding:10px;color:var(--ac3)">
      ✅ <strong>${h(r.message||"")}</strong><br>
      <span style="font-size:12px;color:var(--t2)">📄 ${r.filename} · 📦 ${r.size} · 🏷️ ${r.type}</span>
    </div>`;
    loadBackupList();
  } else {
    msg.innerHTML=`<div style="background:rgba(239,68,68,.15);border:1px solid var(--danger);border-radius:8px;padding:10px;color:var(--danger)">❌ ${h(r.error||'Failed')}</div>`;
  }
}

const _typeLabel = {daily:'📅 Daily',weekly:'📆 Weekly',monthly:'🗓️ Monthly',yearly:'🏆 Yearly',manual:'🔧 Manual'};
const _typeColor = {daily:'var(--ac)',weekly:'var(--ac3)',monthly:'var(--warn)',yearly:'#a78bfa',manual:'var(--t2)'};

async function loadBackupList(){
  const r=await api('list_backups','GET');
  if(!r.success) return;

  // Update stats
  const stats=document.getElementById('bk-stats');
  if(stats) stats.textContent=`Total ${r.total} Backups · ${r.dir_size}`;

  // Build list
  const body=document.getElementById('bk-list-body');
  if(body){
    if(!r.backups||r.backups.length===0){
      body.innerHTML='<div style="text-align:center;padding:30px;color:var(--t2)">No backups. Create your first backup.</div>';
    } else {
      body.innerHTML='';
      r.backups.forEach(b=>{
        const div=document.createElement('div');
        div.style.cssText='background:var(--s2);border-radius:8px;padding:12px;margin-bottom:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap';
        const info=document.createElement('div');
        info.style.cssText='flex:1;min-width:200px';
        const nameEl=document.createElement('div');
        nameEl.style.cssText='font-weight:600;color:var(--t1);font-size:13px';
        nameEl.textContent=b.filename;
        const metaEl=document.createElement('div');
        metaEl.style.cssText='font-size:11px;color:var(--t2);margin-top:3px';
        metaEl.textContent='📅 '+b.created_at+' · 📦 '+b.size+' · ⏱️ '+b.age_days+' days ago';
        info.append(nameEl,metaEl);
        const actDiv=document.createElement('div');
        actDiv.style.cssText='display:flex;gap:6px;align-items:center';
        const badge=document.createElement('span');
        badge.style.cssText=`background:${_typeColor[b.type]||'var(--t2)'};color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600`;
        badge.textContent=_typeLabel[b.type]||b.type;
        const dlBtn=document.createElement('button');
        dlBtn.className='btn bsm';dlBtn.style.cssText='background:var(--ac);padding:4px 10px';
        dlBtn.textContent='⬇️';dlBtn.dataset.filename=b.filename;
        dlBtn.addEventListener('click',()=>downloadBk(dlBtn.dataset.filename));
        const delBtn=document.createElement('button');
        delBtn.className='btn bsm';delBtn.style.cssText='background:var(--danger);padding:4px 10px';
        delBtn.textContent='🗑️';delBtn.dataset.filename=b.filename;
        delBtn.addEventListener('click',()=>deleteBk(delBtn.dataset.filename));
        actDiv.append(badge,dlBtn,delBtn);
        div.append(info,actDiv);
        body.appendChild(div);
      });
    }
  }

  // Update restore select
  const sel=document.getElementById('bk-restore-sel');
  if(sel){
    sel.innerHTML=r.backups&&r.backups.length>0
      ? r.backups.map(b=>`<option value="${b.filename}">${b.filename} (${b.size})</option>`).join('')
      : '<option value="">No backup</option>';
  }
}

function downloadBk(filename){
  window.location.href='api/index.php?action=download_backup&filename='+encodeURIComponent(filename)+'&_='+Date.now();
}

async function deleteBk(filename){
  if(!confirm('Delete this Backup?\n'+filename)) return;
  const r=await api('delete_backup','POST',{filename});
  if(r.success){ toast('🗑️ '+r.message); loadBackupList(); }
  else toast('❌ '+(r.error||'Delete failed'),'var(--danger)');
}

async function doRestore(){
  const filename=document.getElementById('bk-restore-sel')?.value;
  if(!filename){toast('❌ Select a backup','var(--danger)');return;}
  if(!confirm('⚠️ Warning!\n\nAll current data will be deleted!\n\nFile: '+filename+'\n\nConfirm?')) return;
  if(!confirm('Final Confirm — Proceed with Restore?')) return;

  const msg=document.getElementById('bk-restore-msg');
  msg.innerHTML='<div style="color:var(--warn)">⏳ Restoring... Please wait.</div>';

  const r=await api('restore_backup','POST',{filename});
  if(r.success){
    const rows=Object.entries(r.restored||{}).map(([t,n])=>`<span style="font-size:11px;background:var(--bg);border-radius:4px;padding:2px 6px">${t}: ${n}</span>`).join(' ');
    msg.innerHTML=`<div style="background:rgba(16,185,129,.15);border:1px solid var(--ac3);border-radius:8px;padding:12px;color:var(--ac3)">
      ✅ <strong>${h(r.message||"")}</strong><br>
      <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px">${rows}</div>
    </div>`;
    toast('✅ Restore complete! Page reloading...');
    setTimeout(()=>location.reload(), 2000);
  } else {
    msg.innerHTML=`<div style="background:rgba(239,68,68,.15);border:1px solid var(--danger);border-radius:8px;padding:12px;color:var(--danger)">❌ ${h(r.error||'Restore failed')}</div>`;
  }
}


// ══════════════════════════════════════════════════════
// WAF SECURITY DASHBOARD
// ══════════════════════════════════════════════════════
let _wafLog = [];

function openWAF(){
  om('waf');
  loadWAFStats();
}

function wafTab(tab){
  ['overview','log','blocked','ban'].forEach(t=>{
    document.getElementById('wf-'+t).style.display = t===tab?'':'none';
    const btn=document.getElementById('wt-'+t);
    if(btn){btn.className=t===tab?'btn bp bsm':'btn bsm';btn.style.background=t===tab?'':'var(--s2)';}
  });
  if(tab==='log') loadWAFLog();
  else if(tab==='blocked') loadBannedIPs();
}

async function loadWAFStats(){
  const r=await api('waf_stats','GET');
  if(!r.success) return;
  const s=r;
  document.getElementById('ws-total').textContent = s.total||0;
  document.getElementById('ws-sqli').textContent  = s.by_type?.SQLI||0;
  document.getElementById('ws-xss').textContent   = s.by_type?.XSS||0;
  document.getElementById('ws-bot').textContent   = s.by_type?.BAD_UA||0;
  document.getElementById('ws-banned').textContent= s.banned||0;

  // Top IPs
  const topEl=document.getElementById('waf-top-ips');
  if(topEl){
    const ips=Object.entries(s.top_ips||{}).slice(0,5);
    if(ips.length===0){
      topEl.innerHTML='<div style="color:var(--ac3);font-size:13px">✅ No attacks!</div>';
    } else {
      const max=ips[0]?.[1]||1;
      const frag=document.createDocumentFragment();
      ips.forEach(([ip,cnt])=>{
        const row=document.createElement('div');
        row.style.cssText='display:flex;align-items:center;gap:10px;margin-bottom:8px';
        const ipCode=document.createElement('code');
        ipCode.style.cssText='color:var(--danger);font-size:12px;min-width:120px';
        ipCode.textContent=ip;
        const bar=document.createElement('div');
        bar.style.cssText='flex:1;background:var(--bg);border-radius:4px;height:8px;overflow:hidden';
        const fill=document.createElement('div');
        fill.style.cssText=`width:${Math.round(cnt/max*100)}%;height:100%;background:var(--danger);border-radius:4px`;
        bar.appendChild(fill);
        const cntEl=document.createElement('span');
        cntEl.style.cssText='font-size:12px;color:var(--t2);min-width:30px';
        cntEl.textContent=cnt;
        const btn=document.createElement('button');
        btn.className='btn bsm';btn.style.cssText='padding:2px 8px;font-size:10px;background:var(--danger)';
        btn.textContent='Ban';
        btn.dataset.ip=ip;
        btn.addEventListener('click',()=>quickBan(btn.dataset.ip));
        row.append(ipCode,bar,cntEl,btn);
        frag.appendChild(row);
      });
      topEl.innerHTML='';
      topEl.appendChild(frag);
    }
  }

  // Update nav badge
  const badge=document.getElementById('nb-waf');
  if(badge){
    badge.style.display=(s.total>0)?'':'none';
    badge.textContent=s.total>99?'99+':s.total;
  }
}

async function loadWAFLog(){
  const r=await api('waf_attack_log','GET');
  if(!r.success) return;
  _wafLog = r.log||[];
  renderWAFLog(_wafLog);
}

function filterWAFLog(){
  const f=document.getElementById('waf-log-filter')?.value||'';
  const filtered=f?_wafLog.filter(l=>l.type===f):_wafLog;
  renderWAFLog(filtered);
}

const _wafColors={SQLI:'var(--danger)',XSS:'#a78bfa',BAD_UA:'var(--ac3)',PATH_TRAVERSAL:'var(--warn)',CMD_INJECTION:'#f97316',FLOOD:'var(--ac)',BANNED_IP:'#64748b'};
const _wafLabels={SQLI:'💉 SQLi',XSS:'⚡ XSS',BAD_UA:'🤖 Bot',PATH_TRAVERSAL:'📁 Path',CMD_INJECTION:'💻 CMDi',FLOOD:'🌊 Flood',BANNED_IP:'🚫 Banned'};

function renderWAFLog(logs){
  const el=document.getElementById('waf-log-body');
  if(!el) return;
  if(!logs||logs.length===0){
    el.innerHTML='<div style="text-align:center;padding:30px;color:var(--ac3)">✅ No attack logs!</div>';
    return;
  }
  el.innerHTML='';
  logs.forEach(l=>{
    const div=document.createElement('div');
    div.style.cssText='background:var(--s2);border-radius:6px;padding:8px 10px;margin-bottom:6px;border-left:3px solid '+(_wafColors[l.type]||'var(--bd)');
    const color=_wafColors[l.type]||'var(--s2)';
    const label=_wafLabels[l.type]||l.type;
    const row=document.createElement('div');
    row.style.cssText='display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px';
    const badge=document.createElement('span');
    badge.style.cssText=`background:${color};color:#fff;border-radius:10px;padding:1px 8px;font-size:10px;font-weight:700`;
    badge.textContent=label;
    const ipEl=document.createElement('code');
    ipEl.style.cssText='color:var(--warn);font-size:11px';
    ipEl.textContent=l.ip;
    const timeEl=document.createElement('span');
    timeEl.style.cssText='color:var(--t2);font-size:10px';
    timeEl.textContent=l.time;
    const banBtn=document.createElement('button');
    banBtn.className='btn bsm';
    banBtn.style.cssText='padding:1px 6px;font-size:10px;background:var(--danger)';
    banBtn.textContent='Ban';
    banBtn.dataset.ip=l.ip; // safe data attribute
    banBtn.addEventListener('click',()=>quickBan(banBtn.dataset.ip));
    row.append(badge,ipEl,timeEl,banBtn);
    const detail=document.createElement('div');
    detail.style.cssText='color:var(--t2);font-size:11px;margin-top:4px;word-break:break-all';
    detail.textContent=l.detail; // textContent — safe
    const uriEl=document.createElement('div');
    uriEl.style.cssText='color:var(--t3,#475569);font-size:10px;margin-top:2px';
    uriEl.textContent=(l.method||'')+' '+(l.uri||'').substring(0,80);
    div.append(row,detail,uriEl);
    el.appendChild(div);
  });
}

async function loadBannedIPs(){
  const r=await api('waf_banned_ips','GET');
  if(!r.success) return;
  const ips=r.ips||[];
  const countEl=document.getElementById('waf-ban-count');
  if(countEl) countEl.textContent=`${ips.length}IPs blocked`;

  const el=document.getElementById('waf-banned-body');
  if(!el) return;
  if(ips.length===0){
    el.innerHTML='<div style="text-align:center;padding:30px;color:var(--t2)">No IPs blocked.</div>';
    return;
  }
  const active=ips.filter(i=>i.is_active);
  const expired=ips.filter(i=>!i.is_active);
  const rows=[...active,...expired];
  el.innerHTML='';
  rows.forEach(b=>{
    const div=document.createElement('div');
    div.style.cssText=`background:var(--s2);border-radius:8px;padding:10px;margin-bottom:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;opacity:${b.is_active?1:0.5}`;
    const info=document.createElement('div');
    info.style.cssText='flex:1;min-width:150px';
    const ipEl=document.createElement('div');
    ipEl.style.cssText=`font-family:monospace;color:${b.is_active?'var(--danger)':'var(--t2)'};font-weight:700`;
    ipEl.textContent=b.ip;
    const reasonEl=document.createElement('div');
    reasonEl.style.cssText='font-size:11px;color:var(--t2);margin-top:2px';
    reasonEl.textContent=b.reason||'—';
    const timeEl=document.createElement('div');
    timeEl.style.cssText='font-size:10px;color:var(--t2)';
    timeEl.textContent='⏱️ '+(b.expires_in||'')+'  Remaining · 🕐 '+new Date((b.banned_at||0)*1000).toLocaleString('bn-BD');
    info.append(ipEl,reasonEl,timeEl);
    const badge=document.createElement('span');
    badge.style.cssText=`background:${b.is_active?'rgba(239,68,68,.2)':'rgba(100,116,139,.2)'};color:${b.is_active?'var(--danger)':'var(--t2)'};border-radius:20px;padding:2px 10px;font-size:11px`;
    badge.textContent=b.is_active?'🚫 Active':'✅ Expired';
    div.append(info,badge);
    if(b.is_active){
      const btn=document.createElement('button');
      btn.className='btn bsm';btn.style.cssText='background:var(--ac3);padding:4px 10px';
      btn.textContent='Unban';
      btn.dataset.ip=b.ip; // safe data attribute — no XSS
      btn.addEventListener('click',()=>doUnban(btn.dataset.ip));
      div.appendChild(btn);
    }
    el.appendChild(div);
  });
}

async function doBanIP(){
  const ip=document.getElementById('ban-ip')?.value?.trim();
  const reason=document.getElementById('ban-reason')?.value||'Manual ban';
  const duration=parseInt(document.getElementById('ban-dur')?.value||'86400');
  if(!ip){toast('❌ IP days','var(--danger)');return;}
  const r=await api('waf_ban_ip','POST',{ip,reason,duration});
  const msg=document.getElementById('ban-msg');
  if(r.success){
    if(msg) msg.innerHTML=`<div style="background:rgba(16,185,129,.15);border:1px solid var(--ac3);border-radius:8px;padding:10px;color:var(--ac3)">✅ ${h(r.message)}</div>`;
    toast('🔨 '+r.message);
    loadWAFStats();
  } else {
    if(msg) msg.innerHTML=`<div style="background:rgba(239,68,68,.15);border:1px solid var(--danger);border-radius:8px;padding:10px;color:var(--danger)">❌ ${h(r.error||'Error')}</div>`;
  }
}

async function doUnban(ip){
  if(!confirm(`${ip} Unban this IP?`)) return;
  const r=await api('waf_unban_ip','POST',{ip});
  if(r.success){ toast('✅ '+r.message); loadBannedIPs(); loadWAFStats(); }
  else toast('❌ '+(r.error||'Failed'),'var(--danger)');
}

function quickBan(ip){
  wafTab('ban');
  const el=document.getElementById('ban-ip');
  if(el) el.value=ip;
  toast('⚡ IP has been set in field — confirm to Ban');
}
