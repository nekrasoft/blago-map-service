<?php
require __DIR__ . '/auth.inc.php';
requireMapAuth($config);
$isReadonlyUser = !empty($_SESSION['readonly']);
$sessionCounterpartyId = isset($_SESSION['counterparty_id']) ? (int) $_SESSION['counterparty_id'] : null;
if ($sessionCounterpartyId !== null && $sessionCounterpartyId <= 0) {
  $sessionCounterpartyId = null;
}
$isCounterpartyUser = $sessionCounterpartyId !== null;
$roleLabel = 'Админ';
$roleClass = 'role-admin';
if ($isCounterpartyUser) {
  $roleLabel = 'Контрагент';
  $roleClass = 'role-counterparty';
} elseif ($isReadonlyUser) {
  $roleLabel = 'Просмотр';
  $roleClass = 'role-readonly';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Карта бункеров — БлагоСервис</title>
  <link rel="stylesheet" href="/css/styles.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/styles.css') ?>">
</head>
<body>

  <!-- Боковая панель -->
  <aside id="sidebar">
    <div class="sidebar-auth">
      <div class="auth-user-wrap">
        <span id="auth-user" class="auth-user"><?= htmlspecialchars($_SESSION['user'] ?? '') ?></span>
        <span class="auth-role <?= htmlspecialchars($roleClass) ?>"><?= htmlspecialchars($roleLabel) ?></span>
      </div>
      <button id="btn-logout" class="btn btn-secondary" title="Выйти">Выйти</button>
    </div>
    <div class="sidebar-header">
      <h1>Бункеры <span id="bunker-count" class="count-badge"></span></h1>
      <?php if (!$isReadonlyUser && !$isCounterpartyUser): ?>
      <button id="btn-add" class="btn btn-primary" title="Добавить бункер">+ Добавить</button>
      <?php endif; ?>
    </div>

    <div class="filters">
      <select id="filter-district">
        <option value="">Все районы</option>
      </select>
      <?php if (!$isCounterpartyUser): ?>
      <select id="filter-contractor">
        <option value="">Все контрагенты</option>
      </select>
      <?php endif; ?>
      <label class="filter-checkbox"><input type="checkbox" id="filter-full"> Заполненные</label>
    </div>

    <ul id="bunker-list"></ul>
  </aside>

  <!-- Карта -->
  <div id="map"></div>
  <button id="btn-toggle-sidebar" class="sidebar-toggle" title="Показать список">≡ Список</button>

  <!-- Модальное окно для формы бункера -->
  <div id="modal-overlay" class="modal-overlay hidden">
    <div class="modal">
      <div class="modal-header">
        <h2 id="modal-title">Добавить бункер</h2>
        <button id="modal-close" class="btn-icon" title="Закрыть">&times;</button>
      </div>
      <form id="bunker-form">
        <input type="hidden" id="form-id">
        <div class="form-row">
          <label for="form-number">Номер (0 — без номера)</label>
          <input type="number" id="form-number" min="0" required>
        </div>
        <div class="form-row">
          <label for="form-volume">Объём (м³)</label>
          <input type="number" id="form-volume" value="8" min="1" required>
        </div>
        <div class="form-row">
          <label for="form-contractor">Контрагент</label>
          <select id="form-contractor" required>
            <option value="">Выберите контрагента</option>
          </select>
        </div>
        <div class="form-row">
          <label for="form-address">Адрес</label>
          <input type="text" id="form-address">
        </div>
        <div class="form-row">
          <label for="form-district">Район</label>
          <input type="text" id="form-district">
        </div>
        <div class="form-row">
          <label for="form-waste">Тип мусора</label>
          <select id="form-waste">
            <option value="КГО">КГО</option>
            <option value="ТБО">ТБО</option>
            <option value="Строительный">Строительный</option>
          </select>
        </div>
        <div class="form-row">
          <label for="form-pickup-date">Дата последнего вывоза</label>
          <input type="date" id="form-pickup-date" required>
        </div>
        <div class="form-row">
          <label for="form-fill">Заполненность (%)</label>
          <input type="number" id="form-fill" min="0" max="100" value="0" required>
        </div>
        <div class="form-row">
          <label for="form-phone">Телефон для заказа</label>
          <input type="tel" id="form-phone">
        </div>
        <div class="form-row">
          <label for="form-lat">Широта</label>
          <input type="number" id="form-lat" step="any" required>
        </div>
        <div class="form-row">
          <label for="form-lng">Долгота</label>
          <input type="number" id="form-lng" step="any" required>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Сохранить</button>
          <button type="button" id="btn-cancel" class="btn btn-secondary">Отмена</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.READONLY_USER = <?= json_encode($isReadonlyUser) ?>;
    window.COUNTERPARTY_USER = <?= json_encode($isCounterpartyUser) ?>;
    window.COUNTERPARTY_ID = <?= json_encode($sessionCounterpartyId) ?>;
  </script>
  <script src="/js/api.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/api.js') ?>"></script>
  <script src="/js/app.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/app.js') ?>"></script>
</body>
</html>
