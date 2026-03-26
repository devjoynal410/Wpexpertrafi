// ═══════════════════════════════════════════
// Products & Promos module
// WP Sales Pro v5 — Module: products.js
// ═══════════════════════════════════════════

// ══════════════════════════════════════════════════════════
let _clTimer = null;
function clientAjaxSearch(val) {
  const dd = document.getElementById('s-cl-dropdown');
  const spin = document.getElementById('s-cl-spin');
  // If user is typing new text, clear hidden value
  document.getElementById('s-cl').value = '';
  document.getElementById('s-cl-lbl').style.display = 'none';
  document.getElementById('s-cl-clear').style.display = 'none';
  clearTimeout(_clTimer);
  if (!val || val.length < 1) { dd.style.display = 'none'; dd.innerHTML = ''; return; }
  spin.style.display = 'inline';
  _clTimer = setTimeout(async () => {
    const r = await api('get_clients', 'GET', null, { q: val });
    spin.style.display = 'none';
    const clients = r.data || [];
    if (!clients.length) {
      dd.innerHTML = '<div style="padding:14px 16px;font-size:12px;color:var(--t3);text-align:center">কোনো ক্লায়েন্ট পাওয়া যায়নি</div>';
      dd.style.display = 'block';
      return;
    }
    dd.innerHTML = clients.map(c => {
      const info = [c.phone, c.whatsapp ? '💬 '+c.whatsapp : '', c.email, c.facebook ? '👤 FB' : ''].filter(Boolean).join(' · ');
      return `<div class="cl-ajax-item" data-cid="${c.id}" data-cname="${h(c.name)}"
        style="padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.05);transition:background .15s"
        onmouseover="this.style.background='rgba(99,102,241,.1)'" onmouseout="this.style.background=''">
        <div style="display:flex;align-items:center;gap:10px;pointer-events:none">
          <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">${h(c.name.charAt(0).toUpperCase())}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13px;color:var(--t1)">${h(c.name)}</div>
            <div style="font-size:11px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${info}</div>
          </div>
        </div>
      </div>`;
    }).join('');
    dd.style.display = 'block';
  }, 280);
}
function selectClientFromSearch(id, name) {
  document.getElementById('s-cl').value = id;
  document.getElementById('s-cl-search').value = name;
  document.getElementById('s-cl-lbl').style.display = 'inline';
  document.getElementById('s-cl-clear').style.display = 'inline';
  document.getElementById('s-cl-dropdown').style.display = 'none';
}
function clearClientSelection() {
  document.getElementById('s-cl').value = '';
  document.getElementById('s-cl-search').value = '';
  document.getElementById('s-cl-lbl').style.display = 'none';
  document.getElementById('s-cl-clear').style.display = 'none';
  document.getElementById('s-cl-dropdown').style.display = 'none';
  document.getElementById('s-cl-search').focus();
}
// Dropdown — mousedown এ selection (blur এর আগে fire হয়, তাই dropdown লুকায় না)
document.addEventListener('mousedown', function(e) {
  const dd = document.getElementById('s-cl-dropdown');
  if (!dd) return;
  const item = e.target.closest('.cl-ajax-item');
  if (item && dd.contains(item)) {
    e.preventDefault(); // blur prevent করে
    const cid = item.dataset.cid;
    const cname = item.dataset.cname;
    if (cid) selectClientFromSearch(parseInt(cid), cname);
    return;
  }
});
// Dropdown বাইরে click করলে বন্ধ হবে
document.addEventListener('click', function(e) {
  const dd = document.getElementById('s-cl-dropdown');
  const wrap = document.getElementById('s-cl-search');
  if (!dd) return;
  if (wrap && !dd.contains(e.target) && e.target !== wrap) {
    dd.style.display = 'none';
  }
});

async function saveProduct(){
  const _saveBtn=document.querySelector('#m-pd .btn.bp');
  if(_saveBtn&&_saveBtn.disabled)return;
  if(_saveBtn){_saveBtn.disabled=true;_saveBtn.innerHTML='⏳ সংরক্ষণ...';}
  const _restoreBtn=()=>{if(_saveBtn){_saveBtn.disabled=false;_saveBtn.innerHTML='💾 Save';}};
  try {
    const id=document.getElementById('pd-id').value;
    const name=document.getElementById('pd-nm').value.trim();
    const price=parseFloat(document.getElementById('pd-pr').value);
    // ── Validation ──────────────────────────────────────────
    if(!name){toast('❌ Product name is required.','var(--danger)');document.getElementById('pd-nm').focus();_restoreBtn();return;}
    if(isNaN(price)||price<0){toast('❌ Enter a valid price (0 or more).','var(--danger)');document.getElementById('pd-pr').focus();_restoreBtn();return;}
    // ────────────────────────────────────────────────────────
    const body={id:id?parseInt(id):null,name:name,type:document.getElementById('pd-tp').value,price:price,version:document.getElementById('pd-vr').value||'1.0.0',description:document.getElementById('pd-dc').value};
    const _btn=document.querySelector('#m-pd .btn.bp,#m-pd .btn.bs');
    const _orig=_btn?_btn.innerHTML:'';
    if(_btn){_btn.disabled=true;_btn.innerHTML='⏳...';}
    const r=await api(id?'update_product':'add_product','POST',body);
    if(_btn){_btn.disabled=false;_btn.innerHTML=_orig;}
    if(r.success){toast('✅ '+r.message);cm('pd');_p=[];loadProducts();}
    else toast('❌ '+r.error,'var(--danger)');
  } catch(e){toast('❌ '+e.message,'var(--danger)');
  } finally { _restoreBtn(); }
}
async function delProduct(id){
  if(!confirm('এই product মুছে ফেলবেন?'))return;
  const r=await api('delete_product','POST',{id});
  if(r.success){toast('🗑️ '+r.message,'var(--danger)');_p=[];loadProducts();}
  else toast('❌ '+r.error,'var(--danger)');
}

// PROMOS
async function loadPromos(){const r=await api('get_promos');if(!r.success)return;document.getElementById('promo-grid').innerHTML=(r.data||[]).map(p=>`<div class="prc ${p.is_active?'active':'inactive'}">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px">
    <div><div style="font-size:16px;font-weight:800;font-family:'JetBrains Mono';color:var(--ac3)">${h(p.code||'—')}</div><div style="font-size:11px;color:var(--t3)">${h(p.description||'')}</div></div>
    ${p.is_active?'<span class="tag t-on">Active</span>':'<span class="tag t-off">Inactive</span>'}
  </div>
  <div style="font-size:22px;font-weight:700;margin-bottom:6px">${p.type==='percent'?p.value+'%':fmt(p.value)} <span style="font-size:12px;color:var(--t3)">Discount</span></div>
  <div style="font-size:11px;color:var(--t3);margin-bottom:8px">
    ${p.min_amount>0?`Min ${fmt(p.min_amount)} · `:''}
    Usage: ${h(p.used_count||"")}${p.max_uses?'/'+p.max_uses:''}
    ${p.valid_until?` · Until: ${fmtD(p.valid_until)}`:''}
  </div>
  <div style="display:flex;gap:5px">${canEdit()?`<button class="btn bg bsm" onclick="editPromo(${p.id})">✏️</button>`:''}${isSup()?`<button class="btn bg bsm" onclick="delPromo(${p.id})" style="color:var(--danger)">🗑</button>`:''}</div>
</div>`).join('')||'<div class="empty" style="grid-column:span 3"><div class="empty-ico">🎟️</div>No promo codes</div>';}
function openPromoModal(){['id','cd','vl','mn','mx','vf','vu','dc'].forEach(k=>{const el=document.getElementById('pr-'+k);if(el)el.value='';});document.getElementById('pr-tp').value='percent';document.getElementById('pr-ac').value='1';document.getElementById('pr-ttl').textContent='🎟️ New Promo Code';om('pr');}
function editPromo(id){
  api('get_promos').then(r=>{
    const pro=(r.data||[]).find(x=>x.id==id);if(!pro)return;
    document.getElementById('pr-id').value=pro.id||'';
    document.getElementById('pr-cd').value=pro.code||'';
    document.getElementById('pr-vl').value=pro.value||'';
    document.getElementById('pr-mn').value=pro.min_amount||0;
    document.getElementById('pr-mx').value=pro.max_uses||'';
    document.getElementById('pr-vf').value=pro.valid_from||'';
    document.getElementById('pr-vu').value=pro.valid_until||'';
    document.getElementById('pr-dc').value=pro.description||'';
    document.getElementById('pr-tp').value=pro.type||'percent';
    document.getElementById('pr-ac').value=pro.is_active?'1':'0';
    document.getElementById('pr-ttl').textContent='✏️ Edit Promo';
    om('pr');
  });
}
async function savePromo(){
  const id=document.getElementById('pr-id').value;
  const body={id:id?parseInt(id):null,code:document.getElementById('pr-cd').value,type:document.getElementById('pr-tp').value,value:parseFloat(document.getElementById('pr-vl').value),min_amount:parseFloat(document.getElementById('pr-mn').value)||0,max_uses:parseInt(document.getElementById('pr-mx').value)||0,valid_from:document.getElementById('pr-vf').value||null,valid_until:document.getElementById('pr-vu').value||null,is_active:document.getElementById('pr-ac').value,description:document.getElementById('pr-dc').value};
  const btn=document.querySelector('#m-pr .btn.bp'); const orig=btn?btn.innerHTML:'';
  if(btn){btn.disabled=true;btn.innerHTML='⏳...';}
  const r=await api(id?'update_promo':'add_promo','POST',body);
  if(btn){btn.disabled=false;btn.innerHTML=orig;}
  if(r.success){toast('✅ '+r.message);cm('pr');loadPromos();}else toast('❌ '+r.error,'var(--danger)');
}
async function delPromo(id){if(!confirm('Delete?'))return;const r=await api('delete_promo','POST',{id});if(r.success){toast('🗑️ '+r.message,'var(--danger)');loadPromos();}else toast('❌ '+r.error,'var(--danger)');}

// TICKETS
let _curTicket=null;
async function loadTickets(){
  const r=await api('get_tickets','GET',null,{q:document.getElementById('tq')?.value||'',status:document.getElementById('tfs')?.value||'',priority:document.getElementById('tfp')?.value||''});
  if(!r.success)return;
  _tkAll=r.data||[]; _tkPage=1; renderTickets(1);
}
async function viewTicket(id){
  const r=await api('get_ticket','GET',null,{id});if(!r.id)return;_curTicket=r;
  document.getElementById('tkd-ttl').textContent='🎫 '+(r.ticket_no||'')+' — '+(r.subject||'');
  document.getElementById('tkd-st').value=r.status||'open';
  document.getElementById('tkd-info').innerHTML=`<b>${h(r.client_name||'—')}</b> ${tPri(r.priority)} ${tSt(r.status)} <br><span style="font-size:11px;color:var(--t3)">${r.sale_id?'Sale #'+r.sale_id+' · ':''} ${fmtD(r.created_at?.split(' ')[0])}</span>`;
  document.getElementById('tkd-msgs').innerHTML=(r.replies||[]).map(msg=>`<div class="msg-bubble ${msg.sender==='admin'?'msg-admin':'msg-client'}" style="${msg.sender==='admin'?'':''}"><div style="font-size:10px;color:var(--t3);margin-bottom:3px">${msg.sender==='admin'?'👨‍💼 Admin':'👤 Client'} · ${fmtD(msg.created_at?.split(' ')[0])}</div><div style="font-size:12px">${h(msg.message)}</div></div>`).join('')||'<div style="color:var(--t3);text-align:center;font-size:12px">No messages</div>';
  om('tkd');
}
async function updateTicketStatus(){
  if(!_curTicket)return;
  const st=document.getElementById('tkd-st').value;
  const r=await api('update_ticket','POST',{id:_curTicket.id,status:st,priority:_curTicket.priority,subject:_curTicket.subject});
  if(r.success)toast('✅ Status updated');else toast('❌ '+r.error,'var(--danger)');
}
async function delTicket(id){
  if(!confirm('Delete this ticket?'))return;
  const r=await api('delete_ticket','POST',{id});
  if(r.success){toast('✅ Ticket deleted');loadTickets();}else toast('❌ '+r.error,'var(--danger)');
}
async function sendTicketReply(){
  if(!_curTicket)return;
  const msg=document.getElementById('tkd-rep').value.trim();if(!msg)return;
  const btn=document.querySelector('#m-tkd .btn.bs,#m-tkd .btn.bp,[onclick*="sendTicketReply"]');
  const orig=btn?btn.innerHTML:'';
  if(btn){btn.disabled=true;btn.innerHTML='⏳...';}
  try{
    const r=await api('add_ticket_msg','POST',{ticket_id:_curTicket.id,message:msg,sender:'admin'});
    if(r.success){document.getElementById('tkd-rep').value='';viewTicket(_curTicket.id);toast('✅ Message sent');}else toast('❌ '+r.error,'var(--danger)');
  }finally{if(btn){btn.disabled=false;btn.innerHTML=orig;}}
}
async function openTicketModal(clientId=null){
  if(!_c.length){const cr=await api('get_clients');_c=cr.data||[];}
  const cSel=document.getElementById('tk-cl');
  cSel.innerHTML='<option value="">Select...</option>';
  _c.forEach(c=>cSel.innerHTML+=`<option value="${c.id}" ${clientId==c.id?'selected':''}>${h(c.name)}</option>`);
  document.getElementById('tk-id').value='';
  document.getElementById('tk-sb').value='';
  document.getElementById('tk-ms').value='';
  document.getElementById('tk-pr').value='medium';
  document.getElementById('tk-st').value='open';
  document.getElementById('tk-sl').innerHTML='<option value="">None</option>';
  document.getElementById('tk-ttl').textContent='🎫 New Ticket';
  // If client selected, load their sales
  if(clientId) loadClientSalesForTicket(clientId);
  om('tk');
}
async function loadClientSalesForTicket(clientId){
  if(!clientId)return;
  const r=await api('get_sales','GET',null,{client_id:clientId});
  const sel=document.getElementById('tk-sl');
  sel.innerHTML='<option value="">None</option>';
  (r.data||[]).forEach(s=>sel.innerHTML+=`<option value="${s.id}">${h(s.invoice_no||'INV')} - ${h(s.product_name)}</option>`);
}
async function saveTicket(){
  const id=document.getElementById('tk-id').value;
  const body={id:id?parseInt(id):null,client_id:parseInt(document.getElementById('tk-cl').value),sale_id:parseInt(document.getElementById('tk-sl').value)||null,subject:document.getElementById('tk-sb').value.trim(),priority:document.getElementById('tk-pr').value,status:document.getElementById('tk-st').value,message:document.getElementById('tk-ms').value};
  if(!body.subject){toast('❌ Subject লিখুন','var(--danger)');return;}
  const btn=document.querySelector('#m-tk .btn.bp'); const orig=btn?btn.innerHTML:'';
  if(btn){btn.disabled=true;btn.innerHTML='⏳...';}
  const r=await api(id?'update_ticket':'add_ticket','POST',body);
  if(btn){btn.disabled=false;btn.innerHTML=orig;}
  if(r.success){toast('✅ '+r.message);cm('tk');loadTickets();loadDashboard();}else toast('❌ '+r.error,'var(--danger)');
}

// TASKS
async function loadTasks(){
  const r=await api('get_tasks');if(!r.success)return;
  const all=r.data||[];
  const pend=all.filter(t=>t.status!=='done'&&t.status!=='cancelled');
  const done=all.filter(t=>t.status==='done');
  const over=pend.filter(t=>t.due_date&&new Date(t.due_date)<new Date());
  document.getElementById('t-pend').textContent=pend.length;
  document.getElementById('t-over').textContent=over.length;
  document.getElementById('t-done').textContent=done.length;
  const taskCard=(t)=>`<div class="task-card">
    <div class="task-check ${t.status==='done'?'done':''}" onclick="toggleTask(${t.id})">${t.status==='done'?'✓':''}</div>
    <div style="flex:1;min-width:0">
      <div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(t.title||"—")}</div>
      <div style="font-size:10px;color:var(--t3);margin-top:2px">${t.due_date?'📅 '+fmtD(t.due_date.split(' ')[0]):''} ${t.client_name?'· 👤 '+h(t.client_name):''}</div>
    </div>
    <div style="display:flex;gap:3px;flex-shrink:0">${tPri(t.priority)}${canEdit()?`<button class="btn bg bxs" onclick="openEditTask(${t.id})">✏️</button><button class="btn bg bxs" onclick="delTask(${t.id})" style="color:var(--danger)">🗑</button>`:''}</div>
  </div>`;
  _tpAll=pend; _tdAll=done; _tpPage=1; _tdPage=1;
  renderTasksPend(1); renderTasksDone(1);
}
async function toggleTask(id){const r=await api('toggle_task','POST',{id});if(r.success){toast(r.message);loadTasks();loadDashboard();}else toast('❌ '+r.error,'var(--danger)');}
async function openTaskModal(clientId=null){
  if(!_c.length){const cr=await api('get_clients');_c=cr.data||[];}
  if(!_admins.length){const ar=await api('get_admins');_admins=ar.data||[];}
  const cSel=document.getElementById('ta-cl');cSel.innerHTML='<option value="">None</option>';_c.forEach(c=>cSel.innerHTML+=`<option value="${c.id}" ${clientId==c.id?'selected':''}>${h(c.name)}</option>`);
  const aSel=document.getElementById('ta-as');aSel.innerHTML='<option value="">Self</option>';_admins.forEach(a=>aSel.innerHTML+=`<option value="${a.id}">${h(a.full_name||a.username)}</option>`);
  document.getElementById('ta-id').value='';document.getElementById('ta-ti').value='';document.getElementById('ta-dc').value='';populateSelect('ta-pr',_CFG.taskPriority,'medium');const _tast=document.getElementById('ta-st');if(_tast)_tast.value='pending';document.getElementById('ta-ttl').textContent='✅ New Task';om('ta');
}
async function openEditTask(id){
  const r=await api('get_tasks');if(!r.success)return;
  const t=(r.data||[]).find(x=>x.id==id);if(!t)return;
  if(!_c.length){const cr=await api('get_clients');_c=cr.data||[];}
  if(!_admins.length){const ar=await api('get_admins');_admins=ar.data||[];}
  const cSel=document.getElementById('ta-cl');cSel.innerHTML='<option value="">\u09a8\u09c7\u0987</option>';_c.forEach(c=>cSel.innerHTML+=`<option value="${c.id}" ${t.client_id==c.id?'selected':''}>${h(c.name)}</option>`);
  const aSel=document.getElementById('ta-as');aSel.innerHTML='<option value="">\u09a8\u09bf\u099c\u09c7</option>';_admins.forEach(a=>aSel.innerHTML+=`<option value="${a.id}" ${t.assigned_to==a.id?'selected':''}>${h(a.full_name||a.username)}</option>`);
  document.getElementById('ta-id').value=t.id;
  document.getElementById('ta-ti').value=t.title||'';
  document.getElementById('ta-dc').value=t.description||'';
  document.getElementById('ta-pr').value=t.priority||'medium';
  document.getElementById('ta-dd').value=t.due_date?t.due_date.split(' ')[0]:'';
  document.getElementById('ta-ttl').textContent='✏️ Edit Task';
  const _tast2=document.getElementById('ta-st');if(_tast2)_tast2.value=t.status||'pending';
  om('ta');
}
async function saveTask(){
  const id=document.getElementById('ta-id').value;
  const body={id:id?parseInt(id):null,title:document.getElementById('ta-ti').value.trim(),description:document.getElementById('ta-dc').value,client_id:parseInt(document.getElementById('ta-cl').value)||null,priority:document.getElementById('ta-pr').value,status:document.getElementById('ta-st')?.value||'pending',due_date:document.getElementById('ta-dd').value||null,assigned_to:parseInt(document.getElementById('ta-as').value)||null};
  if(!body.title){toast('❌ Title লিখুন','var(--danger)');return;}
  const btn=document.querySelector('#m-ta .btn.bp'); const orig=btn?btn.innerHTML:'';
  if(btn){btn.disabled=true;btn.innerHTML='⏳...';}
  const r=await api(id?'update_task':'add_task','POST',body);
  if(btn){btn.disabled=false;btn.innerHTML=orig;}
  if(r.success){toast('✅ '+r.message);cm('ta');loadTasks();loadDashboard();}else toast('❌ '+r.error,'var(--danger)');
}
async function delTask(id){if(!confirm('Delete?'))return;const r=await api('delete_task','POST',{id});if(r.success){toast('🗑️ '+r.message,'var(--danger)');loadTasks();}else toast('❌ '+r.error,'var(--danger)');}

// SMS LOG
async function loadSMSLog(){
  const r=await api('get_sms_log');
  const tb=document.getElementById('sms-log-tb');
  if(!tb)return;
  if(!r.success){tb.innerHTML='<tr><td colspan="5" style="text-align:center;color:var(--danger)">Load failed</td></tr>';return;}
  const rows=(r.data||[]);
  _smsAll=rows; _smsPage=1; renderSMSLog(1);
}

// REMINDERS
async function loadReminders(){
  const r=await api('get_reminder_log');if(!r.success)return;
  _remAll=r.data||[]; _remPage=1; renderReminders(1);
}
async function markSent(id){const r=await api('send_reminder','POST',{reminder_id:id,mark_sent:true});if(r.success){toast('✅ Reminder marked as complete');loadReminders();}else toast('❌ '+r.error,'var(--danger)');}
async function openReminderModal(){
  if(!_c.length){const cr=await api('get_clients');_c=cr.data||[];}
  const sel=document.getElementById('rem-cl');sel.innerHTML='<option value="">Select Client...</option>';_c.forEach(c=>sel.innerHTML+=`<option value="${c.id}">${h(c.name)}</option>`);
  populateSelect('rem-ch',_CFG.reminderChannel,'whatsapp');
  document.getElementById('rem-dt').value=new Date().toISOString().slice(0,16);
  document.getElementById('rem-msg').value='Dear Customer,\n\nYour license is about to expire. Please contact us to renew.\n\nThank you.';
  om('rem');
}
async function sendReminder(){
  const r=await api('send_reminder','POST',{client_id:parseInt(document.getElementById('rem-cl').value),channel:document.getElementById('rem-ch').value,message:document.getElementById('rem-msg').value,scheduled_at:document.getElementById('rem-dt').value});
  if(r.success){toast('✅ '+r.message);cm('rem');loadReminders();}else toast('❌ '+r.error,'var(--danger)');
}
async function autoSchedule(){
  const r=await api('get_expiring');if(!r.success)return;
  const all=[...(r.expiring_soon||[]),...(r.already_expired||[])];
  if(!all.length){toast('No expired sales.','var(--warn)');return;}
  let cnt=0;
  for(const s of all){
    await api('send_reminder','POST',{client_id:s.client_id,sale_id:s.id,channel:'whatsapp',message:`Dear ${(s.client_name||"").replace(/`/g,"'")},\n\n"${(s.product_name||"").replace(/`/g,"'")}" License ${s.expiry_date} has expired${parseInt(s.days_left)<0?' (past due)':' (upcoming)'}।\n\nPlease renew.`,scheduled_at:new Date().toISOString().slice(0,16),type:'renewal'});
    cnt++;
  }
  toast(`✅ ${cnt} Reminders scheduled.`);loadReminders();
}

