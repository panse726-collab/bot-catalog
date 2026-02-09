<?php
/**
 * Кэширующий прокси для Google Sheets API
 * Кэширует данные на 10 минут для быстрой загрузки каталога
 */

// Настройки
$google_api_url = 'https://script.google.com/macros/s/AKfycbwi8KIwADnVpa6CkZWGgjjXHiHuYzYCyQmN_6a_QqaFtRv9k19-bIXoqc52wiF69W37/exec';
$cache_file = __DIR__ . '/catalog_cache.json';
$cache_time = 600; // 10 минут в секундах

// CORS заголовки
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// Проверяем есть ли актуальный кэш
$use_cache = false;
if (file_exists($cache_file)) {
    $file_age = time() - filemtime($cache_file);
    if ($file_age < $cache_time) {
        $use_cache = true;
    }
}

if ($use_cache) {
    // Отдаём из кэша — мгновенно
    $data = file_get_contents($cache_file);
    
    // Если запрос с callback (JSONP) — оборачиваем
    if (isset($_GET['callback'])) {
        $callback = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['callback']);
        echo $callback . '(' . $data . ');';
    } else {
        echo $data;
    }
} else {
    // Загружаем свежие данные из Google
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'follow_location' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $data = @file_get_contents($google_api_url, false, $context);
    
    if ($data === false) {
        // Если Google не ответил — пробуем отдать старый кэш
        if (file_exists($cache_file)) {
            $data = file_get_contents($cache_file);
        } else {
            // Нет данных вообще
            $data = '{"ok":false,"error":"API unavailable"}';
        }
    } else {
        // Сохраняем в кэш
        file_put_contents($cache_file, $data);
    }
    
    // Если запрос с callback (JSONP) — оборачиваем
    if (isset($_GET['callback'])) {
        $callback = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['callback']);
        echo $callback . '(' . $data . ');';
    } else {
        echo $data;
    }
}
