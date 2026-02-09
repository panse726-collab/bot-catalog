<?php
// ===========================
// BOT CONFIG (separate file)
// ===========================

// --- Telegram ---
define('BOT_TOKEN', '8577965896:AAE7lBeotlRTOUrXI1GlxwqEfdauU4J_tiQ');
define('ADMIN_IDS', [1134229855]);

// --- Domain ---
define('BASE_URL', 'https://daniil-dealer.ru');

// --- Upload API security ---
define('UPLOAD_KEY', 'kF9p2Qv8mX1sA3dZ0nL7rT6uB5yH4cE2');
define('UPLOAD_ENDPOINT', BASE_URL . '/botCATALOG/api/upload.php');
define('DELETE_ENDPOINT', BASE_URL . '/botCATALOG/api/delete.php');

// --- Local paths ---
define('BOT_DATA_DIR', __DIR__ . '/data');
define('BOT_SECURE_DIR', __DIR__ . '/secure');
define('SERVICE_ACCOUNT_JSON', BOT_SECURE_DIR . '/google-service-account.json');

// --- Limits ---
define('MAX_UPLOAD_BYTES', 12 * 1024 * 1024);

// --- Google Sheets ---
define('SPREADSHEET_ID', '1n82atXsAmVREK7KBFbHLSGKo8QXVDAAC1kVrXRCWTRE');

// Названия листов
define('SHEET_JEWELRY', 'Ювелирные');
define('SHEET_BAGS', 'Сумки');
define('SHEET_WATCHES', 'Часы');
define('SHEET_ACCESSORIES', 'Аксессуары');

define('CATEGORY_SHEETS', [
  'jewelry'     => SHEET_JEWELRY,
  'bags'        => SHEET_BAGS,
  'watches'     => SHEET_WATCHES,
  'accessories' => SHEET_ACCESSORIES,
]);

// Куда складывать фото
define('UPLOAD_DIRS', [
  'bags'              => __DIR__ . '/../images/catalogo/bags',
  'watches'           => __DIR__ . '/../images/catalogo/watches',
  'accessories'       => __DIR__ . '/../images/catalogo/accessories',
  'jewelry:rings'     => __DIR__ . '/../images/catalogo/jewelry/rings',
  'jewelry:bracelets' => __DIR__ . '/../images/catalogo/jewelry/bracelets',
  'jewelry:pendants'  => __DIR__ . '/../images/catalogo/jewelry/pendants',
  'jewelry:necklaces' => __DIR__ . '/../images/catalogo/jewelry/necklaces',
  'jewelry:earrings'  => __DIR__ . '/../images/catalogo/jewelry/earrings',
]);