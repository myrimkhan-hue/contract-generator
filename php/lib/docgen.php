<?php
// Сборка документов из входных данных. Используется и при генерации,
// и при повторном скачивании (пересоздание файла из сохранённых данных).

define('TPL_CONTRACT', __DIR__ . '/../templates/template.docx');
define('TPL_ZAYAVKA',  __DIR__ . '/../templates/template_zayavka.docx');
define('TPL_CHINA',    __DIR__ . '/../templates/template_china.docx');

// Заявка отдела перевозок из Китая (экспедирование). $number — сквозной номер (11, 12…).
function buildChinaDoc($input, $number) {
  $number = ltrim((string)$number, '№'); // в истории номер хранится как «№11»
  $companies = our_companies();
  $our = isset($companies[pick($input, 'ourCompanyId', '')]) ? $companies[pick($input, 'ourCompanyId', '')] : [];
  $cargo  = arr($input, 'cargo');
  $client = arr($input, 'client');
  $contractDate = pick($input, 'contractDate', '');
  // В шаблоне после даты стоит «г.», формат — 24.12.2025
  $contractDateStr = $contractDate !== '' ? date('d.m.Y', parseDateISO($contractDate)) : '—';

  $values = [
    'НОМЕР_ЗАЯВКИ'   => $number,
    'НОМЕР_ДОГОВОРА' => pick($input, 'contractNumber', '—'),
    'ДАТА_ДОГОВОРА'  => $contractDateStr,
    'МЕНЕДЖЕР'             => pick($input, 'manager', '—'),
    'ГРУЗООТПРАВИТЕЛЬ'     => pick($input, 'shipper', '—'),
    'ГРУЗОПОЛУЧАТЕЛЬ'      => pick($input, 'consignee', '—'),
    'АДРЕС_ЗАБОРА'         => pick($input, 'pickupAddress', '—'),
    'КОНТАКТ_СОГЛАСОВАНИЯ' => pick($input, 'contact', '—'),
    'ГРУЗ_НАИМЕНОВАНИЕ' => pick($cargo, 'name', '—'),
    'ГРУЗ_УПАКОВКА'     => pick($cargo, 'packing', ''),
    'ГРУЗ_МЕСТ'         => pick($cargo, 'places', ''),
    'ГРУЗ_ВЕС'          => pick($cargo, 'weight', ''),
    'ГРУЗ_ОБЪЕМ'        => pick($cargo, 'volume', ''),
    'ГРУЗ_УСЛОВИЯ'      => pick($cargo, 'conditions', 'нет'),
    'МАРШРУТ'            => pick($input, 'route', '—'),
    'СТОИМОСТЬ_USD'      => pick($input, 'costUsd', '—'),
    'СТОИМОСТЬ_KZT'      => pick($input, 'costKzt', '—'),
    'ЭКСПОРТ_ОФОРМЛЕНИЕ' => pick($input, 'export', 'Нет'),
    'СТРАХОВКА'          => pick($input, 'insurance', 'Нет'),
    'ДОП_УСЛУГИ'         => pick($input, 'extras', 'Нет'),
    'УСЛУГИ_СВХ'         => pick($input, 'svh', 'Нет'),
    'ВИД_ТРАНСПОРТА'     => pick($input, 'transport', '—'),
    'ОБЩАЯ_СТАВКА'       => pick($input, 'totalRate', '—'),
    'ТРАНЗИТ_ВРЕМЯ'      => pick($input, 'transit', '—'),
    'УЧЕТНЫЙ_КУРС'       => pick($input, 'rate', '—'),
    'КЛИЕНТ_ДОЛЖНОСТЬ'     => pick($client, 'position', 'Директор'),
    'КЛИЕНТ_ПОДПИСАНТ'     => pick($client, 'signer', ''),
    'КЛИЕНТ_БИН'           => pick($client, 'bin', '—'),
    'КЛИЕНТ_ПОЧТА'         => pick($client, 'email', '—'),
    'ЭКСПЕДИТОР_ДОЛЖНОСТЬ' => 'Директор',
    'ЭКСПЕДИТОР_ПОДПИСАНТ' => pick($our, 'signerShort', ''),
    'ЭКСПЕДИТОР_БИН'       => pick($our, 'bin', '—'),
    'ЭКСПЕДИТОР_ПОЧТА'     => pick($our, 'email', '—'),
  ];

  $buffer = fillDocx(TPL_CHINA, $values);
  $safeConsignee = safeName(pick($input, 'consignee', 'клиент'), 'клиент');
  $filename = "Заявка_КНР_№{$number}_{$safeConsignee}.docx";
  return [
    'buffer'    => $buffer,
    'filename'  => $filename,
    'otherName' => pick($input, 'consignee', '—'),
    'route'     => pick($input, 'route', ''),
  ];
}

// Договор. $input = { ourCompanyId, ourRole, other }. $number, $dateStr — готовые.
function buildContractDoc($input, $number, $dateStr) {
  $companies = our_companies();
  $ourCompanyId = pick($input, 'ourCompanyId', '');
  $ourRole = pick($input, 'ourRole', '');
  $other = arr($input, 'other');
  $our = isset($companies[$ourCompanyId]) ? $companies[$ourCompanyId] : [];
  $customer = $ourRole === 'customer' ? $our : $other;
  $executor = $ourRole === 'executor' ? $our : $other;

  $values = [
    'НОМЕР_ДОГОВОРА' => $number,
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

  $safeOther = safeName(pick($other, 'name', 'other'));
  $safeNumber = str_replace('/', '-', $number);
  return [
    'buffer'    => fillDocx(TPL_CONTRACT, $values),
    'filename'  => "Договор_{$safeNumber}_{$safeOther}.docx",
    'otherName' => pick($other, 'name'),
    'otherBin'  => pick($other, 'bin', ''),
  ];
}

// Заявка. $input = полное тело запроса /generate-zayavka. $number, $dateStr — готовые.
function buildZayavkaDoc($input, $number, $dateStr) {
  $companies = our_companies();
  $ourCompanyId = pick($input, 'ourCompanyId', '');
  $ourRole = pick($input, 'ourRole', '');
  $manager   = arr($input, 'manager');
  $customer  = arr($input, 'customer');
  $cargo     = arr($input, 'cargo');
  $loading   = arr($input, 'loading');
  $unloading = arr($input, 'unloading');
  $executor  = arr($input, 'executor');
  $payment   = arr($input, 'payment');
  $contractNumber = pick($input, 'contractNumber', '');
  $contractDate   = pick($input, 'contractDate', '');

  $our = isset($companies[$ourCompanyId]) ? $companies[$ourCompanyId] : [];
  $isExecutor = $ourRole === 'executor';
  $amountNum = (int)pick($payment, 'amount', 0);
  // НДС: 'with' → «с НДС», 'without' → «без НДС», иначе не указываем
  $vat = pick($payment, 'vat', '');
  $vatLabel = $vat === 'with' ? 'с НДС' : ($vat === 'without' ? 'без НДС' : '');

  if ($isExecutor) {
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
      'name' => pick($our, 'name'), 'type' => pick($our, 'type'), 'position' => pick($our, 'position'),
      'signerFull' => pick($our, 'signerFull'), 'signerShort' => pick($our, 'signerShort'), 'basis' => pick($our, 'basis'),
      'address' => pick($our, 'address'), 'bin' => pick($our, 'bin'), 'bank' => pick($our, 'bank'),
      'bik' => pick($our, 'bik'), 'account' => pick($our, 'account'),
      'talon' => !empty($our['talon']) ? $our['talon'] : '—',
      'contact' => pick($executor, 'contact', pick($our, 'phone')),
      'vehicle' => pick($executor, 'vehicle', '—'),
      'driver' => pick($executor, 'driver', '—'),
    ];
  } else {
    $Z = [
      'name' => pick($our, 'name'), 'type' => pick($our, 'type'), 'position' => pick($our, 'position'),
      'signerFull' => pick($our, 'signerFull'), 'signerShort' => pick($our, 'signerShort'), 'basis' => pick($our, 'basis'),
      'address' => pick($our, 'address'), 'bin' => pick($our, 'bin'), 'bank' => pick($our, 'bank'),
      'bik' => pick($our, 'bik'), 'account' => pick($our, 'account'),
      'phone' => pick($our, 'phone'), 'email' => pick($our, 'email'),
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

  // Заголовок документа. Заявка к договору: «ЗАЯВКА №N …» (N — счёт заявок
  // этого договора, хранится в данных заявки) + «(Приложение № 1 к Договору …)».
  // Одноразовая заявка без договора: «ДОГОВОР-ЗАЯВКА …» без блока приложения.
  if ($contractNumber !== '') {
    $appendixNo = pick($input, '_appendixNo', '1');
    $contractDateRu = $contractDate !== '' ? formatDateRu(parseDateISO($contractDate)) : '—';
    $zTitle = 'ЗАЯВКА №' . $appendixNo . ' НА ТРАНСПОРТНЫЕ УСЛУГИ № ' . $number . ' от ' . $dateStr . ' ';
    $zAppendix = '(Приложение № 1 к Договору № ' . $contractNumber . ' от ' . $contractDateRu . ')';
  } else {
    $zTitle = 'ДОГОВОР-ЗАЯВКА НА ТРАНСПОРТНЫЕ УСЛУГИ № ' . $number . ' от ' . $dateStr;
    $zAppendix = '';
  }

  $values = [
    'ЗАГОЛОВОК_ЗАЯВКИ' => $zTitle,
    'ПРИЛОЖЕНИЕ_К' => $zAppendix,
    'НОМЕР_ЗАЯВКИ' => $number,
    'ДАТА_ЗАЯВКИ' => $dateStr,
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
    'СТОИМОСТЬ_ЦИФРАМИ' => $amountNum
      ? formatAmount($amountNum) . ' (' . amountToWords($amountNum) . ') тенге' . ($vatLabel !== '' ? ', ' . $vatLabel : '')
      : '—',
    'СТОИМОСТЬ_ПРОПИСЬЮ' => $amountNum ? amountToWords($amountNum) : '—',
    'СПОСОБ_ОПЛАТЫ' => pick($payment, 'method', '—'),
    'УСЛОВИЯ_ОПЛАТЫ' => pick($payment, 'conditions', '—'),
    'ДОКУМЕНТЫ' => pick($payment, 'documents', 'ТТН'),
    'ПРИМЕЧАНИЕ' => pick($payment, 'notes', '—'),
  ];

  $safeExec = safeName(pick($executor, 'name', 'исполнитель'), 'исполнитель');
  $safeNumber = str_replace('/', '-', $number);
  return [
    'buffer'     => fillDocx(TPL_ZAYAVKA, $values),
    'filename'   => "Заявка_{$safeNumber}_{$safeExec}.docx",
    'otherName'  => $isExecutor ? $Z['name'] : $I['name'],
    'route'      => pick($cargo, 'route', ''),
    'isExecutor' => $isExecutor,
  ];
}
