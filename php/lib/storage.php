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
define('MAX_HISTORY', 50);

if (!is_dir(FILES_DIR)) @mkdir(FILES_DIR, 0775, true);

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
