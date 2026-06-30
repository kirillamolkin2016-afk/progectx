# Портал «Инструменты Hide-X» — серверная версия

Внутреннее рабочее пространство компании. Точка входа — портал с карточками инструментов;
первый рабочий инструмент — **Доска бригад** (таймлайн/список по бригадам и объектам).

Данные доски хранятся **на сервере** (общие для всех устройств) через лёгкий PHP-бэкенд.
Доступ — по ссылке, без авторизации (по требованию заказчика; при желании включается общий
пароль через Basic Auth — см. ниже).

## Состав

```
index.html                     — портал (карточки инструментов)
doska-brigad.html              — доска бригад (фронтенд, работает с api.php)
api.php                        — бэкенд хранения доски (GET/POST)
data.json                      — данные доски (создаётся автоматически api.php, в git не хранится)
deploy/tools.hide-x.ru.conf    — пример server-блока nginx
.gitignore
```

Будущие инструменты добавляются новыми файлами (`kp.html`, `smeta.html`) + карточкой в `index.html`.

## Как это работает

- `GET api.php` → отдаёт всю доску как JSON. Если `data.json` ещё нет — `{"brigades":[],"objects":[]}`.
- `POST api.php` (тело = JSON всей доски) → проверяет наличие массивов `brigades`/`objects`,
  пишет в `data.json` под блокировкой `flock`, отвечает `{"ok":true}`.
- Фронтенд:
  - при старте грузит доску `GET api.php`;
  - при изменениях шлёт всё состояние `POST api.php` с **debounce ≈400 мс**;
  - **автообновление** раз в 20 c и при возврате на вкладку (пауза, пока открыта форма
    добавления/редактирования — чтобы не перетереть ввод);
  - если сервер недоступен — ненавязчивое уведомление «Нет связи с сервером, изменения не
    сохранены», данные на экране не теряются;
  - ручной экспорт/импорт JSON (кнопка ⤓) остаётся как резервная копия.

Модель данных (`data.json`, имена полей не менять):

```json
{
  "brigades": [ { "id": "b1", "name": "Бригада 1", "color": "#2563EB" } ],
  "objects": [
    { "id": "x1", "name": "...", "addr": "", "type": "...",
      "brigadeId": "b1", "start": "YYYY-MM-DD", "duration": 12, "status": "active" }
  ]
}
```
`brigadeId` может быть `null`; `status` ∈ `active | planned | done`.

## Локальная проверка

Нужен PHP 8.x. Из корня проекта:

```bash
php -S 127.0.0.1:8080
# открыть http://127.0.0.1:8080/index.html
```

---

## Развёртывание на облачном VPS (Ubuntu/Debian)

Предусловия: доступ root/sudo, известен публичный IP сервера, A-запись поддомена на reg.ru
уже создана. Если что-то из перечисленного уже установлено — переиспользуйте, не дублируйте.

### 1. DNS на reg.ru
Создать **A-запись** для поддомена (напр. `tools.hide-x.ru`) → публичный IP сервера.
Дождаться применения: `dig +short tools.hide-x.ru` должен вернуть ваш IP.

### 2. Фаервол
Открыть порты **80** и **443** — и в фаерволе ОС, и в панели облачного провайдера.

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw status
```

### 3. nginx + PHP-FPM

```bash
sudo apt update
sudo apt install -y nginx php-fpm
php -v                       # убедиться, что PHP 8.x
ls /run/php/                 # узнать имя сокета, напр. php8.3-fpm.sock
```

### 4. Файлы и права

```bash
sudo mkdir -p /var/www/tools
# скопировать index.html, doska-brigad.html, api.php в /var/www/tools
sudo cp index.html doska-brigad.html api.php /var/www/tools/

# php-fpm должен иметь возможность создавать/писать data.json в каталоге.
# Достаточно, чтобы каталог принадлежал пользователю php-fpm (обычно www-data):
sudo chown -R www-data:www-data /var/www/tools
sudo find /var/www/tools -type f -exec chmod 644 {} \;
sudo find /var/www/tools -type d -exec chmod 755 {} \;
```

`data.json` создаётся автоматически при первом сохранении — вручную создавать не нужно.

### 5. server-блок nginx

```bash
sudo cp deploy/tools.hide-x.ru.conf /etc/nginx/sites-available/tools.hide-x.ru.conf
sudo ln -s /etc/nginx/sites-available/tools.hide-x.ru.conf /etc/nginx/sites-enabled/
# при необходимости поправить server_name, root и имя php-сокета (fastcgi_pass)
sudo nginx -t
sudo systemctl reload nginx
```

В конфиге уже заданы:
- обработка `.php` через php-fpm;
- `location = /data.json { deny all; }` — прямой доступ к данным закрыт (только через `api.php`);
- запрет на скрытые файлы (`.htpasswd`, `.git`).

Проверка по http (до SSL): `http://tools.hide-x.ru` открывает портал.

### 6. SSL (Let's Encrypt + автопродление)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d tools.hide-x.ru --redirect -m you@example.com --agree-tos
# certbot сам добавит блок 443, редирект http→https и настроит автопродление (systemd-таймер)
sudo certbot renew --dry-run     # проверка автопродления
```

### 7. Проверка готовности
- `https://tools.hide-x.ru` открывает портал; карточка «Доска бригад» открывает доску.
- С двух разных устройств — одна общая доска (объект с одного виден на другом после
  автообновления/перезагрузки).
- Работает с телефона и компа, по `https://`, без предупреждений браузера.
- Данные хранятся на сервере и переживают перезагрузку страницы и закрытие браузера.

---

## Перенос текущих данных
Доска на первом запуске пустая. Чтобы не вводить заново:
1. В локальном `doska-brigad.html` нажать **⤓ → ОК** (выгрузить копию JSON).
2. В развёрнутой версии нажать **⤓ → Отмена** (импорт) и загрузить этот файл.

## Опционально: один общий пароль (Basic Auth)
Закрыть доступ одним общим логином/паролем **без правок кода**:

```bash
sudo apt install -y apache2-utils
sudo htpasswd -c /etc/nginx/.htpasswd-tools hidex     # задать пароль
```

Затем в `deploy/tools.hide-x.ru.conf` раскомментировать две строки в `server { ... }`:

```nginx
auth_basic "Hide-X";
auth_basic_user_file /etc/nginx/.htpasswd-tools;
```

и применить:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Чтобы снова открыть доступ по ссылке — закомментировать эти строки и перезагрузить nginx.

## Обновление инструментов
Залить изменённые файлы в `/var/www/tools` и (если менялись `.php`) проверить права.
`data.json` при обновлении кода не трогать — это пользовательские данные.
