# Собираем и запускаем контейнеры
docker compose up -d --build

# Устанавливаем зависимости
docker compose exec app composer install

# Генерируем APP_KEY
docker compose exec app php artisan key:generate

# Создаём ссылку на storage
docker compose exec app php artisan storage:link

# Запускаем миграции
docker compose exec app php artisan migrate

# Настраиваем cron
Если на хостовой машине не установлен cron, устанавливаем его: sudo apt install cron

Выполняем: crontab -e

Добавляем в конце файла: * * * * * cd /home/путь_к_каталогу_приложения_laravel && docker compose exec -T app php artisan schedule:run >> /dev/null 2>&1