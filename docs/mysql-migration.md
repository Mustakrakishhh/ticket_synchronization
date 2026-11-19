# Перенос Travel Sync с SQLite на MySQL

## Что переносится

Команда переноса сохраняет порталы, зашифрованные OAuth-токены и ключи провайдеров,
настройки, сопоставления полей, курсоры, связи с карточками, историю запусков и аудит.
Активные блокировки worker не копируются. Задачи в состояниях `queued` и `running`
переносятся как аварийно завершённые, чтобы после запуска не выполнить их повторно.

`APP_ENCRYPTION_KEY` менять нельзя: без старого ключа сохранённые секреты невозможно
расшифровать.

## Порядок на Timeweb

1. Остановите cron `bin/sync.php` и отдельные процессы `bin/worker.php`.
2. Скачайте резервную копию проекта и файла `var/app.sqlite`.
3. Создайте MySQL-базу и отдельного пользователя с правами только на эту базу.
4. Установите для базы `utf8mb4` и часовую зону UTC.
5. В `.env` сохраните прежний `APP_ENCRYPTION_KEY`, задайте параметры MySQL:

```dotenv
APP_ENV=prod
APP_DISTRIBUTION=marketplace
DATABASE_DSN=mysql:host=127.0.0.1;port=3306;dbname=travel_sync;charset=utf8mb4
DATABASE_USER=travel_sync
DATABASE_PASSWORD=strong-password
HEALTH_CHECK_TOKEN=at-least-24-random-characters
```

6. Выполните перенос, указав абсолютный путь к старому файлу:

```bash
php bin/migrate-sqlite-to-mysql.php --source=/home/user/app/var/app.sqlite --confirm
```

Команда откажется работать с непустой MySQL-базой и перед изменением SQLite создаст
рядом файл `app.sqlite.backup-YYYYMMDD-HHMMSS`.

7. Проверьте закрытый health-check:

```bash
curl -H "Authorization: Bearer YOUR_HEALTH_CHECK_TOKEN" https://example.com/health.php
```

8. Откройте приложение из Bitrix24, проверьте подключения всех провайдеров и выполните
ручную синхронизацию одного источника.
9. Верните cron каждую минуту и наблюдайте `var/app.log` и журнал синхронизации.

## Откат

Остановите cron, верните прежний `DATABASE_DSN=sqlite:var/app.sqlite` и резервную копию
SQLite. Новые миграции сохраняют совместимость SQLite для разработки и аварийного
отката, но Marketplace-режим с SQLite намеренно не запускается.
