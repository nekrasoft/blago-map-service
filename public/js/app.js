let map;
let placemarks = [];
let allBunkers = [];

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

  loadBunkers();
  bindEvents();
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
    renderMarkers();
    renderList();
    updateFilterOptions();
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
        draggable: true
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
      }).catch(err => console.error('Ошибка обновления координат:', err));
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

  allBunkers.forEach(b => {
    const cls = getFillClass(b.fillLevel);
    const label = displayNumber(b.number);
    const li = document.createElement('li');
    li.innerHTML =
      '<span class="bunker-indicator indicator-' + cls + '"></span>' +
      '<div class="bunker-info">' +
        '<div class="bunker-number">' + label + ' <span style="font-weight:400;color:#888;font-size:0.8rem">' + (b.contractor || '') + '</span></div>' +
        '<div class="bunker-address">' + (b.address || '—') + '</div>' +
      '</div>' +
      '<span class="bunker-fill-badge fill-' + cls + '">' + b.fillLevel + '%</span>';

    li.addEventListener('click', function () {
      map.setCenter([b.lat, b.lng], 15, { duration: 300 });
      const pm = placemarks.find(p => p.bunkerData && p.bunkerData.id === b.id);
      if (pm) pm.balloon.open();
    });

    list.appendChild(li);
  });
}

// ===== Фильтры =====

function updateFilterOptions() {
  const districtSelect = document.getElementById('filter-district');
  const wasteSelect = document.getElementById('filter-waste');
  const contractorSelect = document.getElementById('filter-contractor');

  const currentDistrict = districtSelect.value;
  const currentWaste = wasteSelect.value;
  const currentContractor = contractorSelect.value;

  const districts = [...new Set(allBunkers.map(b => b.district).filter(Boolean))].sort();
  const wasteTypes = [...new Set(allBunkers.map(b => b.wasteType).filter(Boolean))].sort();
  const contractors = [...new Set(allBunkers.map(b => b.contractor).filter(Boolean))].sort();

  districtSelect.innerHTML = '<option value="">Все районы</option>';
  districts.forEach(d => {
    const opt = document.createElement('option');
    opt.value = d;
    opt.textContent = d;
    if (d === currentDistrict) opt.selected = true;
    districtSelect.appendChild(opt);
  });

  wasteSelect.innerHTML = '<option value="">Все типы мусора</option>';
  wasteTypes.forEach(w => {
    const opt = document.createElement('option');
    opt.value = w;
    opt.textContent = w;
    if (w === currentWaste) opt.selected = true;
    wasteSelect.appendChild(opt);
  });

  contractorSelect.innerHTML = '<option value="">Все контрагенты</option>';
  contractors.forEach(c => {
    const opt = document.createElement('option');
    opt.value = c;
    opt.textContent = c;
    if (c === currentContractor) opt.selected = true;
    contractorSelect.appendChild(opt);
  });
}

function applyFilters() {
  const district = document.getElementById('filter-district').value;
  const wasteType = document.getElementById('filter-waste').value;
  const contractor = document.getElementById('filter-contractor').value;
  loadBunkers({ district, wasteType, contractor });
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

function openCreateForm() {
  openModal('Добавить бункер');
  document.getElementById('form-number').value = 0;
  document.getElementById('form-volume').value = 8;
  document.getElementById('form-fill').value = 0;
  document.getElementById('form-pickup-date').value = new Date().toISOString().slice(0, 10);
  formOriginalAddress = '';

  const center = map.getCenter();
  document.getElementById('form-lat').value = center[0].toFixed(4);
  document.getElementById('form-lng').value = center[1].toFixed(4);
}

function editBunker(id) {
  const b = allBunkers.find(x => x.id === id);
  if (!b) return;

  map.balloon.close();

  openModal('Редактировать бункер ' + displayNumber(b.number));
  document.getElementById('form-id').value = b.id;
  document.getElementById('form-number').value = b.number;
  document.getElementById('form-volume').value = b.volume;
  document.getElementById('form-contractor').value = b.contractor;
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
    await loadBunkers();
  } catch (err) {
    console.error('Ошибка удаления:', err);
    alert('Не удалось удалить бункер');
  }
}

async function handleFormSubmit(e) {
  e.preventDefault();

  const id = document.getElementById('form-id').value;
  const data = {
    number: parseInt(document.getElementById('form-number').value, 10),
    volume: parseInt(document.getElementById('form-volume').value, 10),
    contractor: document.getElementById('form-contractor').value,
    address: document.getElementById('form-address').value,
    district: document.getElementById('form-district').value,
    wasteType: document.getElementById('form-waste').value,
    lastPickupDate: document.getElementById('form-pickup-date').value,
    fillLevel: parseInt(document.getElementById('form-fill').value, 10),
    contactPhone: document.getElementById('form-phone').value,
    lat: parseFloat(document.getElementById('form-lat').value),
    lng: parseFloat(document.getElementById('form-lng').value)
  };

  try {
    if (id) {
      await BunkerAPI.update(id, data);
    } else {
      await BunkerAPI.create(data);
    }
    closeModal();
    await loadBunkers();
  } catch (err) {
    console.error('Ошибка сохранения:', err);
    alert('Не удалось сохранить бункер');
  }
}

// ===== Геокодирование адреса из формы по Enter =====

let formOriginalAddress = '';

function handleAddressKeydown(e) {
  if (e.key !== 'Enter') return;
  e.preventDefault();

  const input = document.getElementById('form-address');
  const newAddress = input.value.trim();
  if (!newAddress || newAddress === formOriginalAddress) return;

  ymaps.geocode(newAddress, { results: 1 }).then(function (res) {
    const geoObject = res.geoObjects.get(0);
    if (!geoObject) {
      alert('Адрес не найден');
      return;
    }
    const coords = geoObject.geometry.getCoordinates();
    const resolvedAddress = geoObject.getAddressLine();

    document.getElementById('form-lat').value = coords[0].toFixed(6);
    document.getElementById('form-lng').value = coords[1].toFixed(6);
    input.value = resolvedAddress;
    formOriginalAddress = resolvedAddress;

    map.setCenter(coords, 16, { duration: 300 });
  }).catch(function () {
    alert('Ошибка геокодирования адреса');
  });
}

// ===== Привязка событий =====

function bindEvents() {
  document.getElementById('btn-add').addEventListener('click', openCreateForm);
  document.getElementById('modal-close').addEventListener('click', closeModal);
  document.getElementById('btn-cancel').addEventListener('click', closeModal);
  document.getElementById('bunker-form').addEventListener('submit', handleFormSubmit);
  document.getElementById('filter-district').addEventListener('change', applyFilters);
  document.getElementById('filter-waste').addEventListener('change', applyFilters);
  document.getElementById('filter-contractor').addEventListener('change', applyFilters);
  document.getElementById('form-address').addEventListener('keydown', handleAddressKeydown);

  document.getElementById('modal-overlay').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
  });
}
