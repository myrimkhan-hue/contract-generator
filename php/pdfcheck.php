<?php
// Диагностика: может ли этот хостинг конвертировать DOCX→PDF через LibreOffice.
// Откройте в браузере, посмотрите verdict, потом файл можно удалить.

header('Content-Type: application/json; charset=utf-8');

function fnAvailable($name) {
  if (!function_exists($name)) return false;
  $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
  return !in_array($name, $disabled, true);
}

$canShell = fnAvailable('shell_exec') || fnAvailable('exec') || fnAvailable('proc_open');

$soffice = null;
if (fnAvailable('shell_exec')) {
  foreach (['soffice', 'libreoffice'] as $bin) {
    $p = trim((string)@shell_exec('command -v ' . $bin . ' 2>/dev/null'));
    if ($p !== '') { $soffice = $p; break; }
  }
}
if ($soffice === null) {
  foreach (['/usr/bin/soffice', '/usr/bin/libreoffice', '/opt/libreoffice/program/soffice',
            '/usr/local/bin/soffice'] as $p) {
    if (@is_file($p)) { $soffice = $p; break; }
  }
}

$possible = $canShell && $soffice !== null;

echo json_encode([
  'php_version'       => PHP_VERSION,
  'zip_ext'           => extension_loaded('zip'),
  'shell_exec'        => fnAvailable('shell_exec'),
  'exec'              => fnAvailable('exec'),
  'proc_open'         => fnAvailable('proc_open'),
  'libreoffice_found' => $soffice !== null,
  'libreoffice_path'  => $soffice,
  'verdict'           => $possible
      ? 'ОК: PDF через LibreOffice возможен на этом хостинге'
      : 'Недоступно: нет LibreOffice и/или запрещён запуск команд — серверная конвертация в PDF не выйдет',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
