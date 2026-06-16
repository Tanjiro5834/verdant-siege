// ══════════════════════════════════════════════════
// CONFIG & STATE
// ══════════════════════════════════════════════════
const API = {
  base: 'http://localhost/verdant-siege/backend/api',
  endpoints: {
    login:         '/Auth.php?action=login',
    register:      '/Auth.php?action=register',
    checkUsername: '/Auth.php?action=check_username',
    forgotPassword:'/Auth.php?action=forgot_password'
  }
};

const BLOCKED_DOMAINS = ['mailinator.com', 'temp-mail.org', 'guerrillamail.com', 'throwaway.email', 'yopmail.com'];
const TAKEN_USERNAMES = ['admin', 'root', 'test', 'player', 'zombie', 'plant', 'sunflower'];

let loginAttempts = 0;
let forgotAttempts = 0;
let captchaSolved = false;
let captchaAnswers = [];
let captchaSelected = [];
let usernameCheckTimer = null;
let isFlipped = false;
let rememberChecked = false;

// ══════════════════════════════════════════════════
// SCENE
// ══════════════════════════════════════════════════
function setScene(mode) {
  const scene = document.getElementById('scene');
  const dayF = document.getElementById('day-floaters');
  const nightF = document.getElementById('night-floaters');
  const title = document.getElementById('main-title');
  const sub = document.getElementById('sub-title');

  if (mode === 'night') {
    scene.className = 'night';
    dayF.style.display = 'none';
    nightF.style.display = 'block';
    title.style.textShadow = '0 4px 20px rgba(0,0,0,0.7), 0 0 40px rgba(123,44,191,0.6)';
  } else {
    scene.className = 'day';
    dayF.style.display = 'block';
    nightF.style.display = 'none';
    title.style.textShadow = '0 4px 20px rgba(0,0,0,0.5), 0 0 40px rgba(255,209,102,0.5)';
  }
}

// ══════════════════════════════════════════════════
// FLIP
// ══════════════════════════════════════════════════
function flipToRegister() {
  document.getElementById('flip-card').classList.add('flipped');
  setScene('night');
  isFlipped = true;
}
function flipToLogin() {
  document.getElementById('flip-card').classList.remove('flipped');
  setScene('day');
  isFlipped = false;
}

// ══════════════════════════════════════════════════
// TOAST
// ══════════════════════════════════════════════════
function toast(type, message) {
  const icons = { success: '🌱', error: '💀', info: '🌻' };
  const container = document.getElementById('toast-container');
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span style="font-size:1.3rem">${icons[type]||'ℹ️'}</span><div><strong>${type.toUpperCase()}</strong><br><span style="font-weight:500">${message}</span></div>`;
  container.appendChild(el);
  setTimeout(() => {
    el.style.animation = 'toastOut 0.3s ease forwards';
    setTimeout(() => el.remove(), 300);
  }, 3200);
}

// ══════════════════════════════════════════════════
// MODAL
// ══════════════════════════════════════════════════
function openForgotModal() {
  document.getElementById('forgot-modal').classList.add('open');
}
function openTermsModal(e) {
  e && e.stopPropagation();
  document.getElementById('terms-modal').classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
// Click outside to close
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', function(e) {
    if (e.target === this) closeModal(this.id);
  });
});

// ══════════════════════════════════════════════════
// EYE TOGGLE
// ══════════════════════════════════════════════════
function toggleEye(inputId, btn) {
  const inp = document.getElementById(inputId);
  if (inp.type === 'password') {
    inp.type = 'text';
    btn.innerHTML = '<i class="fa fa-eye-slash"></i>';
  } else {
    inp.type = 'password';
    btn.innerHTML = '<i class="fa fa-eye"></i>';
  }
}

// ══════════════════════════════════════════════════
// REMEMBER ME
// ══════════════════════════════════════════════════
function toggleRemember() {
  rememberChecked = !rememberChecked;
  const cb = document.getElementById('rememberMe');
  cb.checked = rememberChecked;
}

function loadRemembered() {
  if (localStorage.getItem('remember_me') === 'true') {
    const saved = localStorage.getItem('saved_username') || '';
    document.getElementById('login-username').value = saved;
    rememberChecked = true;
    document.getElementById('rememberMe').checked = true;
    if (saved) loginFieldValidate(document.getElementById('login-username'));
  }
}

// ══════════════════════════════════════════════════
// CUSTOM CHECKBOXES
// ══════════════════════════════════════════════════
function toggleCheck(boxId, hiddenId) {
  const box = document.getElementById(boxId);
  const hidden = document.getElementById(hiddenId);
  box.classList.toggle('checked');
  hidden.checked = box.classList.contains('checked');
}

// ══════════════════════════════════════════════════
// PASSWORD STRENGTH
// ══════════════════════════════════════════════════
function calcStrength(pwd) {
  let score = 0;
  if (pwd.length >= 6) score += 25;
  if (pwd.length >= 10) score += 15;
  if (/[0-9]/.test(pwd)) score += 20;
  if (/[A-Z]/.test(pwd)) score += 20;
  if (/[!@#$%^&*]/.test(pwd)) score += 20;

  let label = '', color = '#e63946', pct = 0;
  if (!pwd) { label = ''; pct = 0; }
  else if (pwd.length < 6) { label = 'Weak — Zombie food 🌱'; pct = 20; color = '#e63946'; }
  else if (score < 60) { label = 'Medium — Growing plant 🟡'; pct = 50; color = '#fb8500'; }
  else if (score < 80) { label = 'Strong — Defended base 🟢'; pct = 80; color = '#52b788'; }
  else { label = 'Unbeatable — Fortress 💪'; pct = 100; color = '#7b2cbf'; }
  return { score, label, pct, color };
}

function setStrengthBar(barId, labelId, pwd, labelColor) {
  const bar = document.getElementById(barId);
  const lbl = document.getElementById(labelId);
  const { label, pct, color } = calcStrength(pwd);
  bar.style.width = pct + '%';
  bar.style.background = color;
  lbl.textContent = label;
  lbl.style.color = labelColor || 'rgba(45,35,39,0.6)';
}

// ══════════════════════════════════════════════════
// LOGIN VALIDATION
// ══════════════════════════════════════════════════
function loginFieldValidate(inp) {
  const val = inp.value.trim();
  const status = document.getElementById('login-u-status');
  const icon = document.getElementById('login-uicon');

  // detect email vs username
  const isEmail = val.includes('@');
  icon.textContent = isEmail ? '📧' : '🌞';

  if (!val) { inp.classList.remove('valid','invalid'); status.textContent = ''; return false; }

  let valid = false;
  if (isEmail) {
    valid = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(val);
  } else {
    valid = val.length >= 3 && val.length <= 30 && /^[a-zA-Z0-9_.@]+$/.test(val);
  }
  inp.classList.toggle('valid', valid);
  inp.classList.toggle('invalid', !valid);
  status.textContent = valid ? '✅' : '❌';
  return valid;
}

function loginPwdValidate(inp) {
  const val = inp.value;
  setStrengthBar('login-strength-bar', 'login-strength-label', val);
  const valid = val.length >= 6;
  inp.classList.toggle('valid', valid && val);
  inp.classList.toggle('invalid', !valid && val.length > 0);
  return valid;
}

// ══════════════════════════════════════════════════
// REGISTER VALIDATION
// ══════════════════════════════════════════════════
function regUsernameInput(inp) {
  const val = inp.value;
  const counter = document.getElementById('reg-u-counter');
  const len = val.length;
  counter.textContent = `${len}/20`;
  counter.style.color = len < 15 ? '#52b788' : len < 18 ? '#fb8500' : '#e63946';

  const status = document.getElementById('reg-u-status');
  const avail = document.getElementById('reg-avail');
  const sugg = document.getElementById('reg-suggestions');

  if (!val) {
    inp.classList.remove('valid','invalid');
    status.textContent = ''; avail.textContent = ''; sugg.innerHTML = '';
    return;
  }
  const patternOk = /^[a-zA-Z0-9_]+$/.test(val) && val.length >= 3;
  if (!patternOk) {
    inp.classList.add('invalid'); inp.classList.remove('valid');
    status.textContent = '❌';
    avail.textContent = val.length < 3 ? '🌿 Min 3 characters' : '🍃 Letters, numbers, underscore only';
    avail.style.color = '#e63946';
    sugg.innerHTML = '';
    return;
  }

  // Debounce availability check
  clearTimeout(usernameCheckTimer);
  avail.textContent = '🌱 Searching the garden...';
  avail.style.color = 'rgba(254,250,224,0.5)';
  status.textContent = '⏳';

  usernameCheckTimer = setTimeout(() => checkUsernameAvailability(val), 500);
}

async function checkUsernameAvailability(username) {
  const status = document.getElementById('reg-u-status');
  const avail = document.getElementById('reg-avail');
  const sugg = document.getElementById('reg-suggestions');
  const inp = document.getElementById('reg-username');

  try {
    let available, suggestions = [];
    try {
      const res = await fetch(`${API.base}${API.endpoints.checkUsername}&username=${encodeURIComponent(username)}`);
      const data = await res.json();
      available = data.available;
      suggestions = data.suggestions || [];
    } catch {
      // Offline mock
      available = !TAKEN_USERNAMES.includes(username.toLowerCase());
      if (!available) suggestions = [username + Math.floor(Math.random()*90+10), username + '_gamer', 'The' + username.charAt(0).toUpperCase() + username.slice(1)];
    }

    if (available) {
      inp.classList.add('valid'); inp.classList.remove('invalid');
      status.textContent = '✅';
      avail.textContent = 'Username is available! ✓';
      avail.style.color = '#52b788';
      sugg.innerHTML = '';
    } else {
      inp.classList.add('invalid'); inp.classList.remove('valid');
      status.textContent = '❌';
      avail.textContent = 'Already taken — try adding numbers! 🧟';
      avail.style.color = '#e63946';
      sugg.innerHTML = suggestions.map(s => `<span class="suggestion-chip" onclick="applyUsername('${s}')">${s}</span>`).join('');
    }
  } catch (err) {
    avail.textContent = '⚠️ Could not check — try a different name';
    avail.style.color = '#fb8500';
  }
}

function applyUsername(name) {
  const inp = document.getElementById('reg-username');
  inp.value = name;
  regUsernameInput(inp);
}

function regEmailInput(inp) {
  const val = inp.value.trim();
  const status = document.getElementById('reg-e-status');
  if (!val) { inp.classList.remove('valid','invalid'); status.textContent = ''; return false; }

  const domain = val.split('@')[1] || '';
  const validFormat = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(val);
  const blocked = BLOCKED_DOMAINS.includes(domain);

  if (!validFormat) {
    inp.classList.add('invalid'); inp.classList.remove('valid');
    status.textContent = '❌'; return false;
  }
  if (blocked) {
    inp.classList.add('invalid'); inp.classList.remove('valid');
    status.textContent = '🚫'; return false;
  }
  inp.classList.add('valid'); inp.classList.remove('invalid');
  status.textContent = '✅'; return true;
}

function regPwdInput(inp) {
  const pwd = inp.value;
  const username = document.getElementById('reg-username').value.toLowerCase();
  setStrengthBar('reg-strength-bar', 'reg-strength-label', pwd, 'rgba(254,250,224,0.6)');
  updateChecklist(pwd, username);
  // also re-check confirm
  const confirm = document.getElementById('reg-confirm');
  if (confirm.value) regConfirmInput(confirm);
}

function updateChecklist(pwd, username) {
  const set = (id, met) => {
    const el = document.getElementById(id);
    const icon = met ? '☑' : '☐';
    el.className = `pwd-check-item ${met ? 'met' : 'unmet'}`;
    el.textContent = icon + el.textContent.slice(1);
  };
  set('chk-len', pwd.length >= 6);
  set('chk-num', /[0-9]/.test(pwd));
  set('chk-upper', /[A-Z]/.test(pwd));
  set('chk-special', /[!@#$%^&*]/.test(pwd));
  set('chk-nouname', username.length === 0 || !pwd.toLowerCase().includes(username));
}

function regConfirmInput(inp) {
  const pwd = document.getElementById('reg-password').value;
  const status = document.getElementById('confirm-status');
  if (!inp.value) { inp.classList.remove('valid','invalid'); status.textContent = ''; return; }
  if (inp.value === pwd) {
    inp.classList.add('valid'); inp.classList.remove('invalid');
    status.textContent = 'Passwords match ✓'; status.style.color = '#52b788';
  } else {
    inp.classList.add('invalid'); inp.classList.remove('valid');
    status.textContent = "Passwords don't match ❌"; status.style.color = '#e63946';
  }
}

function checkReferral(inp) {
  const val = inp.value.trim().toUpperCase();
  const status = document.getElementById('ref-status');
  const bonus = document.getElementById('ref-bonus');
  const VALID_CODES = ['GARDEN100', 'ZOMBIE99', 'PLANT2025', 'VERDANT'];
  if (!val) { status.textContent = ''; bonus.style.display = 'none'; return; }
  const valid = VALID_CODES.includes(val);
  status.textContent = valid ? '✅' : '❌';
  bonus.style.display = valid ? 'block' : 'none';
}

// ══════════════════════════════════════════════════
// SUBMIT: LOGIN
// ══════════════════════════════════════════════════
async function handleLogin(e) {
  e.preventDefault();
  const honeypot = e.target.querySelector('input[name="website"]');
  if (honeypot && honeypot.value) return;

  const username = document.getElementById('login-username').value.trim();
  const password = document.getElementById('login-password').value;

  const uValid = loginFieldValidate(document.getElementById('login-username'));
  const pValid = password.length >= 6;

  if (!username) { toast('error', '🌱 Username is required!'); return; }
  if (!pValid) { toast('error', '🔒 Password must be at least 6 characters'); return; }

  const btn = document.getElementById('login-btn');
  btn.innerHTML = '<span class="spinner"></span> Planting seeds...';
  btn.disabled = true;

  try {
    // ── WIRED: call real Auth.php ──
    const res = await fetch('http://localhost/verdant-siege/backend/api/Auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'login', username, password })
    });
    const data = await res.json();

    if (data.success) {
      // ── WIRED: store real token + user data ──
      if (rememberChecked) {
        localStorage.setItem('saved_username', username);
        localStorage.setItem('remember_me',    'true');
        localStorage.setItem('auth_token',     data.token);
      } else {
        localStorage.removeItem('saved_username');
        localStorage.removeItem('remember_me');
      }
      sessionStorage.setItem('auth_token', data.token);
      sessionStorage.setItem('user_id',    data.user.id);
      sessionStorage.setItem('username',   data.user.username);
      sessionStorage.setItem('elo',        data.user.elo_rating);
      sessionStorage.setItem('coins',      data.user.coins);

      loginAttempts = 0;
      confettiBurst();
      toast('success', `Welcome back, ${data.user.username}! 🌻`);
      setTimeout(() => { toast('info', '🌿 Loading your garden...'); }, 800);
      setTimeout(() => { window.location.href = './lobby.html'; }, 2000); // ← redirect
    } else {
      loginAttempts++;
      toast('error', data.error || '❌ Invalid credentials');
      document.getElementById('login-password').classList.add('invalid');
      setTimeout(() => document.getElementById('login-password').classList.remove('invalid'), 500);
      if (loginAttempts >= 3) {
        toast('info', '⏰ Too many attempts — wait 30 seconds or reset password');
        btn.disabled = true;
        setTimeout(() => { btn.disabled = false; loginAttempts = 0; }, 30000);
        return;
      }
    }
  } catch (err) {
    toast('error', '🧟 Connection lost! Check your internet');
  } finally {
    if (!btn.disabled || loginAttempts >= 3) {
      btn.innerHTML = '🌿 ENTER THE GARDEN';
      if (loginAttempts < 3) btn.disabled = false;
    }
  }
}


async function handleRegister(e) {
  e.preventDefault();
  const honeypot = e.target.querySelector('input[name="website"]');
  if (honeypot && honeypot.value) return;

  const username     = document.getElementById('reg-username').value.trim();
  const email        = document.getElementById('reg-email').value.trim();
  const password     = document.getElementById('reg-password').value;
  const confirm      = document.getElementById('reg-confirm').value;
  const dob          = document.getElementById('reg-dob').value;
  const termsChecked = document.getElementById('terms-hidden').checked;

  // Validation — unchanged
  if (!username || username.length < 3) { toast('error', '🌱 Username needs at least 3 characters'); return; }
  if (!/^[a-zA-Z0-9_]+$/.test(username)) { toast('error', '🍃 Username: letters, numbers, underscore only'); return; }
  if (!email || !/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(email)) { toast('error', '📧 Enter a valid email address'); return; }
  if (BLOCKED_DOMAINS.includes(email.split('@')[1])) { toast('error', '🧟 Please use a permanent email address'); return; }
  if (password.length < 6) { toast('error', '🔒 Password must be at least 6 characters'); return; }
  if (password !== confirm) { toast('error', '❌ Passwords do not match'); return; }
  if (!termsChecked) { toast('error', '📜 Please agree to the Terms of Service'); return; }

  if (dob) {
    const age = Math.floor((Date.now() - new Date(dob)) / (365.25 * 24 * 3600 * 1000));
    if (age < 13) { toast('error', '🎂 You must be at least 13 to play (COPPA)'); return; }
  }

  const btn = document.getElementById('reg-btn');
  btn.innerHTML = '<span class="spinner"></span> Raising the dead...';
  btn.disabled  = true;

  try {
    // ── WIRED: call real Auth.php ──
    const res = await fetch('http://localhost/verdant-siege/backend/api/Auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        action:     'register',
        username,
        email,
        password,
        dob:        dob || null,
        referral:   document.getElementById('reg-referral').value.trim() || null,
        newsletter: document.getElementById('news-hidden').checked
      })
    });
    const data = await res.json();

    if (data.success) {
      // ── WIRED: store token on register — no second login needed ──
      sessionStorage.setItem('auth_token', data.token);
      sessionStorage.setItem('user_id',    data.user.id);
      sessionStorage.setItem('username',   data.user.username);
      sessionStorage.setItem('elo',        data.user.elo_rating);
      sessionStorage.setItem('coins',      data.user.coins);

      confettiBurst();
      toast('success', data.message || '🌱 Account created! Welcome to Verdant Siege!');
      setTimeout(() => { window.location.href = './lobby.html'; }, 2000); // ← redirect
    } else {
      toast('error', data.error || '🧟 Registration failed');
    }
  } catch (err) {
    toast('error', '🧟 Connection lost! Check your internet');
  } finally {
    btn.innerHTML = '🧟 JOIN THE SIEGE';
    btn.disabled  = false;
  }
}

// ══════════════════════════════════════════════════
// FORGOT PASSWORD + CAPTCHA
// ══════════════════════════════════════════════════
const CAPTCHA_EMOJIS = ['🌻','🌿','🧟','🌱','🌻','👻','🌻','🌿'];
const CAPTCHA_ANSWERS = [0, 2, 4, 6]; // indexes of 🌻

function buildCaptcha() {
  const grid = document.getElementById('captcha-grid');
  grid.innerHTML = '';
  captchaAnswers = CAPTCHA_ANSWERS;
  captchaSelected = [];
  captchaSolved = false;
  CAPTCHA_EMOJIS.forEach((em, i) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = em;
    btn.style.cssText = 'font-size:1.6rem; padding:4px 8px; border-radius:8px; border:2px solid transparent; background:rgba(0,0,0,0.05); cursor:pointer; transition:all 0.15s';
    btn.dataset.index = i;
    btn.onclick = function() {
      const idx = parseInt(this.dataset.index);
      if (captchaSelected.includes(idx)) {
        captchaSelected = captchaSelected.filter(x => x !== idx);
        this.style.borderColor = 'transparent';
        this.style.background = 'rgba(0,0,0,0.05)';
      } else {
        captchaSelected.push(idx);
        this.style.borderColor = '#2d6a4f';
        this.style.background = 'rgba(45,106,79,0.1)';
      }
    };
    grid.appendChild(btn);
  });
}

async function sendReset() {
  const email = document.getElementById('forgot-email').value.trim();
  if (!email || !/^[^@]+@[^@]+\.[^@]+$/.test(email)) {
    toast('error', '📧 Enter a valid email address');
    return;
  }

  forgotAttempts++;
  if (forgotAttempts >= 3) {
    const cap = document.getElementById('captcha-area');
    if (cap.style.display === 'none') { cap.style.display = 'block'; buildCaptcha(); return; }
    const correct = captchaAnswers.every(i => captchaSelected.includes(i)) && captchaSelected.length === captchaAnswers.length;
    if (!correct) {
      document.getElementById('captcha-msg').textContent = '❌ Select all sunflowers!';
      return;
    }
    document.getElementById('captcha-msg').textContent = '✅ Verified!';
    captchaSolved = true;
  }

  toast('success', '🌱 Reset link sent! Check your inbox');
  closeModal('forgot-modal');
  forgotAttempts = 0;
  captchaSolved = false;
}

// ══════════════════════════════════════════════════
// DEMO MODE
// ══════════════════════════════════════════════════
function enableDemoMode() {
  localStorage.setItem('demo_mode', 'true');
  localStorage.setItem('demo_username', 'DemoGardener');
  sessionStorage.setItem('auth_token', 'demo_token');
  toast('success', '🌱 Demo mode active! Welcome, DemoGardener');
  // setTimeout(() => window.location.href = '/game.html?demo=true', 1500);
}

// ══════════════════════════════════════════════════
// SOCIAL LOGIN
// ══════════════════════════════════════════════════
function socialLogin(provider) {
  toast('info', `🔗 Connecting with ${provider.charAt(0).toUpperCase()+provider.slice(1)}...`);
  // window.location.href = `${API.base}/oauth.php?provider=${provider}`;
}

// ══════════════════════════════════════════════════
// CONFETTI
// ══════════════════════════════════════════════════
function confettiBurst() {
  const colors = ['#2d6a4f','#ffd166','#52b788','#7b2cbf','#e63946','#fb8500'];
  for (let i = 0; i < 60; i++) {
    const piece = document.createElement('div');
    piece.className = 'confetti-piece';
    piece.style.cssText = `
      left: ${Math.random() * 100}vw;
      top: -10px;
      background: ${colors[Math.floor(Math.random()*colors.length)]};
      width: ${Math.random()*10+5}px;
      height: ${Math.random()*10+5}px;
      animation-duration: ${Math.random()*1.5+1}s;
      animation-delay: ${Math.random()*0.5}s;
      border-radius: ${Math.random() > 0.5 ? '50%' : '2px'};
    `;
    document.body.appendChild(piece);
    setTimeout(() => piece.remove(), 3000);
  }
}

// ══════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  loadRemembered();

  // Set max date on DOB (must be 13+ ago)
  const dob = document.getElementById('reg-dob');
  if (dob) {
    const maxDate = new Date();
    maxDate.setFullYear(maxDate.getFullYear() - 13);
    dob.max = maxDate.toISOString().split('T')[0];
  }

  // Keyboard accessibility for modal close
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    }
  });
});