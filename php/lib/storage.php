<?php
// Хранилище на диске: история документов + справочник контрагентов.
// DATA_DIR можно переопределить переменной окружения.

if (!defined('DATA_DIR')) {
  $envDir = getenv('DATA_DIR');
  define('DATA_DIR', $envDir ? $envDir : (__DIR__ . '/../data'));
}
define('FILES_DIR', DATA_DIR . '/files');          // легаси-кэш файлов (только чтение при скачивании)
define('HISTORY_FILE', DATA_DIR . '/history.json'); // легаси; пишется только при восстановлении старых копий
define('CONTACTS_FILE', DATA_DIR . '/contacts.json');
define('COUNTERS_FILE', DATA_DIR . '/counters.json');

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

// Сквозной номер китайской заявки. Если номер введён вручную — принимаем его
// и подтягиваем счётчик (следующее «авто» будет больше). Пусто — счётчик+1.
function chinaNumber($requested = 0) {
  $fp = @fopen(COUNTERS_FILE, 'c+');
  if (!$fp) return $requested > 0 ? $requested : 1;
  flock($fp, LOCK_EX);
  $data = json_decode(stream_get_contents($fp), true);
  if (!is_array($data)) $data = [];
  $cur = isset($data['china']) ? (int)$data['china'] : 0;
  $n = $requested > 0 ? $requested : $cur + 1;
  $data['china'] = max($cur, $n);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return $n;
}

function chinaNextNumber() {
  $data = loadJson(COUNTERS_FILE, []);
  return (isset($data['china']) ? (int)$data['china'] : 0) + 1;
}

function loadJson($file, $fallback) {
  if (!is_file($file)) return $fallback;
  $data = json_decode(file_get_contents($file), true);
  return $data === null ? $fallback : $data;
}

function saveJson($file, $data) {
  file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

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
// Обновить поля записи документа (напр. отметку «в реестре»)
function updateDocByFilename($filename, $patch) {
  $docs = loadDocs();
  foreach ($docs as $i => $d) {
    if (($d['filename'] ?? '') === $filename) {
      $docs[$i] = array_merge($d, $patch);
      saveJson(DOCS_FILE, $docs);
      return $docs[$i];
    }
  }
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

// === Автоматические резервные копии ===
// Раз в день (при первом запросе к API) полная копия данных сохраняется
// в DATA_DIR/backups; хранятся последние MAX_BACKUPS копий.
define('BACKUPS_DIR', DATA_DIR . '/backups');
define('MAX_BACKUPS', 30);

function buildBackupData() {
  return [
    'version'  => 2,
    'date'     => gmdate('Y-m-d\TH:i:s\Z'),
    'deals'    => loadDeals(),
    'contacts' => loadContacts(),
    'docs'     => loadDocs(),
    'counters' => loadJson(COUNTERS_FILE, []),
  ];
}

// Применить данные копии (общая часть ручного и автоматического восстановления)
function applyBackupData($b) {
  if (isset($b['deals'])    && is_array($b['deals']))    saveDeals($b['deals']);
  if (isset($b['contacts']) && is_array($b['contacts'])) saveJson(CONTACTS_FILE, $b['contacts']);
  if (isset($b['docs'])     && is_array($b['docs']))     saveJson(DOCS_FILE, $b['docs']);
  if (isset($b['history'])  && is_array($b['history']))  saveJson(HISTORY_FILE, $b['history']); // старые копии
  if (isset($b['counters']) && is_array($b['counters'])) saveJson(COUNTERS_FILE, $b['counters']);
}

function maybeAutoBackup() {
  $file = BACKUPS_DIR . '/backup-' . date('Y-m-d') . '.json';
  if (is_file($file)) return; // сегодняшняя копия уже есть
  if (!is_dir(BACKUPS_DIR)) @mkdir(BACKUPS_DIR, 0775, true);
  saveJson($file, buildBackupData());
  $all = glob(BACKUPS_DIR . '/backup-*.json');
  if (is_array($all)) {
    sort($all);
    while (count($all) > MAX_BACKUPS) @unlink(array_shift($all));
  }
}

function listAutoBackups() {
  $all = glob(BACKUPS_DIR . '/backup-*.json');
  if (!is_array($all)) $all = [];
  rsort($all); // свежие сверху
  $out = [];
  foreach ($all as $f) {
    $out[] = ['name' => basename($f), 'date' => substr(basename($f), 7, 10), 'size' => filesize($f)];
  }
  return $out;
}

// === Синхронизация: справочник контрагентов ↔ база договоров ===
// Ключ соответствия — БИН; если его нет, название (без учёта регистра).
// Непустые новые значения перекрывают старые; пустые ничего не затирают.
// Историю документов это не трогает: пересборка идёт из данных на момент создания.

function cpFields() {
  return ['type','name','bin','position','signerFull','signerShort',
    'basis','address','account','bank','bik','phone','email'];
}

// Реквизиты контрагента (из договора/заявки) → обновить или создать контакт
function syncContactFromCounterparty($cp) {
  $cp = normalizeCounterparty($cp);
  if ($cp['name'] === '') return;
  $contacts = loadContacts();
  $idx = -1;
  if ($cp['bin'] !== '') {
    foreach ($contacts as $i => $c) {
      if (isset($c['bin']) && $c['bin'] !== '' && $c['bin'] === $cp['bin']) { $idx = $i; break; }
    }
  }
  if ($idx < 0) {
    foreach ($contacts as $i => $c) {
      if (mb_strtolower(isset($c['name']) ? $c['name'] : '') === mb_strtolower($cp['name'])) { $idx = $i; break; }
    }
  }
  if ($idx >= 0) {
    foreach (cpFields() as $f) if ($cp[$f] !== '') $contacts[$idx][$f] = $cp[$f];
    $contacts[$idx]['label'] = $contacts[$idx]['name'];
  } else {
    $contact = ['id' => (string) round(microtime(true) * 1000)];
    foreach (cpFields() as $f) $contact[$f] = $cp[$f];
    $contact['label'] = $contact['name'];
    array_unshift($contacts, $contact);
  }
  saveJson(CONTACTS_FILE, $contacts);
}

// Контакт из справочника → обновить реквизиты этого контрагента во всех договорах базы
function syncDealsFromContact($contact) {
  $name = isset($contact['name']) ? $contact['name'] : '';
  if ($name === '') return;
  $deals = loadDeals();
  $changed = false;
  foreach ($deals as $i => $d) {
    $cp = isset($d['counterparty']) && is_array($d['counterparty']) ? $d['counterparty'] : [];
    $byBin = !empty($contact['bin']) && isset($cp['bin']) && $cp['bin'] === $contact['bin'];
    $byName = mb_strtolower(isset($cp['name']) ? $cp['name'] : '') === mb_strtolower($name);
    if (!$byBin && !$byName) continue;
    foreach (cpFields() as $f) {
      if (isset($contact[$f]) && $contact[$f] !== '') $cp[$f] = $contact[$f];
    }
    $deals[$i]['counterparty'] = normalizeCounterparty($cp);
    $changed = true;
  }
  if ($changed) saveDeals($deals);
}

function deleteDeal($id) {
  $deals = loadDeals();
  $filtered = array_values(array_filter($deals, function ($d) use ($id) { return $d['id'] !== $id; }));
  if (count($filtered) !== count($deals)) saveDeals($filtered);
}
