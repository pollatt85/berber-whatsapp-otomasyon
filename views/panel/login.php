<?php /* /panel/login — layout dışı bağımsız sayfa (06§2: POST /auth/login → JWT localStorage). */ ?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Giriş — Berber Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary d-flex align-items-center" style="min-height:100vh">
<div class="container" style="max-width: 400px">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 mb-1 text-center"><i class="bi bi-scissors me-1"></i>Berber Paneli</h1>
      <p class="text-body-secondary text-center mb-4">Hesabınızla giriş yapın</p>
      <div id="loginAlert"></div>
      <form id="loginForm" novalidate>
        <div class="mb-3">
          <label class="form-label" for="email">E-posta</label>
          <input type="email" class="form-control" id="email" required autofocus autocomplete="username">
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Parola</label>
          <input type="password" class="form-control" id="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary w-100" type="submit" id="loginBtn">Giriş Yap</button>
      </form>
    </div>
  </div>
</div>
<script>
const TOKEN_KEY = 'panel_jwt';

// Zaten geçerli oturum varsa doğrudan panele geç (payload base64url — atob öncesi normalize).
try {
  let part = localStorage.getItem(TOKEN_KEY).split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
  part += '='.repeat((4 - part.length % 4) % 4);
  const c = JSON.parse(atob(part));
  if (c.exp * 1000 > Date.now()) location.href = '/panel/dashboard';
} catch (e) { /* token yok/bozuk — formda kal */ }

document.getElementById('loginForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const btn = document.getElementById('loginBtn');
  const alertEl = document.getElementById('loginAlert');
  btn.disabled = true;
  alertEl.innerHTML = '';
  try {
    const res = await fetch('/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
      }),
    });
    const json = await res.json();
    if (res.ok && json.token) {
      localStorage.setItem(TOKEN_KEY, json.token);
      location.href = '/panel/dashboard';
      return;
    }
    const msg = res.status === 401 ? 'E-posta veya parola hatalı.' : (json.message || 'Giriş başarısız.');
    alertEl.innerHTML = '<div class="alert alert-danger">' + msg.replace(/[<>&]/g, '') + '</div>';
  } catch (e) {
    alertEl.innerHTML = '<div class="alert alert-danger">Sunucuya ulaşılamadı.</div>';
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
