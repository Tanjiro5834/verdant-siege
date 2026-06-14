// ═══════════════════════════════════════════════════════
// CONSTANTS & DEFINITIONS
// ═══════════════════════════════════════════════════════
const ROWS = 5, COLS = 9;
const TURN_TIME = 30;       // seconds per turn
const SUN_DROP_INTERVAL = 6000;
const ZOMBIE_MOVE_DELAY = 500;

// Plant definitions
const PLANTS = {
  peashooter: { id:'peashooter', name:'Pea Shooter', emoji:'🌱', cost:100, hp:3, atk:1, range:9, anim:'plant-anim', proj:'🟢', color:'#52b788' },
  sunflower:  { id:'sunflower',  name:'Sunflower',   emoji:'🌻', cost:50,  hp:2, atk:0, range:0, anim:'sun-anim',   proj:null,  color:'#ffd166', sunGen:25  },
  wallnut:    { id:'wallnut',    name:'Wall-nut',     emoji:'🪨', cost:50,  hp:8, atk:0, range:0, anim:'plant-anim', proj:null,  color:'#adb5bd' },
  chomper:    { id:'chomper',    name:'Chomper',      emoji:'🌿', cost:150, hp:4, atk:3, range:1, anim:'plant-anim', proj:'💚',  color:'#52b788' },
  snowpea:    { id:'snowpea',    name:'Snow Pea',     emoji:'❄️', cost:175, hp:3, atk:1, range:9, anim:'plant-anim', proj:'💠',  color:'#90e0ef', slow:true },
  cherrybomb: { id:'cherrybomb',name:'Cherry Bomb',  emoji:'🍒', cost:150, hp:1, atk:4, range:1, anim:'plant-anim', proj:'💥',  color:'#e63946', splash:true, oneshot:true },
};

// Zombie definitions
const ZOMBIES = {
  basic:   { id:'basic',   name:'Basic Zombie', emoji:'🧟', cost:1, hp:5, atk:1, speed:1, anim:'zombie-anim', proj:null },
  cone:    { id:'cone',    name:'Cone Zombie',  emoji:'🎃', cost:2, hp:9, atk:1, speed:1, anim:'zombie-anim', proj:null },
  bucket:  { id:'bucket',  name:'Bucket Head',  emoji:'🪣', cost:3, hp:14,atk:2, speed:1, anim:'zombie-anim', proj:null },
  flag:    { id:'flag',    name:'Flag Zombie',  emoji:'🚩', cost:1, hp:4, atk:1, speed:2, anim:'zombie-anim', proj:null },
  imp:     { id:'imp',     name:'Imp',          emoji:'👿', cost:1, hp:3, atk:1, speed:2, anim:'zombie-anim', proj:null },
};

// ═══════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════
let state = null;

function initState() {
  return {
    turn: 'plant',           // 'plant' | 'zombie'
    wave: 1, maxWaves: 5,
    sun: 150, brain: 5,
    baseHp: 30, maxBaseHp: 30,
    timerLeft: TURN_TIME,
    timerInterval: null,
    sunDropInterval: null,
    selectedCard: null,      // { side, id }
    // board[row][col] = { type:'plant'|'zombie', def, hp, maxHp, id } | null
    board: Array.from({length:ROWS}, () => Array(COLS).fill(null)),
    unitIdCounter: 0,
    combatLog: [],
    waveInProgress: false,
    gameOver: false,
  };
}

// ═══════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════
window.addEventListener('DOMContentLoaded', () => {
  state = initState();
  buildGrid();
  buildCards();
  placeMockUnits();
  startTurn('plant');
  startSunDrops();
});

// ═══════════════════════════════════════════════════════
// GRID BUILD
// ═══════════════════════════════════════════════════════
function buildGrid() {
  const cont = document.getElementById('grid-container');
  cont.innerHTML = '';
  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const cell = document.createElement('div');
      cell.className = `cell row-${r}`;
      cell.id = `cell-${r}-${c}`;
      cell.dataset.row = r; cell.dataset.col = c;
      if (c <= 3) cell.classList.add('plant-zone');
      else if (c >= 5) cell.classList.add('zombie-zone');
      else cell.classList.add('mid-zone');
      cell.addEventListener('click', () => onCellClick(r, c));
      cell.addEventListener('contextmenu', e => { e.preventDefault(); onCellRightClick(r, c); });
      cont.appendChild(cell);
    }
  }
}

// ═══════════════════════════════════════════════════════
// CARD TRAYS
// ═══════════════════════════════════════════════════════
function buildCards() {
  const pc = document.getElementById('plant-cards');
  const zc = document.getElementById('zombie-cards');
  pc.innerHTML = ''; zc.innerHTML = '';

  Object.values(PLANTS).forEach(p => {
    const el = document.createElement('div');
    el.className = 'plant-card';
    el.id = `pcard-${p.id}`;
    el.innerHTML = `
      <span class="card-cost">🌞${p.cost}</span>
      <span class="card-emoji">${p.emoji}</span>
      <span class="card-name">${p.name}</span>
      <div class="card-cd">—</div>
    `;
    el.addEventListener('click', () => selectCard('plant', p.id));
    pc.appendChild(el);
  });

  Object.values(ZOMBIES).forEach(z => {
    const el = document.createElement('div');
    el.className = 'zombie-card';
    el.id = `zcard-${z.id}`;
    el.innerHTML = `
      <span class="card-cost" style="color:#c77dff">💀${z.cost}</span>
      <span class="card-emoji">${z.emoji}</span>
      <span class="card-name fn" style="font-size:0.58rem;font-weight:800;color:var(--t-light);text-align:center">${z.name}</span>
      <div class="card-cd">—</div>
    `;
    el.addEventListener('click', () => selectCard('zombie', z.id));
    zc.appendChild(el);
  });

  updateCardStates();
}

function updateCardStates() {
  Object.values(PLANTS).forEach(p => {
    const el = document.getElementById(`pcard-${p.id}`);
    if (!el) return;
    const canAfford = state.sun >= p.cost;
    const isMyTurn = state.turn === 'plant';
    const disabled = !canAfford || !isMyTurn;
    el.classList.toggle('card-disabled', disabled);
  });
  Object.values(ZOMBIES).forEach(z => {
    const el = document.getElementById(`zcard-${z.id}`);
    if (!el) return;
    const canAfford = state.brain >= z.cost;
    const isMyTurn = state.turn === 'zombie';
    const disabled = !canAfford || !isMyTurn;
    el.classList.toggle('card-disabled', disabled);
  });
}

function selectCard(side, id) {
  if (state.gameOver) return;
  // Deselect if same card
  if (state.selectedCard && state.selectedCard.side === side && state.selectedCard.id === id) {
    state.selectedCard = null;
    clearCardHighlight();
    return;
  }
  // Turn check
  if (side === 'plant' && state.turn !== 'plant') { gToast('ti', '🌿 It\'s the zombie player\'s turn!'); return; }
  if (side === 'zombie' && state.turn !== 'zombie') { gToast('ti', '🧟 It\'s the plant player\'s turn!'); return; }
  // Cost check
  const def = side === 'plant' ? PLANTS[id] : ZOMBIES[id];
  const resource = side === 'plant' ? state.sun : state.brain;
  if (resource < def.cost) {
    gToast('te', `Not enough ${side === 'plant' ? '☀️ Sun' : '💀 Brains'}!`);
    return;
  }
  clearCardHighlight();
  state.selectedCard = { side, id };
  const el = document.getElementById(`${side === 'plant' ? 'p' : 'z'}card-${id}`);
  if (el) el.classList.add('active-card');
  // highlight valid cells
  highlightValidCells(side);
  gToast('ti', `${def.emoji} Selected — click a cell to place`);
}

function clearCardHighlight() {
  document.querySelectorAll('.plant-card.active-card, .zombie-card.active-card')
    .forEach(el => el.classList.remove('active-card'));
  document.querySelectorAll('.cell.selected-card').forEach(el => el.classList.remove('selected-card'));
}

function highlightValidCells(side) {
  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const cell = getCell(r, c);
      const occupied = state.board[r][c];
      if (side === 'plant' && c <= 3 && !occupied) cell.classList.add('selected-card');
      if (side === 'zombie' && c >= 5 && !occupied) cell.classList.add('selected-card');
    }
  }
}

// ═══════════════════════════════════════════════════════
// CELL INTERACTION
// ═══════════════════════════════════════════════════════
function onCellClick(r, c) {
  if (state.gameOver) return;
  if (!state.selectedCard) return;

  const { side, id } = state.selectedCard;
  const def = side === 'plant' ? PLANTS[id] : ZOMBIES[id];
  const occupied = state.board[r][c];

  // Validate placement zone
  const validPlant  = side === 'plant'  && c <= 3;
  const validZombie = side === 'zombie' && c >= 5;
  if (!validPlant && !validZombie) {
    flashInvalid(r, c);
    gToast('te', side === 'plant' ? '🌿 Plants go on the left side!' : '🧟 Zombies go on the right side!');
    return;
  }
  if (occupied) {
    flashInvalid(r, c);
    gToast('te', '⚠️ Cell already occupied!');
    return;
  }

  // Place unit
  placeUnit(r, c, side, def);

  // Deduct resource
  if (side === 'plant') state.sun -= def.cost;
  else state.brain -= def.cost;

  updateCounters();
  clearCardHighlight();
  state.selectedCard = null;
  updateCardStates();

  if (def.oneshot) {
    // Cherry Bomb: immediate splash damage
    setTimeout(() => cherrySplash(r, c), 300);
  }
}

function onCellRightClick(r, c) {
  // Right-click: remove own unit (shovel mechanic)
  const unit = state.board[r][c];
  if (!unit) return;
  if (unit.type === 'plant' && state.turn === 'plant') {
    removeUnit(r, c);
    state.sun += Math.floor(unit.def.cost * 0.5); // 50% refund
    updateCounters();
    gToast('ts', `🌿 Plant removed — +${Math.floor(unit.def.cost * 0.5)} ☀️ refunded`);
  }
}

// ═══════════════════════════════════════════════════════
// UNIT MANAGEMENT
// ═══════════════════════════════════════════════════════
function placeUnit(r, c, side, def) {
  const uid = ++state.unitIdCounter;
  const unit = { type: side, def, hp: def.hp, maxHp: def.hp, id: uid };
  state.board[r][c] = unit;
  renderCell(r, c);
  return unit;
}

function removeUnit(r, c) {
  state.board[r][c] = null;
  renderCell(r, c);
}

function renderCell(r, c) {
  const cell = getCell(r, c);
  const unit = state.board[r][c];
  // Clear
  cell.innerHTML = '';
  if (!unit) return;

  const hpPct = unit.hp / unit.maxHp;
  const hpClass = hpPct > 0.6 ? 'hp-high' : hpPct > 0.3 ? 'hp-med' : 'hp-low';

  const unitEl = document.createElement('div');
  unitEl.className = `unit ${unit.def.anim}`;
  unitEl.textContent = unit.def.emoji;
  unitEl.title = `${unit.def.name} HP: ${unit.hp}/${unit.maxHp}`;
  if (unit.type === 'zombie') unitEl.style.transform = 'scaleX(-1)'; // face left

  const hpWrap = document.createElement('div');
  hpWrap.className = 'unit-hp-wrap';
  const hpFill = document.createElement('div');
  hpFill.className = `unit-hp-fill ${hpClass}`;
  hpFill.style.width = (hpPct * 100) + '%';
  hpWrap.appendChild(hpFill);

  cell.appendChild(unitEl);
  cell.appendChild(hpWrap);
}

function renderBoard() {
  for (let r = 0; r < ROWS; r++)
    for (let c = 0; c < COLS; c++)
      renderCell(r, c);
}

// ═══════════════════════════════════════════════════════
// MOCK INITIAL STATE
// ═══════════════════════════════════════════════════════
function placeMockUnits() {
  // Plants (left columns)
  [[0,0,'sunflower'],[1,0,'sunflower'],[2,0,'sunflower'],
   [0,1,'peashooter'],[1,1,'peashooter'],[2,1,'peashooter'],[3,1,'peashooter'],
   [0,2,'peashooter'],[4,2,'wallnut'],
   [3,3,'snowpea']
  ].forEach(([r,c,id]) => {
    const def = PLANTS[id];
    placeUnit(r, c, 'plant', def);
  });
  // Zombies (right columns)
  [[0,8,'basic'],[1,7,'cone'],[2,8,'basic'],[3,6,'bucket'],[4,8,'flag']].forEach(([r,c,id]) => {
    placeUnit(r, c, 'zombie', ZOMBIES[id]);
  });
}

// ═══════════════════════════════════════════════════════
// TURN SYSTEM
// ═══════════════════════════════════════════════════════
function startTurn(side) {
  state.turn = side;
  state.timerLeft = TURN_TIME;
  clearInterval(state.timerInterval);
  updateCardStates();
  updateCounters();
  showTurnBanner(side);

  // Reset timer bar
  const fill = document.getElementById('timer-fill');
  fill.style.transition = 'none';
  fill.style.transform = 'scaleX(1)';
  setTimeout(() => {
    fill.style.transition = `transform ${TURN_TIME}s linear`;
    fill.style.transform = 'scaleX(0)';
  }, 50);

  // Update label
  const lbl = document.getElementById('turn-label');
  lbl.textContent = side === 'plant' ? '🌿 YOUR TURN' : '🧟 ENEMY TURN';
  lbl.style.color = side === 'plant' ? 'var(--g-light)' : '#c77dff';

  state.timerInterval = setInterval(() => {
    state.timerLeft--;
    document.getElementById('timer-num').textContent = state.timerLeft;
    if (state.timerLeft <= 5) {
      document.getElementById('timer-num').style.color = 'var(--red)';
    }
    if (state.timerLeft <= 0) endTurn();
  }, 1000);
}

function endTurn() {
  if (state.gameOver) return;
  clearInterval(state.timerInterval);
  document.getElementById('timer-num').style.color = 'var(--yellow)';
  state.selectedCard = null;
  clearCardHighlight();

  // Run combat then switch
  runCombatPhase(() => {
    if (state.gameOver) return;
    if (state.turn === 'plant') {
      // Sunflower income before zombie turn
      generateSunflowerIncome();
      // Zombie turn: AI spawns a zombie
      state.brain += 2; // regen
      updateCounters();
      startTurn('zombie');
      // Simple AI: spawn a zombie in a random empty right column row
      setTimeout(() => zombieAI(), 800);
    } else {
      // After zombie turn, advance zombies and start new plant turn
      advanceZombies(() => {
        if (state.gameOver) return;
        checkWaveEnd();
      });
    }
  });
}

// ═══════════════════════════════════════════════════════
// COMBAT PHASE
// ═══════════════════════════════════════════════════════
function runCombatPhase(cb) {
  let actions = [];

  // Collect attack actions: plants attack zombies in same row
  for (let r = 0; r < ROWS; r++) {
    // Find plants and zombies in this row
    const plantsInRow = [];
    const zombiesInRow = [];
    for (let c = 0; c < COLS; c++) {
      const u = state.board[r][c];
      if (!u) continue;
      if (u.type === 'plant' && u.def.atk > 0) plantsInRow.push({r, c, u});
      if (u.type === 'zombie') zombiesInRow.push({r, c, u});
    }

    plantsInRow.forEach(({r, c, u}) => {
      const def = u.def;
      // Find target: closest zombie in same row
      const targets = zombiesInRow.filter(z => z.c > c).sort((a,b) => a.c - b.c);
      if (targets.length === 0) return;
      const target = targets[0];
      const inRange = def.range >= (target.c - c);
      if (!inRange) return;
      actions.push({ attacker:{r,c,u}, target, dmg: def.atk, proj: def.proj, slow: def.slow });
    });
  }

  if (actions.length === 0) { cb(); return; }

  let done = 0;
  actions.forEach((act, i) => {
    setTimeout(() => {
      fireProjectile(act, () => {
        applyDamage(act.target.r, act.target.c, act.dmg, act.slow);
        done++;
        if (done === actions.length) cb();
      });
    }, i * 180);
  });
}

// ═══════════════════════════════════════════════════════
// PROJECTILE (signature animation)
// ═══════════════════════════════════════════════════════
function fireProjectile(act, onHit) {
  const proj = act.proj;
  if (!proj) { onHit(); return; }

  const fromCell = getCell(act.attacker.r, act.attacker.c);
  const toCell   = getCell(act.target.r,   act.target.c);
  const fromRect = fromCell.getBoundingClientRect();
  const toRect   = toCell.getBoundingClientRect();

  const el = document.createElement('div');
  el.className = 'projectile';
  el.textContent = proj;
  el.style.left = (fromRect.left + fromRect.width/2 - 10) + 'px';
  el.style.top  = (fromRect.top  + fromRect.height/2 - 10) + 'px';

  const dx = toRect.left - fromRect.left;
  const duration = Math.max(0.25, Math.abs(dx) / 800);
  el.style.setProperty('--proj-dx', dx + 'px');
  el.style.setProperty('--proj-dx50', (dx*0.5) + 'px');
  el.style.setProperty('--proj-arc', '-18px');
  el.style.setProperty('--proj-dur', duration + 's');

  document.body.appendChild(el);
  setTimeout(() => {
    el.remove();
    onHit();
  }, duration * 1000);
}

// ═══════════════════════════════════════════════════════
// DAMAGE
// ═══════════════════════════════════════════════════════
function applyDamage(r, c, dmg, slow) {
  const unit = state.board[r][c];
  if (!unit) return;
  unit.hp -= dmg;

  // Flash
  const cell = getCell(r, c);
  cell.classList.add('flash-damage');
  setTimeout(() => cell.classList.remove('flash-damage'), 350);

  // Float damage number
  floatDamage(r, c, dmg, unit.type === 'zombie' ? '#52b788' : '#ff6b78');

  if (unit.hp <= 0) {
    killUnit(r, c);
  } else {
    renderCell(r, c);
    if (slow) {
      // Visual slow effect (tint)
      const cell2 = getCell(r, c);
      cell2.querySelector('.unit') && (cell2.querySelector('.unit').style.filter = 'hue-rotate(180deg)');
      setTimeout(() => { cell2.querySelector('.unit') && (cell2.querySelector('.unit').style.filter = ''); }, 2000);
    }
  }
}

function killUnit(r, c) {
  const unit = state.board[r][c];
  if (!unit) return;
  const cell = getCell(r, c);
  cell.classList.add('death-pop');
  setTimeout(() => {
    cell.classList.remove('death-pop');
    state.board[r][c] = null;
    renderCell(r, c);
  }, 500);
  gToast('ts', `${unit.def.emoji} ${unit.def.name} defeated!`);
}

function floatDamage(r, c, dmg, color) {
  const cell = getCell(r, c);
  const rect = cell.getBoundingClientRect();
  const el = document.createElement('div');
  el.className = 'dmg-float';
  el.textContent = `-${dmg}`;
  el.style.color = color;
  el.style.left = (rect.left + rect.width/2 - 12) + 'px';
  el.style.top  = (rect.top  + 10) + 'px';
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 900);
}

// ═══════════════════════════════════════════════════════
// CHERRY BOMB SPLASH
// ═══════════════════════════════════════════════════════
function cherrySplash(r, c) {
  floatDamage(r, c, '💥', '#fb8500');
  for (let dr = -1; dr <= 1; dr++) {
    for (let dc = -1; dc <= 1; dc++) {
      const nr = r + dr, nc = c + dc;
      if (nr < 0 || nr >= ROWS || nc < 0 || nc >= COLS) continue;
      const u = state.board[nr][nc];
      if (u && u.type === 'zombie') applyDamage(nr, nc, 4);
    }
  }
  // Remove cherry bomb immediately after use
  removeUnit(r, c);
  gToast('ts', '🍒 BOOM! Cherry Bomb exploded!');
}

// ═══════════════════════════════════════════════════════
// ZOMBIE MOVEMENT
// ═══════════════════════════════════════════════════════
function advanceZombies(cb) {
  let moves = 0, done = 0;
  const movers = [];

  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const u = state.board[r][c];
      if (!u || u.type !== 'zombie') continue;
      movers.push({r, c, u});
    }
  }

  if (movers.length === 0) { cb(); return; }

  movers.forEach(({r, c, u}, i) => {
    setTimeout(() => {
      const steps = u.def.speed;
      let moved = false;
      for (let s = 0; s < steps; s++) {
        const nc = c - 1 - s; // zombies move left
        if (nc < 0) {
          // Zombie reached base!
          state.board[r][c] = null;
          renderCell(r, c);
          damageBase(u.def.atk);
          return;
        }
        const target = state.board[r][nc];
        if (target && target.type === 'plant') {
          // Attack plant
          applyDamage(r, nc, u.def.atk);
          break; // zombies stop when hitting a plant
        }
        if (!target) {
          // Move zombie
          state.board[r][c] = null;
          state.board[r][nc] = u;
          renderCell(r, c);
          renderCell(r, nc);
          moved = true;
          break;
        }
      }
      done++;
      if (done === movers.length) cb();
    }, i * 120);
  });
}

function damageBase(dmg) {
  state.baseHp = Math.max(0, state.baseHp - dmg);
  const pct = (state.baseHp / state.maxBaseHp) * 100;
  document.getElementById('base-hp-fill').style.width = pct + '%';
  document.getElementById('base-hp-val').textContent = state.baseHp;
  floatDamageBase(dmg);
  gToast('te', `🏰 Base took ${dmg} damage! HP: ${state.baseHp}`);
  if (state.baseHp <= 0) triggerGameOver('lose');
}

function floatDamageBase(dmg) {
  const hpEl = document.getElementById('base-hp-fill');
  const rect = hpEl.getBoundingClientRect();
  const el = document.createElement('div');
  el.className = 'dmg-float';
  el.textContent = `BASE -${dmg}`;
  el.style.color = 'var(--red)';
  el.style.left = (rect.left + 30) + 'px';
  el.style.top  = rect.top + 'px';
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 900);
}

// ═══════════════════════════════════════════════════════
// ZOMBIE AI (simple: deploy best affordable zombie)
// ═══════════════════════════════════════════════════════
function zombieAI() {
  if (state.turn !== 'zombie') return;
  const affordable = Object.values(ZOMBIES)
    .filter(z => z.cost <= state.brain)
    .sort((a, b) => b.cost - a.cost);
  if (!affordable.length) return;

  // Find empty rows on right side
  for (let attempt = 0; attempt < 10; attempt++) {
    const r = Math.floor(Math.random() * ROWS);
    const c = 8; // always spawn at col 8
    if (!state.board[r][c]) {
      const z = affordable[0];
      placeUnit(r, c, 'zombie', z);
      state.brain -= z.cost;
      updateCounters();
      gToast('ti', `🧟 Enemy deployed ${z.emoji} ${z.name}!`);
      updateCardStates();
      return;
    }
  }
}

// ═══════════════════════════════════════════════════════
// SUN ECONOMY
// ═══════════════════════════════════════════════════════
function generateSunflowerIncome() {
  let sunEarned = 0;
  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const u = state.board[r][c];
      if (u && u.type === 'plant' && u.def.sunGen) {
        sunEarned += u.def.sunGen;
        // visual pulse on sunflower
        const cell = getCell(r, c);
        cell.style.boxShadow = '0 0 18px rgba(255,209,102,0.7)';
        setTimeout(() => { cell.style.boxShadow = ''; }, 600);
      }
    }
  }
  if (sunEarned > 0) {
    state.sun += sunEarned;
    updateCounters();
    gToast('ts', `🌻 Sunflowers generated +${sunEarned} ☀️!`);
  }
}

// Falling sun drops (clickable)
function startSunDrops() {
  state.sunDropInterval = setInterval(() => {
    if (state.gameOver || state.turn !== 'plant') return;
    spawnSunDrop();
  }, SUN_DROP_INTERVAL);
}

function spawnSunDrop() {
  const el = document.createElement('div');
  el.className = 'sun-drop-anim';
  el.textContent = '☀️';
  const x = 60 + Math.random() * (window.innerWidth - 120);
  const fallDist = 200 + Math.random() * 250;
  const dur = 3 + Math.random() * 2;
  el.style.left = x + 'px';
  el.style.top  = '60px';
  el.style.setProperty('--fall-dist', fallDist + 'px');
  el.style.setProperty('--fall-dur', dur + 's');
  el.addEventListener('click', () => {
    state.sun += 25;
    updateCounters();
    el.remove();
    floatDamage(0, 0, 0, '#ffd166'); // just a flash
    // float at click position
    const f = document.createElement('div');
    f.className = 'dmg-float';
    f.textContent = '+25 ☀️';
    f.style.color = 'var(--yellow)';
    f.style.left = x + 'px';
    f.style.top  = (200 + Math.random()*100) + 'px';
    document.body.appendChild(f);
    setTimeout(() => f.remove(), 900);
  });
  document.body.appendChild(el);
  setTimeout(() => el.remove(), (dur + 0.5) * 1000);
}

// ═══════════════════════════════════════════════════════
// WAVE SYSTEM
// ═══════════════════════════════════════════════════════
function checkWaveEnd() {
  // Count zombies on board
  let zombiesLeft = 0;
  for (let r = 0; r < ROWS; r++)
    for (let c = 0; c < COLS; c++)
      if (state.board[r][c]?.type === 'zombie') zombiesLeft++;

  if (zombiesLeft === 0 && state.wave < state.maxWaves) {
    state.wave++;
    document.getElementById('wave-num').textContent = state.wave;
    showWaveAnnounce(state.wave);
    state.sun += 50; // wave bonus
    updateCounters();
    gToast('ts', `🌊 Wave ${state.wave} incoming! +50 ☀️ bonus`);
    // Spawn harder wave
    setTimeout(() => spawnWave(state.wave), 2000);
  } else if (zombiesLeft === 0 && state.wave >= state.maxWaves) {
    triggerGameOver('win');
    return;
  }
  // Start plant turn regardless
  setTimeout(() => startTurn('plant'), 400);
}

function spawnWave(wave) {
  const count = 2 + wave;
  const pool = Object.keys(ZOMBIES);
  for (let i = 0; i < count; i++) {
    const zid = pool[Math.min(wave - 1, pool.length - 1)];
    // Find empty spot on right
    let placed = false;
    for (let attempt = 0; attempt < 20; attempt++) {
      const r = Math.floor(Math.random() * ROWS);
      const c = 7 + (Math.random() > 0.5 ? 1 : 0);
      if (c < COLS && !state.board[r][c]) {
        placeUnit(r, c, 'zombie', ZOMBIES[zid]);
        placed = true;
        break;
      }
    }
  }
  renderBoard();
}

function showWaveAnnounce(wave) {
  const el = document.getElementById('wave-announce');
  document.getElementById('wave-text').textContent = `⚠️ WAVE ${wave} INCOMING!`;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 1900);
}

// ═══════════════════════════════════════════════════════
// COUNTERS
// ═══════════════════════════════════════════════════════
function updateCounters() {
  document.getElementById('sun-count').textContent   = state.sun;
  document.getElementById('brain-count').textContent = state.brain;
  updateCardStates();
}

// ═══════════════════════════════════════════════════════
// TURN BANNER
// ═══════════════════════════════════════════════════════
function showTurnBanner(side) {
  const el = document.getElementById('turn-banner');
  if (side === 'plant') {
    el.textContent = '🌿 PLANT TURN';
    el.style.background = 'linear-gradient(135deg, rgba(45,106,79,0.9), rgba(82,183,136,0.8))';
    el.style.color = 'white';
    el.style.border = '2px solid rgba(82,183,136,0.5)';
  } else {
    el.textContent = '🧟 ZOMBIE TURN';
    el.style.background = 'linear-gradient(135deg, rgba(91,25,145,0.9), rgba(123,44,191,0.8))';
    el.style.color = 'white';
    el.style.border = '2px solid rgba(123,44,191,0.5)';
  }
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 1800);
}

// ═══════════════════════════════════════════════════════
// FLASH INVALID
// ═══════════════════════════════════════════════════════
function flashInvalid(r, c) {
  const cell = getCell(r, c);
  cell.classList.add('invalid-drop');
  setTimeout(() => cell.classList.remove('invalid-drop'), 400);
}

// ═══════════════════════════════════════════════════════
// SURRENDER
// ═══════════════════════════════════════════════════════
function confirmSurrender() {
  if (state.gameOver) return;
  if (confirm('Surrender? Your base will fall to the zombies! 🧟')) {
    triggerGameOver('lose');
  }
}

// ═══════════════════════════════════════════════════════
// GAME OVER
// ═══════════════════════════════════════════════════════
function triggerGameOver(result) {
  state.gameOver = true;
  clearInterval(state.timerInterval);
  clearInterval(state.sunDropInterval);

  const overlay = document.getElementById('gameover-overlay');
  const emoji   = document.getElementById('go-emoji');
  const title   = document.getElementById('go-title');
  const sub     = document.getElementById('go-sub');

  if (result === 'win') {
    emoji.textContent = '🏆';
    title.textContent = 'VICTORY!';
    title.style.color = 'var(--yellow)';
    sub.textContent   = 'Your garden repelled all the zombies! Well defended, Gardener.';
    confettiBurst();
  } else {
    emoji.textContent = '💀';
    title.textContent = 'DEFEATED!';
    title.style.color = 'var(--red)';
    sub.textContent   = 'The zombies overran your base. Better luck next siege!';
  }
  overlay.classList.add('show');
}

function restartGame() {
  document.getElementById('gameover-overlay').classList.remove('show');
  state = initState();
  buildGrid();
  buildCards();
  placeMockUnits();
  startTurn('plant');
  startSunDrops();
  updateCounters();
  document.getElementById('base-hp-fill').style.width = '100%';
  document.getElementById('base-hp-val').textContent = '30';
  document.getElementById('wave-num').textContent = '1';
}

// ═══════════════════════════════════════════════════════
// CONFETTI
// ═══════════════════════════════════════════════════════
function confettiBurst() {
  const colors = ['#2d6a4f','#ffd166','#52b788','#7b2cbf','#e63946','#fb8500'];
  for (let i = 0; i < 70; i++) {
    const p = document.createElement('div');
    const sz = Math.random()*10+4;
    p.className = 'cf';
    p.style.cssText = `
      left:${Math.random()*100}vw; top:-10px;
      width:${sz}px; height:${sz}px;
      background:${colors[Math.floor(Math.random()*colors.length)]};
      border-radius:${Math.random()>0.5?'50%':'3px'};
      animation:cfFall ${Math.random()*1.6+0.8}s linear ${Math.random()*0.6}s forwards;
    `;
    document.body.appendChild(p);
    setTimeout(() => p.remove(), 3500);
  }
}

// ═══════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════
function gToast(type, msg) {
  const cont = document.getElementById('toast-cont');
  const el = document.createElement('div');
  el.className = `g-toast ${type}`;
  el.innerHTML = `<span>${msg}</span>`;
  cont.appendChild(el);
  // Max 4 toasts
  while (cont.children.length > 4) cont.removeChild(cont.firstChild);
  setTimeout(() => {
    el.style.animation = 'tOut 0.25s ease forwards';
    setTimeout(() => el.remove(), 260);
  }, 2800);
}

// ═══════════════════════════════════════════════════════
// UTIL
// ═══════════════════════════════════════════════════════
function getCell(r, c) {
  return document.getElementById(`cell-${r}-${c}`);
}