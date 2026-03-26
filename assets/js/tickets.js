// ═══════════════════════════════════════════
// Tickets module
// WP Sales Pro v5 — Module: tickets.js
// ═══════════════════════════════════════════

// ══════════════════════════════════════════════════════
// AUTO MESSAGE SYSTEM
// ══════════════════════════════════════════════════════
let _msgData = {}; // stores current modal data

function openMsgModal(clientName, productName, siteUrl, daysLeft, waNumber, fbLink) {
  _msgData = { clientName, productName, siteUrl, daysLeft, waNumber, fbLink };

  // Avatar
  const av = document.getElementById('msg-avatar');
  av.textContent = (clientName||'?').charAt(0).toUpperCase();

  // Client info
  document.getElementById('msg-client-name').textContent = clientName || '—';
  const siteClean = (siteUrl||'').replace(/^https?:\/\//,'').replace(/\/$/,'').substring(0,35);
  document.getElementById('msg-product-info').textContent =
    (productName||'') + (siteClean ? ' · ' + siteClean : '');

  // Expiry badge
  const badge = document.getElementById('msg-expiry-badge');
  const days = parseInt(daysLeft);
  if (isNaN(days) || days > 30) {
    badge.style.display = 'none';
  } else {
    badge.style.display = 'block';
    const isExpired = days <= 0;
    const isUrgent  = days <= 7 && days > 0;
    badge.style.background = isExpired ? 'rgba(239,68,68,.15)' : isUrgent ? 'rgba(239,68,68,.1)' : 'rgba(245,158,11,.1)';
    badge.style.border = isExpired ? '1px solid rgba(239,68,68,.3)' : isUrgent ? '1px solid rgba(239,68,68,.25)' : '1px solid rgba(245,158,11,.2)';
    badge.style.color = isExpired ? '#f87171' : isUrgent ? '#fca5a5' : '#fbbf24';
    badge.textContent = isExpired ? 'মেয়াদ শেষ' : days + ' দিন বাকি';
  }

  // Auto-generate message
  const msgText = buildAutoMsg(clientName, productName, siteUrl, daysLeft);
  document.getElementById('msg-text').value = msgText;

  // Show/hide buttons based on available contact
  const hasWA = !!waNumber;
  const hasFB = !!fbLink;
  document.getElementById('msg-wa-btn').style.display = hasWA ? '' : 'none';
  document.getElementById('msg-fb-btn').style.display = hasFB ? '' : 'none';
  document.getElementById('msg-no-contact').style.display = (!hasWA && !hasFB) ? '' : 'none';

  om('msg');
}

function buildAutoMsg(clientName, productName, siteUrl, daysLeft) {
  const name    = clientName || 'ভাই/আপু';
  const product = productName || 'আপনার পণ্যটি';
  const site    = (siteUrl||'').replace(/^https?:\/\//,'').replace(/\/$/,'');
  const siteText = site ? ` (${site})` : '';
  const days    = parseInt(daysLeft);

  let subject = '', body = '', closing = '';

  if (isNaN(days) || days > 30) {
    subject = `আপনার *${product}*${siteText} এর মেয়াদ শীঘ্রই শেষ হবে।`;
    body    = `সময়মতো নবায়ন করলে আপনার সাইট কোনো বাধা ছাড়াই চলতে থাকবে।`;
    closing = `নবায়নের জন্য আমাদের সাথে যোগাযোগ করুন।`;
  } else if (days <= 0) {
    subject = `⚠️ আপনার *${product}*${siteText} এর মেয়াদ *শেষ হয়ে গেছে।*`;
    body    = `এখনই নবায়ন না করলে আপনার সাইটের সেবা বন্ধ থাকবে। দ্রুত পদক্ষেপ নিন।`;
    closing = `আজই যোগাযোগ করুন, আমরা সাথে সাথে সমাধান দিতে প্রস্তুত আছি।`;
  } else if (days <= 7) {
    subject = `🔴 আপনার *${product}*${siteText} এর মেয়াদ মাত্র *${days} দিন* পরে শেষ হচ্ছে!`;
    body    = `এই সময়ের মধ্যে নবায়ন না করলে আপনার সাইটে সমস্যা হতে পারে।`;
    closing = `দ্রুত যোগাযোগ করুন, আমরা তৎক্ষণাৎ সাহায্য করব।`;
  } else {
    subject = `আপনার *${product}*${siteText} এর মেয়াদ *${days} দিন* পরে শেষ হবে।`;
    body    = `সময়মতো নবায়ন করলে সেবা অব্যাহত থাকবে এবং কোনো ঝামেলায় পড়তে হবে না।`;
    closing = `যোগাযোগ করুন, আমরা সাহায্য করতে প্রস্তুত।`;
  }

  return `প্রিয় ${name},\n\n${subject}\n\n${body}\n\n${closing}\n\nধন্যবাদ 🙏`;
}

function sendMsgWA() {
  const msg = encodeURIComponent(document.getElementById('msg-text').value);
  const num = (_msgData.waNumber||'').replace(/\D/g,'');
  if (!num) { toast('WhatsApp নম্বর পাওয়া যায়নি', 'var(--danger)'); return; }
  window.open(`https://wa.me/${num}?text=${msg}`, '_blank');
  cm('msg');
}

function sendMsgFB() {
  const fb = _msgData.fbLink || '';
  if (!fb) { toast('Facebook লিংক পাওয়া যায়নি', 'var(--danger)'); return; }
  const msg = document.getElementById('msg-text').value;
  // Copy to clipboard — try modern API first, fallback to execCommand
  const doOpen = () => { window.open(fb, '_blank'); cm('msg'); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(msg)
      .then(() => { toast('📋 বার্তা কপি হয়েছে! Facebook খুলছে — Ctrl+V দিয়ে পেস্ট করুন', 'var(--ac2)'); doOpen(); })
      .catch(() => {
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = msg; ta.style.position='fixed'; ta.style.opacity='0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); toast('📋 বার্তা কপি হয়েছে! Facebook-এ পেস্ট করুন', 'var(--ac2)'); } catch(e){}
        document.body.removeChild(ta);
        doOpen();
      });
  } else {
    const ta = document.createElement('textarea');
    ta.value = msg; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); toast('📋 বার্তা কপি হয়েছে! Facebook-এ পেস্ট করুন', 'var(--ac2)'); } catch(e){}
    document.body.removeChild(ta);
    doOpen();
  }
}

// RENEW
async function openRenewModal(id){
  // B09 Fix: Always fetch fresh data — avoids stale cache showing wrong price
  const r = await api('get_sale','GET',null,{id});
  if(r && r.id){
    openRenew(r.id,
      (r.client_name||'').replace(/'/g,"\\'").replace(/</g,'&lt;'),
      (r.product_name||'').replace(/'/g,"\\'").replace(/</g,'&lt;'),
      r.price||0);
  } else {
    // Fallback to cache if API fails
    const s=_s.find(x=>x.id==id);
    if(s&&s.id) openRenew(s.id,(s.client_name||'').replace(/'/g,"\\'").replace(/</g,'&lt;'),(s.product_name||'').replace(/'/g,"\\'").replace(/</g,'&lt;'),s.price||0);
    else toast('❌ Sale তথ্য লোড হয়নি','var(--danger)');
  }
}
function openRenew(id,client,product,price){document.getElementById('rn-id').value=id;document.getElementById('rn-info').innerHTML=`<b>${h(client)}</b> · ${h(product)}`;document.getElementById('rn-pr').value=price;const ny=new Date();ny.setFullYear(ny.getFullYear()+1);document.getElementById('rn-ex').value=ny.toISOString().split('T')[0];om('rn');}
async function confirmRenew(){
  const btn=document.querySelector('#m-rn .btn.bs,#m-rn .btn.bp');
  const orig=btn?btn.innerHTML:'';
  if(btn){btn.disabled=true;btn.innerHTML='⏳...';}
  try{
    const r=await api('mark_renewed','POST',{id:parseInt(document.getElementById('rn-id').value),new_expiry:document.getElementById('rn-ex').value,new_price:parseFloat(document.getElementById('rn-pr').value)||0});
    if(r.success){toast('✅ '+r.message);cm('rn');loadNotify();loadDashboard();}else toast('❌ '+r.error,'var(--danger)');
  }finally{if(btn){btn.disabled=false;btn.innerHTML=orig;}}
}

