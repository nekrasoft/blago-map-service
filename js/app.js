let map;
let placemarks = [];
let allBunkers = [];
let allFilterOptions = { districts: [], wasteTypes: [], contractors: [] };
let allBunkersUnfiltered = [];
let availableCounterparties = [];
// Страница карты доступна только после авторизации (проверка в index.php)
let isReadonly = window.READONLY_USER === true;

const DEFAULT_CENTER = [58.6035, 49.6668];
const DEFAULT_ZOOM = 13;

// ===== Утилиты =====

function getFillColor(level) {
  if (level <= 30) return '#2ecc71';
  if (level <= 70) return '#f39c12';
  return '#e74c3c';
}

function getFillClass(level) {
  if (level <= 30) return 'green';
  if (level <= 70) return 'yellow';
  return 'red';
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('ru-RU');
}

function displayNumber(num) {
  return num ? '№' + num : 'б/н';
}

// ===== Загрузка API Яндекс.Карт и инициализация =====

(async function bootstrap() {
  try {
    bindAuthEvents();

    const res = await fetch('/api/config');
    const config = await res.json();

    if (!config.yandexMapsApiKey || config.yandexMapsApiKey === 'YOUR_KEY_HERE') {
      document.getElementById('map').innerHTML =
        '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#e74c3c;font-size:1.1rem;padding:40px;text-align:center">' +
        'API-ключ Яндекс.Карт не настроен.<br>Укажите YANDEX_MAPS_API_KEY в файле .env и перезапустите сервер.' +
        '</div>';
      return;
    }

    const script = document.createElement('script');
    script.src = 'https://api-maps.yandex.ru/2.1/?apikey=' + encodeURIComponent(config.yandexMapsApiKey) + '&lang=ru_RU';
    script.type = 'text/javascript';
    script.onload = function () {
      ymaps.ready(init);
    };
    document.head.appendChild(script);
  } catch (err) {
    console.error('Ошибка загрузки конфигурации:', err);
  }
})();

function init() {
  map = new ymaps.Map('map', {
    center: DEFAULT_CENTER,
    zoom: DEFAULT_ZOOM,
    controls: ['zoomControl', 'typeSelector', 'fullscreenControl']
  });

  ensureCounterpartiesLoaded().then(function () {
    populateCounterpartySelect();
  });
  refreshFilterOptions();
  loadBunkers();
  bindEvents();
}

async function ensureCounterpartiesLoaded() {
  try {
    const data = await CounterpartyAPI.getAll();
    availableCounterparties = (Array.isArray(data) ? data : [])
      .filter(c => c && c.id != null && c.shortName)
      .map(c => ({
        id: Number(c.id),
        shortName: String(c.shortName).trim(),
        name: c.name ? String(c.name).trim() : ''
      }))
      .filter(c => Number.isFinite(c.id) && c.id > 0 && c.shortName)
      .sort((a, b) => a.shortName.localeCompare(b.shortName, 'ru'));
  } catch (err) {
    console.error('Ошибка загрузки справочника контрагентов:', err);
    availableCounterparties = [];
  }
}

function populateCounterpartySelect(selectedCounterpartyId, fallbackContractorName) {
  const select = document.getElementById('form-contractor');
  if (!select) return;

  const fallbackName = (fallbackContractorName || '').trim();
  let selectedId = '';

  if (selectedCounterpartyId != null && selectedCounterpartyId !== '') {
    selectedId = String(selectedCounterpartyId);
  } else if (fallbackName) {
    const match = availableCounterparties.find(c => c.shortName === fallbackName);
    if (match) selectedId = String(match.id);
  }

  const hasMatch = selectedId && availableCounterparties.some(c => String(c.id) === selectedId);
  const placeholderText = !hasMatch && fallbackName
    ? 'Выберите контрагента (текущий: ' + fallbackName + ')'
    : 'Выберите контрагента';

  select.innerHTML = '';
  const placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = placeholderText;
  select.appendChild(placeholder);

  availableCounterparties.forEach(c => {
    const opt = document.createElement('option');
    opt.value = String(c.id);
    opt.textContent = c.shortName;
    select.appendChild(opt);
  });

  select.value = hasMatch ? selectedId : '';
}

async function refreshFilterOptions() {
  try {
    allBunkersUnfiltered = await BunkerAPI.getAll({});
    allFilterOptions.districts = [...new Set(allBunkersUnfiltered.map(b => b.district).filter(Boolean))].sort();
    allFilterOptions.wasteTypes = [...new Set(allBunkersUnfiltered.map(b => b.wasteType).filter(Boolean))].sort();
    allFilterOptions.contractors = [...new Set(allBunkersUnfiltered.map(b => b.contractor).filter(Boolean))].sort();
  } catch (err) {
    console.error('Ошибка загрузки опций фильтров:', err);
  }
}

// ===== Загрузка и отображение бункеров =====

function getCurrentFilters() {
  return {
    district: document.getElementById('filter-district').value,
    wasteType: document.getElementById('filter-waste').value,
    contractor: document.getElementById('filter-contractor').value
  };
}

async function loadBunkers(filters) {
  try {
    allBunkers = await BunkerAPI.getAll(filters || getCurrentFilters());
    if (document.getElementById('filter-full').checked) {
      allBunkers = allBunkers.filter(b => b.fillLevel > 70);
    }
    renderMarkers();
    renderList();
    updateFilterOptions();
    document.getElementById('bunker-count').textContent = allBunkers.length;
  } catch (err) {
    console.error('Ошибка загрузки:', err);
  }
}

function renderMarkers() {
  placemarks.forEach(pm => map.geoObjects.remove(pm));
  placemarks = [];

  allBunkers.forEach(bunker => {
    const color = getFillColor(bunker.fillLevel);
    const label = displayNumber(bunker.number);

    const pm = new ymaps.Placemark(
      [bunker.lat, bunker.lng],
      {
        balloonContentHeader: '<strong>Бункер ' + label + '</strong>',
        balloonContentBody: buildBalloonBody(bunker),
        balloonContentFooter: buildBalloonFooter(bunker),
        hintContent: 'Бункер ' + label + (bunker.contractor ? ' — ' + bunker.contractor : '') + (bunker.address ? ', ' + bunker.address : '')
      },
      {
        preset: 'islands#dotIcon',
        iconColor: color,
        draggable: !isReadonly
      }
    );

    pm.events.add('dragend', function () {
      const coords = pm.geometry.getCoordinates();
      ymaps.geocode(coords, { results: 1 }).then(function (res) {
        const firstGeoObject = res.geoObjects.get(0);
        const newAddress = firstGeoObject
          ? firstGeoObject.getAddressLine()
          : bunker.address;
        return BunkerAPI.update(bunker.id, {
          lat: coords[0],
          lng: coords[1],
          address: newAddress
        });
      }).then(updated => {
        bunker.lat = updated.lat;
        bunker.lng = updated.lng;
        bunker.address = updated.address;
        pm.properties.set({
          balloonContentBody: buildBalloonBody(bunker),
          hintContent: 'Бункер ' + displayNumber(bunker.number) + (bunker.contractor ? ' — ' + bunker.contractor : '') + (bunker.address ? ', ' + bunker.address : '')
        });
        renderList();
      }).catch(function (err) {
      if (err.message === 'auth_required') window.location.href = '/login';
      else if (err.message === 'readonly') alert('Доступ только для чтения');
      else console.error('Ошибка обновления координат:', err);
    });
    });

    pm.bunkerData = bunker;
    map.geoObjects.add(pm);
    placemarks.push(pm);
  });
}

function buildBalloonBody(b) {
  const cls = getFillClass(b.fillLevel);
  return '' +
    '<div class="balloon-content">' +
      '<div class="balloon-row">' +
        '<span class="balloon-label">Контрагент:</span>' +
        '<span class="balloon-value">' + (b.contractor || '—') + '</span>' +
      '</div>' +
      '<div class="balloon-row">' +
        '<span class="balloon-label">Адрес:</span>' +
        '<span class="balloon-value">' + (b.address || '—') + '</span>' +
      '</div>' +
      (b.district ? '<div class="balloon-row">' +
        '<span class="balloon-label">Район:</span>' +
        '<span class="balloon-value">' + b.district + '</span>' +
      '</div>' : '') +
      '<div class="balloon-row">' +
        '<span class="balloon-label">Объём:</span>' +
        '<span class="balloon-value">' + b.volume + ' м³</span>' +
      '</div>' +
      '<div class="balloon-row">' +
        '<span class="balloon-label">Тип мусора:</span>' +
        '<span class="balloon-value">' + b.wasteType + '</span>' +
      '</div>' +
      '<div class="balloon-row">' +
        '<span class="balloon-label">Заполненность:</span>' +
        '<span class="balloon-value"><span class="bunker-fill-badge fill-' + cls + '">' + b.fillLevel + '%</span></span>' +
      '</div>' +
      '<div class="balloon-row">' +
        '<span class="balloon-label">Последний вывоз:</span>' +
        '<span class="balloon-value">' + formatDate(b.lastPickupDate) + '</span>' +
      '</div>' +
      (b.contactPhone ? '<div class="balloon-row">' +
        '<span class="balloon-label">Телефон:</span>' +
        '<span class="balloon-value"><a href="tel:' + b.contactPhone + '">' + b.contactPhone + '</a></span>' +
      '</div>' : '') +
    '</div>';
}

function buildBalloonFooter(b) {
  if (isReadonly) return '';
  return '' +
    '<div class="balloon-content">' +
      '<div class="balloon-actions">' +
        '<button class="btn btn-primary" onclick="editBunker(\'' + b.id + '\')">Редактировать</button>' +
        '<button class="btn btn-danger" onclick="deleteBunker(\'' + b.id + '\')">Удалить</button>' +
      '</div>' +
    '</div>';
}

// ===== Список бункеров в сайдбаре =====

function renderList() {
  const list = document.getElementById('bunker-list');
  list.innerHTML = '';

  const collator = new Intl.Collator('ru', { sensitivity: 'base', numeric: true });
  const sortedForSidebar = allBunkers.slice().sort(function (a, b) {
    const contractorA = (a.contractor || '').trim();
    const contractorB = (b.contractor || '').trim();
    const contractorCmp = collator.compare(contractorA, contractorB);
    if (contractorCmp !== 0) return contractorCmp;

    const districtA = (a.district || '').trim();
    const districtB = (b.district || '').trim();
    const districtCmp = collator.compare(districtA, districtB);
    if (districtCmp !== 0) return districtCmp;

    const numA = Number(a.number) || 0;
    const numB = Number(b.number) || 0;
    if (numA !== numB) return numA - numB;

    return collator.compare(String(a.id || ''), String(b.id || ''));
  });

  sortedForSidebar.forEach(b => {
    const cls = getFillClass(b.fillLevel);
    const label = displayNumber(b.number);
    const location = (b.district && b.district.trim()) || (b.address && b.address.trim()) || '—';
    const li = document.createElement('li');
    li.innerHTML =
      '<span class="bunker-indicator indicator-' + cls + '"></span>' +
      '<div class="bunker-info">' +
        '<div class="bunker-number">' + label + ' <span style="font-weight:400;color:#888;font-size:0.8rem">' + (b.contractor || '') + '</span></div>' +
        '<div class="bunker-address">' + location + '</div>' +
      '</div>' +
      '<span class="bunker-fill-badge fill-' + cls + '">' + b.fillLevel + '%</span>';

    li.addEventListener('click', function () {
      map.setCenter([b.lat, b.lng], 15, { duration: 300 });
      const pm = placemarks.find(p => p.bunkerData && p.bunkerData.id === b.id);
      if (pm) pm.balloon.open();
      if (isMobileView()) hideSidebar();
    });

    list.appendChild(li);
  });
}

// ===== Фильтры =====

function countByField(field, otherFilters) {
  var counts = {};
  allBunkersUnfiltered.forEach(function (b) {
    var match = true;
    if (otherFilters.district && b.district !== otherFilters.district) match = false;
    if (otherFilters.wasteType && b.wasteType !== otherFilters.wasteType) match = false;
    if (otherFilters.contractor && b.contractor !== otherFilters.contractor) match = false;
    if (match && b[field]) {
      counts[b[field]] = (counts[b[field]] || 0) + 1;
    }
  });
  return counts;
}

function updateFilterOptions() {
  const districtSelect = document.getElementById('filter-district');
  const wasteSelect = document.getElementById('filter-waste');
  const contractorSelect = document.getElementById('filter-contractor');

  const currentDistrict = districtSelect.value;
  const currentWaste = wasteSelect.value;
  const currentContractor = contractorSelect.value;

  const districtCounts = countByField('district', { wasteType: currentWaste, contractor: currentContractor });
  const wasteCounts = countByField('wasteType', { district: currentDistrict, contractor: currentContractor });
  const contractorCounts = countByField('contractor', { district: currentDistrict, wasteType: currentWaste });

  districtSelect.innerHTML = '<option value="">Все районы</option>';
  allFilterOptions.districts.forEach(d => {
    const opt = document.createElement('option');
    opt.value = d;
    var cnt = districtCounts[d] || 0;
    opt.textContent = d + ' (' + cnt + ')';
    if (d === currentDistrict) opt.selected = true;
    districtSelect.appendChild(opt);
  });

  wasteSelect.innerHTML = '<option value="">Все типы мусора</option>';
  allFilterOptions.wasteTypes.forEach(w => {
    const opt = document.createElement('option');
    opt.value = w;
    var cnt = wasteCounts[w] || 0;
    opt.textContent = w + ' (' + cnt + ')';
    if (w === currentWaste) opt.selected = true;
    wasteSelect.appendChild(opt);
  });

  contractorSelect.innerHTML = '<option value="">Все контрагенты</option>';
  allFilterOptions.contractors.forEach(c => {
    const opt = document.createElement('option');
    opt.value = c;
    var cnt = contractorCounts[c] || 0;
    opt.textContent = c + ' (' + cnt + ')';
    if (c === currentContractor) opt.selected = true;
    contractorSelect.appendChild(opt);
  });
}

async function applyFilters() {
  const district = document.getElementById('filter-district').value;
  const wasteType = document.getElementById('filter-waste').value;
  const contractor = document.getElementById('filter-contractor').value;
  const onlyFull = document.getElementById('filter-full').checked;
  const hasFilter = district || wasteType || contractor || onlyFull;
  await loadBunkers({ district, wasteType, contractor });
  if (hasFilter && allBunkers.length > 0) {
    fitMapToBunkers();
  }
}

function fitMapToBunkers() {
  if (allBunkers.length === 0) return;
  if (allBunkers.length === 1) {
    map.setCenter([allBunkers[0].lat, allBunkers[0].lng], 15, { duration: 300 });
    return;
  }
  var bounds = placemarks.reduce(function (acc, pm) {
    var coords = pm.geometry.getCoordinates();
    return [
      [Math.min(acc[0][0], coords[0]), Math.min(acc[0][1], coords[1])],
      [Math.max(acc[1][0], coords[0]), Math.max(acc[1][1], coords[1])]
    ];
  }, [[90, 180], [-90, -180]]);
  map.setBounds(bounds, { checkZoomRange: true, zoomMargin: 40, duration: 300 }).then(function () {
    if (map.getZoom() > 16) map.setZoom(16);
  });
}

// ===== Модальное окно (CRUD) =====

function openModal(title) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-overlay').classList.remove('hidden');
}

function closeModal() {
  document.getElementById('modal-overlay').classList.add('hidden');
  document.getElementById('bunker-form').reset();
  document.getElementById('form-id').value = '';
}

async function openCreateForm() {
  await ensureCounterpartiesLoaded();
  openModal('Добавить бункер');
  populateCounterpartySelect(null, '');
  document.getElementById('form-number').value = 0;
  document.getElementById('form-volume').value = 8;
  document.getElementById('form-fill').value = 0;
  document.getElementById('form-pickup-date').value = new Date().toISOString().slice(0, 10);
  formOriginalAddress = '';

  const center = map.getCenter();
  document.getElementById('form-lat').value = center[0].toFixed(4);
  document.getElementById('form-lng').value = center[1].toFixed(4);
}

async function editBunker(id) {
  const b = allBunkers.find(x => x.id === id);
  if (!b) return;

  await ensureCounterpartiesLoaded();
  map.balloon.close();

  openModal('Редактировать бункер ' + displayNumber(b.number));
  document.getElementById('form-id').value = b.id;
  document.getElementById('form-number').value = b.number;
  document.getElementById('form-volume').value = b.volume;
  populateCounterpartySelect(b.counterpartyId, b.contractor);
  document.getElementById('form-address').value = b.address;
  formOriginalAddress = b.address;
  document.getElementById('form-district').value = b.district;
  document.getElementById('form-waste').value = b.wasteType;
  document.getElementById('form-pickup-date').value = b.lastPickupDate;
  document.getElementById('form-fill').value = b.fillLevel;
  document.getElementById('form-phone').value = b.contactPhone;
  document.getElementById('form-lat').value = b.lat;
  document.getElementById('form-lng').value = b.lng;
}

async function deleteBunker(id) {
  if (!confirm('Удалить этот бункер?')) return;

  try {
    map.balloon.close();
    await BunkerAPI.remove(id);
    await refreshFilterOptions();
    await loadBunkers();
  } catch (err) {
    if (err.message === 'auth_required') {
      window.location.href = '/login';
      return;
    }
    if (err.message === 'readonly') {
      alert('Доступ только для чтения');
      return;
    }
    console.error('Ошибка удаления:', err);
    alert('Не удалось удалить бункер');
  }
}

async function geocodeAddress(address) {
  const trimmed = (address || '').trim();
  if (!trimmed) return null;
  const res = await ymaps.geocode(trimmed, { results: 1 });
  const geoObject = res.geoObjects.get(0);
  if (!geoObject) return null;
  const coords = geoObject.geometry.getCoordinates();
  return {
    lat: coords[0],
    lng: coords[1],
    address: geoObject.getAddressLine()
  };
}

async function handleFormSubmit(e) {
  e.preventDefault();

  const addressInput = document.getElementById('form-address').value.trim();
  let address = addressInput;
  let lat = parseFloat(document.getElementById('form-lat').value);
  let lng = parseFloat(document.getElementById('form-lng').value);

  if (addressInput && addressInput !== formOriginalAddress) {
    try {
      const geo = await geocodeAddress(addressInput);
      if (geo) {
        address = geo.address;
        lat = geo.lat;
        lng = geo.lng;
        document.getElementById('form-lat').value = lat;
        document.getElementById('form-lng').value = lng;
        document.getElementById('form-address').value = address;
      }
    } catch (err) {
      alert('Ошибка геокодирования адреса. Сохраняю без обновления координат.');
    }
  }

  const contractorSelect = document.getElementById('form-contractor');
  const counterpartyIdValue = contractorSelect.value;
  if (!counterpartyIdValue) {
    alert('Выберите контрагента из списка');
    contractorSelect.focus();
    return;
  }
  const counterpartyId = parseInt(counterpartyIdValue, 10);
  if (!Number.isInteger(counterpartyId) || counterpartyId <= 0) {
    alert('Некорректный контрагент');
    contractorSelect.focus();
    return;
  }
  const selectedCounterparty = availableCounterparties.find(c => c.id === counterpartyId);
  const selectedOption = contractorSelect.options[contractorSelect.selectedIndex];
  const contractorName = selectedCounterparty
    ? selectedCounterparty.shortName
    : ((selectedOption && selectedOption.textContent) ? selectedOption.textContent : '').trim();

  const id = document.getElementById('form-id').value;
  const data = {
    number: parseInt(document.getElementById('form-number').value, 10),
    volume: parseInt(document.getElementById('form-volume').value, 10),
    counterpartyId: counterpartyId,
    contractor: contractorName,
    address: address,
    district: document.getElementById('form-district').value,
    wasteType: document.getElementById('form-waste').value,
    lastPickupDate: document.getElementById('form-pickup-date').value,
    fillLevel: parseInt(document.getElementById('form-fill').value, 10),
    contactPhone: document.getElementById('form-phone').value,
    lat: lat,
    lng: lng
  };

  try {
    if (id) {
      await BunkerAPI.update(id, data);
    } else {
      await BunkerAPI.create(data);
    }
    closeModal();
    await refreshFilterOptions();
    await loadBunkers();
  } catch (err) {
    if (err.message === 'auth_required') {
      window.location.href = '/login';
      return;
    }
    if (err.message === 'readonly') {
      alert('Доступ только для чтения');
      return;
    }
    console.error('Ошибка сохранения:', err);
    alert(err.message || 'Не удалось сохранить бункер');
  }
}

// ===== Геокодирование адреса из формы по Enter =====

let formOriginalAddress = '';

async function handleAddressKeydown(e) {
  if (e.key !== 'Enter') return;
  e.preventDefault();

  const input = document.getElementById('form-address');
  const newAddress = input.value.trim();
  if (!newAddress || newAddress === formOriginalAddress) return;

  try {
    const geo = await geocodeAddress(newAddress);
    if (!geo) {
      alert('Адрес не найден');
      return;
    }
    document.getElementById('form-lat').value = geo.lat.toFixed(6);
    document.getElementById('form-lng').value = geo.lng.toFixed(6);
    input.value = geo.address;
    formOriginalAddress = geo.address;
    map.setCenter([geo.lat, geo.lng], 16, { duration: 300 });
  } catch (err) {
    alert('Ошибка геокодирования адреса');
  }
}

// ===== Скрытие/показ панели (для мобильных) =====

function hideSidebar() {
  document.body.classList.add('sidebar-hidden');
}

function showSidebar() {
  document.body.classList.remove('sidebar-hidden');
}

function isMobileView() {
  return window.matchMedia('(max-width: 768px)').matches;
}

// Свайп влево для скрытия панели
let touchStartX = 0;
function handleSidebarTouchStart(e) {
  touchStartX = e.touches[0].clientX;
}
function handleSidebarTouchEnd(e) {
  const touchEndX = e.changedTouches[0].clientX;
  if (touchStartX - touchEndX > 60) hideSidebar();
}

// ===== Авторизация =====

async function handleLogout() {
  await AuthAPI.logout();
  window.location.href = '/login';
}

function bindAuthEvents() {
  const btnLogout = document.getElementById('btn-logout');
  if (btnLogout) btnLogout.addEventListener('click', handleLogout);
}

// ===== Привязка событий =====

function bindEvents() {
  const btnAdd = document.getElementById('btn-add');
  if (btnAdd) btnAdd.addEventListener('click', openCreateForm);
  document.getElementById('modal-close').addEventListener('click', closeModal);
  document.getElementById('btn-cancel').addEventListener('click', closeModal);
  document.getElementById('bunker-form').addEventListener('submit', handleFormSubmit);
  document.getElementById('filter-district').addEventListener('change', applyFilters);
  document.getElementById('filter-waste').addEventListener('change', applyFilters);
  document.getElementById('filter-contractor').addEventListener('change', applyFilters);
  document.getElementById('filter-full').addEventListener('change', applyFilters);
  document.getElementById('form-address').addEventListener('keydown', handleAddressKeydown);
  document.getElementById('btn-toggle-sidebar').addEventListener('click', showSidebar);

  document.getElementById('modal-overlay').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
  });

  const sidebar = document.getElementById('sidebar');
  const mapEl = document.getElementById('map');

  sidebar.addEventListener('touchstart', handleSidebarTouchStart);
  sidebar.addEventListener('touchend', handleSidebarTouchEnd);

  var toggleBtn = document.getElementById('btn-toggle-sidebar');
  function hideSidebarOnOutsideTap(e) {
    if (!isMobileView()) return;
    if (document.body.classList.contains('sidebar-hidden')) return;
    if (sidebar.contains(e.target)) return;
    if (toggleBtn.contains(e.target)) return;
    hideSidebar();
  }

  document.addEventListener('click', hideSidebarOnOutsideTap);
  document.addEventListener('touchstart', hideSidebarOnOutsideTap);
}
