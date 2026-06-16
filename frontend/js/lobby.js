// ══════════════════════════════════════════════
// CONFIG
// ══════════════════════════════════════════════
const LOBBY_API = "http://localhost/verdant-siege/backend/api/Lobby.php";

// ══════════════════════════════════════════════
// MOCK DATA (friends + chat stay mock for now)
// ══════════════════════════════════════════════
const FRIENDS = [
  {
    name: "ZombieSlayer",
    avatar: "🧟",
    status: "online",
    statusText: "In lobby",
    color: "#52b788",
  },
  {
    name: "SunDropper",
    avatar: "☀️",
    status: "in-game",
    statusText: "Playing Ranked",
    color: "#ffd166",
  },
  {
    name: "PeaShooterPro",
    avatar: "🌿",
    status: "online",
    statusText: "Online",
    color: "#52b788",
  },
  {
    name: "GardenGuard",
    avatar: "🌻",
    status: "in-game",
    statusText: "Playing Casual",
    color: "#ffd166",
  },
  {
    name: "NightCrawler",
    avatar: "🌙",
    status: "offline",
    statusText: "2 hours ago",
    color: "",
  },
  {
    name: "BrainEater",
    avatar: "💀",
    status: "offline",
    statusText: "Yesterday",
    color: "",
  },
  {
    name: "ThornyRose",
    avatar: "🌹",
    status: "offline",
    statusText: "3 days ago",
    color: "",
  },
];

const CHAT_SEED = [
  { user: "ZombieKing", color: "purple", msg: "anyone want to 2v2? 🧟" },
  {
    user: "SunDropper",
    color: "yellow",
    msg: "gg last match! those ranked queues are brutal",
  },
  { user: "GardenGuard", color: "green", msg: "Night Defense is 🔥 right now" },
  {
    user: "PlantMaster",
    color: "orange",
    msg: "finally hit Diamond! took long enough 🌻",
  },
  {
    user: "NightCrawler",
    color: "purple",
    msg: "who buffed the zombie horde?? 😭",
  },
  {
    user: "PeaShooterPro",
    color: "green",
    msg: "tip: stack sunflowers on left lane",
  },
  {
    user: "BrainEater42",
    color: "yellow",
    msg: "leaderboard race is getting tight at top 5",
  },
];

// ══════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════
document.addEventListener("DOMContentLoaded", async () => {
  buildBgParticles();
  buildBattlefield();
  buildFriends();
  seedChat();
  startOnlineCounter();
  startCoinFlicker();

  document.querySelectorAll(".modal-overlay").forEach((m) => {
    m.addEventListener("click", (e) => {
      if (e.target === m) closeModal(m.id);
    });
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape")
      document
        .querySelectorAll(".modal-overlay.open")
        .forEach((m) => m.classList.remove("open"));
  });

  // Auth guard
  const token = sessionStorage.getItem("auth_token");
  if (!token) {
    window.location.href = "./login.html";
    return;
  }

  // Load real data
  await loadLobbyData(token);
});

// ══════════════════════════════════════════════
// LOAD LOBBY DATA
// ══════════════════════════════════════════════
async function loadLobbyData(token) {
  try {
    const res = await fetch(`${LOBBY_API}?action=data&token=${token}`);
    const data = await res.json();

    if (!data.success) {
      if (res.status === 401) {
        window.location.href = "./login.html";
        return;
      }
      toast("error", data.error || "Failed to load lobby");
      useMockFallback();
      return;
    }

    updateUserUI(data.user);
    buildMatchHistory(data.recent_matches);
    buildMiniLeaderboard(data.leaderboard, data.user.id);
    buildFullLeaderboard(data.leaderboard, data.user.id);
  } catch (err) {
    console.warn("[Lobby] API unavailable — using mock data");
    useMockFallback();
  }
}

function useMockFallback() {
  const mockMatches = [
    {
      result: "win",
      opponent_username: "ZombieKing",
      mode: "ranked",
      elo_change: 18,
      played_at: new Date(Date.now() - 720000).toISOString(),
    },
    {
      result: "loss",
      opponent_username: "NightCrawler99",
      mode: "ranked",
      elo_change: -12,
      played_at: new Date(Date.now() - 2040000).toISOString(),
    },
    {
      result: "win",
      opponent_username: "SunDropper",
      mode: "casual",
      elo_change: 8,
      played_at: new Date(Date.now() - 3600000).toISOString(),
    },
  ];
  const mockLb = [
    {
      id: 99,
      username: "ZombieKing",
      elo_rating: 4820,
      wins: 320,
      losses: 80,
      is_me: false,
    },
    {
      id: 98,
      username: "PlantLord",
      elo_rating: 4615,
      wins: 290,
      losses: 90,
      is_me: false,
    },
    {
      id: 97,
      username: "SunGod99",
      elo_rating: 4490,
      wins: 270,
      losses: 100,
      is_me: false,
    },
  ];
  buildMatchHistory(mockMatches);
  buildMiniLeaderboard(mockLb, -1);
  buildFullLeaderboard(mockLb, -1);
}

// ══════════════════════════════════════════════
// UPDATE USER UI
// ══════════════════════════════════════════════
function updateUserUI(user) {
  // Navbar username
  const navName = document.querySelector(
    'button[onclick*="profile.html"] span',
  );
  if (navName) navName.textContent = user.display_name || user.username;

  // Navbar coins
  const coinEl = document.getElementById("coin-count");
  if (coinEl) coinEl.textContent = Number(user.coins).toLocaleString();

  // Profile card username
  const profileName = document
    .querySelector(".avatar-inner")
    ?.closest("div[style]")
    ?.nextElementSibling?.querySelector("p");
  if (profileName) profileName.textContent = user.display_name || user.username;

  // Rank badge
  const rankBadge = document.querySelector(".rank-badge");
  if (rankBadge) rankBadge.textContent = `⚔️ ${eloToRank(user.elo_rating)}`;

  // Stats
  const statVals = document.querySelectorAll(".stat-val");
  if (statVals[0]) {
    statVals[0].textContent = user.wins;
    statVals[0].style.color = "var(--green-light)";
  }
  if (statVals[1]) {
    statVals[1].textContent = user.losses;
    statVals[1].style.color = "#ff6b78";
  }
  if (statVals[2]) {
    statVals[2].textContent = user.win_rate + "%";
    statVals[2].style.color = "var(--yellow)";
  }

  // XP bar
  const xpPct = ((user.elo_rating % 100) / 100) * 100;
  const level = Math.floor(user.elo_rating / 100);
  const xpBar = document.getElementById("xp-bar");
  if (xpBar)
    setTimeout(() => {
      xpBar.style.width = xpPct + "%";
    }, 400);

  // Avatar
  const avatarInner = document.querySelector(".avatar-inner");
  if (avatarInner && user.avatar_url) {
    avatarInner.innerHTML = `<img src="${user.avatar_url}" style="width:100%;height:100%;border-radius:50%;object-fit:cover" onerror="this.parentElement.textContent='🌻'" />`;
  }

  // Refresh sessionStorage
  sessionStorage.setItem("username", user.username);
  sessionStorage.setItem("elo", user.elo_rating);
  sessionStorage.setItem("coins", user.coins);
}

// ══════════════════════════════════════════════
// MATCH HISTORY (real)
// ══════════════════════════════════════════════
function buildMatchHistory(matches) {
  const cont = document.getElementById("match-history");
  cont.innerHTML = "";

  if (!matches || matches.length === 0) {
    cont.innerHTML = `<p style="font-family:'Nunito',sans-serif;font-size:0.82rem;color:rgba(254,250,224,0.4);text-align:center;padding:20px">No matches yet — go play! 🌱</p>`;
    return;
  }

  matches.forEach((m) => {
    const opponent = m.opponent_username ?? `Wave ${m.wave_reached ?? "?"}`;
    const modeLabel = m.mode === "ai" ? "🤖 AI" : m.mode;
    const timeAgo = formatTimeAgo(new Date(m.played_at));
    const eloSign = m.elo_change >= 0 ? "+" : "";
    const eloColor = m.elo_change >= 0 ? "var(--green-light)" : "#ff6b78";

    const el = document.createElement("div");
    el.className = "match-row";
    el.innerHTML = `
      <div class="match-result ${m.result}">${m.result.toUpperCase()}</div>
      <div style="flex:1; min-width:0">
        <p class="match-map">vs ${opponent}</p>
        <p class="match-meta">${timeAgo} · ${eloSign}${m.elo_change} ELO</p>
      </div>
      <span class="match-type-pill ${m.mode}">${modeLabel}</span>
      <span class="match-kda" style="color:${eloColor}">${eloSign}${m.elo_change}</span>
    `;
    cont.appendChild(el);
  });
}

// ══════════════════════════════════════════════
// LEADERBOARDS (real)
// ══════════════════════════════════════════════
function buildMiniLeaderboard(leaderboard, myUserId) {
  const cont = document.getElementById("mini-lb");
  cont.innerHTML = "";
  leaderboard.slice(0, 5).forEach((p, i) => {
    const rank = i + 1;
    const rankSymbol =
      rank === 1 ? "🥇" : rank === 2 ? "🥈" : rank === 3 ? "🥉" : rank;
    const rankClass =
      rank === 1 ? "gold" : rank === 2 ? "silver" : rank === 3 ? "bronze" : "";
    const isMe = p.id === myUserId || p.is_me;
    const el = document.createElement("div");
    el.className = "lb-row";
    if (isMe) el.style.color = "var(--yellow)";
    el.innerHTML = `
      <span class="lb-rank ${rankClass}">${rankSymbol}</span>
      <span class="lb-name">${p.username}${isMe ? " 🌻" : ""}</span>
      <span class="lb-score">${Number(p.elo_rating).toLocaleString()}</span>
    `;
    cont.appendChild(el);
  });
}

function buildFullLeaderboard(leaderboard, myUserId) {
  const cont = document.getElementById("full-leaderboard");
  cont.innerHTML = "";
  leaderboard.forEach((p, i) => {
    const rank = i + 1;
    const rankSymbol =
      rank === 1 ? "🥇" : rank === 2 ? "🥈" : rank === 3 ? "🥉" : `#${rank}`;
    const rankClass =
      rank === 1 ? "gold" : rank === 2 ? "silver" : rank === 3 ? "bronze" : "";
    const isMe = p.id === myUserId || p.is_me;
    const el = document.createElement("div");
    el.className = "lb-row";
    el.style.padding = "10px 0";
    if (isMe) {
      el.style.background = "rgba(255,209,102,0.08)";
      el.style.padding = "10px 10px";
      el.style.borderRadius = "10px";
      el.style.color = "var(--yellow)";
    }
    el.innerHTML = `
      <span class="lb-rank ${rankClass}" style="font-size:0.95rem">${rankSymbol}</span>
      <span class="lb-name" style="font-size:0.88rem">${p.username}${isMe ? " 🌻" : ""}</span>
      <span class="lb-score" style="font-size:0.88rem">${Number(p.elo_rating).toLocaleString()} SR</span>
    `;
    cont.appendChild(el);
  });
}

// ══════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════
function eloToRank(elo) {
  if (elo >= 2400) return "Grandmaster";
  if (elo >= 2000) return "Diamond";
  if (elo >= 1700) return "Platinum";
  if (elo >= 1400) return "Gold";
  if (elo >= 1200) return "Silver";
  if (elo >= 1000) return "Bronze";
  return "Seed";
}

function formatTimeAgo(date) {
  const secs = Math.floor((Date.now() - date) / 1000);
  if (secs < 60) return "just now";
  if (secs < 3600) return `${Math.floor(secs / 60)}m ago`;
  if (secs < 86400) return `${Math.floor(secs / 3600)}h ago`;
  return `${Math.floor(secs / 86400)}d ago`;
}

// ══════════════════════════════════════════════
// BG PARTICLES
// ══════════════════════════════════════════════
function buildBgParticles() {
  const cont = document.getElementById("bg-particles");
  const colors = ["#2d6a4f", "#7b2cbf", "#ffd166", "#52b788", "#1a1a2e"];
  for (let i = 0; i < 22; i++) {
    const el = document.createElement("div");
    const size = Math.random() * 120 + 40;
    el.className = "bp";
    el.style.cssText = `left:${Math.random() * 100}%;width:${size}px;height:${size}px;background:${colors[Math.floor(Math.random() * colors.length)]};animation-duration:${Math.random() * 20 + 18}s;animation-delay:-${Math.random() * 20}s;`;
    cont.appendChild(el);
  }
}

// ══════════════════════════════════════════════
// BATTLEFIELD
// ══════════════════════════════════════════════
function buildBattlefield() {
  const bf = document.getElementById("battlefield");
  [
    { em: "🌱", delay: 0, dur: 22 },
    { em: "🌻", delay: 3, dur: 28 },
    { em: "🧟", delay: 7, dur: 18 },
    { em: "🌿", delay: 12, dur: 25 },
    { em: "🌻", delay: 16, dur: 32 },
    { em: "💀", delay: 20, dur: 20 },
    { em: "🌱", delay: 25, dur: 26 },
    { em: "🧟", delay: 30, dur: 19 },
    { em: "☀️", delay: 35, dur: 23 },
    { em: "🌻", delay: 40, dur: 30 },
  ].forEach(({ em, delay, dur }) => {
    const el = document.createElement("div");
    el.className = "bf-entity";
    el.textContent = em;
    el.style.animationDuration = dur + "s";
    el.style.animationDelay = "-" + delay + "s";
    bf.appendChild(el);
  });
  [15, 35, 55, 75, 90].forEach((left, i) => {
    const el = document.createElement("div");
    el.className = "sun-drop";
    el.textContent = "🪙";
    el.style.cssText = `left:${left}%;top:10px;animation-duration:${2.5 + i * 0.4}s;animation-delay:-${i * 0.6}s`;
    bf.appendChild(el);
  });
}

// ══════════════════════════════════════════════
// FRIENDS
// ══════════════════════════════════════════════
function buildFriends() {
  const online = document.getElementById("friends-online");
  const offline = document.getElementById("friends-offline");
  FRIENDS.forEach((f) => {
    const el = document.createElement("div");
    el.className = "friend-row";
    el.innerHTML = `
      <div class="friend-avatar">${f.avatar}<div class="status-dot ${f.status}"></div></div>
      <div><p class="friend-name">${f.name}</p><p class="friend-status">${f.statusText}</p></div>
      ${f.status === "online" ? `<button class="invite-btn" onclick="inviteFriend('${f.name}')">Invite</button>` : ""}
    `;
    (f.status === "offline" ? offline : online).appendChild(el);
  });
}
function inviteFriend(name) {
  toast("success", `🌿 Invite sent to ${name}!`);
}

// ══════════════════════════════════════════════
// CHAT
// ══════════════════════════════════════════════
function seedChat() {
  CHAT_SEED.forEach((m) => appendChatMsg(m.user, m.color, m.msg));
  scrollChat();
}
function appendChatMsg(user, color, msg) {
  const cont = document.getElementById("chat-messages");
  const el = document.createElement("div");
  el.className = "chat-msg";
  el.innerHTML = `<span class="msg-user ${color}">${user}: </span><span class="msg-text">${msg}</span>`;
  cont.appendChild(el);
  scrollChat();
}
function scrollChat() {
  const cont = document.getElementById("chat-messages");
  cont.scrollTop = cont.scrollHeight;
}
function sendChat() {
  const inp = document.getElementById("chat-input");
  const val = inp.value.trim();
  if (!val) return;
  appendChatMsg(sessionStorage.getItem("username") || "You", "orange", val);
  inp.value = "";
  const r = [
    ["ZombieKing", "purple", "lol 😂"],
    ["SunDropper", "yellow", "true! 🌻"],
    ["GardenGuard", "green", "gg wp"],
  ][Math.floor(Math.random() * 3)];
  setTimeout(
    () => appendChatMsg(r[0], r[1], r[2]),
    1200 + Math.random() * 1800,
  );
}

// ══════════════════════════════════════════════
// MATCHMAKING
// ══════════════════════════════════════════════
let searchTimer = null,
  searchSeconds = 0,
  searching = false;

function startSearch(mode) {
  if (searching) {
    cancelSearch();
    return;
  }
  searching = true;
  const other = document.getElementById(
    mode === "casual" ? "btn-ranked" : "btn-casual",
  );
  other.style.opacity = "0.4";
  other.style.pointerEvents = "none";
  document.getElementById("searching-bar").classList.add("active");
  document.getElementById("search-status").style.display = "flex";
  searchSeconds = 0;
  const ml = mode === "casual" ? "🌱 Casual" : "🧟 Ranked";
  toast("info", `${ml} search started!`);
  searchTimer = setInterval(() => {
    searchSeconds++;
    const m = Math.floor(searchSeconds / 60),
      s = searchSeconds % 60;
    document.getElementById("search-label").textContent =
      `${ml} — Searching ${m > 0 ? m + "m " : ""}${s}s…`;
    if (searchSeconds >= 12 + Math.floor(Math.random() * 10)) {
      clearInterval(searchTimer);
      matchFound(mode);
    }
  }, 1000);
}
function cancelSearch() {
  clearInterval(searchTimer);
  searching = false;
  document.getElementById("searching-bar").classList.remove("active");
  document.getElementById("search-status").style.display = "none";
  ["btn-casual", "btn-ranked"].forEach((id) => {
    document.getElementById(id).style.opacity = "";
    document.getElementById(id).style.pointerEvents = "";
  });
  toast("info", "🌱 Search cancelled");
}
function matchFound(mode) {
  searching = false;
  document.getElementById("searching-bar").classList.remove("active");
  document.getElementById("search-status").style.display = "none";
  ["btn-casual", "btn-ranked"].forEach((id) => {
    document.getElementById(id).style.opacity = "";
    document.getElementById(id).style.pointerEvents = "";
  });
  confettiBurst();
  toast(
    "success",
    `🌻 Match found! Loading ${mode === "ranked" ? "Ranked" : "Casual"} game…`,
  );
}

// ══════════════════════════════════════════════
// UI HELPERS
// ══════════════════════════════════════════════
function toggleChip(el) {
  el.classList.toggle("active-chip");
}
function setPill(el) {
  document
    .querySelectorAll(".nav-pill")
    .forEach((p) => p.classList.remove("active"));
  el.classList.add("active");
}
function buyItem(name, cost) {
  const el = document.getElementById("coin-count");
  const current = parseInt(el.textContent.replace(/,/g, ""));
  if (current < cost) {
    toast("error", `🪙 Not enough coins for ${name}`);
    return;
  }
  el.textContent = (current - cost).toLocaleString();
  toast("success", `${name} purchased! ✓`);
}
function openModal(id) {
  document.getElementById(id).classList.add("open");
}
function closeModal(id) {
  document.getElementById(id).classList.remove("open");
}
function toast(type, msg) {
  const icons = { success: "🌱", error: "💀", info: "🌻" };
  const cont = document.getElementById("toast-container");
  const el = document.createElement("div");
  el.className = `toast ${type}`;
  el.innerHTML = `<span style="font-size:1.1rem">${icons[type] || "ℹ️"}</span><span>${msg}</span>`;
  cont.appendChild(el);
  setTimeout(() => {
    el.style.animation = "toastOut 0.3s ease forwards";
    setTimeout(() => el.remove(), 300);
  }, 3000);
}
function confettiBurst() {
  const colors = [
    "#2d6a4f",
    "#ffd166",
    "#52b788",
    "#7b2cbf",
    "#e63946",
    "#fb8500",
  ];
  for (let i = 0; i < 55; i++) {
    const p = document.createElement("div"),
      size = Math.random() * 10 + 4;
    p.style.cssText = `position:fixed;pointer-events:none;z-index:9998;left:${Math.random() * 100}vw;top:-10px;width:${size}px;height:${size}px;background:${colors[Math.floor(Math.random() * colors.length)]};border-radius:${Math.random() > 0.5 ? "50%" : "3px"};animation:confettiFall ${Math.random() * 1.4 + 0.8}s linear ${Math.random() * 0.5}s forwards;`;
    document.body.appendChild(p);
    setTimeout(() => p.remove(), 3000);
  }
}
function startOnlineCounter() {
  setInterval(() => {
    document.getElementById("online-count").textContent =
      214 + Math.floor(Math.random() * 12) - 5 + " online";
  }, 5000);
}
function startCoinFlicker() {
  setTimeout(() => {
    const el = document.getElementById("coin-count");
    el.style.transition = "color 0.3s";
    el.style.color = "#fff";
    setTimeout(() => {
      el.style.color = "";
    }, 400);
  }, 8000);
}
const style = document.createElement("style");
style.textContent = `@keyframes confettiFall{0%{transform:translateY(-10px) rotate(0deg);opacity:1}100%{transform:translateY(100vh) rotate(720deg);opacity:0}}`;
document.head.appendChild(style);
