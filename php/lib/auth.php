<?php
// Аутентификация по паролю (сессия). Хэш пароля хранится в DATA_DIR/auth.json —
// не в коде и не в репозитории. Пароль задаётся при первом входе.

if (!defined('DATA_DIR')) {
  $envDir = getenv('DATA_DIR');
  define('DATA_DIR', $envDir ? $envDir : (__DIR__ . '/../data'));
}
define('AUTH_FILE', DATA_DIR . '/auth.json');

function authStartSession() {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  // Храним сессии в нашей (заведомо доступной для записи) папке данных
  $sessDir = DATA_DIR . '/sessions';
  if (!is_dir($sessDir)) @mkdir($sessDir, 0775, true);
  if (is_dir($sessDir) && is_writable($sessDir)) session_save_path($sessDir);
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'lifetime'  => 60 * 60 * 24 * 30, // держим вход 30 дней
    'path'      => '/',
    'httponly'  => true,
    'samesite'  => 'Lax',
    'secure'    => $secure,
  ]);
  session_start();
}

function authConfigured() {
  return is_file(AUTH_FILE);
}

function authSetPassword($pw) {
  if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
  file_put_contents(AUTH_FILE, json_encode(['hash' => password_hash($pw, PASSWORD_DEFAULT)]), LOCK_EX);
}

function authVerify($pw) {
  if (!authConfigured()) return false;
  $data = json_decode(file_get_contents(AUTH_FILE), true);
  return isset($data['hash']) && password_verify($pw, $data['hash']);
}

function authIsLoggedIn() {
  authStartSession();
  return !empty($_SESSION['authed']);
}

function authLogin() {
  authStartSession();
  $_SESSION['authed'] = true;
}

function authLogout() {
  authStartSession();
  $_SESSION = [];
  session_destroy();
}
