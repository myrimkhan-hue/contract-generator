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

// === Защита от перебора пароля ===
// Считаем неудачные попытки по IP (файл в DATA_DIR). После 5 неудач за 15 минут —
// блокировка на 15 минут; каждая неудача дополнительно тормозится на 1–3 секунды.
define('LOGIN_ATTEMPTS_FILE', DATA_DIR . '/login_attempts.json');
define('LOGIN_MAX_FAILS', 5);
define('LOGIN_WINDOW_SEC', 900);

function loginClientIp() {
  return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
}

function loginAttemptsLoad() {
  $data = is_file(LOGIN_ATTEMPTS_FILE) ? json_decode(file_get_contents(LOGIN_ATTEMPTS_FILE), true) : [];
  return is_array($data) ? $data : [];
}

// Осталось ли право на попытку. Возвращает 0 (можно) или секунды до разблокировки.
function loginBlockedFor() {
  $a = loginAttemptsLoad();
  $ip = loginClientIp();
  if (!isset($a[$ip])) return 0;
  $rec = $a[$ip];
  if (time() - $rec['first'] > LOGIN_WINDOW_SEC) return 0; // окно истекло
  if ($rec['count'] < LOGIN_MAX_FAILS) return 0;
  return LOGIN_WINDOW_SEC - (time() - $rec['first']);
}

function loginRegisterFail() {
  $a = loginAttemptsLoad();
  $ip = loginClientIp();
  $now = time();
  // Чистим устаревшие записи, чтобы файл не рос
  foreach ($a as $k => $rec) {
    if ($now - $rec['first'] > LOGIN_WINDOW_SEC) unset($a[$k]);
  }
  if (!isset($a[$ip])) $a[$ip] = ['count' => 0, 'first' => $now];
  $a[$ip]['count']++;
  file_put_contents(LOGIN_ATTEMPTS_FILE, json_encode($a), LOCK_EX);
  // Нарастающее замедление ответа
  sleep(min(3, $a[$ip]['count']));
}

function loginRegisterSuccess() {
  $a = loginAttemptsLoad();
  $ip = loginClientIp();
  if (isset($a[$ip])) {
    unset($a[$ip]);
    file_put_contents(LOGIN_ATTEMPTS_FILE, json_encode($a), LOCK_EX);
  }
}
