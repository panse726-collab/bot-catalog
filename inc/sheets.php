<?php
/**
 * Google Sheets API helper (PHP 7.4)
 * Requires in bot.config.php:
 *  - SPREADSHEET_ID
 *  - SERVICE_ACCOUNT_JSON (path to google-service-account.json)
 *  - BOT_SECURE_DIR (folder for token cache)
 */

if (!defined('SPREADSHEET_ID')) {
  throw new Exception('SPREADSHEET_ID not defined (bot.config.php)');
}
if (!defined('SERVICE_ACCOUNT_JSON')) {
  throw new Exception('SERVICE_ACCOUNT_JSON not defined (bot.config.php)');
}
if (!defined('BOT_SECURE_DIR')) {
  throw new Exception('BOT_SECURE_DIR not defined (bot.config.php)');
}

function sheets_b64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function sheets_load_service_account(): array {
  $path = SERVICE_ACCOUNT_JSON;
  if (!file_exists($path)) {
    throw new Exception('Service account json not found: ' . $path);
  }
  $j = json_decode(file_get_contents($path), true);
  if (!is_array($j)) throw new Exception('Bad service account json');
  foreach (['client_email','private_key','token_uri'] as $k) {
    if (empty($j[$k])) throw new Exception("Service account missing: {$k}");
  }
  return $j;
}

function sheets_token_cache_path(): string {
  if (!is_dir(BOT_SECURE_DIR)) @mkdir(BOT_SECURE_DIR, 0755, true);
  return rtrim(BOT_SECURE_DIR, '/\\') . '/google-token-cache.json';
}

function sheets_get_access_token(): string {
  $cache = sheets_token_cache_path();

  // use cached token if still valid
  if (file_exists($cache)) {
    $c = json_decode(file_get_contents($cache), true);
    if (is_array($c) && !empty($c['access_token']) && !empty($c['expires_at'])) {
      if (time() < ((int)$c['expires_at'] - 60)) {
        return (string)$c['access_token'];
      }
    }
  }

  $sa = sheets_load_service_account();

  $now = time();
  $header = ['alg'=>'RS256','typ'=>'JWT'];
  $claims = [
    'iss'   => $sa['client_email'],
    'scope' => 'https://www.googleapis.com/auth/spreadsheets',
    'aud'   => $sa['token_uri'],
    'iat'   => $now,
    'exp'   => $now + 3600,
  ];

  $jwtUnsigned = sheets_b64url_encode(json_encode($header)) . '.' . sheets_b64url_encode(json_encode($claims));
  $signature = '';
  $ok = openssl_sign($jwtUnsigned, $signature, $sa['private_key'], 'sha256');
  if (!$ok) throw new Exception('openssl_sign failed (private_key?)');

  $jwt = $jwtUnsigned . '.' . sheets_b64url_encode($signature);

  $postFields = http_build_query([
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion'  => $jwt,
  ]);

  $ch = curl_init($sa['token_uri']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) throw new Exception('Token request failed: ' . $err);

  $j = json_decode($resp, true);
  if (!is_array($j) || empty($j['access_token'])) {
    throw new Exception('Token response invalid: ' . $resp);
  }
  if ($http < 200 || $http >= 300) {
    throw new Exception('Token http ' . $http . ': ' . $resp);
  }

  $accessToken = (string)$j['access_token'];
  $expiresIn = (int)($j['expires_in'] ?? 3600);

  file_put_contents($cache, json_encode([
    'access_token' => $accessToken,
    'expires_at'   => time() + $expiresIn,
  ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

  return $accessToken;
}

function sheets_api_request(string $method, string $url, array $body = null): array {
  $token = sheets_get_access_token();

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 45);

  $headers = [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
  ];

  if ($body !== null) {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $headers[] = 'Content-Type: application/json; charset=utf-8';
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  }

  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) throw new Exception('Sheets request failed: ' . $err);

  $j = json_decode($resp, true);

  // Google иногда возвращает HTML/текст при ошибках — держим raw
  if ($http < 200 || $http >= 300) {
    $msg = is_array($j) ? json_encode($j, JSON_UNESCAPED_UNICODE) : $resp;
    throw new Exception("Sheets API HTTP {$http}: {$msg}");
  }

  return is_array($j) ? $j : [];
}

/**
 * Get values from range
 * @param string $range like "Ювелирные!A2:W"
 * @return array rows
 */
function sheets_get_values(string $range): array {
  $rangeEnc = rawurlencode($range);
  $url = "https://sheets.googleapis.com/v4/spreadsheets/" . SPREADSHEET_ID . "/values/{$rangeEnc}?majorDimension=ROWS";
  $j = sheets_api_request('GET', $url);
  $vals = $j['values'] ?? [];
  return is_array($vals) ? $vals : [];
}

/**
 * Update single cell
 * @param string $sheetTitle
 * @param string $cell like "B12"
 * @param string $value
 */
function sheets_update_cell(string $sheetTitle, string $cell, $value): void {
  $range = $sheetTitle . '!' . $cell;
  $rangeEnc = rawurlencode($range);
  $url = "https://sheets.googleapis.com/v4/spreadsheets/" . SPREADSHEET_ID . "/values/{$rangeEnc}?valueInputOption=USER_ENTERED";

  $val = $value;
  // Important: for checkboxes use TRUE/FALSE (string ok with USER_ENTERED)
  // Keep as-is.

  sheets_api_request('PUT', $url, [
    'range'  => $range,
    'majorDimension' => 'ROWS',
    'values' => [[ $val ]],
  ]);
}

/**
 * Find row by ID in column A (A2:A...)
 * Returns ['row'=>int, 'values'=>array]
 * where values is row values from A..W (or whatever exists)
 */
function sheets_find_by_id(string $sheetTitle, string $id): ?array {
  $id = trim((string)$id);
  if ($id === '') return null;

  // Pull enough columns to cover jewelry (A..W) and others (A..U)
  $rows = sheets_get_values($sheetTitle . '!A2:W');

  $rowNum = 2;
  foreach ($rows as $r) {
    $cellId = trim((string)($r[0] ?? ''));
    if ($cellId !== '' && $cellId === $id) {
      return [
        'row' => $rowNum,
        'values' => $r,
      ];
    }
    $rowNum++;
  }
  return null;
}

/**
 * Find first empty row by checking ID column A starting from row 2
 * Returns row number (>=2)
 */
function sheets_find_first_empty_row_by_id(string $sheetTitle): int {
  $vals = sheets_get_values($sheetTitle . '!A2:A');
  $row = 2;
  foreach ($vals as $r) {
    if (trim((string)($r[0] ?? '')) === '') return $row;
    $row++;
  }
  return $row;
}

/**
 * Get sheetId by sheet title for batchUpdate operations
 */
function sheets_get_sheet_id_by_title(string $sheetTitle): int {
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . SPREADSHEET_ID . '?fields=sheets(properties(sheetId,title))';
  $j = sheets_api_request('GET', $url);

  foreach (($j['sheets'] ?? []) as $s) {
    $p = $s['properties'] ?? [];
    if (($p['title'] ?? '') === $sheetTitle) {
      return (int)$p['sheetId'];
    }
  }
  throw new Exception('SheetId not found for title: ' . $sheetTitle);
}

/**
 * Delete one row with shift up (like deleting row in UI)
 * @param string $sheetTitle
 * @param int $rowNum 1-based row number
 */
function sheets_delete_row_shift(string $sheetTitle, int $rowNum): void {
  $sheetId = sheets_get_sheet_id_by_title($sheetTitle);

  $startIndex = $rowNum - 1; // 0-based
  $endIndex   = $rowNum;     // exclusive

  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . SPREADSHEET_ID . ':batchUpdate';

  sheets_api_request('POST', $url, [
    'requests' => [[
      'deleteDimension' => [
        'range' => [
          'sheetId' => $sheetId,
          'dimension' => 'ROWS',
          'startIndex' => $startIndex,
          'endIndex' => $endIndex,
        ],
      ],
    ]],
  ]);
}
