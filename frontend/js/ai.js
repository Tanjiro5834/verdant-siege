/**
 * Verdant Siege — Single Player AI Engine
 * ─────────────────────────────────────────────────────────────
 * Real-time PvZ-style game loop. No backend, no turns.
 * Runs entirely in the browser on top of board.js.
 *
 * Load order in board.html:
 *   <script src="./assets/js/board.js"></script>
 *   <script src="./assets/js/ai.js"></script>   ← this file
 *
 * Only activates when NO match_id in URL (single player mode).
 * If match_id present → game.js handles multiplayer instead.
 *
 * Game loop (runs every TICK ms):
 *   1. Sunflowers generate sun
 *   2. Plants shoot at zombies in range
 *   3. Zombies attack adjacent plants
 *   4. Zombies march left one step
 *   5. Check base damage / win condition
 *   6. Spawn new zombies on schedule
 */

'use strict';

const AI = (() => {

  // ── DIFFICULTY PRESETS ────────────────────────────────────────
  const DIFFICULTIES = {
    easy: {
      label: 'Easy',
      emoji: '🌱',
      desc:  'Slow zombies, more sun to start',
      color: '#52b788',
      tick:         2000,   // ms per game tick
      hpMult:       0.75,   // zombie HP multiplier
      spawnInterval:8,      // ticks between zombie spawns
      startSun:     200,
      sunPerTick:   0,      // bonus sun per tick
      waveSize:     [2, 3, 4, 5, 6],
    },
    normal: {
      label: 'Normal',
      emoji: '🌻',
      desc:  'Balanced challenge',
      color: '#ffd166',
      tick:         1500,
      hpMult:       1.0,
      spawnInterval:6,
      startSun:     150,
      sunPerTick:   0,
      waveSize:     [3, 4, 5, 6, 8],
    },
    hard: {
      label: 'Hard',
      emoji: '🧟',
      desc:  'Fast zombies, scarce sun',
      color: '#fb8500',
      tick:         1000,
      hpMult:       1.5,
      spawnInterval:4,
      startSun:     100,
      sunPerTick:   0,
      waveSize:     [4, 5, 7, 8, 10],
    },
    nightmare: {
      label: 'Nightmare',
      emoji: '💀',
      desc:  'Maximum chaos',
      color: '#e63946',
      tick:         700,
      hpMult:       2.0,
      spawnInterval:3,
      startSun:     75,
      sunPerTick:   0,
      waveSize:     [5, 7, 9, 11, 14],
    },
  };

  // Zombie pool per wave (harder waves get tougher zombies)
  const WAVE_POOLS = [
    ['basic', 'basic', 'basic'],
    ['basic', 'basic', 'cone'],
    ['basic', 'cone', 'cone', 'bucket'],
    ['cone', 'bucket', 'flag', 'imp'],
    ['bucket', 'bucket', 'flag', 'imp', 'cone'],
  ];

  // ── STATE ─────────────────────────────────────────────────────
  let ai = {
    active:       false,
    difficulty:   null,
    preset:       null,
    tickInterval: null,
    sunInterval:  null,
    tickCount:    0,
    wave:         1,
    maxWaves:     5,
    zombiesLeftInWave: 0,
    zombiesToSpawn:    [],   // queue of zombie keys to spawn
    spawnQueue:        [],
    waveClearing:      false,
    gameOver:          false,
    baseHp:            30,
    maxBaseHp:         30,
  };

  // ── INIT ──────────────────────────────────────────────────────
  function init() {
    // Only activate when no match_id (single player)
    const params  = new URLSearchParams(window.location.search);
    const matchId = params.get('match_id');

    if (matchId) {
      console.log('[AI] match_id detected — deferring to game.js');
      return;
    }

    // Patch board.js to disable turn system
    patchBoardJS();

    // Show difficulty modal on load
    document.addEventListener('DOMContentLoaded', () => {
      injectDifficultyModal();
      injectAIStyles();
      showDifficultyModal();
    });
  }

  // ── PATCH BOARD.JS ────────────────────────────────────────────
  // Disable the turn-based system — AI handles everything
  function patchBoardJS() {
    // Override DOMContentLoaded init so board.js doesn't auto-start
    const origAddEvent = window.addEventListener.bind(window);
    window.addEventListener = function(type, fn, opts) {
      if (type === 'DOMContentLoaded' && fn.toString().includes('placeMockUnits')) {
        // Replace with our version
        origAddEvent('DOMContentLoaded', () => {
          state = initState();
          buildGrid();
          buildCards();
          updateHUD();
          // Don't call placeMockUnits or startTurn — AI modal handles start
        }, opts);
        return;
      }
      origAddEvent(type, fn, opts);
    };

    // Disable endTurn button default behavior
    window.endTurn = () => {
      if (!ai.active) return;
      // In real-time mode, end turn = shovel selected plant (or just ignore)
      gToast('ti', '🌿 Game is real-time — plants and zombies act automatically!');
    };

    // Right-click shovel works any time (not turn-restricted)
    window.onCellRightClick = function(r, c) {
      const unit = state.board[r][c];
      if (!unit || unit.type !== 'plant') return;
      removeUnit(r, c);
      state.sun += Math.floor(unit.def.cost * 0.5);
      updateCounters();
      gToast('ts', `🌿 Shoveled — +${Math.floor(unit.def.cost * 0.5)} ☀️`);
    };

    // Cell click: plant placement only (no zombie cards in single player)
    window.onCellClick = function(r, c) {
      if (!ai.active || ai.gameOver) return;
      if (!state.selectedCard) return;

      const { side, id } = state.selectedCard;

      // Single player: only plants side
      if (side !== 'plant') {
        gToast('te', '🌿 Single player: you control plants only!');
        return;
      }

      const def      = PLANTS[id];
      const occupied = state.board[r][c];

      if (c > 3) { flashInvalid(r, c); gToast('te', '🌿 Plants go on the left side!'); return; }
      if (occupied) { flashInvalid(r, c); gToast('te', '⚠️ Cell already occupied!'); return; }
      if (state.sun < def.cost) { gToast('te', '☀️ Not enough sun!'); return; }

      placeUnit(r, c, 'plant', def);
      state.sun -= def.cost;
      updateCounters();
      clearCardHighlight();
      state.selectedCard = null;

      if (def.oneshot) setTimeout(() => cherrySplash(r, c), 300);
    };
  }

  // ── START GAME ────────────────────────────────────────────────
  function startGame(difficultyKey) {
    ai.difficulty = difficultyKey;
    ai.preset     = DIFFICULTIES[difficultyKey];
    ai.active     = true;
    ai.gameOver   = false;
    ai.tickCount  = 0;
    ai.wave       = 1;
    ai.baseHp     = 30;
    ai.waveClearing = false;

    // Reset board state
    state = initState();
    state.sun  = ai.preset.startSun;
    state.turn = 'plant'; // always plants in single player
    buildGrid();
    buildCards();

    // Hide zombie cards in single player
    document.getElementById('zombie-cards').style.display = 'none';

    // Update HUD
    updateHUD();
    updateCounters();
    hideDifficultyModal();

    // Update turn label to real-time indicator
    const lbl = document.getElementById('turn-label');
    if (lbl) { lbl.textContent = '🌿 REAL-TIME'; lbl.style.color = 'var(--g-light)'; }

    // Hide timer (no turns)
    const timerTrack = document.getElementById('timer-track');
    const timerNum   = document.getElementById('timer-num');
    if (timerTrack) timerTrack.style.display = 'none';
    if (timerNum)   timerNum.style.display   = 'none';

    // Hide brain counter (no zombie resources)
    document.querySelectorAll('.chip-brain').forEach(el => el.style.display = 'none');

    // Hide end turn button
    const endBtn = document.getElementById('end-turn-btn');
    if (endBtn) endBtn.style.display = 'none';

    // Build first wave spawn queue
    buildWaveQueue(ai.wave);

    const originalTriggerGameOver = window.triggerGameOver;
    window.triggerGameOver = function(result) {
      ai.gameOver = true;
      clearInterval(ai.tickInterval);
      saveMatchResult(result === 'win' ? 'win' : 'loss');
      const sub = document.getElementById('go-sub');
      if (sub) {
        const diffLabel = ai.preset?.label ?? '';
        const stats = result === 'win'
          ? `Difficulty: ${diffLabel} · All ${ai.maxWaves} waves cleared! 🌻`
          : `Difficulty: ${diffLabel} · Reached Wave ${ai.wave} · Base HP: ${ai.baseHp}`;
        sub.textContent = stats;
      }
      originalTriggerGameOver(result);
    };

    // Start sun drops
    startSunDrops();

    // Start game tick
    ai.tickInterval = setInterval(gameTick, ai.preset.tick);

    gToast('ts', `🌻 Wave 1 incoming! Difficulty: ${ai.preset.label} ${ai.preset.emoji}`);
    showWaveAnnounce(1);
  }

  // ── GAME TICK (heart of the AI) ───────────────────────────────
  function gameTick() {
    if (ai.gameOver) return;

    ai.tickCount++;

    // 1. Sunflower income
    sunflowerTick();

    // 2. Plants attack zombies
    plantAttackTick();

    // 3. Zombies attack adjacent plants
    zombieAttackTick();

    // 4. Zombies march left
    zombieMarchTick();

    // 5. Spawn next zombie from queue
    if (ai.tickCount % ai.preset.spawnInterval === 0) {
      spawnNextZombie();
    }

    // 6. Check wave complete
    checkWaveComplete();

    // 7. Check game over
    checkGameOver();

    // Re-render
    renderBoard();
    updateCounters();
    updateBaseHpBar();
  }

  // ── SUNFLOWER TICK ────────────────────────────────────────────
  function sunflowerTick() {
    // Every 3 ticks, sunflowers generate sun
    if (ai.tickCount % 3 !== 0) return;
    let earned = 0;
    for (let r = 0; r < ROWS; r++) {
      for (let c = 0; c < COLS; c++) {
        const u = state.board[r][c];
        if (u && u.type === 'plant' && u.def.sunGen) {
          earned += u.def.sunGen;
          // Pulse effect on sunflower cell
          const cell = getCell(r, c);
          cell.style.boxShadow = '0 0 14px rgba(255,209,102,0.7)';
          setTimeout(() => { if (cell) cell.style.boxShadow = ''; }, 400);
        }
      }
    }
    if (earned > 0) {
      state.sun += earned;
    }
  }

  // ── PLANT ATTACK TICK ─────────────────────────────────────────
  function plantAttackTick() {
    for (let r = 0; r < ROWS; r++) {
      for (let c = 0; c < COLS; c++) {
        const plant = state.board[r][c];
        if (!plant || plant.type !== 'plant') continue;
        if (!plant.def.atk || plant.def.atk <= 0) continue;

        const range = plant.def.range ?? 1;

        // Scan right — find first zombie within range
        for (let tc = c + 1; tc <= Math.min(c + range, COLS - 1); tc++) {
          const target = state.board[r][tc];
          if (!target) continue;              // empty cell — keep scanning
          if (target.type === 'plant') break; // friendly plant blocking — stop
          if (target.type !== 'zombie') continue;

          // Found a zombie in range — fire
          const dmg = plant.def.atk;
          fireProjectileVisual(r, c, tc, plant.def.proj);

          // Capture tc in closure
          ((targetRow, targetCol, damage) => {
            setTimeout(() => {
              const t = state.board[targetRow][targetCol];
              if (!t || t.type !== 'zombie') return;
              t.hp -= damage;
              floatDmgAt(targetRow, targetCol, damage, '#52b788');
              if (t.hp <= 0) killUnit(targetRow, targetCol);
              else renderCell(targetRow, targetCol);
            }, 250);
          })(r, tc, dmg);

          break; // one target per tick per plant
        }
      }
    }
  }
  // ── ZOMBIE ATTACK TICK ────────────────────────────────────────
  function zombieAttackTick() {
    for (let r = 0; r < ROWS; r++) {
      for (let c = COLS - 1; c >= 0; c--) {
        const zombie = state.board[r][c];
        if (!zombie || zombie.type !== 'zombie') continue;

        const nc = c - 1;
        if (nc < 0) continue; // handled in march

        const target = state.board[r][nc];
        if (!target || target.type !== 'plant') continue;

        // Zombie attacks plant
        target.hp -= zombie.def.atk;
        floatDmgAt(r, nc, zombie.def.atk, '#ff6b78');

        if (target.hp <= 0) {
          killUnit(r, nc);
        } else {
          renderCell(r, nc);
        }
      }
    }
  }

  // ── ZOMBIE MARCH TICK ─────────────────────────────────────────
  function zombieMarchTick() {
    // Process right to left to avoid conflicts
    for (let r = 0; r < ROWS; r++) {
      for (let c = 0; c < COLS; c++) {
        const zombie = state.board[r][c];
        if (!zombie || zombie.type !== 'zombie') continue;

        const nc = c - 1;

        if (nc < 0) {
          // Reached base
          damageBase(zombie.def.atk);
          state.board[r][c] = null;
          renderCell(r, c);
          floatDmgBase(zombie.def.atk);
          continue;
        }

        // Blocked by plant — don't move (zombie attacks instead)
        if (state.board[r][nc] && state.board[r][nc].type === 'plant') continue;

        // Move zombie left
        state.board[r][nc] = zombie;
        state.board[r][c]  = null;
      }
    }
  }

  // ── BASE DAMAGE ───────────────────────────────────────────────
  function damageBase(dmg) {
    ai.baseHp = Math.max(0, ai.baseHp - dmg);
    updateBaseHpBar();
    gToast('te', `🏰 Base took ${dmg} damage! HP: ${ai.baseHp}/${ai.maxBaseHp}`);
  }

  function updateBaseHpBar() {
    const pct  = (ai.baseHp / ai.maxBaseHp) * 100;
    const fill = document.getElementById('base-hp-fill');
    const val  = document.getElementById('base-hp-val');
    if (fill) fill.style.width = pct + '%';
    if (val)  val.textContent  = ai.baseHp;
  }

  // ── ZOMBIE SPAWNING ───────────────────────────────────────────
  function buildWaveQueue(wave) {
    const preset   = ai.preset;
    const waveIdx  = Math.min(wave - 1, WAVE_POOLS.length - 1);
    const pool     = WAVE_POOLS[waveIdx];
    const count    = preset.waveSize[Math.min(wave - 1, preset.waveSize.length - 1)];

    ai.spawnQueue = [];
    for (let i = 0; i < count; i++) {
      ai.spawnQueue.push(pool[i % pool.length]);
    }
    ai.zombiesLeftInWave = count;
  }

  function spawnNextZombie() {
    if (ai.spawnQueue.length === 0) return;

    const zKey   = ai.spawnQueue.shift();
    const zDef   = ZOMBIES[zKey];
    if (!zDef) return;

    // Scale HP by difficulty
    const scaledDef = {
      ...zDef,
      hp: Math.ceil(zDef.hp * ai.preset.hpMult),
    };

    // Find empty right-side cell
    const rows = [0,1,2,3,4].sort(() => Math.random() - 0.5);
    for (const r of rows) {
      const c = Math.random() > 0.5 ? 8 : 7;
      if (!state.board[r][c]) {
        const unit = { type: 'zombie', def: scaledDef, hp: scaledDef.hp, maxHp: scaledDef.hp, id: ++state.unitIdCounter };
        state.board[r][c] = unit;
        renderCell(r, c);
        return;
      }
    }
    // All preferred cols full — try any right col
    for (let c = 8; c >= 5; c--) {
      for (let r = 0; r < ROWS; r++) {
        if (!state.board[r][c]) {
          const unit = { type: 'zombie', def: scaledDef, hp: scaledDef.hp, maxHp: scaledDef.hp, id: ++state.unitIdCounter };
          state.board[r][c] = unit;
          renderCell(r, c);
          return;
        }
      }
    }
  }

  // ── WAVE COMPLETE CHECK ───────────────────────────────────────
  function checkWaveComplete() {
    if (ai.waveClearing) return;
    if (ai.spawnQueue.length > 0) return; // still spawning

    // Count zombies on board
    let zombiesOnBoard = 0;
    for (let r = 0; r < ROWS; r++)
      for (let c = 0; c < COLS; c++)
        if (state.board[r][c]?.type === 'zombie') zombiesOnBoard++;

    if (zombiesOnBoard > 0) return; // still fighting

    // Wave cleared
    ai.waveClearing = true;

    if (ai.wave >= ai.maxWaves) {
      triggerGameOver('win');
      return;
    }

    ai.wave++;
    document.getElementById('wave-num').textContent = ai.wave;
    state.sun += 50; // wave bonus
    updateCounters();
    gToast('ts', `🌊 Wave ${ai.wave} incoming! +50 ☀️`);
    showWaveAnnounce(ai.wave);

    setTimeout(() => {
      buildWaveQueue(ai.wave);
      ai.waveClearing = false;
    }, 3000);
  }

  // ── GAME OVER CHECK ───────────────────────────────────────────
  function checkGameOver() {
    if (ai.baseHp <= 0) {
      triggerGameOver('lose');
    }
  }

  // ── KILL UNIT ─────────────────────────────────────────────────
  function killUnit(r, c) {
    const cell = getCell(r, c);
    cell.classList.add('death-pop');
    setTimeout(() => {
      cell.classList.remove('death-pop');
      state.board[r][c] = null;
      renderCell(r, c);
    }, 400);
  }

  // ── PROJECTILE VISUAL ─────────────────────────────────────────
  function fireProjectileVisual(r, fromCol, toCol, proj) {
    if (!proj) return;
    const fromCell = getCell(r, fromCol);
    const toCell   = getCell(r, toCol);
    if (!fromCell || !toCell) return;

    const fr = fromCell.getBoundingClientRect();
    const tr = toCell.getBoundingClientRect();
    const dx = tr.left - fr.left;

    const el = document.createElement('div');
    el.className   = 'projectile';
    el.textContent = proj;
    el.style.left  = (fr.left + fr.width / 2 - 10) + 'px';
    el.style.top   = (fr.top  + fr.height / 2 - 10) + 'px';
    el.style.setProperty('--proj-dx',   dx + 'px');
    el.style.setProperty('--proj-dx50', (dx * 0.5) + 'px');
    el.style.setProperty('--proj-arc',  '-14px');
    el.style.setProperty('--proj-dur',  '0.25s');
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 300);
  }

  // ── DAMAGE FLOATS ─────────────────────────────────────────────
  function floatDmgAt(r, c, dmg, color) {
    const cell = getCell(r, c);
    if (!cell) return;
    const rect = cell.getBoundingClientRect();
    const el   = document.createElement('div');
    el.className   = 'dmg-float';
    el.textContent = `-${dmg}`;
    el.style.color = color;
    el.style.left  = (rect.left + rect.width / 2 - 12) + 'px';
    el.style.top   = (rect.top + 8) + 'px';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 900);
  }

  function floatDmgBase(dmg) {
    const el = document.createElement('div');
    el.className   = 'dmg-float';
    el.textContent = `🏰 -${dmg}`;
    el.style.color = 'var(--red)';
    el.style.left  = '60px';
    el.style.top   = '80px';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 900);
  }

  // ── SUN DROPS (clickable falling suns) ────────────────────────
  function startSunDrops() {
    // Sun drops every 7-12 seconds
    scheduleSunDrop();
  }

  function scheduleSunDrop() {
    if (ai.gameOver) return;
    const delay = 7000 + Math.random() * 5000;
    setTimeout(() => {
      spawnSunDrop();
      scheduleSunDrop();
    }, delay);
  }

  function spawnSunDrop() {
    if (ai.gameOver) return;
    const el  = document.createElement('div');
    el.className = 'sun-drop-anim';
    el.textContent = '☀️';
    const x        = 60 + Math.random() * (window.innerWidth - 200);
    const fallDist = 180 + Math.random() * 200;
    const dur      = 3 + Math.random() * 2;
    el.style.left = x + 'px';
    el.style.top  = '60px';
    el.style.setProperty('--fall-dist', fallDist + 'px');
    el.style.setProperty('--fall-dur',  dur + 's');
    el.addEventListener('click', () => {
      state.sun += 25;
      updateCounters();
      el.remove();
      const f = document.createElement('div');
      f.className   = 'dmg-float';
      f.textContent = '+25 ☀️';
      f.style.color = 'var(--yellow)';
      f.style.left  = x + 'px';
      f.style.top   = (150 + Math.random() * 100) + 'px';
      document.body.appendChild(f);
      setTimeout(() => f.remove(), 900);
    });
    document.body.appendChild(el);
    setTimeout(() => el.remove(), (dur + 0.5) * 1000);
  }

  // ── HUD UPDATES ───────────────────────────────────────────────
  function updateHUD() {
    document.getElementById('wave-num').textContent = ai.wave || 1;
    updateBaseHpBar();
  }

  // ── SURRENDER ─────────────────────────────────────────────────
  window.confirmSurrender = function() {
    if (ai.gameOver) return;
    if (confirm('Give up? The zombies win! 🧟')) {
      triggerGameOver('lose');
    }
  };

  // ── RESTART ───────────────────────────────────────────────────
  window.restartGame = function() {
    clearInterval(ai.tickInterval);
    ai.active   = false;
    ai.gameOver = false;
    document.getElementById('gameover-overlay').classList.remove('show');
    // Re-show difficulty modal
    showDifficultyModal();
  };

  // ── DIFFICULTY MODAL ─────────────────────────────────────────
  function injectDifficultyModal() {
    const modal = document.createElement('div');
    modal.id = 'difficulty-modal';
    modal.style.cssText = `
      position: fixed; inset: 0; z-index: 9500;
      background: rgba(0,0,0,0.85); backdrop-filter: blur(10px);
      display: flex; align-items: center; justify-content: center;
    `;

    modal.innerHTML = `
      <div style="
        background: linear-gradient(145deg, #1a2e1a, #1e1630);
        border: 2px solid rgba(82,183,136,0.35);
        border-radius: 28px; padding: 40px 36px;
        max-width: 520px; width: 92%; text-align: center;
        box-shadow: 0 20px 60px rgba(0,0,0,0.7);
      ">
        <div style="font-size:3rem; margin-bottom:12px">🌻</div>
        <h2 style="font-family:'Luckiest Guy',cursive; font-size:2rem;
            color:#ffd166; letter-spacing:2px; margin-bottom:6px">
          VERDANT SIEGE
        </h2>
        <p style="font-family:'Nunito',sans-serif; font-size:0.85rem;
            color:rgba(254,250,224,0.5); font-weight:700; margin-bottom:28px;
            letter-spacing:1px; text-transform:uppercase">
          Choose Difficulty
        </p>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:28px">
          ${Object.entries(DIFFICULTIES).map(([key, d]) => `
            <button onclick="AI.selectDifficulty('${key}')" style="
              padding: 18px 12px; border-radius: 16px;
              background: rgba(255,255,255,0.05);
              border: 2px solid ${d.color}44;
              cursor: pointer; transition: all 0.18s ease;
              text-align: center;
            "
            onmouseover="this.style.background='${d.color}22'; this.style.borderColor='${d.color}'"
            onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.borderColor='${d.color}44'"
            >
              <div style="font-size:1.8rem; margin-bottom:6px">${d.emoji}</div>
              <div style="font-family:'Luckiest Guy',cursive; font-size:1rem;
                  color:${d.color}; letter-spacing:1px; margin-bottom:4px">
                ${d.label}
              </div>
              <div style="font-family:'Nunito',sans-serif; font-size:0.72rem;
                  color:rgba(254,250,224,0.45); font-weight:700">
                ${d.desc}
              </div>
              <div style="font-family:'Nunito',sans-serif; font-size:0.65rem;
                  color:rgba(254,250,224,0.3); font-weight:700; margin-top:6px">
                🕐 ${d.tick}ms tick · 🧟 HP ×${d.hpMult}
              </div>
            </button>
          `).join('')}
        </div>

        <p style="font-family:'Nunito',sans-serif; font-size:0.75rem;
            color:rgba(254,250,224,0.3); font-weight:700">
          🌿 Place plants on the left · Click ☀️ to collect sun · Right-click plants to shovel
        </p>
      </div>
    `;

    document.body.appendChild(modal);
  }

  function showDifficultyModal() {
    const modal = document.getElementById('difficulty-modal');
    if (modal) modal.style.display = 'flex';
  }

  function hideDifficultyModal() {
    const modal = document.getElementById('difficulty-modal');
    if (modal) modal.style.display = 'none';
  }

  function selectDifficulty(key) {
    startGame(key);
  }

  // ── AI STYLES ─────────────────────────────────────────────────
  function injectAIStyles() {
    const style = document.createElement('style');
    style.textContent = `
      @keyframes confettiFall {
        0%   { transform: translateY(-10px) rotate(0deg); opacity: 1; }
        100% { transform: translateY(100vh)  rotate(720deg); opacity: 0; }
      }
      #end-turn-btn { display: none !important; }
    `;
    document.head.appendChild(style);
  }

  // ── CHERRY BOMB (keep working in real-time) ───────────────────
  window.cherrySplash = function(r, c) {
    floatDmgAt(r, c, '💥', '#fb8500');
    for (let dr = -1; dr <= 1; dr++) {
      for (let dc = -1; dc <= 1; dc++) {
        const nr = r + dr, nc = c + dc;
        if (nr < 0 || nr >= ROWS || nc < 0 || nc >= COLS) continue;
        const u = state.board[nr][nc];
        if (u && u.type === 'zombie') {
          u.hp -= 4;
          floatDmgAt(nr, nc, 4, '#52b788');
          if (u.hp <= 0) killUnit(nr, nc);
          else renderCell(nr, nc);
        }
      }
    }
    state.board[r][c] = null;
    renderCell(r, c);
    gToast('ts', '🍒 BOOM! Cherry Bomb exploded!');
  };

  // ── SAVE MATCH RESULT TO DB ───────────────────────────────
  async function saveMatchResult(result) {
    const token    = sessionStorage.getItem('auth_token');
    const userId   = sessionStorage.getItem('user_id');
    const username = sessionStorage.getItem('username');

    if (!token || !userId) return; // not logged in — skip save

    try {
      const res = await fetch('http://localhost/verdant-siege/backend/api/Auth.php?action=verify&token=' + token);
      const verified = await res.json();
      if (!verified.success) return;

      await fetch('http://localhost/verdant-siege/backend/api/save_match.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save_ai', 
          token,
          result,                          // 'win' | 'lose'
          difficulty: ai.difficulty,
          wave_reached: ai.wave,
          base_hp_left: ai.baseHp,
          duration_secs: Math.floor(ai.tickCount * ai.preset.tick / 1000),
        })
      });

      console.log(`[AI] Match result saved: ${result}`);
    } catch (err) {
      console.warn('[AI] Could not save match result:', err);
    }
  }

  // ── PUBLIC ────────────────────────────────────────────────────
  return { init, selectDifficulty, ctx: ai };

})();

// ── BOOT ──────────────────────────────────────────────────────────
AI.init();