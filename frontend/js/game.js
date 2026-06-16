/**
 * Verdant Siege — Multiplayer Game Layer
 * ─────────────────────────────────────────────────────────────
 * Sits on top of board.js. Intercepts place_unit, end_turn,
 * and surrender calls — sends them to game.php — then syncs
 * the board from the server response.
 *
 * Also opens an SSE connection that keeps both players in sync
 * in real time without WebSockets.
 *
 * Load order in board.html:
 *   <script src="./assets/js/board.js"></script>
 *   <script src="./assets/js/game.js"></script>   ← this file
 *
 * Falls back to offline/mock mode if no match_id in URL.
 */

'use strict';

const Game = (() => {

  // ── CONFIG ──────────────────────────────────────────────────
  const API = 'http://localhost/verdant-siege/backend/api/Game.php';

  // ── STATE ───────────────────────────────────────────────────
  let ctx = {
    matchId:    null,
    gameId:     null,
    side:       null,   // 'plants' | 'zombies'
    mode:       null,
    myTurn:     false,
    token:      null,
    userId:     null,
    sse:        null,   // EventSource
    online:     false,  // true = multiplayer, false = offline mock
    baseHp:     30,
  };

  // ── INIT ────────────────────────────────────────────────────
  async function init() {
    const params  = new URLSearchParams(window.location.search);
    ctx.matchId   = params.get('match_id');
    //ctx.side      = params.get('side');
    ctx.mode      = params.get('mode') ?? 'casual';
    ctx.token     = sessionStorage.getItem('auth_token');
    ctx.userId    = parseInt(sessionStorage.getItem('user_id') ?? '0', 10);

    if (!ctx.matchId || !ctx.token) {
      console.warn('[Game] No match_id or token — running in offline mock mode');
      ctx.online = false;
      return; // board.js handles everything in mock mode
    }

    ctx.online = true;

    // Override board.js functions with multiplayer versions
    patchBoardJS();

    try {
      await initMatch();
      openSSE();
    } catch (err) {
      console.error('[Game] Init failed:', err);
      gToast('te', '⚠️ Could not connect to match server — playing offline');
      ctx.online = false;
    }
  }

  // ── INIT MATCH ──────────────────────────────────────────────
  async function initMatch() {
    const data = await gameAPI('init', { match_id: ctx.matchId });

    ctx.gameId = data.game_id;
    ctx.side   = data.side;
    ctx.myTurn = data.current_turn === ctx.side;

    // Clear mock board, apply server state
    clearBoard();
    applyUnits(data.units);
    updateHUD(data);

    const opponent = ctx.side === 'plants' ? data.zombie_player : data.plant_player;
    gToast('ts', `⚔️ Match started! You are ${ctx.side}. Opponent: ${opponent?.username ?? '?'}`);
  }

  // ── SSE CONNECTION ──────────────────────────────────────────
  function openSSE() {
    if (ctx.sse) ctx.sse.close();

    const url = `${API}?action=stream&match_id=${ctx.matchId}&token=${ctx.token}`;
    ctx.sse   = new EventSource(url);

    ctx.sse.addEventListener('state', (e) => {
      const data = JSON.parse(e.data);
      applyServerState(data);
    });

    ctx.sse.addEventListener('ping', () => {
      // keepalive — no action needed
    });

    ctx.sse.addEventListener('reconnect', () => {
      ctx.sse.close();
      setTimeout(openSSE, 1000);
    });

    ctx.sse.onerror = () => {
      console.warn('[Game] SSE error — reconnecting in 2s');
      ctx.sse.close();
      setTimeout(openSSE, 2000);
    };
  }

  // ── APPLY SERVER STATE ───────────────────────────────────────
  // Called on every SSE push — updates board to match server truth
  function applyServerState(data) {
    if (!data.units) return;

    // Game over check
    if (data.status === 'completed') {
      const won = data.winner === ctx.side;
      triggerGameOver(won ? 'win' : 'lose');
      return;
    }

    ctx.myTurn = data.current_turn === ctx.side;
    clearBoard();
    applyUnits(data.units);
    updateHUD(data);

    if (ctx.myTurn) {
      showTurnBanner(ctx.side === 'plants' ? 'plant' : 'zombie');
      gToast('ts', '⚡ Your turn!');
    } else {
      const otherSide = ctx.side === 'plants' ? 'zombie' : 'plant';
      showTurnBanner(otherSide);
      gToast('ti', "⏳ Opponent's turn...");
    }
  }

  // ── UNIT SYNC ────────────────────────────────────────────────
  function clearBoard() {
    // Reset board.js state grid
    state.board = Array.from({length: 5}, () => Array(9).fill(null));
    // Clear all cell DOM
    for (let r = 0; r < 5; r++)
      for (let c = 0; c < 9; c++)
        renderCell(r, c);
  }

  function applyUnits(units) {
    if (!units) return;
    units.forEach(u => {
      const side = u.side === 'plants' ? 'plant' : 'zombie';
      // Match unit_key to PLANTS/ZOMBIES def
      const defs  = side === 'plant' ? PLANTS : ZOMBIES;
      const defKey = Object.keys(defs).find(k => k === u.unit_key);
      if (!defKey) return;

      const def  = defs[defKey];
      const unit = {
        type:  side,
        def,
        hp:    u.hp,
        maxHp: u.max_hp,
        id:    u.unit_key + '_' + u.row_pos + '_' + u.col_pos,
      };
      state.board[u.row_pos][u.col_pos] = unit;
      renderCell(u.row_pos, u.col_pos);
    });
  }

  function updateHUD(data) {
    // Wave
    if (data.wave) {
      document.getElementById('wave-num').textContent = data.wave;
      state.wave = data.wave;
    }
    // Base HP
    if (data.base_hp !== undefined) {
      ctx.baseHp = data.base_hp;
      const pct  = (data.base_hp / 30) * 100;
      document.getElementById('base-hp-fill').style.width = pct + '%';
      document.getElementById('base-hp-val').textContent  = data.base_hp;
      state.baseHp = data.base_hp;
    }
    // Turn label
    const isMyTurn = data.current_turn === ctx.side;
    const lbl      = document.getElementById('turn-label');
    if (lbl) {
      lbl.textContent = isMyTurn ? '⚡ YOUR TURN' : "⏳ OPPONENT'S TURN";
      lbl.style.color = isMyTurn ? 'var(--g-light)' : 'var(--t-muted)';
    }
    // Lock/unlock card tray
    updateCardStates();
  }

  // ── PATCH BOARD.JS ──────────────────────────────────────────
  // Override the functions board.js calls so actions go to server
  function patchBoardJS() {

    // ── Place unit ──
    const origOnCellClick = window.onCellClick ?? function(){};
    window.onCellClick = async function(r, c) {
      if (!ctx.online) { origOnCellClick(r, c); return; }
      if (!ctx.myTurn) { gToast('te', "⏳ Not your turn!"); return; }
      if (!state.selectedCard) return;

      const { side, id } = state.selectedCard;
      const mySide = ctx.side === 'plants' ? 'plant' : 'zombie';

      // Side mismatch check (shouldn't happen but guard it)
      if (side !== mySide) {
        gToast('te', `You are playing as ${ctx.side}!`);
        return;
      }

      // Optimistic local render
      origOnCellClick(r, c);

      try {
        const data = await gameAPI('place_unit', {
          match_id: ctx.matchId,
          unit_key: id,
          row:      r,
          col:      c,
        });
        // Server response has authoritative units — apply it
        // (SSE will also push this, but apply immediately for responsiveness)
        clearBoard();
        applyUnits(data.units);
      } catch (err) {
        gToast('te', `❌ ${err.message}`);
        // Rollback optimistic render
        state.board[r][c] = null;
        renderCell(r, c);
      }
    };

    // ── End turn ──
    window.endTurn = async function() {
      if (!ctx.online) {
        //originalEndTurn();
        return;
      }
      if (!ctx.myTurn) { gToast('te', "⏳ Not your turn!"); return; }

      const btn = document.getElementById('end-turn-btn');
      btn.disabled   = true;
      btn.textContent = '⏳ Processing...';

      try {
        const data = await gameAPI('end_turn', { match_id: ctx.matchId });

        if (data.game_over) {
          triggerGameOver(data.winner === ctx.side ? 'win' : 'lose');
          return;
        }

        // Apply combat results
        if (data.combat_log?.length) {
          animateCombatLog(data.combat_log);
        }

        clearBoard();
        applyUnits(data.units);
        updateHUD(data.game_state ?? {});
        ctx.myTurn = false;

      } catch (err) {
        gToast('te', `❌ ${err.message}`);
      } finally {
        btn.disabled    = false;
        btn.textContent = '⚡ END TURN';
      }
    };

    // ── Surrender ──
    window.confirmSurrender = async function() {
      if (!ctx.online) {
        if (confirm('Surrender? 🧟')) triggerGameOver('lose');
        return;
      }
      if (!confirm('Surrender? Your opponent wins! 🧟')) return;

      try {
        await gameAPI('surrender', { match_id: ctx.matchId });
        triggerGameOver('lose');
      } catch (err) {
        gToast('te', `❌ ${err.message}`);
      }
    };

    // Disable the card tray for the wrong side
    overrideCardTurnCheck();
  }

  function overrideCardTurnCheck() {
    // board.js updateCardStates checks state.turn ('plant'|'zombie')
    // In multiplayer we check ctx.myTurn instead
    const orig = window.updateCardStates;
    window.updateCardStates = function() {
      if (!ctx.online) { orig?.(); return; }

      const mySide = ctx.side === 'plants' ? 'plant' : 'zombie';

      Object.values(PLANTS).forEach(p => {
        const el = document.getElementById(`pcard-${p.id}`);
        if (!el) return;
        const disabled = !ctx.myTurn || mySide !== 'plant' || state.sun < p.cost;
        el.classList.toggle('card-disabled', disabled);
      });

      Object.values(ZOMBIES).forEach(z => {
        const el = document.getElementById(`zcard-${z.id}`);
        if (!el) return;
        const disabled = !ctx.myTurn || mySide !== 'zombie' || state.brain < z.cost;
        el.classList.toggle('card-disabled', disabled);
      });
    };
  }

  // ── COMBAT LOG ANIMATION ─────────────────────────────────────
  function animateCombatLog(log) {
    log.forEach((entry, i) => {
      setTimeout(() => {
        if (entry.type === 'attack' || entry.type === 'zombie_attack') {
          const from = entry.from ?? entry;
          const to   = entry.to   ?? entry;
          const fromCell = document.getElementById(`cell-${from.row}-${from.col}`);
          const toCell   = document.getElementById(`cell-${to.row}-${to.col}`);
          if (fromCell && toCell) {
            // Flash damage on target
            toCell.classList.add('flash-damage');
            setTimeout(() => toCell.classList.remove('flash-damage'), 350);
            // Float damage number
            floatDamageAt(to.row, to.col, entry.damage,
              entry.type === 'attack' ? '#52b788' : '#ff6b78');
          }
        }
        if (entry.type === 'base_damage') {
          floatDamageBase(entry.damage);
        }
      }, i * 200);
    });
  }

  function floatDamageAt(r, c, dmg, color) {
    const cell = document.getElementById(`cell-${r}-${c}`);
    if (!cell) return;
    const rect = cell.getBoundingClientRect();
    const el   = document.createElement('div');
    el.className   = 'dmg-float';
    el.textContent = `-${dmg}`;
    el.style.color = color;
    el.style.left  = (rect.left + rect.width / 2 - 12) + 'px';
    el.style.top   = (rect.top + 10) + 'px';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 900);
  }

  function floatDamageBase(dmg) {
    const el = document.createElement('div');
    el.className   = 'dmg-float';
    el.textContent = `BASE -${dmg}`;
    el.style.color = 'var(--red)';
    el.style.left  = '80px';
    el.style.top   = '80px';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 900);
  }

  // ── HTTP HELPER ──────────────────────────────────────────────
  async function gameAPI(action, body = {}) {
    body.action = action;
    const res = await fetch(API, {
      method:  'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': `Bearer ${ctx.token}`,
      },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error ?? `HTTP ${res.status}`);
    return data;
  }

  // ── LOBBY REDIRECT ───────────────────────────────────────────
  // Fix the lobby button in game over overlay
  function patchLobbyBtn() {
    document.querySelectorAll('[onclick*="verdant-siege-lobby"]').forEach(btn => {
      btn.onclick = () => { window.location.href = './lobby.html'; };
    });
  }

  // ── PUBLIC ───────────────────────────────────────────────────
  return { init, ctx };

})();

// ── BOOT ────────────────────────────────────────────────────────
// Wait for board.js DOMContentLoaded to finish, then layer on top
window.addEventListener('load', () => {
  Game.init();

  // Fix lobby redirect button
  document.querySelectorAll('#gameover-overlay button').forEach(btn => {
    if (btn.textContent.includes('LOBBY')) {
      btn.onclick = () => { window.location.href = './lobby.html'; };
    }
  });
});