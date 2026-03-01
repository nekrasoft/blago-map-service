<?php
header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.php';
$dataFile   = __DIR__ . '/data/bunkers.json';

// --- Конфигурация ---
$config = file_exists($configFile) ? require $configFile : [];

// --- Маршрутизация ---
$method = $_SERVER['REQUEST_METHOD'];
$route  = isset($_GET['route']) ? trim($_GET['route'], '/') : '';
$id     = isset($_GET['id']) ? $_GET['id'] : null;

// --- Утилиты ---
function readBunkers($file) {
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    return json_decode($json, true) ?: [];
}

function writeBunkers($file, $bunkers) {
    file_put_contents($file, json_encode($bunkers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function getRequestBody() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function generateId() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Роуты ---

// GET /api/config
if ($route === 'config' && $method === 'GET') {
    jsonResponse([
        'yandexMapsApiKey' => $config['yandexMapsApiKey'] ?? ''
    ]);
}

// /api/bunkers
if ($route === 'bunkers') {

    // GET /api/bunkers — список с фильтрацией
    if ($method === 'GET' && !$id) {
        $bunkers = readBunkers($dataFile);

        if (!empty($_GET['district'])) {
            $district = $_GET['district'];
            $bunkers = array_values(array_filter($bunkers, function ($b) use ($district) {
                return $b['district'] === $district;
            }));
        }
        if (!empty($_GET['wasteType'])) {
            $wasteType = $_GET['wasteType'];
            $bunkers = array_values(array_filter($bunkers, function ($b) use ($wasteType) {
                return $b['wasteType'] === $wasteType;
            }));
        }
        if (!empty($_GET['contractor'])) {
            $contractor = $_GET['contractor'];
            $bunkers = array_values(array_filter($bunkers, function ($b) use ($contractor) {
                return $b['contractor'] === $contractor;
            }));
        }

        jsonResponse($bunkers);
    }

    // POST /api/bunkers — создание
    if ($method === 'POST' && !$id) {
        $body = getRequestBody();
        $bunkers = readBunkers($dataFile);

        $newBunker = [
            'id'             => generateId(),
            'number'         => $body['number'] ?? count($bunkers) + 1,
            'volume'         => $body['volume'] ?? 8,
            'address'        => $body['address'] ?? '',
            'district'       => $body['district'] ?? '',
            'contractor'     => $body['contractor'] ?? '',
            'wasteType'      => $body['wasteType'] ?? 'ТБО',
            'lastPickupDate' => $body['lastPickupDate'] ?? date('Y-m-d'),
            'fillLevel'      => $body['fillLevel'] ?? 0,
            'contactPhone'   => $body['contactPhone'] ?? '',
            'lat'            => $body['lat'] ?? 0,
            'lng'            => $body['lng'] ?? 0,
        ];

        $bunkers[] = $newBunker;
        writeBunkers($dataFile, $bunkers);
        jsonResponse($newBunker, 201);
    }

    // PUT /api/bunkers/:id — обновление
    if ($method === 'PUT' && $id) {
        $body = getRequestBody();
        $bunkers = readBunkers($dataFile);
        $index = null;

        foreach ($bunkers as $i => $b) {
            if ($b['id'] === $id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            jsonResponse(['error' => 'Бункер не найден'], 404);
        }

        $bunkers[$index] = array_merge($bunkers[$index], $body);
        $bunkers[$index]['id'] = $id;
        writeBunkers($dataFile, $bunkers);
        jsonResponse($bunkers[$index]);
    }

    // DELETE /api/bunkers/:id — удаление
    if ($method === 'DELETE' && $id) {
        $bunkers = readBunkers($dataFile);
        $found = false;

        foreach ($bunkers as $i => $b) {
            if ($b['id'] === $id) {
                array_splice($bunkers, $i, 1);
                $found = true;
                break;
            }
        }

        if (!$found) {
            jsonResponse(['error' => 'Бункер не найден'], 404);
        }

        writeBunkers($dataFile, $bunkers);
        jsonResponse(['success' => true]);
    }
}

// Неизвестный маршрут
jsonResponse(['error' => 'Маршрут не найден'], 404);
