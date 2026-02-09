<?php
ini_set('display_errors', 0);
error_reporting(0);
http_response_code(200);

require_once __DIR__ . '/bot.config.php';
require_once __DIR__ . '/inc/sheets.php';
require_once __DIR__ . '/inc/ui_texts.php';

function starts_with($h, $n){ return $n === '' || strpos($h, $n) === 0; }

if (!is_dir(BOT_DATA_DIR)) @mkdir(BOT_DATA_DIR, 0755, true);

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

function tg_api(string $method, array $params = []) {
  $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  $resp = curl_exec($ch);
  curl_close($ch);
  $j = json_decode($resp, true);
  return $j ?: ['ok'=>false,'raw'=>$resp];
}

function send($chatId, $text, $replyMarkup = null) {
  $params = ['chat_id'=>$chatId, 'text'=>$text, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true];
  if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
  tg_api('sendMessage', $params);
}

function answer_cb($cbId, $text='') {
  tg_api('answerCallbackQuery', ['callback_query_id'=>$cbId, 'text'=>$text, 'show_alert'=>false]);
}

function is_admin($fromId): bool { return in_array((int)$fromId, ADMIN_IDS, true); }

function state_path($chatId): string { return BOT_DATA_DIR . "/state_{$chatId}.json"; }
function load_state($chatId): array {
  $p = state_path($chatId);
  if (!file_exists($p)) return [];
  $j = json_decode(file_get_contents($p), true);
  return is_array($j) ? $j : [];
}
function save_state($chatId, array $state): void {
  file_put_contents(state_path($chatId), json_encode($state, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}
function clear_state($chatId): void { @unlink(state_path($chatId)); }

function download_telegram_file(string $fileId): string {
  $r = tg_api('getFile', ['file_id'=>$fileId]);
  if (empty($r['ok']) || empty($r['result']['file_path'])) throw new Exception('getFile failed');
  $path = $r['result']['file_path'];
  $url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $path;

  $tmp = tempnam(sys_get_temp_dir(), 'tg_');
  $fp = fopen($tmp, 'w');
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_FILE, $fp);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 60);
  curl_exec($ch);
  curl_close($ch);
  fclose($fp);
  return $tmp;
}

function upload_to_site(string $localPath, string $category, string $sub = ''): string {
  $post = [
    'upload_key' => UPLOAD_KEY,
    'category'   => $category,
  ];
  if ($category === 'jewelry') $post['sub'] = $sub ?: 'rings';
  $post['file'] = new CURLFile($localPath);

  $ch = curl_init(UPLOAD_ENDPOINT);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 60);
  $resp = curl_exec($ch);
  curl_close($ch);

  $j = json_decode($resp, true);
  if (!$j || empty($j['ok']) || empty($j['url'])) throw new Exception('Upload failed: ' . ($resp ?: 'no response'));
  return $j['url'];
}

function delete_photo_url(string $url): bool {
  if (!defined('DELETE_ENDPOINT')) return false;
  $post = http_build_query(['upload_key'=>UPLOAD_KEY, 'file'=>$url]);
  $ch = curl_init(DELETE_ENDPOINT);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  $resp = curl_exec($ch);
  curl_close($ch);
  $j = json_decode($resp, true);
  return is_array($j) && !empty($j['ok']);
}

function main_menu() {
  return [
    'keyboard' => [
      [['text'=>'➕ Добавить товар'], ['text'=>'📦 Товары']],
      [['text'=>'🧹 Сбросить черновик']]
    ],
    'resize_keyboard' => true
  ];
}

function categories_kb() {
  return [
    'inline_keyboard' => [
      [['text'=>'💎 Ювелирные', 'callback_data'=>'cat:jewelry']],
      [['text'=>'👜 Сумки', 'callback_data'=>'cat:bags'], ['text'=>'⌚ Часы', 'callback_data'=>'cat:watches']],
      [['text'=>'🕶 Аксессуары', 'callback_data'=>'cat:accessories']],
    ]
  ];
}

function jewelry_sub_kb() {
  return [
    'inline_keyboard' => [
      [['text'=>'💍 Кольцо', 'callback_data'=>'sub:rings'], ['text'=>'📿 Браслет', 'callback_data'=>'sub:bracelets']],
      [['text'=>'📎 Подвеска', 'callback_data'=>'sub:pendants'], ['text'=>'⛓ Ожерелье', 'callback_data'=>'sub:necklaces']],
      [['text'=>'✨ Серьги', 'callback_data'=>'sub:earrings']],
    ]
  ];
}

// ------- ТВОЯ СХЕМА КОЛОНОК (ПО БУКВАМ), С УЧЁТОМ "N" ПУСТОЙ -------
const SHEET_SCHEMA = [
  'jewelry' => [
    ['k'=>'id','col'=>'A','title'=>'ID','type'=>'text','req'=>true],
    ['k'=>'active','col'=>'B','title'=>'Активно','type'=>'btn_active','req'=>true],
    ['k'=>'brand','col'=>'C','title'=>'Бренд','type'=>'text','req'=>false],
    ['k'=>'model_ref','col'=>'D','title'=>'Модель / Референс','type'=>'text','req'=>false],
    ['k'=>'title','col'=>'E','title'=>'Заголовок','type'=>'text','req'=>true],
    ['k'=>'desc','col'=>'F','title'=>'Краткое описание','type'=>'text','req'=>false],
    ['k'=>'price_mode','col'=>'G','title'=>'Режим цены','type'=>'btn_price_mode','req'=>true],
    ['k'=>'price','col'=>'H','title'=>'Цена','type'=>'text','req'=>false],
    ['k'=>'currency','col'=>'I','title'=>'Валюта','type'=>'btn_currency','req'=>true],
    ['k'=>'photo1','col'=>'J','title'=>'Фото 1','type'=>'photo','req'=>false],
    ['k'=>'photo2','col'=>'K','title'=>'Фото 2','type'=>'photo','req'=>false],
    ['k'=>'photo3','col'=>'L','title'=>'Фото 3','type'=>'photo','req'=>false],
    ['k'=>'photo4','col'=>'M','title'=>'Фото 4','type'=>'photo','req'=>false],
    // N пропуск
    ['k'=>'type','col'=>'O','title'=>'Тип изделия','type'=>'btn_jewel_type','req'=>true],
    ['k'=>'metal','col'=>'P','title'=>'Металл / проба','type'=>'text','req'=>false],
    ['k'=>'stone','col'=>'Q','title'=>'Камень','type'=>'text','req'=>false],
    ['k'=>'stone_specs','col'=>'R','title'=>'Характеристики камня','type'=>'text','req'=>false],
    ['k'=>'weight','col'=>'S','title'=>'Вес изделия','type'=>'text','req'=>false],
    ['k'=>'size','col'=>'T','title'=>'Размер','type'=>'text','req'=>false],
    ['k'=>'condition','col'=>'U','title'=>'Состояние','type'=>'text','req'=>false],
    ['k'=>'set','col'=>'V','title'=>'Комплект','type'=>'text','req'=>false],
    ['k'=>'comment','col'=>'W','title'=>'Комментарий','type'=>'text','req'=>false],
  ],
  'bags' => [
    ['k'=>'id','col'=>'A','title'=>'ID','type'=>'text','req'=>true],
    ['k'=>'active','col'=>'B','title'=>'Активно','type'=>'btn_active','req'=>true],
    ['k'=>'brand','col'=>'C','title'=>'Бренд','type'=>'text','req'=>false],
    ['k'=>'model_ref','col'=>'D','title'=>'Модель / Референс','type'=>'text','req'=>false],
    ['k'=>'title','col'=>'E','title'=>'Заголовок','type'=>'text','req'=>true],
    ['k'=>'desc','col'=>'F','title'=>'Краткое описание','type'=>'text','req'=>false],
    ['k'=>'price_mode','col'=>'G','title'=>'Режим цены','type'=>'btn_price_mode','req'=>true],
    ['k'=>'price','col'=>'H','title'=>'Цена','type'=>'text','req'=>false],
    ['k'=>'currency','col'=>'I','title'=>'Валюта','type'=>'btn_currency','req'=>true],
    ['k'=>'photo1','col'=>'J','title'=>'Фото 1','type'=>'photo','req'=>false],
    ['k'=>'photo2','col'=>'K','title'=>'Фото 2','type'=>'photo','req'=>false],
    ['k'=>'photo3','col'=>'L','title'=>'Фото 3','type'=>'photo','req'=>false],
    ['k'=>'photo4','col'=>'M','title'=>'Фото 4','type'=>'photo','req'=>false],
    // N пропуск
    ['k'=>'size','col'=>'O','title'=>'Размер (см)','type'=>'text','req'=>false],
    ['k'=>'material','col'=>'P','title'=>'Материал','type'=>'text','req'=>false],
    ['k'=>'color','col'=>'Q','title'=>'Цвет','type'=>'text','req'=>false],
    ['k'=>'hardware','col'=>'R','title'=>'Фурнитура','type'=>'text','req'=>false],
    ['k'=>'condition','col'=>'S','title'=>'Состояние','type'=>'text','req'=>false],
    ['k'=>'set','col'=>'T','title'=>'Комплект','type'=>'text','req'=>false],
    ['k'=>'comment','col'=>'U','title'=>'Комментарий','type'=>'text','req'=>false],
  ],
  'watches' => [
    ['k'=>'id','col'=>'A','title'=>'ID','type'=>'text','req'=>true],
    ['k'=>'active','col'=>'B','title'=>'Активно','type'=>'btn_active','req'=>true],
    ['k'=>'brand','col'=>'C','title'=>'Бренд','type'=>'text','req'=>false],
    ['k'=>'model_ref','col'=>'D','title'=>'Модель / Референс','type'=>'text','req'=>false],
    ['k'=>'title','col'=>'E','title'=>'Заголовок','type'=>'text','req'=>true],
    ['k'=>'desc','col'=>'F','title'=>'Краткое описание','type'=>'text','req'=>false],
    ['k'=>'price_mode','col'=>'G','title'=>'Режим цены','type'=>'btn_price_mode','req'=>true],
    ['k'=>'price','col'=>'H','title'=>'Цена','type'=>'text','req'=>false],
    ['k'=>'currency','col'=>'I','title'=>'Валюта','type'=>'btn_currency','req'=>true],
    ['k'=>'photo1','col'=>'J','title'=>'Фото 1','type'=>'photo','req'=>false],
    ['k'=>'photo2','col'=>'K','title'=>'Фото 2','type'=>'photo','req'=>false],
    ['k'=>'photo3','col'=>'L','title'=>'Фото 3','type'=>'photo','req'=>false],
    ['k'=>'photo4','col'=>'M','title'=>'Фото 4','type'=>'photo','req'=>false],
    // N пропуск
    ['k'=>'case_material','col'=>'O','title'=>'Материал корпуса','type'=>'text','req'=>false],
    ['k'=>'strap_material','col'=>'P','title'=>'Материал ремешка/браслета','type'=>'text','req'=>false],
    ['k'=>'movement','col'=>'Q','title'=>'Механизм','type'=>'text','req'=>false],
    ['k'=>'diameter','col'=>'R','title'=>'Диаметр (мм)','type'=>'text','req'=>false],
    ['k'=>'condition','col'=>'S','title'=>'Состояние','type'=>'text','req'=>false],
    ['k'=>'set','col'=>'T','title'=>'Комплект','type'=>'text','req'=>false],
    ['k'=>'comment','col'=>'U','title'=>'Комментарий','type'=>'text','req'=>false],
  ],
  'accessories' => [
    ['k'=>'id','col'=>'A','title'=>'ID','type'=>'text','req'=>true],
    ['k'=>'active','col'=>'B','title'=>'Активно','type'=>'btn_active','req'=>true],
    ['k'=>'brand','col'=>'C','title'=>'Бренд','type'=>'text','req'=>false],
    ['k'=>'model_ref','col'=>'D','title'=>'Модель / Референс','type'=>'text','req'=>false],
    ['k'=>'title','col'=>'E','title'=>'Заголовок','type'=>'text','req'=>true],
    ['k'=>'desc','col'=>'F','title'=>'Краткое описание','type'=>'text','req'=>false],
    ['k'=>'price_mode','col'=>'G','title'=>'Режим цены','type'=>'btn_price_mode','req'=>true],
    ['k'=>'price','col'=>'H','title'=>'Цена','type'=>'text','req'=>false],
    ['k'=>'currency','col'=>'I','title'=>'Валюта','type'=>'btn_currency','req'=>true],
    ['k'=>'photo1','col'=>'J','title'=>'Фото 1','type'=>'photo','req'=>false],
    ['k'=>'photo2','col'=>'K','title'=>'Фото 2','type'=>'photo','req'=>false],
    ['k'=>'photo3','col'=>'L','title'=>'Фото 3','type'=>'photo','req'=>false],
    ['k'=>'photo4','col'=>'M','title'=>'Фото 4','type'=>'photo','req'=>false],
    // N пропуск
    ['k'=>'material','col'=>'O','title'=>'Материал','type'=>'text','req'=>false],
    ['k'=>'color','col'=>'P','title'=>'Цвет','type'=>'text','req'=>false],
    ['k'=>'lenses','col'=>'Q','title'=>'Линзы','type'=>'text','req'=>false],
    ['k'=>'size','col'=>'R','title'=>'Размер','type'=>'text','req'=>false],
    ['k'=>'condition','col'=>'S','title'=>'Состояние','type'=>'text','req'=>false],
    ['k'=>'set','col'=>'T','title'=>'Комплект','type'=>'text','req'=>false],
    ['k'=>'comment','col'=>'U','title'=>'Комментарий','type'=>'text','req'=>false],
  ],
];

function kb_active() {
  return ['inline_keyboard'=>[
    [['text'=>'✅ Да', 'callback_data'=>'set:active:TRUE'], ['text'=>'⛔ Нет', 'callback_data'=>'set:active:FALSE']],
    [['text'=>'⏭ Пропустить', 'callback_data'=>'skip']]
  ]];
}

function kb_price_mode() {
  return ['inline_keyboard'=>[
    [['text'=>'Фикс', 'callback_data'=>'set:price_mode:Фикс']],
    [['text'=>'Под запрос', 'callback_data'=>'set:price_mode:Под запрос']],
    [['text'=>'Диапазон', 'callback_data'=>'set:price_mode:Диапазон']],
    [['text'=>'⏭ Пропустить', 'callback_data'=>'skip']]
  ]];
}

function kb_currency() {
  return ['inline_keyboard'=>[
    [['text'=>'RUB', 'callback_data'=>'set:currency:RUB'], ['text'=>'USD', 'callback_data'=>'set:currency:USD'], ['text'=>'USDT', 'callback_data'=>'set:currency:USDT']],
    [['text'=>'⏭ Пропустить', 'callback_data'=>'skip']]
  ]];
}

function kb_jewel_type() {
  return ['inline_keyboard'=>[
    [['text'=>'Кольцо', 'callback_data'=>'set:type:Кольцо'], ['text'=>'Браслет', 'callback_data'=>'set:type:Браслет']],
    [['text'=>'Подвеска', 'callback_data'=>'set:type:Подвеска'], ['text'=>'Ожерелье', 'callback_data'=>'set:type:Ожерелье']],
    [['text'=>'Серьги', 'callback_data'=>'set:type:Серьги']],
    [['text'=>'⏭ Пропустить', 'callback_data'=>'skip']]
  ]];
}

function prompt_field($chatId, array $st) {
  $cat = $st['category'];
  $schema = SHEET_SCHEMA[$cat];

  // ищем следующий НЕ photo шаг
  $i = (int)($st['i'] ?? 0);
  while ($i < count($schema) && $schema[$i]['type'] === 'photo') $i++;
  $st['i'] = $i;
  save_state($chatId, $st);

  if ($i >= count($schema)) {
    send($chatId, "Отправь до <b>4 фото</b>. Потом <b>/publish</b>.");
    return;
  }

  $f = $schema[$i];

  if ($f['type'] === 'btn_active')    { send($chatId, "Активно?", kb_active()); return; }
  if ($f['type'] === 'btn_price_mode'){ send($chatId, "Режим цены?", kb_price_mode()); return; }
  if ($f['type'] === 'btn_currency')  { send($chatId, "Валюта?", kb_currency()); return; }
  if ($f['type'] === 'btn_jewel_type'){ send($chatId, "Тип изделия?", kb_jewel_type()); return; }

  send($chatId, "Введи <b>{$f['title']}</b>:", ['inline_keyboard'=>[
    [['text'=>'⏭ Пропустить', 'callback_data'=>'skip']]
  ]]);
}

function set_value_next($chatId, array $st, string $key, string $val) {
  $st['values'][$key] = $val;
  $st['i'] = (int)$st['i'] + 1;
  save_state($chatId, $st);
  prompt_field($chatId, $st);
}

function sheet_for_category(string $cat): string {
  $m = CATEGORY_SHEETS;
  if (!isset($m[$cat])) throw new Exception('Bad category');
  return $m[$cat];
}

function cell_a1(string $col, int $row): string { return $col.$row; }

function bool_from_sheet($v): bool {
  $s = mb_strtolower(trim((string)$v));
  return in_array($s, ['true','1','да','yes'], true);
}

function item_kb($category, $id, $active, $page=0) {
  return ['inline_keyboard'=>[
    [
      ['text'=>'⛔ Выключить', 'callback_data'=>"toggle:{$category}:{$id}:0:{$page}"],
      ['text'=>'✅ Включить',  'callback_data'=>"toggle:{$category}:{$id}:1:{$page}"],
    ],
    [['text'=>'✏️ Редактировать', 'callback_data'=>"edit:{$category}:{$id}:0"]],
    [['text'=>'🗑 Удалить товар', 'callback_data'=>"delask:{$category}:{$id}"]],
    [['text'=>'⬅️ Назад', 'callback_data'=>"list:{$category}:{$page}"]],
  ]];
}

function edit_menu_kb($category, $id, $page=0) {
  $schema = SHEET_SCHEMA[$category];
  $per = 10;
  $start = $page*$per;
  $chunk = array_slice($schema, $start, $per);

  $ikb = [];
  foreach ($chunk as $f) {
    $ikb[] = [[ 'text'=>$f['title'], 'callback_data'=>"editfield:{$category}:{$id}:{$f['k']}:{$page}" ]];
  }

  $nav = [];
  if ($page>0) $nav[] = ['text'=>'⬅️', 'callback_data'=>"edit:{$category}:{$id}:".($page-1)];
  if ($start+$per < count($schema)) $nav[] = ['text'=>'➡️', 'callback_data'=>"edit:{$category}:{$id}:".($page+1)];
  if ($nav) $ikb[] = $nav;

  $ikb[] = [[ 'text'=>'⬅️ К товару', 'callback_data'=>"item:{$category}:{$id}:0" ]];
  return ['inline_keyboard'=>$ikb];
}

$cb = $update['callback_query'] ?? null;
$msg = $update['message'] ?? null;

// ---------------- CALLBACK ----------------
if ($cb) {
  $fromId = $cb['from']['id'] ?? 0;
  $chatId = $cb['message']['chat']['id'] ?? 0;
  $data = $cb['data'] ?? '';
  if (!$chatId) exit;
  if (!is_admin($fromId)) { answer_cb($cb['id'], ui_text('access_denied')); exit; }

  try {
    if (starts_with($data,'cat:')) {
      $cat = explode(':',$data,2)[1] ?? '';
      $st = ['mode'=>'add','category'=>$cat,'sub'=>'','i'=>0,'values'=>[],'photos'=>[]];
      save_state($chatId,$st);
      answer_cb($cb['id']);
      if ($cat==='jewelry') send($chatId,"Выбери папку для фото (это не тип изделия):", jewelry_sub_kb());
      else prompt_field($chatId, $st);
      exit;
    }

    if (starts_with($data,'sub:')) {
      $sub = explode(':',$data,2)[1] ?? '';
      $st = load_state($chatId);
      $st['sub'] = $sub; // папка для фото
      save_state($chatId,$st);
      answer_cb($cb['id']);
      // дальше сразу идём по полям (тип изделия будет отдельным полем кнопкой)
      prompt_field($chatId, $st);
      exit;
    }

    if ($data === 'skip') {
      $st = load_state($chatId);
      answer_cb($cb['id']);
      $schema = SHEET_SCHEMA[$st['category']];
      $i = (int)$st['i'];
      if ($i >= count($schema)) exit;
      $key = $schema[$i]['k'];
      set_value_next($chatId, $st, $key, '');
      exit;
    }

    if (starts_with($data,'set:')) {
      $parts = explode(':',$data,3);
      $key = $parts[1] ?? '';
      $val = $parts[2] ?? '';
      $st = load_state($chatId);
      answer_cb($cb['id']);
      set_value_next($chatId, $st, $key, $val);
      exit;
    }

    if (starts_with($data,'list:')) {
      $parts = explode(':',$data);
      $category = $parts[1] ?? '';
      $page = (int)($parts[2] ?? 0);

      $sheet = sheet_for_category($category);
      $vals = sheets_get_values("{$sheet}!A2:W"); // запасом
      $rows = [];
      foreach ($vals as $r) if (!empty($r[0])) $rows[] = $r;

      $perPage = 10;
      $total = count($rows);
      $start = max(0, $page*$perPage);
      $chunk = array_slice($rows, $start, $perPage);

      if ($total===0) {
        answer_cb($cb['id']);
        send($chatId,"<b>{$sheet}</b>\nПока пусто.");
        exit;
      }

      $ikb = [];
      foreach ($chunk as $r) {
        $id = (string)($r[0] ?? '');
        $active = bool_from_sheet($r[1] ?? '');
        $brand = (string)($r[2] ?? '');
        $title = (string)($r[4] ?? ''); // E
        $price = (string)($r[7] ?? ''); // H
        $cur   = (string)($r[8] ?? ''); // I
        $status = $active ? "ВКЛ" : "ВЫКЛ";
        $ikb[] = [[ 'text'=>"{$id} | {$brand} | {$title} | {$price} {$cur} | {$status}", 'callback_data'=>"item:{$category}:{$id}:{$page}" ]];
      }

      $nav = [];
      if ($page>0) $nav[] = ['text'=>'⬅️','callback_data'=>"list:{$category}:".($page-1)];
      if ($start+$perPage < $total) $nav[] = ['text'=>'➡️','callback_data'=>"list:{$category}:".($page+1)];
      if ($nav) $ikb[] = $nav;

      answer_cb($cb['id']);
      send($chatId,"<b>{$sheet}</b>\nПоказано ".($start+1)."-".min($start+$perPage,$total)." из {$total}", ['inline_keyboard'=>$ikb]);
      exit;
    }

    if (starts_with($data,'item:')) {
      $parts = explode(':',$data);
      $category = $parts[1] ?? '';
      $id = $parts[2] ?? '';
      $page = (int)($parts[3] ?? 0);

      $sheet = sheet_for_category($category);
      $found = sheets_find_by_id($sheet, $id);
      if (!$found) throw new Exception('Не найдено');
      $r = $found['values'];
      $active = bool_from_sheet($r[1] ?? '');

      $text = "<b>{$id}</b>\n"
        ."Статус: <b>".($active?'ВКЛ':'ВЫКЛ')."</b>\n"
        ."Бренд: ".($r[2] ?? '')."\n"
        ."Модель/Реф: ".($r[3] ?? '')."\n"
        ."Заголовок: ".($r[4] ?? '')."\n"
        ."Описание: ".($r[5] ?? '')."\n"
        ."Цена: ".($r[6] ?? '')." | ".($r[7] ?? '')." ".($r[8] ?? '')."\n";

      answer_cb($cb['id']);
      send($chatId, $text, item_kb($category, $id, $active, $page));
      exit;
    }

    if (starts_with($data,'toggle:')) {
      $parts = explode(':',$data);
      $category = $parts[1] ?? '';
      $id = $parts[2] ?? '';
      $to = (int)($parts[3] ?? 0);
      $page = (int)($parts[4] ?? 0);

      $sheet = sheet_for_category($category);
      $found = sheets_find_by_id($sheet, $id);
      if (!$found) throw new Exception('Не найдено');

      $rowNum = $found['row'];
      sheets_update_cell($sheet, "B{$rowNum}", $to ? 'TRUE' : 'FALSE');

      answer_cb($cb['id'], $to?'Включено':'Выключено');
      send($chatId, "Готово: <b>{$id}</b> → ".($to?'ВКЛ':'ВЫКЛ'), ['inline_keyboard'=>[
        [['text'=>'Открыть товар','callback_data'=>"item:{$category}:{$id}:{$page}"]],
        [['text'=>'⬅️ К списку','callback_data'=>"list:{$category}:{$page}"]],
      ]]);
      exit;
    }

    if (starts_with($data,'edit:')) {
      $parts = explode(':',$data);
      $category = $parts[1] ?? '';
      $id = $parts[2] ?? '';
      $page = (int)($parts[3] ?? 0);
      answer_cb($cb['id']);
      send($chatId, "Выбери поле для редактирования:", edit_menu_kb($category,$id,$page));
      exit;
    }

    if (starts_with($data,'editfield:')) {
      $parts = explode(':',$data);
      $category = $parts[1] ?? '';
      $id = $parts[2] ?? '';
      $field = $parts[3] ?? '';
      $page  = (int)($parts[4] ?? 0);

      $st = ['mode'=>'edit','edit_category'=>$category,'edit_id'=>$id,'edit_field'=>$field,'edit_page'=>$page];
      save_state($chatId,$st);
      answer_cb($cb['id']);

      // кнопки для редактирования
      if ($field==='active') { send($chatId,"Активно?", kb_active()); exit; }
      if ($field==='price_mode') { send($chatId,"Режим цены?", kb_price_mode()); exit; }
      if ($field==='currency') { send($chatId,"Валюта?", kb_currency()); exit; }
      if ($field==='type') { send($chatId,"Тип изделия?", kb_jewel_type()); exit; }

      send($chatId, "Введи новое значение (или /cancel):");
      exit;
    }

    if (starts_with($data,'delask:')) {
      $parts = explode(':',$data);
      $category = $parts[1] ?? '';
      $id = $parts[2] ?? '';
      answer_cb($cb['id']);
      send($chatId, "⚠️ Удалить <b>{$id}</b>?\nУдалится строка из таблицы и фото с хостинга.", [
        'inline_keyboard'=>[
          [['text'=>'🗑 Да, удалить','callback_data'=>"deldo:{$category}:{$id}"]],
          [['text'=>'Отмена','callback_data'=>"item:{$category}:{$id}:0"]],
        ]
      ]);
      exit;
    }

    if (starts_with($data,'deldo:')) {
      $parts = explode(':',$data);
      $category = $parts[1] ?? '';
      $id = $parts[2] ?? '';

      $sheet = sheet_for_category($category);
      $found = sheets_find_by_id($sheet, $id);
      if (!$found) throw new Exception('Не найдено');

      $rowNum = $found['row'];
      $r = $found['values'];

      // J-M = indexes 9..12 в массиве (A=0)
      $urls = [];
      foreach ([9,10,11,12] as $ix) {
        $u = trim((string)($r[$ix] ?? ''));
        if ($u !== '') $urls[] = $u;
      }

      $deleted = 0;
      foreach ($urls as $u) if (delete_photo_url($u)) $deleted++;

      sheets_delete_row_shift($sheet, $rowNum);

      answer_cb($cb['id'], 'Удалено');
      send($chatId, "✅ Удалено: <b>{$id}</b>\nФото удалено: {$deleted}/".count($urls), main_menu());
      exit;
    }

  } catch (Throwable $e) {
    answer_cb($cb['id'], 'Ошибка');
    send($chatId, "Ошибка: " . htmlspecialchars($e->getMessage()));
    exit;
  }
}

// ---------------- MESSAGE ----------------
if ($msg) {
  $chatId = $msg['chat']['id'] ?? 0;
  $fromId = $msg['from']['id'] ?? 0;
  if (!$chatId) exit;
  if (!is_admin($fromId)) { send($chatId, ui_text('access_denied')); exit; }

  $text = trim((string)($msg['text'] ?? ''));
  $st = load_state($chatId);

  if ($text === '/start') {
    send($chatId, ui_text('start'), main_menu());
    exit;
  }

  if ($text === '🧹 Сбросить черновик' || $text === '/reset') {
    clear_state($chatId);
    send($chatId, ui_text('draft_cleared'), main_menu());
    exit;
  }

  if ($text === '➕ Добавить товар' || $text === '/add') {
    clear_state($chatId);
    send($chatId, ui_text('choose_category'), categories_kb());
    exit;
  }

  if ($text === '📦 Товары' || $text === '/catalog') {
    send($chatId, ui_text('choose_catalog'), [
      'inline_keyboard'=>[
        [['text'=>'💎 Ювелирные', 'callback_data'=>'list:jewelry:0']],
        [['text'=>'👜 Сумки', 'callback_data'=>'list:bags:0'], ['text'=>'⌚ Часы', 'callback_data'=>'list:watches:0']],
        [['text'=>'🕶 Аксессуары', 'callback_data'=>'list:accessories:0']],
      ]
    ]);
    exit;
  }

  if ($text === '/cancel') {
    clear_state($chatId);
    send($chatId, ui_text('cancelled'), main_menu());
    exit;
  }

  // Фото в режиме add
  if (!empty($msg['photo']) && ($st['mode'] ?? '') === 'add') {
    try {
      $photos = $msg['photo'];
      $best = end($photos);
      $fileId = $best['file_id'];

      $category = $st['category'] ?? '';
      $sub = $st['sub'] ?? '';

      $tmp = download_telegram_file($fileId);
      $url = upload_to_site($tmp, $category, $sub);
      @unlink($tmp);

      $st['photos'] = $st['photos'] ?? [];
      if (count($st['photos']) >= 4) {
        send($chatId, ui_text('photo_limit'));
        exit;
      }
      $st['photos'][] = $url;
      save_state($chatId, $st);

      send($chatId, ui_text('photo_uploaded', ['count'=>count($st['photos'])]));
      exit;

    } catch (Throwable $e) {
      send($chatId, ui_text('upload_error', ['error'=>htmlspecialchars($e->getMessage())]));
      exit;
    }
  }

  // publish
  if ($text === '/publish' && ($st['mode'] ?? '') === 'add') {
    try {
      $category = $st['category'] ?? '';
      $sheet = sheet_for_category($category);
      $schema = SHEET_SCHEMA[$category];

      $values = $st['values'] ?? [];
      $id = trim((string)($values['id'] ?? ''));
      if ($id === '') throw new Exception('ID обязателен');

      if (sheets_find_by_id($sheet, $id)) throw new Exception('Такой ID уже есть');

      // обязательные поля
      foreach ($schema as $f) {
        if (!empty($f['req'])) {
          $k = $f['k'];
          if ($f['type'] === 'photo') continue;
          if (!isset($values[$k]) || trim((string)$values[$k]) === '') {
            throw new Exception("Обязательное поле: {$f['title']}");
          }
        }
      }

      $rowNum = sheets_find_first_empty_row_by_id($sheet);

      // записываем по ячейкам строго в нужные колонки
      foreach ($schema as $f) {
        $k = $f['k'];
        if ($f['type'] === 'photo') continue;
        $val = $values[$k] ?? '';

        // чекбокс: TRUE/FALSE
        if ($k === 'active') {
          if ($val !== 'TRUE' && $val !== 'FALSE' && $val !== '') $val = 'FALSE';
        }

        sheets_update_cell($sheet, cell_a1($f['col'], $rowNum), $val);
      }

      // фото J-M
      $p = $st['photos'] ?? [];
      while (count($p) < 4) $p[] = '';
      sheets_update_cell($sheet, "J{$rowNum}", $p[0]);
      sheets_update_cell($sheet, "K{$rowNum}", $p[1]);
      sheets_update_cell($sheet, "L{$rowNum}", $p[2]);
      sheets_update_cell($sheet, "M{$rowNum}", $p[3]);

      clear_state($chatId);
      send($chatId, ui_text('added_success', ['id'=>$id, 'sheet'=>$sheet, 'row'=>$rowNum]), main_menu());
      exit;

    } catch (Throwable $e) {
      send($chatId, ui_text('publish_error', ['error'=>htmlspecialchars($e->getMessage())]));
      exit;
    }
  }

  // заполнение add
  if (($st['mode'] ?? '') === 'add') {
    // если ювелирка и не выбрана папка фото — просим
    if (($st['category'] ?? '') === 'jewelry' && empty($st['sub'])) {
      send($chatId, ui_text('select_photo_folder'), jewelry_sub_kb());
      exit;
    }

    $cat = $st['category'];
    $schema = SHEET_SCHEMA[$cat];
    $i = (int)($st['i'] ?? 0);

    while ($i < count($schema) && $schema[$i]['type'] === 'photo') $i++;
    if ($i >= count($schema)) {
      send($chatId, ui_text('send_photos_then_publish'));
      exit;
    }

    $key = $schema[$i]['k'];

    // если мы в edit-режиме — не сюда
    if (($st['mode'] ?? '') !== 'add') { send($chatId, ui_text('not_mode')); exit; }

    set_value_next($chatId, $st, $key, $text);
    exit;
  }

  // edit: если пришёл текст, обновляем выбранное поле
  if (($st['mode'] ?? '') === 'edit') {
    try {
      $category = $st['edit_category'] ?? '';
      $id = $st['edit_id'] ?? '';
      $field = $st['edit_field'] ?? '';
      $page  = (int)($st['edit_page'] ?? 0);

      $sheet = sheet_for_category($category);
      $found = sheets_find_by_id($sheet, $id);
      if (!$found) throw new Exception('Не найдено');

      $rowNum = $found['row'];
      $schema = SHEET_SCHEMA[$category];
      $col = null;
      foreach ($schema as $f) if ($f['k'] === $field) $col = $f['col'];

      if (!$col) throw new Exception('Bad field');

      sheets_update_cell($sheet, cell_a1($col, $rowNum), $text);

      clear_state($chatId);
      send($chatId, ui_text('saved'), ['inline_keyboard'=>[
        [['text'=>'⬅️ Назад к полям','callback_data'=>"edit:{$category}:{$id}:{$page}"]],
        [['text'=>'Открыть товар','callback_data'=>"item:{$category}:{$id}:0"]],
      ]]);
      exit;

    } catch (Throwable $e) {
      send($chatId, ui_text('edit_error', ['error'=>htmlspecialchars($e->getMessage())]));
      exit;
    }
  }

  send($chatId, ui_text('commands'), main_menu());
  exit;
}
