<?php
require __DIR__ . '/auth.inc.php';
requireLoginPage($config);

$root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$cssTime = file_exists($root . '/css/styles.css') ? filemtime($root . '/css/styles.css') : 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Вход — БлагоСервис</title>
  <link rel="stylesheet" href="/css/styles.css?v=<?= $cssTime ?>">
  <style>
    body.login-page { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f5f5f5; }
    .login-card { width: 320px; padding: 24px; }
  </style>
</head>
<body class="login-page">
  <div class="modal login-card">
    <div class="modal-header">
      <h2>Вход</h2>
    </div>
    <form id="login-form">
      <div class="form-row">
        <label for="login-input">Логин</label>
        <input type="text" id="login-input" name="login" autocomplete="username" required>
      </div>
      <div class="form-row">
        <label for="login-password">Пароль</label>
        <input type="password" id="login-password" name="password" autocomplete="current-password" required>
      </div>
      <div id="login-error" class="login-error hidden"></div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Войти</button>
      </div>
    </form>
  </div>
  <script>
    document.getElementById('login-form').addEventListener('submit', async function (e) {
      e.preventDefault();
      const login = document.getElementById('login-input').value.trim();
      const password = document.getElementById('login-password').value;
      const errEl = document.getElementById('login-error');
      errEl.classList.add('hidden');
      try {
        const res = await fetch('/api/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ login, password })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Ошибка авторизации');
        window.location.href = '/';
      } catch (err) {
        errEl.textContent = err.message || 'Ошибка входа';
        errEl.classList.remove('hidden');
      }
    });
  </script>
</body>
</html>
