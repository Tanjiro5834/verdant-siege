/**
 * Verdant Siege — Matchmaking Module
 * ─────────────────────────────────────────────────────────────
 * Separate from lobby.js. Import via <script src="./js/matchmaking.js"></script>
 * after lobby.js in lobby.html.
 *
 * Hooks into the existing lobby HTML:
 *   - Hijacks #btn-casual and #btn-ranked onclick
 *   - Injects queue status UI into the matchmaking section
 *   - Renders the match-found modal (self-contained)
 *   - On confirmation: redirects to game.html?match_id=xxx&side=plants|zombies
 *
 * Exposes on window: MM.join(), MM.cancel(), MM.confirm()
 */

'use strict';

const MM = (() => {
  const API_BASE = 'http://localhost/verdant-siege/backend/api/MatchMaking.php';
  const POLL_RETRY_DELAY = 300;   // ms between poll requests (server holds 25s each)
  const STATUS_INTERVAL  = 8000;  // ms between queue status refreshes
  const CONFIRM_SECONDS  = 10;    // countdown in match-found modal

  let state = {
    inQueue:       false,
    mode:          null,       // 'casual' | 'ranked'
    token:         null,       // session token (generated once per page load)
    userId:        null,
    username:      null,
    elo:           null,
    queueStart:    null,       // Date.now() when joined
    waitTimer:     null,       // setInterval ID for elapsed display
    pollAborted:   false,      // flag to stop poll loop
    statusTimer:   null,       // setInterval for queue_status polling
    confirmTimer:  null,       // setInterval for the 10s countdown
    confirmSeconds: CONFIRM_SECONDS,
    currentMatchId: null,
    currentSide:   null,
  };

  // ─────────────────────────────────────────────────────────────
  // INIT — called on DOMContentLoaded
  // ─────────────────────────────────────────────────────────────
  function init() {
    // Pull player identity from session / mock
    state.userId   = getSessionInt('user_id',   1);
    state.username = getSession('username',      'PlantMaster');
    state.elo      = getSessionInt('elo',        1200);
    state.token    = getOrCreateToken();

    // Override the existing lobby onclick handlers
    const btnCasual = document.getElementById('btn-casual');
    const btnRanked = document.getElementById('btn-ranked');
    if (btnCasual) btnCasual.onclick = () => join('casual');
    if (btnRanked) btnRanked.onclick = () => join('ranked');

    // Wire cancel button (already in your lobby HTML)
    const cancelEl = document.querySelector('[onclick="cancelSearch()"]');
    if (cancelEl) cancelEl.onclick = cancel;

    // Inject enhanced queue status row below the searching bar
    injectQueueStatusUI();

    // Inject match-found modal into body
    injectMatchFoundModal();

    // Start passive queue status polling (shows sizes even when not in queue)
    startStatusPolling();
  }

  // ─────────────────────────────────────────────────────────────
  // JOIN QUEUE
  // ─────────────────────────────────────────────────────────────
  async function join(mode) {
    if (state.inQueue) {
      // If clicking same button, treat as cancel
      if (state.mode === mode) { cancel(); return; }
      // Switching modes — cancel current first
      await cancel(true);
    }

    state.mode      = mode;
    state.inQueue   = true;
    state.queueStart = Date.now();
    state.pollAborted = false;

    setUISearching(mode);
    startWaitTimer();

    try {
      const res = await api('join', {
        user_id:  state.userId,
        username: state.username,
        elo:      state.elo,
        mode,
        token:    state.token,
      });

      if (!res.success) throw new Error(res.error || 'Failed to join queue');

      showToast('info', `${mode === 'casual' ? '🌱' : '🏆'} Joined ${mode} queue!`);
      updateQueueCount(mode, res.queue_size);

      // Start the long-poll loop
      pollLoop();

    } catch (err) {
      cancelCleanup();
      showToast('error', `❌ ${err.message}`);
    }
  }

  // ─────────────────────────────────────────────────────────────
  // CANCEL
  // ─────────────────────────────────────────────────────────────
  async function cancel(silent = false) {
    if (!state.inQueue) return;

    state.pollAborted = true;
    const mode = state.mode;

    cancelCleanup();

    try {
      await api('leave', {
        user_id: state.userId,
        token:   state.token,
        mode,
      });
    } catch (_) { /* fire-and-forget */ }

    if (!silent) showToast('info', '🌿 Search cancelled');
  }

  // ─────────────────────────────────────────────────────────────
  // CONFIRM MATCH
  // ─────────────────────────────────────────────────────────────
  async function confirm() {
    try {
      const res = await api('confirm', {
        match_id: state.currentMatchId,
        user_id:  state.userId,
        token:    state.token,
      });

      if (res.status === 'expired') {
        closeMatchFoundModal();
        showToast('error', '⏰ Confirmation window expired — re-queuing...');
        await join(state.mode);
        return;
      }

      if (res.status === 'active') {
        // Both players confirmed → go to game
        clearConfirmTimer();
        redirectToGame(state.currentMatchId, state.currentSide, state.mode);
        return;
      }

      // Waiting for opponent to confirm
      setConfirmBtn('Waiting for opponent... 🌱', true);

      // Keep polling match_state until both confirmed or expired
      waitForOpponent();

    } catch (err) {
      showToast('error', `❌ ${err.message}`);
    }
  }

  // ─────────────────────────────────────────────────────────────
  // POLL LOOP (long-polling)
  // ─────────────────────────────────────────────────────────────
  async function pollLoop() {
    while (state.inQueue && !state.pollAborted) {
      try {
        const params = new URLSearchParams({
          action:  'poll',
          user_id: state.userId,
          token:   state.token,
          mode:    state.mode,
        });

        // Each request holds connection for ~25s server-side
        const res = await fetchWithTimeout(
          `${API_BASE}?${params}`,
          { method: 'GET' },
          28000   // client-side abort after 28s (> server's 25s)
        );

        if (state.pollAborted) break;

        const data = await res.json();

        switch (data.status) {
          case 'matched':
            handleMatchFound(data);
            return; // stop loop

          case 'waiting':
            updateQueueStatus(data);
            break;

          case 'cancelled':
            // Server purged our entry (stale)
            cancelCleanup();
            showToast('info', '🌿 Queue entry expired — re-join to search');
            return;

          default:
            console.warn('[MM] Unknown poll status:', data.status);
        }

      } catch (err) {
        if (state.pollAborted) break;
        if (err.name === 'AbortError') {
          // Network timeout — just re-poll
          await sleep(POLL_RETRY_DELAY);
          continue;
        }
        console.error('[MM] Poll error:', err);
        await sleep(2000); // back-off on unexpected errors
      }

      await sleep(POLL_RETRY_DELAY);
    }
  }

  // ─────────────────────────────────────────────────────────────
  // MATCH FOUND HANDLER
  // ─────────────────────────────────────────────────────────────
  function handleMatchFound(data) {
    state.inQueue       = false;
    state.pollAborted   = true;
    state.currentMatchId = data.match_id;
    state.currentSide   = data.side;

    stopWaitTimer();
    setUIIdle();

    // Populate and show modal
    const opponent = data.opponent;
    setEl('mf-mode',     data.mode === 'ranked' ? '🏆 Ranked Match' : '🌱 Casual Match');
    setEl('mf-opponent', opponent?.username ?? 'Unknown');
    setEl('mf-your-side',
      data.side === 'plants'
        ? '🌿 You play as <strong>Plants</strong>'
        : '🧟 You play as <strong>Zombies</strong>'
    );
    setEl('mf-elo-diff',
      data.mode === 'ranked' && opponent?.elo
        ? `ELO: ${opponent.elo} (${delta(state.elo, opponent.elo)})`
        : ''
    );

    showMatchFoundModal();
    startConfirmCountdown();
    showToast('success', '🌻 Match found! Confirm to battle!');
  }

  // ─────────────────────────────────────────────────────────────
  // WAIT FOR OPPONENT CONFIRMATION (after you've confirmed)
  // ─────────────────────────────────────────────────────────────
  async function waitForOpponent() {
    for (let i = 0; i < 12; i++) { // max 12 × 1s = 12s
      await sleep(1000);
      if (!state.currentMatchId) return; // modal closed / cancelled

      try {
        const params = new URLSearchParams({ action: 'match_state', match_id: state.currentMatchId });
        const res  = await fetch(`${API_BASE}?${params}`);
        const data = await res.json();

        if (!data.success) return;

        if (data.match.status === 'active') {
          clearConfirmTimer();
          redirectToGame(state.currentMatchId, state.currentSide, state.mode);
          return;
        }
        if (data.match.status === 'expired') {
          closeMatchFoundModal();
          showToast('error', '⏰ Opponent didn\'t confirm — re-queuing...');
          await join(state.mode);
          return;
        }
      } catch (_) { /* network blip — keep polling */ }
    }
    // Timed out waiting
    closeMatchFoundModal();
    showToast('error', '⏰ Confirmation timed out — re-queuing...');
    await join(state.mode);
  }

  // ─────────────────────────────────────────────────────────────
  // REDIRECT
  // ─────────────────────────────────────────────────────────────
  function redirectToGame(matchId, side, mode) {
    const url = `./board.html?match_id=${encodeURIComponent(matchId)}&side=${side}&mode=${mode}`;
    window.location.href = url;
  }

  // ─────────────────────────────────────────────────────────────
  // QUEUE STATUS POLLING (passive, always-on)
  // ─────────────────────────────────────────────────────────────
  function startStatusPolling() {
    fetchQueueStatus(); // immediate
    state.statusTimer = setInterval(fetchQueueStatus, STATUS_INTERVAL);
  }

  async function fetchQueueStatus() {
    try {
      const res  = await fetch(`${API_BASE}?action=queue_status`);
      const data = await res.json();
      updateWaitBadges(data);
    } catch (_) { /* silent fail */ }
  }

  function updateWaitBadges(data) {
    // Update the static wait badges on the match buttons
    const casualBadge = document.querySelector('#btn-casual .wait-badge');
    const rankedBadge = document.querySelector('#btn-ranked .wait-badge');

    if (casualBadge) {
      const w = data.casual_wait_est;
      casualBadge.textContent = `⚡ ${formatWait(w)} wait · ${data.casual_count} in queue`;
    }
    if (rankedBadge) {
      const w = data.ranked_wait_est;
      rankedBadge.textContent = `⏳ ${formatWait(w)} wait · ${data.ranked_count} in queue`;
    }

    // Also update the injected status indicators
    setElSafe('mm-status-casual', `${data.casual_count} in queue · ~${formatWait(data.casual_wait_est)}`);
    setElSafe('mm-status-ranked', `${data.ranked_count} in queue · ~${formatWait(data.ranked_wait_est)}`);
  }

  function updateQueueStatus(data) {
    if (data.queue_size !== undefined) {
      updateQueueCount(state.mode, data.queue_size);
    }
    setElSafe('mm-elo-window',
      state.mode === 'ranked'
        ? `ELO window: ±${data.elo_window ?? '—'}`
        : ''
    );
  }

  function updateQueueCount(mode, count) {
    const id = `mm-status-${mode}`;
    setElSafe(id, `${count} in ${mode} queue`);
  }

  // ─────────────────────────────────────────────────────────────
  // TIMERS
  // ─────────────────────────────────────────────────────────────
  function startWaitTimer() {
    stopWaitTimer();
    updateWaitDisplay();
    state.waitTimer = setInterval(updateWaitDisplay, 1000);
  }

  function stopWaitTimer() {
    if (state.waitTimer) { clearInterval(state.waitTimer); state.waitTimer = null; }
  }

  function updateWaitDisplay() {
    if (!state.queueStart) return;
    const elapsed = Math.floor((Date.now() - state.queueStart) / 1000);
    const label = document.getElementById('search-label');
    if (label) {
      const modeLabel = state.mode === 'ranked' ? '🏆 Ranked' : '🌱 Casual';
      label.textContent = `${modeLabel} — Searching ${formatElapsed(elapsed)}…`;
    }
    setElSafe('mm-elapsed', formatElapsed(elapsed));
  }

  function startConfirmCountdown() {
    state.confirmSeconds = CONFIRM_SECONDS;
    updateConfirmTimer();
    state.confirmTimer = setInterval(() => {
      state.confirmSeconds--;
      updateConfirmTimer();
      if (state.confirmSeconds <= 0) {
        clearConfirmTimer();
        // Auto-decline — close modal and requeue
        closeMatchFoundModal();
        showToast('info', '⏰ No response — back to queue');
        join(state.mode);
      }
    }, 1000);
  }

  function updateConfirmTimer() {
    const bar   = document.getElementById('mf-timer-fill');
    const label = document.getElementById('mf-timer-num');
    if (bar)   bar.style.width = ((state.confirmSeconds / CONFIRM_SECONDS) * 100) + '%';
    if (label) label.textContent = state.confirmSeconds;

    // Urgency colour: red below 4s
    if (bar) {
      bar.style.background = state.confirmSeconds <= 3
        ? 'var(--red)'
        : 'linear-gradient(90deg, var(--g-light), var(--yellow))';
    }
  }

  function clearConfirmTimer() {
    if (state.confirmTimer) { clearInterval(state.confirmTimer); state.confirmTimer = null; }
  }

  // ─────────────────────────────────────────────────────────────
  // UI STATE HELPERS
  // ─────────────────────────────────────────────────────────────
  function setUISearching(mode) {
    const bar    = document.getElementById('searching-bar');
    const status = document.getElementById('search-status');
    const casual = document.getElementById('btn-casual');
    const ranked = document.getElementById('btn-ranked');
    const other  = mode === 'casual' ? ranked : casual;

    if (bar)    bar.classList.add('active');
    if (status) status.style.display = 'flex';
    if (other)  { other.style.opacity = '0.4'; other.style.pointerEvents = 'none'; }

    // Show elapsed row
    showEl('mm-elapsed-row');
  }

  function setUIIdle() {
    stopWaitTimer();
    const bar    = document.getElementById('searching-bar');
    const status = document.getElementById('search-status');
    const casual = document.getElementById('btn-casual');
    const ranked = document.getElementById('btn-ranked');

    if (bar)    bar.classList.remove('active');
    if (status) status.style.display = 'none';
    if (casual) { casual.style.opacity = ''; casual.style.pointerEvents = ''; }
    if (ranked) { ranked.style.opacity = ''; ranked.style.pointerEvents = ''; }

    hideEl('mm-elapsed-row');
    setElSafe('mm-elo-window', '');
  }

  function cancelCleanup() {
    state.inQueue     = false;
    state.pollAborted = true;
    state.mode        = null;
    state.queueStart  = null;
    setUIIdle();
  }

  // ─────────────────────────────────────────────────────────────
  // DOM INJECTION — Queue status row
  // ─────────────────────────────────────────────────────────────
  function injectQueueStatusUI() {
    // Find the matchmaking glass card
    const mmSection = document.querySelector('.glass-green');
    if (!mmSection) return;

    const html = `
      <div id="mm-extra-ui" style="margin-top:4px;">
        <!-- Live queue counts row -->
        <div style="display:flex; gap:12px; margin-bottom:8px;">
          <div style="flex:1; display:flex; align-items:center; gap:7px; padding:6px 12px;
               border-radius:10px; background:rgba(82,183,136,0.1); border:1px solid rgba(82,183,136,0.2)">
            <span style="font-size:1rem">🌱</span>
            <span id="mm-status-casual" style="font-family:'Nunito',sans-serif; font-size:0.72rem;
                  font-weight:800; color:rgba(254,250,224,0.6)">Loading...</span>
          </div>
          <div style="flex:1; display:flex; align-items:center; gap:7px; padding:6px 12px;
               border-radius:10px; background:rgba(123,44,191,0.1); border:1px solid rgba(123,44,191,0.2)">
            <span style="font-size:1rem">🏆</span>
            <span id="mm-status-ranked" style="font-family:'Nunito',sans-serif; font-size:0.72rem;
                  font-weight:800; color:rgba(254,250,224,0.6)">Loading...</span>
          </div>
        </div>

        <!-- Elapsed + ELO window (hidden until searching) -->
        <div id="mm-elapsed-row" style="display:none; justify-content:space-between; align-items:center;
             padding:5px 10px; border-radius:10px; background:rgba(255,255,255,0.04);
             border:1px solid rgba(255,255,255,0.07); margin-top:4px;">
          <span style="font-family:'Nunito',sans-serif; font-size:0.72rem; font-weight:800;
                color:rgba(254,250,224,0.5)">
            ⏱ Searching: <span id="mm-elapsed" style="color:var(--yellow)">0s</span>
          </span>
          <span id="mm-elo-window" style="font-family:'Nunito',sans-serif; font-size:0.72rem;
                font-weight:800; color:rgba(254,250,224,0.4)"></span>
        </div>
      </div>
    `;

    // Insert after the mode chip row (last child of mm section)
    mmSection.insertAdjacentHTML('beforeend', html);
  }

  // ─────────────────────────────────────────────────────────────
  // DOM INJECTION — Match Found Modal
  // ─────────────────────────────────────────────────────────────
  function injectMatchFoundModal() {
    const modal = document.createElement('div');
    modal.id = 'match-found-modal';
    modal.style.cssText = `
      position: fixed; inset: 0; z-index: 9000;
      background: rgba(0,0,0,0.75); backdrop-filter: blur(8px);
      display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none;
      transition: opacity 0.3s ease;
    `;

    modal.innerHTML = `
      <div style="
        background: linear-gradient(145deg, #1e1630, #1a2e1a);
        border: 2px solid rgba(82,183,136,0.35);
        border-radius: 28px;
        padding: 36px 32px;
        max-width: 420px; width: 92%;
        box-shadow: 0 20px 60px rgba(0,0,0,0.7), 0 0 40px rgba(82,183,136,0.1);
        transform: scale(0.92);
        transition: transform 0.3s cubic-bezier(0.23,1,0.32,1);
        text-align: center;
      " id="mf-box">

        <!-- Pulse ring -->
        <div style="position:relative; display:inline-block; margin-bottom:16px;">
          <div style="
            width:80px; height:80px; border-radius:50%;
            background:linear-gradient(135deg,var(--g-light,#52b788),var(--purple,#7b2cbf));
            display:flex; align-items:center; justify-content:center;
            font-size:2.6rem; margin:0 auto;
            animation:mfPulse 1.2s ease-in-out infinite;
          ">⚔️</div>
        </div>

        <!-- Mode label -->
        <p id="mf-mode" style="
          font-family:'Nunito',sans-serif; font-size:0.72rem; font-weight:800;
          letter-spacing:2px; text-transform:uppercase;
          color:rgba(254,250,224,0.5); margin-bottom:6px;
        "></p>

        <!-- Match Found title -->
        <h2 style="
          font-family:'Luckiest Guy',cursive; font-size:2rem; letter-spacing:2px;
          color:#ffd166; text-shadow:0 0 24px rgba(255,209,102,0.5);
          margin-bottom:4px;
        ">MATCH FOUND!</h2>

        <!-- Opponent -->
        <p style="font-family:'Nunito',sans-serif; font-size:0.9rem; font-weight:700;
           color:rgba(254,250,224,0.7); margin-bottom:2px;">
          VS <span id="mf-opponent" style="color:#fff; font-size:1rem;"></span>
        </p>
        <p id="mf-elo-diff" style="font-family:'Nunito',sans-serif; font-size:0.72rem;
           color:rgba(254,250,224,0.4); margin-bottom:12px;"></p>

        <!-- Side assignment -->
        <div style="
          padding:10px 20px; border-radius:14px; margin-bottom:20px;
          background:rgba(82,183,136,0.12); border:1px solid rgba(82,183,136,0.25);
          font-family:'Nunito',sans-serif; font-size:0.88rem; font-weight:700;
          color:rgba(254,250,224,0.85);
        ">
          <span id="mf-your-side"></span>
        </div>

        <!-- Countdown timer -->
        <div style="margin-bottom:20px;">
          <div style="height:6px; background:rgba(255,255,255,0.1); border-radius:999px; overflow:hidden; margin-bottom:6px;">
            <div id="mf-timer-fill" style="
              height:100%; border-radius:999px; width:100%;
              background:linear-gradient(90deg,#52b788,#ffd166);
              transition:width 1s linear, background 0.3s;
            "></div>
          </div>
          <p style="font-family:'Luckiest Guy',cursive; font-size:1.6rem; color:#ffd166;">
            <span id="mf-timer-num">10</span>s
          </p>
        </div>

        <!-- Action buttons -->
        <div style="display:flex; gap:12px; justify-content:center;">
          <button id="mf-confirm-btn" onclick="MM.confirm()" style="
            flex:1; padding:13px; border-radius:14px;
            font-family:'Luckiest Guy',cursive; font-size:1rem; letter-spacing:1px;
            background:linear-gradient(135deg,#2d6a4f,#4a7c59);
            border:2px solid #52b788; color:white; cursor:pointer;
            box-shadow:0 4px 18px rgba(45,106,79,0.5);
            transition:all 0.18s ease;
          " onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform=''">
            ✅ ACCEPT
          </button>
          <button onclick="MM.declineMatch()" style="
            flex:1; padding:13px; border-radius:14px;
            font-family:'Luckiest Guy',cursive; font-size:1rem; letter-spacing:1px;
            background:rgba(230,57,70,0.15);
            border:2px solid rgba(230,57,70,0.4); color:#ff6b78; cursor:pointer;
            transition:all 0.18s ease;
          " onmouseover="this.style.background='rgba(230,57,70,0.28)'" onmouseout="this.style.background='rgba(230,57,70,0.15)'">
            ✕ DECLINE
          </button>
        </div>
      </div>

      <style>
        @keyframes mfPulse {
          0%,100% { box-shadow: 0 0 0 0 rgba(82,183,136,0.5); transform: scale(1); }
          50%      { box-shadow: 0 0 0 14px rgba(82,183,136,0); transform: scale(1.06); }
        }
      </style>
    `;

    document.body.appendChild(modal);
  }

  function showMatchFoundModal() {
    const modal = document.getElementById('match-found-modal');
    const box   = document.getElementById('mf-box');
    if (!modal) return;
    modal.style.opacity       = '1';
    modal.style.pointerEvents = 'all';
    if (box) box.style.transform = 'scale(1)';
  }

  function closeMatchFoundModal() {
    clearConfirmTimer();
    state.currentMatchId = null;
    const modal = document.getElementById('match-found-modal');
    const box   = document.getElementById('mf-box');
    if (!modal) return;
    if (box) box.style.transform = 'scale(0.92)';
    modal.style.opacity       = '0';
    modal.style.pointerEvents = 'none';
  }

  function setConfirmBtn(text, disabled = false) {
    const btn = document.getElementById('mf-confirm-btn');
    if (!btn) return;
    btn.textContent = text;
    btn.disabled    = disabled;
    btn.style.opacity = disabled ? '0.6' : '1';
    btn.style.cursor  = disabled ? 'not-allowed' : 'pointer';
  }

  // ─────────────────────────────────────────────────────────────
  // DECLINE (from modal)
  // ─────────────────────────────────────────────────────────────
  function declineMatch() {
    clearConfirmTimer();
    closeMatchFoundModal();
    showToast('info', '🌿 Match declined');
    // Don't re-queue automatically — let the player choose
  }

  // ─────────────────────────────────────────────────────────────
  // HTTP HELPERS
  // ─────────────────────────────────────────────────────────────
  async function api(action, body = null) {
    const opts = {
      method: body ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
    };
    if (body) {
      body.action = action;
      opts.body   = JSON.stringify(body);
    }
    const url = body
      ? API_BASE
      : `${API_BASE}?action=${action}`;

    const res = await fetch(url, opts);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }

  async function fetchWithTimeout(url, opts, ms) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), ms);
    try {
      return await fetch(url, { ...opts, signal: controller.signal });
    } finally {
      clearTimeout(timer);
    }
  }

  // ─────────────────────────────────────────────────────────────
  // DOM UTILS
  // ─────────────────────────────────────────────────────────────
  function setEl(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
  }

  function setElSafe(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function showEl(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
  }

  function hideEl(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  }

  // ─────────────────────────────────────────────────────────────
  // FORMAT HELPERS
  // ─────────────────────────────────────────────────────────────
  function formatElapsed(secs) {
    if (secs < 60) return `${secs}s`;
    return `${Math.floor(secs / 60)}m ${secs % 60}s`;
  }

  function formatWait(secs) {
    if (!secs) return '—';
    if (secs < 60) return `~${secs}s`;
    return `~${Math.round(secs / 60)}m`;
  }

  function delta(myElo, theirElo) {
    const d = theirElo - myElo;
    return d > 0 ? `+${d}` : `${d}`;
  }

  // ─────────────────────────────────────────────────────────────
  // SESSION STORAGE (replace with JWT claims later)
  // ─────────────────────────────────────────────────────────────
  function getSession(key, fallback) {
    return sessionStorage.getItem(key) ?? fallback;
  }

  function getSessionInt(key, fallback) {
    const v = sessionStorage.getItem(key);
    return v ? parseInt(v, 10) : fallback;
  }

  function getOrCreateToken() {
    let tok = sessionStorage.getItem('mm_token');
    if (!tok) {
      tok = crypto.randomUUID?.() ?? Math.random().toString(36).slice(2) + Date.now();
      sessionStorage.setItem('mm_token', tok);
    }
    return tok;
  }

  // ─────────────────────────────────────────────────────────────
  // TOAST  (delegates to lobby.js if available, else fallback)
  // ─────────────────────────────────────────────────────────────
  function showToast(type, msg) {
    if (typeof toast === 'function') {
      toast(type, msg); // lobby.js function
      return;
    }
    const icons = { success: '🌱', error: '💀', info: '🌻' };
    const cont  = document.getElementById('toast-container');
    if (!cont) return;
    const el = document.createElement('div');
    el.style.cssText = `
      padding:12px 16px; border-radius:16px; background:rgba(22,33,62,0.95);
      font-family:'Nunito',sans-serif; font-size:0.82rem; font-weight:700;
      display:flex; align-items:center; gap:10px; max-width:290px;
      border:1.5px solid ${type === 'success' ? '#52b788' : type === 'error' ? '#e63946' : '#ffd166'};
      color:${type === 'success' ? '#52b788' : type === 'error' ? '#ff6b78' : '#ffd166'};
      animation:tIn 0.25s ease forwards; margin-top:8px;
    `;
    el.innerHTML = `<span>${icons[type] ?? 'ℹ️'}</span><span>${msg}</span>`;
    cont.appendChild(el);
    setTimeout(() => { el.style.animation = 'tOut 0.25s ease forwards'; setTimeout(() => el.remove(), 260); }, 3200);
  }

  function sleep(ms) {
    return new Promise(r => setTimeout(r, ms));
  }

  // ─────────────────────────────────────────────────────────────
  // PUBLIC API
  // ─────────────────────────────────────────────────────────────
  return { init, join, cancel, confirm, declineMatch, closeMatchFoundModal };

})(); // end IIFE

// ─────────────────────────────────────────────────────────────
// BOOT
// ─────────────────────────────────────────────────────────────
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', MM.init);
} else {
  MM.init();
}