<?php
// Фронт-контроллер API. Все /api/* маршруты приходят сюда (см. .htaccess).
// Порт эндпоинтов из Node-версии (server.js).

require __DIR__ . '/../lib/companies.php';
require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/storage.php';

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
// Достать вложенное значение массива с дефолтом
function pick($arr, $key, $default = '') {
  return (isset($arr[$key]) && $arr[$key] !== '') ? $arr[$key] : $default;
}

// Привести реквизиты контрагента к единому набору полей
function normalizeCounterparty($cp) {
  $fields = ['type','name','bin','position','signerFull','signerShort',
    'basis','address','account','bank','bik','phone','email'];
  $out = [];
  foreach ($fields as $f) $out[$f] = isset($cp[$f]) ? $cp[$f] : '';
  return $out;
}

try {
  // === GET /api/companies ===
  if ($method === 'GET' && $segs === ['companies']) {
    $list = [];
    foreach (our_companies() as $id => $c) {
      $list[] = ['id' => $id, 'short' => $c['short'], 'type' => $c['type'], 'name' => $c['name']];
    }
    jsonResponse($list);
    exit;
  }

  // === GET /api/history ===
  if ($method === 'GET' && $segs === ['history']) {
    jsonResponse(loadHistory());
    exit;
  }

  // === GET /api/history/{filename} ===
  if ($method === 'GET' && count($segs) === 2 && $segs[0] === 'history') {
    $safe = basename(rawurldecode($segs[1]));
    $file = FILES_DIR . '/' . $safe;
    if (!is_file($file)) { jsonResponse(['error' => 'Файл не найден'], 404); exit; }
    sendDocx(file_get_contents($file), $safe);
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
    jsonResponse($deal);
    exit;
  }
  if (count($segs) === 2 && $segs[0] === 'deals' && $method === 'DELETE') {
    deleteDeal(rawurldecode($segs[1]));
    jsonResponse(['ok' => true]);
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
    $customer = $ourRole === 'customer' ? $our : $other;
    $executor = $ourRole === 'executor' ? $our : $other;

    // Уникальный номер (суффикс -2, -3… если в этот день уже были такие)
    $contractNumber = uniqueDocNumber(generateContractNumber($our['prefix'], $ourRole));
    $dateStr = formatDateRu();

    $values = [
      'НОМЕР_ДОГОВОРА' => $contractNumber,
      'ДАТА_ДОГОВОРА'  => $dateStr,
      'ЗАКАЗЧИК_НАЗВАНИЕ'          => pick($customer, 'name'),
      'ЗАКАЗЧИК_БИН'               => pick($customer, 'bin'),
      'ЗАКАЗЧИК_АДРЕС'             => pick($customer, 'address'),
      'ЗАКАЗЧИК_СЧЕТ'              => pick($customer, 'account'),
      'ЗАКАЗЧИК_БАНК'              => pick($customer, 'bank'),
      'ЗАКАЗЧИК_БИК'               => pick($customer, 'bik'),
      'ЗАКАЗЧИК_ДОЛЖНОСТЬ'         => pick($customer, 'position'),
      'ЗАКАЗЧИК_ПОДПИСАНТ'         => pick($customer, 'signerFull'),
      'ЗАКАЗЧИК_ПОДПИСАНТ_КРАТКО'  => pick($customer, 'signerShort'),
      'ЗАКАЗЧИК_ОСНОВАНИЕ'         => pick($customer, 'basis'),
      'ЗАКАЗЧИК_ТЕЛЕФОН'          => pick($customer, 'phone'),
      'ЗАКАЗЧИК_EMAIL'            => pick($customer, 'email'),
      'ИСПОЛНИТЕЛЬ_НАЗВАНИЕ'         => pick($executor, 'name'),
      'ИСПОЛНИТЕЛЬ_БИН'              => pick($executor, 'bin'),
      'ИСПОЛНИТЕЛЬ_АДРЕС'            => pick($executor, 'address'),
      'ИСПОЛНИТЕЛЬ_СЧЕТ'             => pick($executor, 'account'),
      'ИСПОЛНИТЕЛЬ_БАНК'             => pick($executor, 'bank'),
      'ИСПОЛНИТЕЛЬ_БИК'              => pick($executor, 'bik'),
      'ИСПОЛНИТЕЛЬ_ДОЛЖНОСТЬ'        => pick($executor, 'position'),
      'ИСПОЛНИТЕЛЬ_ПОДПИСАНТ'        => pick($executor, 'signerFull'),
      'ИСПОЛНИТЕЛЬ_ПОДПИСАНТ_КРАТКО' => pick($executor, 'signerShort'),
      'ИСПОЛНИТЕЛЬ_ОСНОВАНИЕ'        => pick($executor, 'basis'),
      'ИСПОЛНИТЕЛЬ_ТЕЛЕФОН'         => pick($executor, 'phone'),
      'ИСПОЛНИТЕЛЬ_EMAIL'          => pick($executor, 'email'),
    ];

    $buffer = fillDocx($TEMPLATE, $values);
    $safeOther = safeName(pick($other, 'name', 'other'));
    $safeNumber = str_replace('/', '-', $contractNumber);
    $filename = "Договор_{$safeNumber}_{$safeOther}.docx";

    storeDocument([
      'type' => 'contract',
      'number' => $contractNumber,
      'date' => gmdate('Y-m-d\TH:i:s\Z'),
      'dateRu' => $dateStr,
      'dateISO' => date('Y-m-d'),
      'ourCompany' => $our['short'],
      'ourRole' => $ourRole,
      'otherName' => pick($other, 'name'),
      'otherBin' => pick($other, 'bin', ''),
      'filename' => $filename,
    ], $buffer);

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

    sendDocx($buffer, $filename, 'X-Contract-Number', $contractNumber);
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
    $amountNum = (int)pick($payment, 'amount', 0);

    if ($isExecutor) {
      // Мы — Исполнитель, другая сторона — Заказчик
      $Z = [
        'name' => pick($customer, 'name', '—'),
        'type' => pick($customer, 'type', 'ТОО'),
        'position' => pick($customer, 'position', 'Директор'),
        'signerFull' => pick($customer, 'signerFull', '—'),
        'signerShort' => pick($customer, 'signerShort', pick($customer, 'signerFull', '—')),
        'basis' => pick($customer, 'basis', '—'),
        'address' => pick($customer, 'address', '—'),
        'bin' => pick($customer, 'bin', '—'),
        'bank' => pick($customer, 'bank', '—'),
        'bik' => pick($customer, 'bik', '—'),
        'account' => pick($customer, 'account', '—'),
        'phone' => pick($customer, 'phone', '—'),
        'email' => pick($customer, 'email', '—'),
        'manager' => pick($customer, 'manager', '—'),
        'managerPhone' => pick($customer, 'managerPhone', '—'),
      ];
      $I = [
        'name' => $our['name'], 'type' => $our['type'], 'position' => $our['position'],
        'signerFull' => $our['signerFull'], 'signerShort' => $our['signerShort'], 'basis' => $our['basis'],
        'address' => $our['address'], 'bin' => $our['bin'], 'bank' => $our['bank'],
        'bik' => $our['bik'], 'account' => $our['account'],
        'talon' => !empty($our['talon']) ? $our['talon'] : '—',
        'contact' => pick($executor, 'contact', $our['phone']),
        'vehicle' => pick($executor, 'vehicle', '—'),
        'driver' => pick($executor, 'driver', '—'),
      ];
    } else {
      // Мы — Заказчик, другая сторона — Исполнитель
      $Z = [
        'name' => $our['name'], 'type' => $our['type'], 'position' => $our['position'],
        'signerFull' => $our['signerFull'], 'signerShort' => $our['signerShort'], 'basis' => $our['basis'],
        'address' => $our['address'], 'bin' => $our['bin'], 'bank' => $our['bank'],
        'bik' => $our['bik'], 'account' => $our['account'],
        'phone' => $our['phone'], 'email' => $our['email'],
        'manager' => pick($manager, 'name', '—'),
        'managerPhone' => pick($manager, 'phone', '—'),
      ];
      $I = [
        'name' => pick($executor, 'name', '—'),
        'type' => pick($executor, 'type', 'ИП'),
        'position' => pick($executor, 'position', 'Директор'),
        'signerFull' => pick($executor, 'signerFull', '—'),
        'signerShort' => pick($executor, 'signerShort', pick($executor, 'signerFull', '—')),
        'basis' => pick($executor, 'basis', '—'),
        'address' => pick($executor, 'address', '—'),
        'bin' => pick($executor, 'bin', '—'),
        'bank' => pick($executor, 'bank', '—'),
        'bik' => pick($executor, 'bik', '—'),
        'account' => pick($executor, 'account', '—'),
        'talon' => pick($executor, 'talon', '—'),
        'contact' => pick($executor, 'contact', '—'),
        'vehicle' => pick($executor, 'vehicle', '—'),
        'driver' => pick($executor, 'driver', '—'),
      ];
    }

    $values = [
      'НОМЕР_ЗАЯВКИ' => $zayavkaNumber,
      'ДАТА_ЗАЯВКИ' => $dateStr,
      // Ссылка на договор — только если указан его номер (иначе обе метки «—»)
      'НОМЕР_ДОГОВОРА' => $contractNumber !== '' ? $contractNumber : '—',
      'ДАТА_ДОГОВОРА' => ($contractNumber !== '' && $contractDate !== '')
          ? formatDateRu(parseDateISO($contractDate)) : '—',
      'ЗАКАЗЧИК_НАЗВАНИЕ' => $Z['name'],
      'ЗАКАЗЧИК_КРАТКОЕ' => $Z['name'],
      'ЗАКАЗЧИК_ДОЛЖНОСТЬ' => $Z['position'],
      'ЗАКАЗЧИК_ПОДПИСАНТ' => $Z['signerFull'],
      'ЗАК_КР' => $Z['signerShort'],
      'ЗАКАЗЧИК_ОСНОВАНИЕ' => $Z['basis'],
      'ЗАКАЗЧИК_АДРЕС' => $Z['address'],
      'ЗАКАЗЧИК_БИН' => $Z['bin'],
      'ЗАКАЗЧИК_БАНК' => $Z['bank'],
      'ЗАКАЗЧИК_БИК' => $Z['bik'],
      'ЗАКАЗЧИК_СЧЕТ' => $Z['account'],
      'ЗАКАЗЧИК_ТЕЛЕФОН' => !empty($Z['phone']) ? $Z['phone'] : '—',
      'ЗАКАЗЧИК_EMAIL' => !empty($Z['email']) ? $Z['email'] : '—',
      'ЗАКАЗЧИК_МЕНЕДЖЕР' => $Z['manager'],
      'ЗАКАЗЧИК_МЕНЕДЖЕР_ТЕЛ' => $Z['managerPhone'],
      'ГРУЗООТПРАВИТЕЛЬ' => pick($cargo, 'shipper', '—'),
      'ГРУЗОПОЛУЧАТЕЛЬ' => pick($cargo, 'consignee', '—'),
      'МАРШРУТ' => pick($cargo, 'route', '—'),
      'НАИМЕНОВАНИЕ_ГРУЗА' => pick($cargo, 'name', '—'),
      'КОЛ_МЕСТ' => pick($cargo, 'qty', '—'),
      'ГАБАРИТЫ' => pick($cargo, 'dimensions', 'Согласно ТТН'),
      'ДАТА_ПОГРУЗКИ' => pick($loading, 'datetime', '—'),
      'АДРЕС_ПОГРУЗКИ' => pick($loading, 'address', '—'),
      'КОНТАКТ_ПОГРУЗКИ' => pick($loading, 'contact', '—'),
      'АДРЕС_РАЗГРУЗКИ' => pick($unloading, 'address', '—'),
      'КОНТАКТ_РАЗГРУЗКИ' => pick($unloading, 'contact', '—'),
      'ИСПОЛНИТЕЛЬ_НАЗВАНИЕ' => $I['name'],
      'ИСПОЛНИТЕЛЬ_ТИП' => $I['type'],
      'ИСПОЛНИТЕЛЬ_ДОЛЖНОСТЬ' => $I['position'],
      'ИСПОЛНИТЕЛЬ_ПОДПИСАНТ' => $I['signerFull'],
      'ИСП_КР' => $I['signerShort'],
      'ИСПОЛНИТЕЛЬ_ОСНОВАНИЕ' => $I['basis'],
      'ИСПОЛНИТЕЛЬ_АДРЕС' => $I['address'],
      'ИСПОЛНИТЕЛЬ_ИИН' => $I['bin'],
      'ИСПОЛНИТЕЛЬ_БАНК' => $I['bank'],
      'ИСПОЛНИТЕЛЬ_БИК' => $I['bik'],
      'ИСПОЛНИТЕЛЬ_СЧЕТ' => $I['account'],
      'ИСПОЛНИТЕЛЬ_ТАЛОН' => $I['talon'],
      'ИСПОЛНИТЕЛЬ_КОНТАКТ' => $I['contact'],
      'ДАННЫЕ_АМ' => $I['vehicle'],
      'ДАННЫЕ_ВОДИТЕЛЯ' => $I['driver'],
      'СТОИМОСТЬ_ЦИФРАМИ' => $amountNum ? formatAmount($amountNum) : '—',
      'СТОИМОСТЬ_ПРОПИСЬЮ' => $amountNum ? amountToWords($amountNum) : '—',
      'СПОСОБ_ОПЛАТЫ' => pick($payment, 'method', '—'),
      'УСЛОВИЯ_ОПЛАТЫ' => pick($payment, 'conditions', '—'),
      'ДОКУМЕНТЫ' => pick($payment, 'documents', 'ТТН'),
      'ПРИМЕЧАНИЕ' => pick($payment, 'notes', '—'),
    ];

    $buffer = fillDocx($ZAYAVKA_TEMPLATE, $values);
    $safeExec = safeName(pick($executor, 'name', 'исполнитель'), 'исполнитель');
    $safeNumber = str_replace('/', '-', $zayavkaNumber);
    $filename = "Заявка_{$safeNumber}_{$safeExec}.docx";

    storeDocument([
      'type' => 'zayavka',
      'number' => $zayavkaNumber,
      'date' => gmdate('Y-m-d\TH:i:s\Z'),
      'ourCompany' => $our['short'],
      'ourRole' => $isExecutor ? 'executor' : 'customer',
      'otherName' => $isExecutor ? $Z['name'] : $I['name'],
      'route' => pick($cargo, 'route', ''),
      'filename' => $filename,
    ], $buffer);

    sendDocx($buffer, $filename, 'X-Zayavka-Number', $zayavkaNumber);
    exit;
  }

  jsonResponse(['error' => 'Маршрут не найден'], 404);
} catch (Exception $e) {
  jsonResponse(['error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()], 500);
}
