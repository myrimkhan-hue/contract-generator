<?php
// Утилиты: экранирование, даты, номера, сумма прописью, заполнение DOCX.
// Портировано с Node-версии (server.js) без изменения логики.

function escapeXml($str) {
  if ($str === null) return '';
  $str = (string)$str;
  $str = str_replace('&', '&amp;', $str);
  $str = str_replace('<', '&lt;', $str);
  $str = str_replace('>', '&gt;', $str);
  $str = str_replace('"', '&quot;', $str);
  return $str;
}

$GLOBALS['RU_MONTHS'] = ['января','февраля','марта','апреля','мая','июня','июля',
  'августа','сентября','октября','ноября','декабря'];

// "20 мая 2026 г." из отметки времени (int) или DateTime
function formatDateRu($ts = null) {
  if ($ts === null) $ts = time();
  $d = (int)date('j', $ts);
  $m = (int)date('n', $ts) - 1;
  $y = (int)date('Y', $ts);
  return $d . ' ' . $GLOBALS['RU_MONTHS'][$m] . ' ' . $y . ' г.';
}

// Парсинг "YYYY-MM-DD" в отметку времени (локальная, без смещения TZ)
function parseDateISO($str) {
  $parts = explode('-', $str);
  if (count($parts) < 3) return time();
  return mktime(12, 0, 0, (int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

// Номер договора: <ПРЕФИКС>-<EX|CL>-DDMM/YYYY
function generateContractNumber($prefix, $ourRole) {
  $dd = date('d'); $mm = date('m'); $yyyy = date('Y');
  $roleCode = $ourRole === 'customer' ? 'EX' : 'CL';
  return "$prefix-$roleCode-$dd$mm/$yyyy";
}

// Номер заявки: <ПРЕФИКС>-Z-DDMM/YYYY
function generateZayavkaNumber($prefix) {
  $dd = date('d'); $mm = date('m'); $yyyy = date('Y');
  return "$prefix-Z-$dd$mm/$yyyy";
}

// Сумма цифрами с разделителями: 1000000 -> "1 000 000"
function formatAmount($n) {
  return number_format((int)$n, 0, '', ' ');
}

// Сумма прописью (рубли/тенге, целое). Порт amountToWords из server.js.
function amountToWords($n) {
  $n = (int)$n;
  if ($n === 0) return 'ноль';
  $onesM = ['','один','два','три','четыре','пять','шесть','семь','восемь','девять',
    'десять','одиннадцать','двенадцать','тринадцать','четырнадцать','пятнадцать',
    'шестнадцать','семнадцать','восемнадцать','девятнадцать'];
  $onesF = ['','одна','две','три','четыре','пять','шесть','семь','восемь','девять',
    'десять','одиннадцать','двенадцать','тринадцать','четырнадцать','пятнадцать',
    'шестнадцать','семнадцать','восемнадцать','девятнадцать'];
  $tens = ['','','двадцать','тридцать','сорок','пятьдесят','шестьдесят','семьдесят','восемьдесят','девяносто'];
  $hundreds = ['','сто','двести','триста','четыреста','пятьсот','шестьсот','семьсот','восемьсот','девятьсот'];

  $plural = function($num, $one, $two, $five) {
    $m = $num % 100;
    if ($m >= 11 && $m <= 19) return $five;
    $m10 = $num % 10;
    if ($m10 === 1) return $one;
    if ($m10 >= 2 && $m10 <= 4) return $two;
    return $five;
  };
  $chunk = function($num, $fem = false) use ($onesM, $onesF, $tens, $hundreds) {
    if ($num === 0) return '';
    $ones = $fem ? $onesF : $onesM;
    $s = '';
    $h = intdiv($num, 100);
    $r = $num % 100;
    if ($h) $s .= $hundreds[$h] . ' ';
    if ($r < 20 && $r > 0) { $s .= $ones[$r] . ' '; }
    elseif ($r >= 20) {
      $s .= $tens[intdiv($r, 10)] . ' ';
      if ($r % 10) $s .= $ones[$r % 10] . ' ';
    }
    return $s;
  };

  $mil = intdiv($n, 1000000);
  $tho = intdiv($n % 1000000, 1000);
  $rem = $n % 1000;
  $result = '';
  if ($mil) $result .= $chunk($mil) . $plural($mil, 'миллион', 'миллиона', 'миллионов') . ' ';
  if ($tho) $result .= $chunk($tho, true) . $plural($tho, 'тысяча', 'тысячи', 'тысяч') . ' ';
  if ($rem) $result .= $chunk($rem);
  return trim($result);
}

// Заполнение DOCX-шаблона: подстановка {МЕТКА} -> значение. Возвращает бинарные данные.
function fillDocx($templatePath, $values) {
  $tmp = tempnam(sys_get_temp_dir(), 'docx');
  if (!copy($templatePath, $tmp)) {
    throw new Exception('Не удалось скопировать шаблон');
  }
  $zip = new ZipArchive();
  if ($zip->open($tmp) !== true) {
    throw new Exception('Не удалось открыть шаблон DOCX');
  }
  $xml = $zip->getFromName('word/document.xml');
  foreach ($values as $key => $val) {
    $xml = str_replace('{' . $key . '}', escapeXml($val), $xml);
  }
  $zip->deleteName('word/document.xml');
  $zip->addFromString('word/document.xml', $xml);
  $zip->close();
  $data = file_get_contents($tmp);
  @unlink($tmp);
  return $data;
}

// Безопасное имя файла из названия контрагента
function safeName($name, $fallback = 'other') {
  $s = $name ? $name : $fallback;
  $s = preg_replace('/[^\p{L}\p{N}\- ]/u', '', $s);
  $s = preg_replace('/\s+/u', '_', $s);
  return mb_substr($s, 0, 40);
}

// Отправка сгенерированного .docx в браузер
function sendDocx($buffer, $filename, $numberHeader = null, $numberValue = null) {
  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
  if ($numberHeader && $numberValue !== null) header("$numberHeader: $numberValue");
  echo $buffer;
}

// JSON-ответ
function jsonResponse($data, $status = 200) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
