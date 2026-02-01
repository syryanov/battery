# Собираем и запускаем контейнеры
docker compose up -d --build

# Генерируем APP_KEY
docker compose exec app php artisan key:generate

# Создаём ссылку на storage
docker compose exec app php artisan storage:link

# Запускаем миграции
docker compose exec app php artisan migrate