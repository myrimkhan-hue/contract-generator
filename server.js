// Генератор договоров — Node.js сервер
// Принимает реквизиты, подставляет в шаблон, отдаёт .docx
// История и файлы хранятся в памяти (сбрасываются при перезапуске)

const express = require('express');
const path = require('path');
const AdmZip = require('adm-zip');

const app = express();
const PORT = process.env.PORT || 3000;

const TEMPLATE_PATH = path.join(__dirname, 'template.docx');
const MAX_HISTORY = 20;

// In-memory хранилище
let history = [];
const archiveBuffers = {};

app.use(express.json({ limit: '1mb' }));
app.use(express.static(__dirname));

// === Реквизиты ваших 3 компаний (отредактируйте при необходимости) ===
const OUR_COMPANIES = {
  ava_solution: {
    short: 'AVA Solution',
    prefix: 'AVA',
    type: 'ТОО',
    name: 'ТОО "AVA Solution"',
    bin: '180840002872',
    address: 'РК, г. Алматы, Жетысуский район, улица Жайсан 52',
    account: 'KZ168562203126096001',
    bank: 'АО «Банк ЦентрКредит», г. Алматы',
    bik: 'KCJBKZKX',
    position: 'Генеральный директор',
    signerFull: 'Юань Вэн-Лун',
    signerShort: 'Юань В.',
    basis: 'Устава',
    talon: '',
    phone: '+7 747 523 52 90',
    email: 'info@ava-solution.kz',
  },
  alt_corp: {
    short: 'ALT Corp',
    prefix: 'ALT',
    type: 'ТОО',
    name: 'ТОО "ALT Corp"',
    bin: '250640033474',
    address: 'РК, г. Алматы, Бостандыкский район, улица Егизбаева 7/6, кв. 7',
    account: 'KZ30601A861061562641',
    bank: 'АО «Народный Банк Казахстана»',
    bik: 'HSBKKZKX',
    position: 'Генеральный директор',
    signerFull: 'Сапаргалиев Алмат Абилдаевич',
    signerShort: 'Сапаргалиев А. А.',
    basis: 'Устава',
    talon: '',
    phone: '+7 701 904 7777',
    email: 'altcorp01@gmail.com',
  },
  transit_trail: {
    short: 'Transit Trail',
    prefix: 'TT',
    type: 'ИП',
    name: 'ИП "Transit Trail"',
    bin: '040624501090',
    address: 'РК, г. Алматы, Жетысуский район, улица Жайсан 52',
    account: 'KZ71722S000036131743',
    bank: 'АО «Kaspi Bank»',
    bik: 'CASPKZKA',
    position: 'Директор',
    signerFull: 'Юань Эрик Вэнович',
    signerShort: 'Юань Э. В.',
    basis: 'Талона',
    talon: '',
    phone: '—',
    email: 'info@ava-solution.kz',
  },
};

app.get('/api/companies', (req, res) => {
  // Отдаём только короткое представление для меню выбора
  const list = Object.entries(OUR_COMPANIES).map(([id, c]) => ({
    id,
    short: c.short,
    type: c.type,
    name: c.name,
  }));
  res.json(list);
});

// === Утилита: экранирование XML ===
function escapeXml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// === Утилита: форматирование даты в "20 мая 2026 г." ===
const MONTHS = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
function formatDateRu(d) {
  return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()} г.`;
}

// === Утилита: генерация номера договора ===
// Формат: <ПРЕФИКС>-<EX|CL>-DDMM/YYYY
//   EX — мы Заказчик (Exporter заказа / Заказчик)
//   CL — мы Исполнитель (Client-facing — мы для клиента)
function generateContractNumber(companyPrefix, ourRole) {
  const now = new Date();
  const dd = String(now.getDate()).padStart(2, '0');
  const mm = String(now.getMonth() + 1).padStart(2, '0');
  const yyyy = now.getFullYear();
  const roleCode = ourRole === 'customer' ? 'EX' : 'CL';
  return `${companyPrefix}-${roleCode}-${dd}${mm}/${yyyy}`;
}

// === Утилита: склонение "действующего/действующей" в зависимости от пола ===
// Простая эвристика: имя оканчивается на -а/-я → женский, иначе мужской
function detectGender(fullName) {
  if (!fullName) return 'm';
  // Берём первое слово (фамилию или имя)
  const parts = fullName.trim().split(/\s+/);
  // Чаще всего ФИО: Фамилия Имя Отчество — отчество с -ич = мужчина, -на = женщина
  if (parts.length >= 3) {
    const patronymic = parts[2].toLowerCase();
    if (patronymic.endsWith('на') || patronymic.endsWith('кызы')) return 'f';
    if (patronymic.endsWith('ич') || patronymic.endsWith('улы') || patronymic.endsWith('оглы')) return 'm';
  }
  // Фолбэк: смотрим на окончание фамилии
  const last = parts[0].toLowerCase();
  if (last.endsWith('а') || last.endsWith('я')) return 'f';
  return 'm';
}

// === Основная функция: подстановка в шаблон ===
function fillTemplate(values) {
  const zip = new AdmZip(TEMPLATE_PATH);
  const docXmlEntry = zip.getEntry('word/document.xml');
  let xml = docXmlEntry.getData().toString('utf-8');

  // Подставляем все метки
  for (const [key, value] of Object.entries(values)) {
    const tag = `{${key}}`;
    const safe = escapeXml(value);
    xml = xml.split(tag).join(safe);
  }

  zip.updateFile('word/document.xml', Buffer.from(xml, 'utf-8'));
  return zip.toBuffer();
}

// === API: генерация договора ===
app.post('/api/generate', (req, res) => {
  try {
    const { ourCompanyId, ourRole, other } = req.body;

    if (!ourCompanyId || !OUR_COMPANIES[ourCompanyId]) {
      return res.status(400).json({ error: 'Не выбрана наша компания.' });
    }
    if (!ourRole || !['executor', 'customer'].includes(ourRole)) {
      return res.status(400).json({ error: 'Не выбрана роль (исполнитель/заказчик).' });
    }
    if (!other || !other.name) {
      return res.status(400).json({ error: 'Не введено название второй стороны.' });
    }

    const our = OUR_COMPANIES[ourCompanyId];

    // Определяем кто Заказчик, кто Исполнитель
    const customer = ourRole === 'customer' ? our : other;
    const executor = ourRole === 'executor' ? our : other;

    // Адаптация форм окончаний по роду
    const customerGender = detectGender(customer.signerFull || customer.signerShort);
    const executorGender = detectGender(executor.signerFull || executor.signerShort);
    // (Используем для "действующего/действующей" если в шаблоне понадобится — пока в шаблоне статичное "действующего")
    void customerGender; void executorGender;

    const contractNumber = generateContractNumber(our.prefix, ourRole);
    const dateStr = formatDateRu(new Date());

    const values = {
      'НОМЕР_ДОГОВОРА': contractNumber,
      'ДАТА_ДОГОВОРА': dateStr,

      'ЗАКАЗЧИК_НАЗВАНИЕ': customer.name,
      'ЗАКАЗЧИК_БИН': customer.bin,
      'ЗАКАЗЧИК_АДРЕС': customer.address,
      'ЗАКАЗЧИК_СЧЕТ': customer.account,
      'ЗАКАЗЧИК_БАНК': customer.bank,
      'ЗАКАЗЧИК_БИК': customer.bik,
      'ЗАКАЗЧИК_ДОЛЖНОСТЬ': customer.position,
      'ЗАКАЗЧИК_ПОДПИСАНТ': customer.signerFull,
      'ЗАКАЗЧИК_ПОДПИСАНТ_КРАТКО': customer.signerShort,
      'ЗАКАЗЧИК_ОСНОВАНИЕ': customer.basis,
      'ЗАКАЗЧИК_ТЕЛЕФОН': customer.phone,
      'ЗАКАЗЧИК_EMAIL': customer.email,

      'ИСПОЛНИТЕЛЬ_НАЗВАНИЕ': executor.name,
      'ИСПОЛНИТЕЛЬ_БИН': executor.bin,
      'ИСПОЛНИТЕЛЬ_АДРЕС': executor.address,
      'ИСПОЛНИТЕЛЬ_СЧЕТ': executor.account,
      'ИСПОЛНИТЕЛЬ_БАНК': executor.bank,
      'ИСПОЛНИТЕЛЬ_БИК': executor.bik,
      'ИСПОЛНИТЕЛЬ_ДОЛЖНОСТЬ': executor.position,
      'ИСПОЛНИТЕЛЬ_ПОДПИСАНТ': executor.signerFull,
      'ИСПОЛНИТЕЛЬ_ПОДПИСАНТ_КРАТКО': executor.signerShort,
      'ИСПОЛНИТЕЛЬ_ОСНОВАНИЕ': executor.basis,
      'ИСПОЛНИТЕЛЬ_ТЕЛЕФОН': executor.phone,
      'ИСПОЛНИТЕЛЬ_EMAIL': executor.email,
    };

    const buffer = fillTemplate(values);

    const safeOther = (other.name || 'other').replace(/[^\wа-яА-Я\- ]/g, '').replace(/\s+/g, '_').slice(0, 40);
    const safeNumber = contractNumber.replace(/\//g, '-');
    const filename = `Договор_${safeNumber}_${safeOther}.docx`;

    // Сохраняем в памяти
    archiveBuffers[filename] = buffer;

    const entry = {
      number: contractNumber,
      date: new Date().toISOString(),
      ourCompany: our.short,
      ourRole,
      otherName: other.name,
      otherBin: other.bin || '',
      filename,
    };
    history.unshift(entry);

    // Чистим лишнее из памяти
    while (history.length > MAX_HISTORY) {
      const old = history.pop();
      delete archiveBuffers[old.filename];
    }

    // Отдаём файл
    res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    res.setHeader('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(filename)}`);
    res.setHeader('X-Contract-Number', contractNumber);
    res.send(buffer);
  } catch (err) {
    console.error('Ошибка генерации:', err);
    res.status(500).json({ error: 'Внутренняя ошибка сервера: ' + err.message });
  }
});

app.get('/api/history', (req, res) => {
  res.json(history);
});

app.get('/api/history/:filename', (req, res) => {
  const safe = path.basename(req.params.filename);
  const buffer = archiveBuffers[safe];
  if (!buffer) {
    return res.status(404).json({ error: 'Файл не найден (сервер был перезапущен)' });
  }
  res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  res.setHeader('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(safe)}`);
  res.send(buffer);
});

// === Заявки ===

const ZAYAVKA_TEMPLATE_PATH = path.join(__dirname, 'template_zayavka.docx');

function amountToWords(n) {
  n = parseInt(n);
  if (isNaN(n) || n === 0) return 'ноль';
  const onesM = ['','один','два','три','четыре','пять','шесть','семь','восемь','девять',
    'десять','одиннадцать','двенадцать','тринадцать','четырнадцать','пятнадцать',
    'шестнадцать','семнадцать','восемнадцать','девятнадцать'];
  const onesF = ['','одна','две','три','четыре','пять','шесть','семь','восемь','девять',
    'десять','одиннадцать','двенадцать','тринадцать','четырнадцать','пятнадцать',
    'шестнадцать','семнадцать','восемнадцать','девятнадцать'];
  const tens = ['','','двадцать','тридцать','сорок','пятьдесят','шестьдесят','семьдесят','восемьдесят','девяносто'];
  const hundreds = ['','сто','двести','триста','четыреста','пятьсот','шестьсот','семьсот','восемьсот','девятьсот'];
  function plural(n, one, two, five) {
    const m = n % 100;
    if (m >= 11 && m <= 19) return five;
    const m10 = n % 10;
    if (m10 === 1) return one;
    if (m10 >= 2 && m10 <= 4) return two;
    return five;
  }
  function chunk(n, fem) {
    if (n === 0) return '';
    const ones = fem ? onesF : onesM;
    let s = '';
    const h = Math.floor(n / 100);
    const r = n % 100;
    if (h) s += hundreds[h] + ' ';
    if (r < 20 && r > 0) { s += ones[r] + ' '; }
    else if (r >= 20) {
      s += tens[Math.floor(r / 10)] + ' ';
      if (r % 10) s += ones[r % 10] + ' ';
    }
    return s;
  }
  const mil = Math.floor(n / 1e6);
  const tho = Math.floor((n % 1e6) / 1e3);
  const rem = n % 1e3;
  let result = '';
  if (mil) result += chunk(mil) + plural(mil, 'миллион', 'миллиона', 'миллионов') + ' ';
  if (tho) result += chunk(tho, true) + plural(tho, 'тысяча', 'тысячи', 'тысяч') + ' ';
  if (rem) result += chunk(rem);
  return result.trim();
}

function formatAmount(n) {
  return parseInt(n).toLocaleString('ru-RU').replace(/ /g, ' ');
}

function generateZayavkaNumber(companyPrefix) {
  const now = new Date();
  const dd = String(now.getDate()).padStart(2, '0');
  const mm = String(now.getMonth() + 1).padStart(2, '0');
  const yyyy = now.getFullYear();
  return `${companyPrefix}-Z-${dd}${mm}/${yyyy}`;
}

app.post('/api/generate-zayavka', (req, res) => {
  try {
    const { ourCompanyId, ourRole, manager, customer, cargo, loading, unloading, executor, payment } = req.body;

    if (!ourCompanyId || !OUR_COMPANIES[ourCompanyId]) {
      return res.status(400).json({ error: 'Не выбрана наша компания.' });
    }

    const our = OUR_COMPANIES[ourCompanyId];
    const isExecutor = ourRole === 'executor';

    // Проверка обязательных полей другой стороны
    if (isExecutor && (!customer || !customer.name)) {
      return res.status(400).json({ error: 'Не введено название Заказчика.' });
    }
    if (!isExecutor && (!executor || !executor.name)) {
      return res.status(400).json({ error: 'Не введено название Исполнителя.' });
    }

    const zayavkaNumber = generateZayavkaNumber(our.prefix);
    const dateStr = formatDateRu(new Date());
    const amountNum = parseInt(payment.amount) || 0;

    let Z, I; // Заказчик, Исполнитель

    if (isExecutor) {
      // Мы — Исполнитель, другая сторона — Заказчик
      Z = {
        name:     customer.name     || '—',
        type:     customer.type     || 'ТОО',
        position: customer.position || 'Директор',
        signerFull:  customer.signerFull  || '—',
        signerShort: customer.signerShort || customer.signerFull || '—',
        basis:    customer.basis    || '—',
        address:  customer.address  || '—',
        bin:      customer.bin      || '—',
        bank:     customer.bank     || '—',
        bik:      customer.bik      || '—',
        account:  customer.account  || '—',
        phone:    customer.phone    || '—',
        email:    customer.email    || '—',
        manager:      customer.manager      || '—',
        managerPhone: customer.managerPhone || '—',
      };
      I = {
        name:        our.name,
        type:        our.type,
        position:    our.position,
        signerFull:  our.signerFull,
        signerShort: our.signerShort,
        basis:       our.basis,
        address:     our.address,
        bin:         our.bin,
        bank:        our.bank,
        bik:         our.bik,
        account:     our.account,
        talon:       our.talon || '—',
        contact:     (executor && executor.contact) || our.phone,
        vehicle:     (executor && executor.vehicle) || '—',
        driver:      (executor && executor.driver)  || '—',
      };
    } else {
      // Мы — Заказчик, другая сторона — Исполнитель
      Z = {
        name:        our.name,
        type:        our.type,
        position:    our.position,
        signerFull:  our.signerFull,
        signerShort: our.signerShort,
        basis:       our.basis,
        address:     our.address,
        bin:         our.bin,
        bank:        our.bank,
        bik:         our.bik,
        account:     our.account,
        phone:       our.phone,
        email:       our.email,
        manager:      (manager && manager.name)  || '—',
        managerPhone: (manager && manager.phone) || '—',
      };
      I = {
        name:        executor.name     || '—',
        type:        executor.type     || 'ИП',
        position:    executor.position || 'Директор',
        signerFull:  executor.signerFull  || '—',
        signerShort: executor.signerShort || executor.signerFull || '—',
        basis:       executor.basis    || '—',
        address:     executor.address  || '—',
        bin:         executor.bin      || '—',
        bank:        executor.bank     || '—',
        bik:         executor.bik      || '—',
        account:     executor.account  || '—',
        talon:       executor.talon    || '—',
        contact:     executor.contact  || '—',
        vehicle:     executor.vehicle  || '—',
        driver:      executor.driver   || '—',
      };
    }

    const values = {
      'НОМЕР_ЗАЯВКИ': zayavkaNumber,
      'ДАТА_ЗАЯВКИ': dateStr,

      'ЗАКАЗЧИК_НАЗВАНИЕ':        Z.name,
      'ЗАКАЗЧИК_КРАТКОЕ':         Z.name,
      'ЗАКАЗЧИК_ДОЛЖНОСТЬ':       Z.position,
      'ЗАКАЗЧИК_ПОДПИСАНТ':       Z.signerFull,
      'ЗАК_КР': Z.signerShort,
      'ЗАКАЗЧИК_ОСНОВАНИЕ':       Z.basis,
      'ЗАКАЗЧИК_АДРЕС':           Z.address,
      'ЗАКАЗЧИК_БИН':             Z.bin,
      'ЗАКАЗЧИК_БАНК':            Z.bank,
      'ЗАКАЗЧИК_БИК':             Z.bik,
      'ЗАКАЗЧИК_СЧЕТ':            Z.account,
      'ЗАКАЗЧИК_ТЕЛЕФОН':         Z.phone || '—',
      'ЗАКАЗЧИК_EMAIL':           Z.email || '—',
      'ЗАКАЗЧИК_МЕНЕДЖЕР':        Z.manager,
      'ЗАКАЗЧИК_МЕНЕДЖЕР_ТЕЛ':    Z.managerPhone,

      'ГРУЗООТПРАВИТЕЛЬ':  (cargo && cargo.shipper)     || '—',
      'ГРУЗОПОЛУЧАТЕЛЬ':   (cargo && cargo.consignee)   || '—',
      'МАРШРУТ':           (cargo && cargo.route)       || '—',
      'НАИМЕНОВАНИЕ_ГРУЗА': (cargo && cargo.name)       || '—',
      'КОЛ_МЕСТ':          (cargo && cargo.qty)         || '—',
      'ГАБАРИТЫ':          (cargo && cargo.dimensions)  || 'Согласно ТТН',

      'ДАТА_ПОГРУЗКИ':    (loading && loading.datetime) || '—',
      'АДРЕС_ПОГРУЗКИ':   (loading && loading.address)  || '—',
      'КОНТАКТ_ПОГРУЗКИ': (loading && loading.contact)  || '—',

      'АДРЕС_РАЗГРУЗКИ':   (unloading && unloading.address) || '—',
      'КОНТАКТ_РАЗГРУЗКИ': (unloading && unloading.contact) || '—',

      'ИСПОЛНИТЕЛЬ_НАЗВАНИЕ':        I.name,
      'ИСПОЛНИТЕЛЬ_ТИП':             I.type,
      'ИСПОЛНИТЕЛЬ_ДОЛЖНОСТЬ':       I.position,
      'ИСПОЛНИТЕЛЬ_ПОДПИСАНТ':       I.signerFull,
      'ИСП_КР': I.signerShort,
      'ИСПОЛНИТЕЛЬ_ОСНОВАНИЕ':       I.basis,
      'ИСПОЛНИТЕЛЬ_АДРЕС':           I.address,
      'ИСПОЛНИТЕЛЬ_ИИН':             I.bin,
      'ИСПОЛНИТЕЛЬ_БАНК':            I.bank,
      'ИСПОЛНИТЕЛЬ_БИК':             I.bik,
      'ИСПОЛНИТЕЛЬ_СЧЕТ':            I.account,
      'ИСПОЛНИТЕЛЬ_ТАЛОН':           I.talon,
      'ИСПОЛНИТЕЛЬ_КОНТАКТ':         I.contact,
      'ДАННЫЕ_АМ':                   I.vehicle,
      'ДАННЫЕ_ВОДИТЕЛЯ':             I.driver,

      'СТОИМОСТЬ_ЦИФРАМИ': amountNum ? formatAmount(amountNum) : '—',
      'СТОИМОСТЬ_ПРОПИСЬЮ': amountNum ? amountToWords(amountNum) : '—',
      'СПОСОБ_ОПЛАТЫ':  (payment && payment.method)     || '—',
      'УСЛОВИЯ_ОПЛАТЫ': (payment && payment.conditions) || '—',
      'ДОКУМЕНТЫ':      (payment && payment.documents)  || 'ТТН',
      'ПРИМЕЧАНИЕ':     (payment && payment.notes)      || '—',
    };

    const zip = new AdmZip(ZAYAVKA_TEMPLATE_PATH);
    const docXmlEntry = zip.getEntry('word/document.xml');
    let xml = docXmlEntry.getData().toString('utf-8');
    for (const [key, value] of Object.entries(values)) {
      xml = xml.split(`{${key}}`).join(escapeXml(value));
    }
    zip.updateFile('word/document.xml', Buffer.from(xml, 'utf-8'));
    const buffer = zip.toBuffer();

    const safeExec = (executor.name || 'исполнитель').replace(/[^\wа-яА-Я\- ]/g, '').replace(/\s+/g, '_').slice(0, 40);
    const safeNumber = zayavkaNumber.replace(/\//g, '-');
    const filename = `Заявка_${safeNumber}_${safeExec}.docx`;

    archiveBuffers[filename] = buffer;
    history.unshift({
      type: 'zayavka',
      number: zayavkaNumber,
      date: new Date().toISOString(),
      ourCompany: our.short,
      ourRole: isExecutor ? 'executor' : 'customer',
      otherName: isExecutor ? Z.name : I.name,
      route: (cargo && cargo.route) || '',
      filename,
    });
    while (history.length > MAX_HISTORY) {
      const old = history.pop();
      delete archiveBuffers[old.filename];
    }

    res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    res.setHeader('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(filename)}`);
    res.setHeader('X-Zayavka-Number', zayavkaNumber);
    res.send(buffer);
  } catch (err) {
    console.error('Ошибка генерации заявки:', err);
    res.status(500).json({ error: 'Внутренняя ошибка сервера: ' + err.message });
  }
});

app.listen(PORT, () => {
  console.log(`✓ Сервер запущен: http://localhost:${PORT}`);
});
