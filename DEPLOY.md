# Развёртывание на своём сервере (PS.kz VPS)

Инструкция для переезда генератора договоров/заявок с Railway на собственный
VPS от **PS.kz**. Всё приложение — это обычный Node.js‑сервер, поэтому подойдёт
любой VPS с Ubuntu.

Генератор запускается на **поддомене** (например `docs.ваш-домен.kz`), потому
что основной домен уже занят вашим сайтом. Берём **отдельный новый VPS** под
генератор, а поддомен направляем на него — основной сайт при этом остаётся на
своём текущем хостинге и никак не затрагивается.

> **Важно:** нужен именно **VPS/VDS**, а не «виртуальный хостинг». На обычном
> shared‑хостинге PS.kz (PHP/LiteSpeed) Node.js‑приложение не запустить.

---

## 0. Что заказать на PS.kz

1. **VPS/VDS**, минимального тарифа хватает с запасом:
   - 1 vCPU, 1 ГБ RAM, 10–20 ГБ диска
   - ОС: **Ubuntu 22.04** или **24.04 LTS**
2. **Домен** (например, `.kz`) — можно там же, в разделе доменов PS.kz.

После заказа PS.kz пришлёт **IP‑адрес сервера** и **root‑пароль** (или доступ по SSH‑ключу).

---

## 1. Привязка поддомена

Основной домен занят вашим сайтом — его **не трогаем**. Генератор вешаем на
**поддомен**, например `docs.ваш-домен.kz` (можно `generator` / `dogovor` —
на ваш вкус). Основной сайт остаётся там, где стоит сейчас.

В панели управления DNS вашего домена (у регистратора / PS.kz) добавьте **одну**
A‑запись для поддомена, указывающую на IP нового VPS:

| Тип | Имя (host) | Значение         |
|-----|------------|------------------|
| A   | `docs`     | `IP_вашего_VPS`  |

> Существующие записи основного сайта (`@`, `www` и др.) оставьте как есть —
> они продолжают вести на ваш текущий сайт. Мы добавляем только `docs`.

DNS обновляется до нескольких часов. Проверить: `ping docs.ваш-домен.kz` должен
отвечать с IP нового VPS.

Дальше во всей инструкции вместо `docs.ваш-домен.kz` подставляйте выбранный
поддомен.

---

## 2. Подключение к серверу

```bash
ssh root@IP_вашего_VPS
```

Обновите систему:

```bash
apt update && apt upgrade -y
```

---

## 3. Установка Node.js

Ставим актуальную LTS‑версию Node.js 20 через NodeSource:

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs git nginx
node -v   # должно показать v20.x
```

---

## 4. Отдельный пользователь для приложения

Не запускаем приложение от root:

```bash
adduser --disabled-password --gecos "" appuser
```

---

## 5. Загрузка кода

Вариант А — **из вашего GitHub‑репозитория** (рекомендуется, удобно обновлять):

```bash
su - appuser
git clone https://github.com/myrimkhan-hue/contract-generator.git
cd contract-generator
npm install --omit=dev
exit   # вернуться в root
```

Вариант Б — залить файлы вручную по SCP/SFTP в `/home/appuser/contract-generator`,
затем `npm install --omit=dev` в этом каталоге.

---

## 6. Каталог для данных

История и справочник контрагентов хранятся в `DATA_DIR`. Держим их вне кода,
чтобы обновления не затирали данные:

```bash
mkdir -p /var/lib/contract-generator/data
chown -R appuser:appuser /var/lib/contract-generator
```

---

## 7. Автозапуск через systemd

Скопируйте готовый unit из репозитория и включите сервис:

```bash
cp /home/appuser/contract-generator/deploy/contract-generator.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now contract-generator
systemctl status contract-generator   # должно быть "active (running)"
```

Приложение теперь слушает `127.0.0.1:3000` и само поднимается после перезагрузки.

---

## 8. nginx как обратный прокси

```bash
cp /home/appuser/contract-generator/deploy/nginx.conf /etc/nginx/sites-available/contract-generator
# отредактируйте поддомен внутри файла:
nano /etc/nginx/sites-available/contract-generator     # замените docs.your-domain.kz

ln -s /etc/nginx/sites-available/contract-generator /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

> `default`‑конфиг nginx **не удаляем** — на этом VPS кроме генератора ничего
> нет, а наш блок отвечает только за поддомен `docs.ваш-домен.kz`, так что
> конфликтов не будет.

Теперь по `http://docs.ваш-домен.kz` уже должно открываться приложение.

---

## 9. Бесплатный SSL (https)

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d docs.ваш-домен.kz
```

Certbot сам пропишет SSL в конфиг nginx и настроит автопродление сертификата.
После этого генератор работает по **https://docs.ваш-домен.kz**, а основной
сайт на главном домене продолжает работать отдельно и независимо.

---

## 10. Обновление в будущем

Когда внесёте изменения (запушите в GitHub) — на сервере просто:

```bash
su - appuser
cd contract-generator
bash deploy/update.sh
```

Скрипт заберёт код, поставит зависимости и перезапустит сервис.

---

## Реквизиты компаний

Данные ваших трёх компаний зашиты в `server.js` (объект `OUR_COMPANIES`).
Чтобы поменять счёт/подписанта/адрес — отредактируйте файл и перезапустите
(`bash deploy/update.sh` или `systemctl restart contract-generator`).

## Резервная копия данных

Вся ценная информация — в `/var/lib/contract-generator/data`
(`history.json`, `contacts.json` и папка `files/`). Для бэкапа достаточно
скопировать этот каталог:

```bash
tar czf backup-$(date +%F).tar.gz -C /var/lib/contract-generator data
```
