// ══════════════════════════════════════════════════
// CONFIG
// ══════════════════════════════════════════════════
const API      = 'http://localhost/verdant-siege/backend/api/UserProfile.php';
const AUTH_API = 'http://localhost/verdant-siege/backend/api/Auth.php';
const token    = sessionStorage.getItem('auth_token');

let profile = null;
let selectedUnit = null;

// ══════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  if (!token) { window.location.href = './login.html'; return; }
  buildBgParticles();
  loadProfile();
});

// ══════════════════════════════════════════════════
// LOAD PROFILE
// ══════════════════════════════════════════════════
async function loadProfile() {
  try {
    const res  = await fetch(`${API}?action=profile&token=${token}`);
    const data = await res.json();
    if (!data.success) { pToast('e', data.error || 'Failed to load profile'); return; }

    profile = data.profile;
    renderProfile(profile);
    renderHistory(data.match_history);
  } catch (err) {
    pToast('e', '🧟 Could not load profile');
  }
}

// ══════════════════════════════════════════════════
// RENDER PROFILE
// ══════════════════════════════════════════════════
function renderProfile(p) {
  // Avatar
  const avatarEl = document.getElementById('avatar-display');
  if (p.avatar_url) {
    avatarEl.innerHTML = `<img src="${p.avatar_url}" style="width:100%;height:100%;border-radius:50%;object-fit:cover" onerror="this.parentElement.textContent='🌻'" />`;
  } else {
    avatarEl.textContent = '🌻';
  }

  // Name + username
  setEl('display-name', p.display_name || p.username);
  setEl('username-tag', '@' + p.username);

  // Rank badge
  const rankLabel = eloToRank(p.elo_rating);
  setEl('rank-badge', `⚔️ ${rankLabel}`);

  // Stats
  setEl('stat-elo',    p.elo_rating);
  setEl('stat-wins',   p.wins);
  setEl('stat-losses', p.losses);
  setEl('stat-wr',     p.win_rate + '%');
  const streak = p.current_streak;
  setEl('stat-streak', streak > 0 ? `+${streak}🔥` : streak < 0 ? `${streak}💀` : '0');
  setEl('stat-rank',   '#' + p.rank_position);

  // Level (ELO-based)
  const level = Math.floor(p.elo_rating / 100);
  const xpPct = ((p.elo_rating % 100) / 100) * 100;
  setEl('level-label', `LVL ${level}`);
  setEl('coins-label', `🪙 ${p.coins.toLocaleString()}`);
  setTimeout(() => {
    document.getElementById('xp-fill').style.width = xpPct + '%';
  }, 300);

  // Economy
  setEl('eco-coins',        p.coins.toLocaleString());
  setEl('eco-gems',         p.gems);
  setEl('eco-total-matches',p.total_matches);
  setEl('eco-best-streak',  p.best_streak);

  // Member since
  const date = new Date(p.created_at);
  setEl('member-since', `Member since ${date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}`);

  // Populate edit form
  document.getElementById('edit-display-name').value = p.display_name || '';
  document.getElementById('edit-bio').value           = p.bio || '';

  // Favorite unit
  if (p.favorite_unit) {
    selectedUnit = p.favorite_unit;
    document.querySelectorAll('.unit-chip').forEach(el => {
      el.classList.toggle('selected', el.dataset.unit === p.favorite_unit);
    });
  }
}

// ══════════════════════════════════════════════════
// RENDER MATCH HISTORY
// ══════════════════════════════════════════════════
function renderHistory(history) {
  const cont = document.getElementById('match-history-list');
  setEl('history-count', `Last ${history.length} game${history.length !== 1 ? 's' : ''}`);

  if (!history || history.length === 0) {
    cont.innerHTML = `
      <div class="empty-state">
        <div style="font-size:2.5rem;margin-bottom:10px">🌱</div>
        <p>No matches yet — go play!</p>
      </div>`;
    return;
  }

  cont.innerHTML = '';
  history.forEach(m => {
    const eloSign  = m.elo_change >= 0 ? '+' : '';
    const eloClass = m.elo_change >= 0 ? 'pos' : 'neg';
    const modeLabel = m.mode === 'ai' ? 'VS AI' : m.mode;
    const date = new Date(m.played_at);
    const dateStr = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    const duration = m.duration_secs ? formatDuration(m.duration_secs) : '—';
    const opponent = m.opponent_username ? `vs ${m.opponent_username}` : `Wave ${m.wave_reached ?? '?'}`;

    const el = document.createElement('div');
    el.className = 'match-row';
    el.innerHTML = `
      <div class="result-badge ${m.result}">${m.result.toUpperCase()}</div>
      <div class="match-info">
        <p class="match-title">${opponent}</p>
        <p class="match-meta">${dateStr} · ${duration}</p>
      </div>
      <span class="mode-pill ${m.mode}">${modeLabel}</span>
      <span class="match-elo ${eloClass}">${eloSign}${m.elo_change}</span>
    `;
    cont.appendChild(el);
  });
}

// ══════════════════════════════════════════════════
// SAVE PROFILE
// ══════════════════════════════════════════════════
async function saveProfile() {
  const btn = document.getElementById('save-btn');
  btn.innerHTML = '<span class="spinner"></span>';
  btn.disabled  = true;

  try {
    const res = await fetch(`${API}?token=${token}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action:        'update_bio',
        display_name:  document.getElementById('edit-display-name').value.trim(),
        bio:           document.getElementById('edit-bio').value.trim(),
        favorite_unit: selectedUnit,
        token,
      })
    });
    const data = await res.json();
    if (data.success) {
      pToast('s', '🌻 Profile updated!');
      loadProfile(); // refresh
    } else {
      pToast('e', data.error || 'Update failed');
    }
  } catch (err) {
    pToast('e', '🧟 Connection error');
  } finally {
    btn.innerHTML = '🌿 SAVE CHANGES';
    btn.disabled  = false;
  }
}

// ══════════════════════════════════════════════════
// AVATAR UPLOAD
// ══════════════════════════════════════════════════
async function uploadAvatar(input) {
  const file = input.files[0];
  if (!file) return;

  if (file.size > 2 * 1024 * 1024) { pToast('e', '❌ Max file size is 2MB'); return; }

  const progress = document.getElementById('upload-progress');
  const fill     = document.getElementById('upload-fill');
  progress.style.display = 'block';
  fill.style.width = '30%';

  const formData = new FormData();
  formData.append('avatar', file);
  formData.append('token',  token);

  try {
    fill.style.width = '60%';
    const res  = await fetch(`${API}?action=upload_avatar&token=${token}`, {
      method: 'POST',
      body:   formData,
    });
    fill.style.width = '90%';
    const data = await res.json();

    if (data.success) {
      fill.style.width = '100%';
      pToast('s', '🌻 Avatar updated!');
      // Update avatar display immediately
      const avatarEl = document.getElementById('avatar-display');
      avatarEl.innerHTML = `<img src="${data.avatar_url}" style="width:100%;height:100%;border-radius:50%;object-fit:cover" />`;
      setTimeout(() => { progress.style.display = 'none'; fill.style.width = '0%'; }, 600);
    } else {
      pToast('e', data.error || 'Upload failed');
      progress.style.display = 'none'; fill.style.width = '0%';
    }
  } catch (err) {
    pToast('e', '🧟 Upload failed');
    progress.style.display = 'none'; fill.style.width = '0%';
  }

  input.value = ''; // reset input
}

// ══════════════════════════════════════════════════
// UNIT PICKER
// ══════════════════════════════════════════════════
function selectUnit(el) {
  document.querySelectorAll('.unit-chip').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  selectedUnit = el.dataset.unit;
}

// ══════════════════════════════════════════════════
// LOGOUT
// ══════════════════════════════════════════════════
async function logout() {
  try {
    await fetch(`${AUTH_API}?action=logout&token=${token}`, { method: 'POST' });
  } catch (_) {}
  sessionStorage.clear();
  localStorage.removeItem('auth_token');
  window.location.href = './login.html';
}

// ══════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════
function eloToRank(elo) {
  if (elo >= 2400) return '🏆 Grandmaster';
  if (elo >= 2000) return '💎 Diamond';
  if (elo >= 1700) return '🥇 Platinum';
  if (elo >= 1400) return '🥈 Gold';
  if (elo >= 1200) return '🥉 Silver';
  if (elo >= 1000) return '🌿 Bronze';
  return '🌱 Seed';
}

function formatDuration(secs) {
  if (secs < 60) return `${secs}s`;
  return `${Math.floor(secs / 60)}m ${secs % 60}s`;
}

function setEl(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

function pToast(type, msg) {
  const icons = { s: '🌱', e: '💀', i: '🌻' };
  const cont  = document.getElementById('toast-cont');
  const el    = document.createElement('div');
  el.className = `p-toast ${type}`;
  el.innerHTML = `<span>${icons[type]}</span><span>${msg}</span>`;
  cont.appendChild(el);
  setTimeout(() => { el.style.animation = 'tOut 0.25s ease forwards'; setTimeout(() => el.remove(), 260); }, 3000);
}

function buildBgParticles() {
  const cont   = document.getElementById('bg-particles');
  const colors = ['#2d6a4f','#7b2cbf','#ffd166','#52b788'];
  for (let i = 0; i < 18; i++) {
    const el   = document.createElement('div');
    const size = Math.random() * 120 + 40;
    el.className = 'bp';
    el.style.cssText = `
      left:${Math.random()*100}%; width:${size}px; height:${size}px;
      background:${colors[Math.floor(Math.random()*colors.length)]};
      animation-duration:${Math.random()*20+18}s;
      animation-delay:-${Math.random()*20}s;
    `;
    cont.appendChild(el);
  }
}