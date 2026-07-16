<?php
// Фронт-контроллер API. Все /api/* маршруты приходят сюда (см. .htaccess).
// Порт эндпоинтов из Node-версии (server.js).

// Дата/номера документов всегда по времени Алматы, независимо от TZ сервера
date_default_timezone_set('Asia/Almaty');

require __DIR__ . '/../lib/companies.php';
require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/storage.php';
require __DIR__ . '/../lib/docgen.php';
require __DIR__ . '/../lib/auth.php';

authStartSession();

$TEMPLATE       = __DIR__ . '/../templates/template.docx';
$ZAYAVKA_TEMPLATE = __DIR__ . '/../templates/template_zayavka.docx';

// --- Разбор маршрута ---
// Приоритет — параметр ?r= (работает без rewrite/.htaccess, на любом сервере).
// Если его нет — разбираем «красивый» путь после /api (когда rewrite включён).
$method = $_SERVER['REQUEST_METHOD'];
if (isset($_GET['r']) && $_GET['r'] !== '') {
  $r = $_GET['r'];
  if ($r === 'history-file') {
    $segs = ['history', isset($_GET['name']) ? $_GET['name'] : ''];
  } elseif (($r === 'contacts' || $r === 'deals') && isset($_GET['id']) && $_GET['id'] !== '') {
    $segs = [$r, $_GET['id']];
  } else {
    $segs = [$r];
  }
} else {
  $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  $after = preg_replace('#^.*?/api#', '', $uri);   // всё после /api
  $path = trim($after, '/');
  $segs = $path === '' ? [] : explode('/', $path);
}

function body() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
// pick(), arr(), normalizeCounterparty() определены в helpers.php

try {
  // === Аутентификация (публичные маршруты) ===
  if ($segs === ['auth-status'] && $method === 'GET') {
    jsonResponse(['configured' => authConfigured(), 'authed' => authIsLoggedIn()]);
    exit;
  }
  if ($segs === ['setup'] && $method === 'POST') {
    if (authConfigured()) { jsonResponse(['error' => 'Пароль уже установлен.'], 400); exit; }
    $b = body();
    $pw = isset($b['password']) ? $b['password'] : '';
    $pw2 = isset($b['password2']) ? $b['password2'] : '';
    if (strlen($pw) < 6) { jsonResponse(['error' => 'Пароль слишком короткий (минимум 6 символов).'], 400); exit; }
    if ($pw !== $pw2) { jsonResponse(['error' => 'Пароли не совпадают.'], 400); exit; }
    authSetPassword($pw);
    authLogin();
    jsonResponse(['ok' => true]);
    exit;
  }
  if ($segs === ['login'] && $method === 'POST') {
    $wait = loginBlockedFor();
    if ($wait > 0) {
      jsonResponse(['error' => 'Слишком много неудачных попыток. Попробуйте через ' . ceil($wait / 60) . ' мин.'], 429);
      exit;
    }
    $b = body();
    if (authVerify(isset($b['password']) ? $b['password'] : '')) {
      loginRegisterSuccess();
      authLogin();
      jsonResponse(['ok' => true]);
      exit;
    }
    loginRegisterFail();
    jsonResponse(['error' => 'Неверный пароль.'], 401);
    exit;
  }
  if ($segs === ['logout'] && $method === 'POST') {
    authLogout();
    jsonResponse(['ok' => true]);
    exit;
  }

  // Всё остальное — только после входа
  if (!authIsLoggedIn()) {
    jsonResponse(['error' => 'Требуется вход.'], 401);
    exit;
  }

  // Ежедневная автоматическая резервная копия (не чаще раза в день, ~мгновенно)
  maybeAutoBackup();

  // === GET /api/companies ===
  if ($method === 'GET' && $segs === ['companies']) {
    $list = [];
    foreach (our_companies() as $id => $c) {
      $list[] = ['id' => $id, 'short' => $c['short'], 'type' => $c['type'], 'name' => $c['name']];
    }
    jsonResponse($list);
    exit;
  }

  // === GET /api/history (последние документы; ?q= — поиск по ВСЕЙ истории) ===
  if ($method === 'GET' && $segs === ['history']) {
    $docs = loadDocs();
    $q = isset($_GET['q']) ? trim(mb_strtolower($_GET['q'])) : '';
    if ($q !== '') {
      $docs = array_values(array_filter($docs, function ($d) use ($q) {
        foreach (['number', 'otherName', 'route', 'ourCompany'] as $f) {
          if (isset($d[$f]) && mb_stripos($d[$f], $q) !== false) return true;
        }
        return false;
      }));
    }
    $recent = array_slice($docs, 0, 50);
    $out = array_map(function ($d) { unset($d['input']); return $d; }, $recent);
    jsonResponse($out);
    exit;
  }

  // === GET /api/history/{filename} — пересобрать документ и отдать ===
  if ($method === 'GET' && count($segs) === 2 && $segs[0] === 'history') {
    $safe = basename(rawurldecode($segs[1]));
    // Легаси-кэш файлов (если остался)
    $file = FILES_DIR . '/' . $safe;
    if (is_file($file)) { sendDocx(file_get_contents($file), $safe); exit; }
    // Иначе пересобираем из сохранённых данных
    $doc = findDocByFilename($safe);
    if (!$doc || empty($doc['input'])) { jsonResponse(['error' => 'Документ не найден'], 404); exit; }
    $type = isset($doc['type']) ? $doc['type'] : 'contract';
    if ($type === 'zayavka')    $res = buildZayavkaDoc($doc['input'], $doc['number'], $doc['dateRu']);
    elseif ($type === 'china')  $res = buildChinaDoc($doc['input'], $doc['number']);
    else                        $res = buildContractDoc($doc['input'], $doc['number'], $doc['dateRu']);
    sendDocx($res['buffer'], $safe);
    exit;
  }

  // === Аннулирование документа (обратимо; запись и файл сохраняются) ===
  if ($segs === ['annul-mark'] && $method === 'POST') {
    $b = body();
    $filename = basename(pick($b, 'filename', ''));
    if ($filename === '') { jsonResponse(['error' => 'Не указан файл.'], 400); exit; }
    $state = !empty($b['state']);
    $doc = updateDocByFilename($filename, [
      'annulled'   => $state,
      'annulledAt' => $state ? gmdate('Y-m-d\TH:i:s\Z') : null,
    ]);
    if (!$doc) { jsonResponse(['error' => 'Документ не найден'], 404); exit; }
    jsonResponse(['ok' => true, 'annulled' => $state]);
    exit;
  }

  // === Отметка «отправлено в реестр перевозок» ===
  if ($segs === ['registry-mark'] && $method === 'POST') {
    $b = body();
    $filename = basename(pick($b, 'filename', ''));
    if ($filename === '') { jsonResponse(['error' => 'Не указан файл.'], 400); exit; }
    $state = !empty($b['state']);
    $doc = updateDocByFilename($filename, [
      'registry'   => $state,
      'registryAt' => $state ? gmdate('Y-m-d\TH:i:s\Z') : null,
    ]);
    if (!$doc) { jsonResponse(['error' => 'Документ не найден'], 404); exit; }
    jsonResponse(['ok' => true, 'registry' => $state]);
    exit;
  }

  // === Исходные данные документа (предзаполнение Google Формы, дублирование) ===
  if ($segs === ['doc-input'] && $method === 'GET') {
    $filename = basename(rawurldecode(isset($_GET['name']) ? $_GET['name'] : ''));
    $doc = findDocByFilename($filename);
    if (!$doc || empty($doc['input'])) {
      jsonResponse(['error' => 'Данные документа не найдены'], 404); exit;
    }
    jsonResponse(['type' => isset($doc['type']) ? $doc['type'] : 'contract', 'input' => $doc['input']]);
    exit;
  }

  // === Справочник контрагентов ===
  $CONTACT_FIELDS = ['type','name','bin','position','signerFull','signerShort',
    'basis','address','account','bank','bik','phone','email'];

  if ($segs === ['contacts'] && $method === 'GET') {
    jsonResponse(loadContacts());
    exit;
  }
  if ($segs === ['contacts'] && $method === 'POST') {
    $b = body();
    if (empty($b['name'])) { jsonResponse(['error' => 'Не указано название контрагента.'], 400); exit; }
    $contact = ['id' => !empty($b['id']) ? (string)$b['id'] : (string)round(microtime(true) * 1000)];
    foreach ($CONTACT_FIELDS as $f) $contact[$f] = isset($b[$f]) ? $b[$f] : '';
    $contact['label'] = $contact['name'];

    $contacts = loadContacts();
    $found = false;
    foreach ($contacts as $i => $c) {
      if ($c['id'] === $contact['id']) { $contacts[$i] = $contact; $found = true; break; }
    }
    if (!$found) array_unshift($contacts, $contact);
    saveJson(CONTACTS_FILE, $contacts);
    // Реквизиты контакта → во все договоры этого контрагента в базе
    syncDealsFromContact($contact);
    jsonResponse($contact);
    exit;
  }
  if (count($segs) === 2 && $segs[0] === 'contacts' && $method === 'DELETE') {
    $id = rawurldecode($segs[1]);
    $contacts = loadContacts();
    $filtered = array_values(array_filter($contacts, function($c) use ($id) { return $c['id'] !== $id; }));
    if (count($filtered) !== count($contacts)) saveJson(CONTACTS_FILE, $filtered);
    jsonResponse(['ok' => true]);
    exit;
  }

  // === База договоров ===
  if ($segs === ['deals'] && $method === 'GET') {
    jsonResponse(loadDeals());
    exit;
  }
  if ($segs === ['deals'] && $method === 'POST') {
    $b = body();
    $companies = our_companies();
    if (empty($b['number'])) { jsonResponse(['error' => 'Не указан номер договора.'], 400); exit; }
    $cp = isset($b['counterparty']) && is_array($b['counterparty']) ? $b['counterparty'] : [];
    if (empty($cp['name'])) { jsonResponse(['error' => 'Не указано название контрагента.'], 400); exit; }
    $companyId = pick($b, 'ourCompanyId', '');
    $deal = upsertDeal([
      'id'           => !empty($b['id']) ? (string)$b['id'] : '',
      'number'       => trim($b['number']),
      'dateISO'      => pick($b, 'dateISO', ''),
      'dateRu'       => pick($b, 'dateISO', '') !== '' ? formatDateRu(parseDateISO($b['dateISO'])) : '',
      'ourCompanyId' => $companyId,
      'ourCompany'   => isset($companies[$companyId]) ? $companies[$companyId]['short'] : '',
      'ourRole'      => in_array(pick($b, 'ourRole', ''), ['executor','customer'], true) ? $b['ourRole'] : '',
      'source'       => 'manual',
      'counterparty' => normalizeCounterparty($cp),
    ]);
    // Реквизиты контрагента из базы → в справочник контактов
    syncContactFromCounterparty($deal['counterparty']);
    jsonResponse($deal);
    exit;
  }
  if (count($segs) === 2 && $segs[0] === 'deals' && $method === 'DELETE') {
    deleteDeal(rawurldecode($segs[1]));
    jsonResponse(['ok' => true]);
    exit;
  }

  // === Экспорт базы договоров в CSV (для Excel) ===
  if ($segs === ['deals-export'] && $method === 'GET') {
    $deals = loadDeals();
    $cols = ['Номер','Дата','Наша компания','Наша роль','Тип контрагента','Контрагент','БИН/ИИН',
      'Должность','Подписант','Основание','Адрес','Счёт','Банк','БИК','Телефон','Email','Заявок','Источник'];
    $esc = function ($v) {
      $v = (string)$v;
      if (preg_match('/[";\n\r]/', $v)) $v = '"' . str_replace('"', '""', $v) . '"';
      return $v;
    };
    $out = "\xEF\xBB\xBF"; // UTF-8 BOM, чтобы Excel корректно показал кириллицу
    $out .= implode(';', array_map($esc, $cols)) . "\r\n";
    foreach ($deals as $d) {
      $cp = isset($d['counterparty']) ? $d['counterparty'] : [];
      $role = ($d['ourRole'] ?? '') === 'executor' ? 'Исполнитель' : (($d['ourRole'] ?? '') === 'customer' ? 'Заказчик' : '');
      $zc = isset($d['zayavki']) && is_array($d['zayavki']) ? count($d['zayavki']) : 0;
      $row = [
        $d['number'] ?? '', $d['dateRu'] ?? '', $d['ourCompany'] ?? '', $role,
        $cp['type'] ?? '', $cp['name'] ?? '', $cp['bin'] ?? '', $cp['position'] ?? '', $cp['signerFull'] ?? '',
        $cp['basis'] ?? '', $cp['address'] ?? '', $cp['account'] ?? '', $cp['bank'] ?? '', $cp['bik'] ?? '',
        $cp['phone'] ?? '', $cp['email'] ?? '', $zc, (($d['source'] ?? '') === 'manual' ? 'вручную' : 'сгенерирован'),
      ];
      $out .= implode(';', array_map($esc, $row)) . "\r\n";
    }
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('База_договоров.csv'));
    echo $out;
    exit;
  }

  // === Резервная копия / восстановление ===
  if ($segs === ['backup'] && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('backup_generator.json'));
    echo json_encode(buildBackupData(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  }
  if ($segs === ['restore'] && $method === 'POST') {
    $b = body();
    if (!isset($b['deals']) && !isset($b['contacts']) && !isset($b['docs']) && !isset($b['history'])) {
      jsonResponse(['error' => 'Файл не похож на резервную копию.'], 400); exit;
    }
    // Перед восстановлением из файла — страховочная копия текущего состояния
    if (!is_dir(BACKUPS_DIR)) @mkdir(BACKUPS_DIR, 0775, true);
    saveJson(BACKUPS_DIR . '/backup-' . date('Y-m-d') . '-pre-restore.json', buildBackupData());
    applyBackupData($b);
    jsonResponse(['ok' => true]);
    exit;
  }

  // === Автокопии: список и восстановление ===
  if ($segs === ['backups'] && $method === 'GET') {
    jsonResponse(listAutoBackups());
    exit;
  }
  if ($segs === ['backups-restore'] && $method === 'POST') {
    $b = body();
    $name = basename(pick($b, 'name', ''));
    $file = BACKUPS_DIR . '/' . $name;
    if (!preg_match('/^backup-\d{4}-\d{2}-\d{2}(-pre-restore)?\.json$/', $name) || !is_file($file)) {
      jsonResponse(['error' => 'Копия не найдена.'], 404); exit;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) { jsonResponse(['error' => 'Копия повреждена.'], 400); exit; }
    // Страховочная копия текущего состояния перед откатом
    saveJson(BACKUPS_DIR . '/backup-' . date('Y-m-d') . '-pre-restore.json', buildBackupData());
    applyBackupData($data);
    jsonResponse(['ok' => true]);
    exit;
  }

  // === Следующий номер китайской заявки (для подсказки в форме) ===
  if ($segs === ['china-next'] && $method === 'GET') {
    jsonResponse(['next' => chinaNextNumber()]);
    exit;
  }

  // === POST /api/generate-china (заявка отдела Китая) ===
  if ($method === 'POST' && $segs === ['generate-china']) {
    $b = body();
    $companies = our_companies();
    $ourCompanyId = pick($b, 'ourCompanyId', '');
    if (!isset($companies[$ourCompanyId])) {
      jsonResponse(['error' => 'Не выбрана наша компания.'], 400); exit;
    }
    if (pick($b, 'consignee', '') === '') { jsonResponse(['error' => 'Не указан грузополучатель.'], 400); exit; }
    if (pick($b, 'route', '') === '')     { jsonResponse(['error' => 'Не указан маршрут.'], 400); exit; }

    // Сквозной номер: введённый вручную или следующий по счётчику
    $number = chinaNumber((int)pick($b, 'number', 0));
    $res = buildChinaDoc($b, $number);

    addDoc([
      'type' => 'china',
      'number' => '№' . $number,
      'date' => gmdate('Y-m-d\TH:i:s\Z'),
      'dateISO' => date('Y-m-d'),
      'dateRu' => formatDateRu(),
      'ourCompany' => $companies[$ourCompanyId]['short'],
      'otherName' => $res['otherName'],
      'route' => $res['route'],
      'filename' => $res['filename'],
      'input' => $b,
    ]);

    sendDocx($res['buffer'], $res['filename'], 'X-China-Number', $number);
    exit;
  }

  // === POST /api/generate (договор) ===
  if ($method === 'POST' && $segs === ['generate']) {
    $b = body();
    $companies = our_companies();
    $ourCompanyId = pick($b, 'ourCompanyId', null);
    $ourRole = pick($b, 'ourRole', null);
    $other = isset($b['other']) && is_array($b['other']) ? $b['other'] : null;

    if (!$ourCompanyId || !isset($companies[$ourCompanyId])) {
      jsonResponse(['error' => 'Не выбрана наша компания.'], 400); exit;
    }
    if (!$ourRole || !in_array($ourRole, ['executor', 'customer'], true)) {
      jsonResponse(['error' => 'Не выбрана роль (исполнитель/заказчик).'], 400); exit;
    }
    if (!$other || empty($other['name'])) {
      jsonResponse(['error' => 'Не введено название второй стороны.'], 400); exit;
    }

    $our = $companies[$ourCompanyId];
    // Уникальный номер (суффикс /2, /3… если в этот день уже были такие)
    $contractNumber = uniqueDocNumber(generateContractNumber($our['prefix'], $ourRole));
    $dateStr = formatDateRu();
    $input = ['ourCompanyId' => $ourCompanyId, 'ourRole' => $ourRole, 'other' => $other];
    $res = buildContractDoc($input, $contractNumber, $dateStr);

    // Сохраняем исходные данные (файл пересоберём при повторном скачивании)
    addDoc([
      'type' => 'contract',
      'number' => $contractNumber,
      'date' => gmdate('Y-m-d\TH:i:s\Z'),
      'dateISO' => date('Y-m-d'),
      'dateRu' => $dateStr,
      'ourCompany' => $our['short'],
      'ourRole' => $ourRole,
      'otherName' => $res['otherName'],
      'otherBin' => $res['otherBin'],
      'filename' => $res['filename'],
      'input' => $input,
    ]);

    // Авто-регистрация договора в базе (с полными реквизитами контрагента)
    upsertDeal([
      'number'       => $contractNumber,
      'dateISO'      => date('Y-m-d'),
      'dateRu'       => $dateStr,
      'ourCompanyId' => $ourCompanyId,
      'ourCompany'   => $our['short'],
      'ourRole'      => $ourRole,
      'source'       => 'generated',
      'counterparty' => normalizeCounterparty($other),
    ]);
    // Контрагент нового договора → в справочник контактов
    syncContactFromCounterparty($other);

    sendDocx($res['buffer'], $res['filename'], 'X-Contract-Number', $contractNumber);
    exit;
  }

  // === POST /api/generate-zayavka (заявка) ===
  if ($method === 'POST' && $segs === ['generate-zayavka']) {
    $b = body();
    $companies = our_companies();
    $ourCompanyId = pick($b, 'ourCompanyId', null);
    $ourRole = pick($b, 'ourRole', null);
    $manager   = isset($b['manager'])   && is_array($b['manager'])   ? $b['manager']   : [];
    $customer  = isset($b['customer'])  && is_array($b['customer'])  ? $b['customer']  : [];
    $cargo     = isset($b['cargo'])     && is_array($b['cargo'])     ? $b['cargo']     : [];
    $loading   = isset($b['loading'])   && is_array($b['loading'])   ? $b['loading']   : [];
    $unloading = isset($b['unloading']) && is_array($b['unloading']) ? $b['unloading'] : [];
    $executor  = isset($b['executor'])  && is_array($b['executor'])  ? $b['executor']  : [];
    $payment   = isset($b['payment'])   && is_array($b['payment'])   ? $b['payment']   : [];
    $contractNumber = pick($b, 'contractNumber', '');
    $contractDate   = pick($b, 'contractDate', '');

    if (!$ourCompanyId || !isset($companies[$ourCompanyId])) {
      jsonResponse(['error' => 'Не выбрана наша компания.'], 400); exit;
    }
    $our = $companies[$ourCompanyId];
    $isExecutor = $ourRole === 'executor';

    if ($isExecutor && empty($customer['name'])) {
      jsonResponse(['error' => 'Не введено название Заказчика.'], 400); exit;
    }
    if (!$isExecutor && empty($executor['name'])) {
      jsonResponse(['error' => 'Не введено название Исполнителя.'], 400); exit;
    }

    $zayavkaNumber = uniqueDocNumber(generateZayavkaNumber($our['prefix']));
    $dateStr = formatDateRu();

    // Номер приложения к договору: сколько заявок уже привязано к нему + 1.
    // Сохраняется в данных заявки — при пересоздании файла номер не меняется.
    if ($contractNumber !== '') {
      $appendix = 1;
      foreach (loadDeals() as $d) {
        if (isset($d['number']) && $d['number'] === $contractNumber) {
          $appendix = (isset($d['zayavki']) && is_array($d['zayavki']) ? count($d['zayavki']) : 0) + 1;
          break;
        }
      }
      $b['_appendixNo'] = (string)$appendix;
    }

    $res = buildZayavkaDoc($b, $zayavkaNumber, $dateStr);

    // Сохраняем полные исходные данные (файл пересоберём при повторном скачивании)
    addDoc([
      'type' => 'zayavka',
      'number' => $zayavkaNumber,
      'date' => gmdate('Y-m-d\TH:i:s\Z'),
      'dateISO' => date('Y-m-d'),
      'dateRu' => $dateStr,
      'ourCompany' => $our['short'],
      'ourRole' => $isExecutor ? 'executor' : 'customer',
      'otherName' => $res['otherName'],
      'route' => $res['route'],
      'filename' => $res['filename'],
      'input' => $b,
    ]);

    // Привязать заявку к договору в базе (если указан номер договора и он там есть)
    if ($contractNumber !== '') {
      $deals = loadDeals();
      foreach ($deals as $i => $d) {
        if ($d['number'] !== $contractNumber) continue;
        if (!isset($deals[$i]['zayavki']) || !is_array($deals[$i]['zayavki'])) $deals[$i]['zayavki'] = [];
        $exists = false;
        foreach ($deals[$i]['zayavki'] as $z) if (($z['number'] ?? '') === $zayavkaNumber) { $exists = true; break; }
        if (!$exists) {
          array_unshift($deals[$i]['zayavki'], [
            'number'   => $zayavkaNumber,
            'dateRu'   => $dateStr,
            'route'    => $res['route'],
            'filename' => $res['filename'],
          ]);
          saveDeals($deals);
        }
        break;
      }
    }

    // Контрагент заявки (другая сторона) → в справочник контактов
    syncContactFromCounterparty($isExecutor ? $customer : $executor);

    sendDocx($res['buffer'], $res['filename'], 'X-Zayavka-Number', $zayavkaNumber);
    exit;
  }

  jsonResponse(['error' => 'Маршрут не найден'], 404);
} catch (Exception $e) {
  jsonResponse(['error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()], 500);
}
