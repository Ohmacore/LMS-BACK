# VPS Deployment Guide

This guide deploys the LMS as two services:

- Backend API/admin: Laravel 12, PHP 8.2+, Sanctum, Filament
- Frontend app: Next.js 16, Node.js 20+

Example domains used below:

- Frontend: `https://app.example.com`
- Backend API/admin: `https://api.example.com`
- Optional live server later: `https://live.example.com`

Replace these with the real domains before deployment.

## 1. Server Baseline

Recommended VPS: Ubuntu 22.04 or 24.04, 2 CPU, 4 GB RAM minimum for MVP.

Install system packages:

```bash
sudo apt update
sudo apt install -y nginx git unzip curl supervisor mysql-server certbot python3-certbot-nginx
```

Install PHP 8.2+ with required extensions:

```bash
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-xml php8.2-curl php8.2-mbstring php8.2-zip php8.2-bcmath php8.2-intl
```

Install Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Install Node.js 20+:

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
sudo npm install -g pm2
```

Create an app user and folders:

```bash
sudo adduser lms
sudo mkdir -p /var/www/lms
sudo chown -R lms:www-data /var/www/lms
```

## 2. Clone Repositories

```bash
sudo -iu lms
cd /var/www/lms
git clone https://github.com/Ohmacore/LMS-BACK.git backend
git clone https://github.com/Ohmacore/LMS-FRONT.git frontend
```

Use deploy keys or GitHub SSH keys later for private repositories. Do not store GitHub tokens in remote URLs.

## 3. Database

Create a production database:

```bash
sudo mysql
```

```sql
CREATE DATABASE lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lms_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON lms.* TO 'lms_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 4. Backend Setup

```bash
sudo -iu lms
cd /var/www/lms/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `/var/www/lms/backend/.env`:

```env
APP_NAME="LMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lms
DB_USERNAME=lms_user
DB_PASSWORD=CHANGE_THIS_STRONG_PASSWORD

SESSION_DRIVER=database
SESSION_DOMAIN=.example.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=app.example.com,api.example.com

QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

JITSI_BASE_URL=https://live.example.com
```

Run migrations and optimize:

```bash
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Permissions:

```bash
sudo chown -R lms:www-data /var/www/lms/backend
sudo chmod -R ug+rwX /var/www/lms/backend/storage /var/www/lms/backend/bootstrap/cache
```

## 5. Backend Queue Worker

Create `/etc/supervisor/conf.d/lms-worker.conf`:

```ini
[program:lms-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/lms/backend/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=lms
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/lms/backend/storage/logs/worker.log
stopwaitsecs=3600
```

Reload Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

## 6. Backend Nginx

Create `/etc/nginx/sites-available/lms-api`:

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www/lms/backend/public;

    index index.php;

    client_max_body_size 200M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/lms-api /etc/nginx/sites-enabled/lms-api
sudo nginx -t
sudo systemctl reload nginx
```

## 7. Frontend Setup

```bash
sudo -iu lms
cd /var/www/lms/frontend
cp .env.example .env.local
```

Edit `/var/www/lms/frontend/.env.local`:

```env
NEXT_PUBLIC_API_URL=https://api.example.com/api
```

Install and build:

```bash
npm ci
npm run build
```

Start with PM2:

```bash
pm2 start npm --name lms-frontend -- start
pm2 save
pm2 startup
```

Run the command printed by `pm2 startup` with sudo.

## 8. Frontend Nginx

Create `/etc/nginx/sites-available/lms-front`:

```nginx
server {
    listen 80;
    server_name app.example.com;

    client_max_body_size 200M;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/lms-front /etc/nginx/sites-enabled/lms-front
sudo nginx -t
sudo systemctl reload nginx
```

## 9. SSL

```bash
sudo certbot --nginx -d api.example.com -d app.example.com
sudo systemctl reload nginx
```

After SSL, confirm `.env` uses HTTPS URLs and rerun:

```bash
cd /var/www/lms/backend
php artisan config:cache
sudo supervisorctl restart lms-worker:*
pm2 restart lms-frontend
```

## 10. Live Classes

For a free MVP, self-host Jitsi Meet and set the backend live provider values:

```env
JITSI_BASE_URL=https://live.example.com
JITSI_JWT_APP_ID=your-app-id
JITSI_JWT_APP_SECRET=your-shared-secret
JITSI_JWT_TTL_MINUTES=240
```

Do not use `https://meet.jit.si` for production embedded live classes; it is demo-oriented and can disconnect sessions.

Recommended docker-jitsi-meet `.env` values:

```env
ENABLE_AUTH=1
ENABLE_GUESTS=0
AUTH_TYPE=jwt
JWT_APP_ID=your-app-id
JWT_APP_SECRET=your-shared-secret
JWT_ACCEPTED_ISSUERS=your-app-id
JWT_ACCEPTED_AUDIENCES=jitsi
XMPP_MUC_MODULES=token_affiliation
ENABLE_AUTO_OWNER=0
ENABLE_MODERATOR_CHECKS=1
DISABLE_DEEP_LINKING=1
```

`token_affiliation` keeps teachers as moderators and students as participants. `ENABLE_AUTO_OWNER=0` prevents the first student in an empty room from becoming moderator.

Recommended production live defaults:

- Teacher is the only user who schedules and starts a session.
- Students join only through the platform after enrollment eligibility is checked.
- Join window opens 10 minutes before start.
- Reminder notification is sent 15 minutes before start.
- Students join muted by default.
- Keep recording disabled for MVP unless storage and privacy rules are ready.

## 11. Deploy Updates

Backend update:

```bash
sudo -iu lms
cd /var/www/lms/backend
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl restart lms-worker:*
sudo systemctl reload php8.2-fpm
```

Frontend update:

```bash
sudo -iu lms
cd /var/www/lms/frontend
git pull
npm ci
npm run build
pm2 restart lms-frontend
```

## 12. Health Checks

Backend:

```bash
curl -I https://api.example.com
curl -I https://api.example.com/api/modules
```

Frontend:

```bash
curl -I https://app.example.com
```

Admin/API smoke test:

- Register/login as a teacher.
- Create a draft module.
- Upload a PDF and video resource.
- Register/login as a student.
- Subscribe to a module.
- Open resources inside the platform.
- Schedule a live session and confirm notifications appear.

## 13. Security Notes

- Rotate any GitHub token that has been shared in chat or stored in a remote URL.
- Use SSH deploy keys for the VPS instead of personal tokens.
- Keep `.env`, `.env.local`, storage keys, and database backups out of Git.
- Set `APP_DEBUG=false` in production.
- Restrict MySQL to localhost unless a managed database requires otherwise.
- Enable automated database and uploaded-file backups before real users join.
