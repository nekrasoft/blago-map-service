require('dotenv').config();
const express = require('express');
const fs = require('fs');
const path = require('path');
const { v4: uuidv4 } = require('uuid');

const app = express();
const PORT = process.env.PORT || 3000;
const DATA_FILE = path.join(__dirname, 'data', 'bunkers.json');

app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

function readBunkers() {
  const raw = fs.readFileSync(DATA_FILE, 'utf-8');
  return JSON.parse(raw);
}

function writeBunkers(bunkers) {
  fs.writeFileSync(DATA_FILE, JSON.stringify(bunkers, null, 2), 'utf-8');
}

// GET /api/config — конфигурация для фронтенда (API-ключ карт)
app.get('/api/config', (req, res) => {
  res.json({
    yandexMapsApiKey: process.env.YANDEX_MAPS_API_KEY || ''
  });
});

// GET /api/bunkers — список бункеров с фильтрацией
app.get('/api/bunkers', (req, res) => {
  try {
    let bunkers = readBunkers();
    const { district, wasteType, contractor } = req.query;

    if (district) {
      bunkers = bunkers.filter(b => b.district === district);
    }
    if (wasteType) {
      bunkers = bunkers.filter(b => b.wasteType === wasteType);
    }
    if (contractor) {
      bunkers = bunkers.filter(b => b.contractor === contractor);
    }

    res.json(bunkers);
  } catch (err) {
    console.error('Ошибка чтения бункеров:', err.message);
    res.status(500).json({ error: 'Ошибка чтения данных' });
  }
});

// POST /api/bunkers — создание бункера
app.post('/api/bunkers', (req, res) => {
  try {
    const bunkers = readBunkers();
    const newBunker = {
      id: uuidv4(),
      number: req.body.number || bunkers.length + 1,
      volume: req.body.volume || 8,
      address: req.body.address || '',
      district: req.body.district || '',
      contractor: req.body.contractor || '',
      wasteType: req.body.wasteType || 'ТБО',
      lastPickupDate: req.body.lastPickupDate || new Date().toISOString().slice(0, 10),
      fillLevel: req.body.fillLevel || 0,
      contactPhone: req.body.contactPhone || '',
      lat: req.body.lat || 0,
      lng: req.body.lng || 0
    };

    bunkers.push(newBunker);
    writeBunkers(bunkers);
    res.status(201).json(newBunker);
  } catch (err) {
    console.error('Ошибка создания бункера:', err.message);
    res.status(500).json({ error: 'Ошибка создания бункера' });
  }
});

// PUT /api/bunkers/:id — обновление бункера
app.put('/api/bunkers/:id', (req, res) => {
  try {
    const bunkers = readBunkers();
    const index = bunkers.findIndex(b => b.id === req.params.id);

    if (index === -1) {
      return res.status(404).json({ error: 'Бункер не найден' });
    }

    const updatedBunker = { ...bunkers[index], ...req.body, id: bunkers[index].id };
    bunkers[index] = updatedBunker;
    writeBunkers(bunkers);
    res.json(updatedBunker);
  } catch (err) {
    console.error('Ошибка обновления бункера:', err.message);
    res.status(500).json({ error: 'Ошибка обновления бункера' });
  }
});

// DELETE /api/bunkers/:id — удаление бункера
app.delete('/api/bunkers/:id', (req, res) => {
  try {
    let bunkers = readBunkers();
    const index = bunkers.findIndex(b => b.id === req.params.id);

    if (index === -1) {
      return res.status(404).json({ error: 'Бункер не найден' });
    }

    bunkers.splice(index, 1);
    writeBunkers(bunkers);
    res.json({ success: true });
  } catch (err) {
    console.error('Ошибка удаления бункера:', err.message);
    res.status(500).json({ error: 'Ошибка удаления бункера' });
  }
});

app.listen(PORT, () => {
  console.log(`Сервер запущен на http://localhost:${PORT}`);
});
