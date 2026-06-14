// ══════════════════════════════════════════════
// DATA
// ══════════════════════════════════════════════
const MATCH_HISTORY = [
  { result:'win',  map:'🌻 Sunflower Fields', mode:'ranked', kda:'18/4/9',  time:'12 min ago', enemy:'ZombieKing' },
  { result:'loss', map:'🧟 Graveyard Rush',   mode:'ranked', kda:'9/11/6',  time:'34 min ago', enemy:'NightCrawler99' },
  { result:'win',  map:'🌙 Night Defense',    mode:'casual', kda:'22/6/14', time:'1h ago',      enemy:'SunDropper' },
  { result:'win',  map:'🌊 Tidal Siege',      mode:'casual', kda:'15/5/11', time:'2h ago',      enemy:'GraveDigger' },
  { result:'draw', map:'🌻 Sunflower Fields', mode:'ranked', kda:'11/11/8', time:'3h ago',      enemy:'BrainEater42' },
];

const FRIENDS = [
  { name:'ZombieSlayer',  avatar:'🧟', status:'online',   statusText:'In lobby',         color:'#52b788' },
  { name:'SunDropper',    avatar:'☀️', status:'in-game',  statusText:'Playing Ranked',   color:'#ffd166' },
  { name:'PeaShooterPro', avatar:'🌿', status:'online',   statusText:'Online',            color:'#52b788' },
  { name:'GardenGuard',   avatar:'🌻', status:'in-game',  statusText:'Playing Casual',   color:'#ffd166' },
  { name:'NightCrawler',  avatar:'🌙', status:'offline',  statusText:'2 hours ago',      color:'' },
  { name:'BrainEater',    avatar:'💀', status:'offline',  statusText:'Yesterday',        color:'' },
  { name:'ThornyRose',    avatar:'🌹', status:'offline',  statusText:'3 days ago',       color:'' },
];

const LEADERBOARD = [
  { rank:1,  name:'ZombieKing',    score:'4,820', badge:'🏆' },
  { rank:2,  name:'PlantLord',     score:'4,615', badge:'🥈' },
  { rank:3,  name:'SunGod99',      score:'4,490', badge:'🥉' },
  { rank:4,  name:'NightReaper',   score:'4,320', badge:'' },
  { rank:5,  name:'GardenGuard',   score:'4,210', badge:'' },
  { rank:6,  name:'BrainMuncher',  score:'4,100', badge:'' },
  { rank:7,  name:'PlantMaster',   score:'3,980', badge:'🌻 You', highlight:true },
  { rank:8,  name:'ThornyRose',    score:'3,870', badge:'' },
  { rank:9,  name:'PeaShooterPro', score:'3,740', badge:'' },
  { rank:10, name:'ZombieSlayer',  score:'3,610', badge:'' },
];

const CHAT_SEED = [
  { user:'ZombieKing',   color:'purple', msg:'anyone want to 2v2? 🧟' },
  { user:'SunDropper',   color:'yellow', msg:'gg last match! those ranked queues are brutal' },
  { user:'GardenGuard',  color:'green',  msg:'Night Defense is 🔥 right now' },
  { user:'PlantMaster',  color:'orange', msg:'finally hit Diamond! took long enough 🌻' },
  { user:'NightCrawler', color:'purple', msg:'who buffed the zombie horde?? 😭' },
  { user:'PeaShooterPro',color:'green',  msg:'tip: stack sunflowers on left lane' },
  { user:'BrainEater42', color:'yellow', msg:'leaderboard race is getting tight at top 5' },
];

// ══════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  buildBgParticles();
  buildBattlefield();
  buildMatchHistory();
  buildFriends();
  buildMiniLeaderboard();
  buildFullLeaderboard();
  seedChat();
  startOnlineCounter();
  animateXP();
  startCoinFlicker();
  // Close modals on outside click
  document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
  });
});

// ══════════════════════════════════════════════
// BG PARTICLES
// ══════════════════════════════════════════════
function buildBgParticles() {
  const cont = document.getElementById('bg-particles');
  const colors = ['#2d6a4f','#7b2cbf','#ffd166','#52b788','#1a1a2e'];
  for (let i = 0; i < 22; i++) {
    const el = document.createElement('div');
    const size = Math.random() * 120 + 40;
    el.className = 'bp';
    el.style.cssText = `
      left:${Math.random()*100}%;
      width:${size}px; height:${size}px;
      background:${colors[Math.floor(Math.random()*colors.length)]};
      animation-duration:${Math.random()*20+18}s;
      animation-delay:-${Math.random()*20}s;
    `;
    cont.appendChild(el);
  }
}

// ══════════════════════════════════════════════
// BATTLEFIELD STRIP
// ══════════════════════════════════════════════
function buildBattlefield() {
  const bf = document.getElementById('battlefield');
  const entities = [
    { em:'🌱', delay:0, dur:22 }, { em:'🌻', delay:3, dur:28 },
    { em:'🧟', delay:7, dur:18 }, { em:'🌿', delay:12, dur:25 },
    { em:'🌻', delay:16, dur:32 }, { em:'💀', delay:20, dur:20 },
    { em:'🌱', delay:25, dur:26 }, { em:'🧟', delay:30, dur:19 },
    { em:'☀️', delay:35, dur:23 }, { em:'🌻', delay:40, dur:30 },
  ];
  entities.forEach(({ em, delay, dur }) => {
    const el = document.createElement('div');
    el.className = 'bf-entity';
    el.textContent = em;
    el.style.animationDuration = dur + 's';
    el.style.animationDelay = '-' + delay + 's';
    bf.appendChild(el);
  });
  // Sun drops
  [15, 35, 55, 75, 90].forEach((left, i) => {
    const el = document.createElement('div');
    el.className = 'sun-drop';
    el.textContent = '🪙';
    el.style.cssText = `left:${left}%; top:10px; animation-duration:${2.5 + i * 0.4}s; animation-delay:-${i*0.6}s`;
    bf.appendChild(el);
  });
}

// ══════════════════════════════════════════════
// MATCH HISTORY
// ══════════════════════════════════════════════
function buildMatchHistory() {
  const cont = document.getElementById('match-history');
  MATCH_HISTORY.forEach(m => {
    const el = document.createElement('div');
    el.className = 'match-row';
    el.innerHTML = `
      <div class="match-result ${m.result}">${m.result.toUpperCase()}</div>
      <div style="flex:1; min-width:0">
        <p class="match-map">${m.map}</p>
        <p class="match-meta">vs ${m.enemy} · ${m.time}</p>
      </div>
      <span class="match-type-pill ${m.mode}">${m.mode}</span>
      <span class="match-kda">${m.kda}</span>
    `;
    cont.appendChild(el);
  });
}

// ══════════════════════════════════════════════
// FRIENDS
// ══════════════════════════════════════════════
function buildFriends() {
  const online = document.getElementById('friends-online');
  const offline = document.getElementById('friends-offline');

  FRIENDS.forEach(f => {
    const el = document.createElement('div');
    el.className = 'friend-row';
    const canInvite = f.status === 'online';
    el.innerHTML = `
      <div class="friend-avatar">
        ${f.avatar}
        <div class="status-dot ${f.status}"></div>
      </div>
      <div>
        <p class="friend-name">${f.name}</p>
        <p class="friend-status">${f.statusText}</p>
      </div>
      ${canInvite ? `<button class="invite-btn" onclick="inviteFriend('${f.name}')">Invite</button>` : ''}
    `;
    (f.status === 'offline' ? offline : online).appendChild(el);
  });
}

function inviteFriend(name) {
  toast('success', `🌿 Invite sent to ${name}!`);
}

// ══════════════════════════════════════════════
// MINI LEADERBOARD (right column)
// ══════════════════════════════════════════════
function buildMiniLeaderboard() {
  const cont = document.getElementById('mini-lb');
  const top5 = LEADERBOARD.slice(0, 5);
  top5.forEach(p => {
    const rankClass = p.rank === 1 ? 'gold' : p.rank === 2 ? 'silver' : p.rank === 3 ? 'bronze' : '';
    const rankSymbol = p.rank === 1 ? '🥇' : p.rank === 2 ? '🥈' : p.rank === 3 ? '🥉' : p.rank;
    const el = document.createElement('div');
    el.className = 'lb-row';
    el.style.color = p.highlight ? 'var(--yellow)' : '';
    el.innerHTML = `
      <span class="lb-rank ${rankClass}">${rankSymbol}</span>
      <span class="lb-name">${p.name}${p.highlight ? ' 🌻' : ''}</span>
      <span class="lb-score">${p.score}</span>
    `;
    cont.appendChild(el);
  });
}

// ══════════════════════════════════════════════
// FULL LEADERBOARD (modal)
// ══════════════════════════════════════════════
function buildFullLeaderboard() {
  const cont = document.getElementById('full-leaderboard');
  LEADERBOARD.forEach(p => {
    const rankClass = p.rank === 1 ? 'gold' : p.rank === 2 ? 'silver' : p.rank === 3 ? 'bronze' : '';
    const rankSymbol = p.rank === 1 ? '🥇' : p.rank === 2 ? '🥈' : p.rank === 3 ? '🥉' : `#${p.rank}`;
    const el = document.createElement('div');
    el.className = 'lb-row';
    el.style.padding = '10px 0';
    if (p.highlight) {
      el.style.background = 'rgba(255,209,102,0.08)';
      el.style.padding = '10px 10px';
      el.style.borderRadius = '10px';
      el.style.color = 'var(--yellow)';
    }
    el.innerHTML = `
      <span class="lb-rank ${rankClass}" style="font-size:0.95rem">${rankSymbol}</span>
      <span class="lb-name" style="font-size:0.88rem">${p.name}${p.badge ? ' ' + p.badge : ''}</span>
      <span class="lb-score" style="font-size:0.88rem">${p.score} SR</span>
    `;
    cont.appendChild(el);
  });
}

// ══════════════════════════════════════════════
// CHAT
// ══════════════════════════════════════════════
function seedChat() {
  const cont = document.getElementById('chat-messages');
  CHAT_SEED.forEach(m => appendChatMsg(m.user, m.color, m.msg));
  scrollChat();
}

function appendChatMsg(user, color, msg) {
  const cont = document.getElementById('chat-messages');
  const el = document.createElement('div');
  el.className = 'chat-msg';
  el.innerHTML = `<span class="msg-user ${color}">${user}: </span><span class="msg-text">${msg}</span>`;
  cont.appendChild(el);
  scrollChat();
}

function scrollChat() {
  const cont = document.getElementById('chat-messages');
  cont.scrollTop = cont.scrollHeight;
}

function sendChat() {
  const inp = document.getElementById('chat-input');
  const val = inp.value.trim();
  if (!val) return;
  appendChatMsg('PlantMaster', 'orange', val);
  inp.value = '';
  // Simulate a reply after random delay
  const replies = [
    ['ZombieKing','purple','lol nice one 😂'],
    ['SunDropper','yellow','true! 🌻'],
    ['GardenGuard','green','gg wp'],
    ['PeaShooterPro','green','haha same bro'],
  ];
  const r = replies[Math.floor(Math.random()*replies.length)];
  setTimeout(() => appendChatMsg(r[0], r[1], r[2]), 1200 + Math.random()*1800);
}

// ══════════════════════════════════════════════
// MATCHMAKING
// ══════════════════════════════════════════════
let searchTimer = null;
let searchSeconds = 0;
let searching = false;

function startSearch(mode) {
  if (searching) { cancelSearch(); return; }
  searching = true;

  const btn = document.getElementById(`btn-${mode}`);
  const other = document.getElementById(mode === 'casual' ? 'btn-ranked' : 'btn-casual');
  const bar = document.getElementById('searching-bar');
  const status = document.getElementById('search-status');
  const label = document.getElementById('search-label');

  other.style.opacity = '0.4';
  other.style.pointerEvents = 'none';
  bar.classList.add('active');
  status.style.display = 'flex';
  searchSeconds = 0;

  const modeLabel = mode === 'casual' ? '🌱 Casual' : '🧟 Ranked';
  toast('info', `${modeLabel} search started! Finding opponents…`);

  searchTimer = setInterval(() => {
    searchSeconds++;
    const m = Math.floor(searchSeconds / 60);
    const s = searchSeconds % 60;
    label.textContent = `${modeLabel} — Searching ${m > 0 ? m + 'm ' : ''}${s}s…`;

    // Simulate match found (15-30s)
    if (searchSeconds >= 12 + Math.floor(Math.random() * 10)) {
      clearInterval(searchTimer);
      matchFound(mode);
    }
  }, 1000);
}

function cancelSearch() {
  clearInterval(searchTimer);
  searching = false;
  document.getElementById('searching-bar').classList.remove('active');
  document.getElementById('search-status').style.display = 'none';
  document.getElementById('btn-casual').style.opacity = '';
  document.getElementById('btn-ranked').style.opacity = '';
  document.getElementById('btn-casual').style.pointerEvents = '';
  document.getElementById('btn-ranked').style.pointerEvents = '';
  toast('info', '🌱 Search cancelled');
}

function matchFound(mode) {
  searching = false;
  document.getElementById('searching-bar').classList.remove('active');
  document.getElementById('search-status').style.display = 'none';
  document.getElementById('btn-casual').style.opacity = '';
  document.getElementById('btn-ranked').style.opacity = '';
  document.getElementById('btn-casual').style.pointerEvents = '';
  document.getElementById('btn-ranked').style.pointerEvents = '';
  confettiBurst();
  toast('success', `🌻 Match found! Loading ${mode === 'ranked' ? 'Ranked' : 'Casual'} game…`);
}

// ══════════════════════════════════════════════
// MAP MODE CHIPS
// ══════════════════════════════════════════════
function toggleChip(el) {
  el.classList.toggle('active-chip');
}

// ══════════════════════════════════════════════
// LEFT NAV PILLS
// ══════════════════════════════════════════════
function setPill(el) {
  document.querySelectorAll('.nav-pill').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
}

// ══════════════════════════════════════════════
// SHOP BUY
// ══════════════════════════════════════════════
function buyItem(name, cost) {
  const el = document.getElementById('coin-count');
  const current = parseInt(el.textContent.replace(/,/g, ''));
  if (current < cost) { toast('error', `🪙 Not enough coins for ${name}`); return; }
  el.textContent = (current - cost).toLocaleString();
  toast('success', `${name} purchased! ✓`);
}

// ══════════════════════════════════════════════
// MODAL
// ══════════════════════════════════════════════
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ══════════════════════════════════════════════
// TOAST
// ══════════════════════════════════════════════
function toast(type, msg) {
  const icons = { success:'🌱', error:'💀', info:'🌻' };
  const cont = document.getElementById('toast-container');
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span style="font-size:1.1rem">${icons[type]||'ℹ️'}</span><span>${msg}</span>`;
  cont.appendChild(el);
  setTimeout(() => {
    el.style.animation = 'toastOut 0.3s ease forwards';
    setTimeout(() => el.remove(), 300);
  }, 3000);
}

// ══════════════════════════════════════════════
// CONFETTI
// ══════════════════════════════════════════════
function confettiBurst() {
  const colors = ['#2d6a4f','#ffd166','#52b788','#7b2cbf','#e63946','#fb8500'];
  for (let i = 0; i < 55; i++) {
    const p = document.createElement('div');
    const size = Math.random()*10+4;
    p.style.cssText = `
      position:fixed; pointer-events:none; z-index:9998;
      left:${Math.random()*100}vw; top:-10px;
      width:${size}px; height:${size}px;
      background:${colors[Math.floor(Math.random()*colors.length)]};
      border-radius:${Math.random()>0.5?'50%':'3px'};
      animation:confettiFall ${Math.random()*1.4+0.8}s linear ${Math.random()*0.5}s forwards;
    `;
    document.body.appendChild(p);
    setTimeout(() => p.remove(), 3000);
  }
}

// ══════════════════════════════════════════════
// ONLINE COUNTER (simulated flicker)
// ══════════════════════════════════════════════
function startOnlineCounter() {
  setInterval(() => {
    const base = 214;
    const noise = Math.floor(Math.random()*12) - 5;
    document.getElementById('online-count').textContent = (base + noise) + ' online';
  }, 5000);
}

// ══════════════════════════════════════════════
// XP BAR ANIMATION
// ══════════════════════════════════════════════
function animateXP() {
  setTimeout(() => {
    document.getElementById('xp-bar').style.width = '85%';
  }, 400);
}

// ══════════════════════════════════════════════
// COIN FLICKER (subtle pulse)
// ══════════════════════════════════════════════
function startCoinFlicker() {
  // Occasionally add a small coin animation to indicate earning
  setTimeout(() => {
    const el = document.getElementById('coin-count');
    el.style.transition = 'color 0.3s';
    el.style.color = '#fff';
    setTimeout(() => { el.style.color = ''; }, 400);
  }, 8000);
}

// ══════════════════════════════════════════════
// CSS injection for confetti keyframe
// ══════════════════════════════════════════════
const style = document.createElement('style');
style.textContent = `@keyframes confettiFall {
  0%   { transform:translateY(-10px) rotate(0deg); opacity:1; }
  100% { transform:translateY(100vh) rotate(720deg); opacity:0; }
}`;
document.head.appendChild(style);