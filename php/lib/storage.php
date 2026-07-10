<?php
// Хранилище на диске: история документов + справочник контрагентов.
// DATA_DIR можно переопределить переменной окружения.

if (!defined('DATA_DIR')) {
  $envDir = getenv('DATA_DIR');
  define('DATA_DIR', $envDir ? $envDir : (__DIR__ . '/../data'));
}
define('FILES_DIR', DATA_DIR . '/files');
define('HISTORY_FILE', DATA_DIR . '/history.json');
define('CONTACTS_FILE', DATA_DIR . '/contacts.json');
define('COUNTERS_FILE', DATA_DIR . '/counters.json');
define('MAX_HISTORY', 50);

if (!is_dir(FILES_DIR)) @mkdir(FILES_DIR, 0775, true);

// Атомарный счётчик по ключу (напр. по базовому номеру документа за день).
// Возвращает порядковый номер: 1 для первого, 2 для второго и т.д.
function nextSequence($key) {
  $fp = @fopen(COUNTERS_FILE, 'c+');
  if (!$fp) return 1;
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
  $n = (isset($data[$key]) ? (int)$data[$key] : 0) + 1;
  $data[$key] = $n;
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return $n;
}

// Уникальный номер документа: базовый, либо с суффиксом /N для 2-го и далее за день
function uniqueDocNumber($base) {
  $n = nextSequence($base);
  return $n <= 1 ? $base : $base . '/' . $n;
}

function loadJson($file, $fallback) {
  if (!is_file($file)) return $fallback;
  $data = json_decode(file_get_contents($file), true);
  return $data === null ? $fallback : $data;
}

function saveJson($file, $data) {
  file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function loadHistory()  { return loadJson(HISTORY_FILE, []); }
function loadContacts() { return loadJson(CONTACTS_FILE, []); }

// === Реестр документов (метаданные + исходные данные для пересоздания файла) ===
define('DOCS_FILE', DATA_DIR . '/docs.json');
define('MAX_DOCS', 2000); // крошечный JSON; файлы .docx не хранятся — пересобираются по запросу
function loadDocs() { return loadJson(DOCS_FILE, []); }
function addDoc($entry) {
  $docs = loadDocs();
  array_unshift($docs, $entry);
  if (count($docs) > MAX_DOCS) $docs = array_slice($docs, 0, MAX_DOCS);
  saveJson(DOCS_FILE, $docs);
}
function findDocByFilename($filename) {
  foreach (loadDocs() as $d) if (($d['filename'] ?? '') === $filename) return $d;
  return null;
}

// === База договоров ===
define('DEALS_FILE', DATA_DIR . '/deals.json');
function loadDeals() { return loadJson(DEALS_FILE, []); }
function saveDeals($deals) { saveJson(DEALS_FILE, $deals); }

// Добавить/обновить договор в базе. Дедуп по номеру (один номер — одна запись).
function upsertDeal($deal) {
  $deals = loadDeals();
  $idx = -1;
  foreach ($deals as $i => $d) {
    if ((!empty($deal['id']) && $d['id'] === $deal['id']) || $d['number'] === $deal['number']) { $idx = $i; break; }
  }
  if (empty($deal['id'])) {
    $deal['id'] = $idx >= 0 ? $deals[$idx]['id'] : (string) round(microtime(true) * 1000);
  }
  if ($idx >= 0) $deals[$idx] = $deal;
  else array_unshift($deals, $deal);
  saveDeals($deals);
  return $deal;
}

function deleteDeal($id) {
  $deals = loadDeals();
  $filtered = array_values(array_filter($deals, function ($d) use ($id) { return $d['id'] !== $id; }));
  if (count($filtered) !== count($deals)) saveDeals($filtered);
}

// Сохранить документ на диск и добавить запись в начало истории
function storeDocument($entry, $buffer) {
  @file_put_contents(FILES_DIR . '/' . $entry['filename'], $buffer);
  $history = loadHistory();
  array_unshift($history, $entry);
  while (count($history) > MAX_HISTORY) {
    $old = array_pop($history);
    @unlink(FILES_DIR . '/' . $old['filename']);
  }
  saveJson(HISTORY_FILE, $history);
}
