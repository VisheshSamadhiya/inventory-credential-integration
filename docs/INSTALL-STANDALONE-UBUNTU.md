# Standalone Ubuntu deployment

Target: `it-password-manager.ditinus.com`, Apache `mod_php`, PHP 8.3, MySQL/MariaDB. These steps prepare a new release at `/var/www/html/php/inventory-credential-new/`; they do not alter production Apache or a production database. Review commands and replace placeholders before use.

## 1. Prepare the host

Use a supported Ubuntu release with PHP 8.3 packages already configured (or your approved PHP repository). Install the app's required extensions and tools:

```sh
sudo apt update
sudo apt install apache2 libapache2-mod-php8.3 php8.3-cli php8.3-mysql php8.3-gd php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl php8.3-bcmath unzip composer mariadb-client
sudo a2enmod rewrite headers
php -v
php -m | grep -Ei 'PDO|pdo_mysql|gd|mbstring|xml|zip|curl|bcmath|openssl|sodium'
```

Required PHP modules: PDO, PDO MySQL, OpenSSL, Sodium, JSON, mbstring, XML, GD, ZIP, cURL, and BCMath. Check `composer.lock` platform requirements after installing Composer dependencies.

## 2. Upload and extract

Run from an administrator workstation, replacing `HOST` with the SSH destination:

```sh
scp inventory-credential-manager-production.zip HOST:/tmp/
```

On the server:

```sh
sudo install -d -o root -g www-data -m 0750 /var/www/html/php/inventory-credential-new
sudo unzip -q /tmp/inventory-credential-manager-production.zip -d /var/www/html/php/inventory-credential-new
cd /var/www/html/php/inventory-credential-new
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
```

## 3. Create protected configuration and storage

Create the DB and a least-privilege account as described in [DATABASE-SETUP.md](DATABASE-SETUP.md). For an existing deployment, retain its encryption key and attachment storage. For a new installation, generate a key once with `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` and store it only in the protected config.

```sh
sudo install -d -o root -g www-data -m 0750 /etc/inventory-manager
sudo install -d -o www-data -g www-data -m 0700 /var/lib/inventory-manager/sessions /var/lib/inventory-manager/credential-attachments
sudo install -d -o www-data -g www-data -m 0750 /var/www/html/php/inventory-credential-new/storage/logs
sudo install -o root -g www-data -m 0640 .env.production.example /etc/inventory-manager/production.env
sudoedit /etc/inventory-manager/production.env
```

Set `APP_ENV=production`, the exact HTTPS `APP_URL`, DB credentials, `SESSION_SAVE_PATH`, `CREDENTIAL_ATTACHMENT_PATH`, and encryption key. Set real SMTP values only if mail notifications are required. Do not put this file under any document root.

The release's `app/.htaccess` reads no secrets itself. Apache `SetEnv APP_ENV_FILE /etc/inventory-manager/production.env` tells the PHP config loader where to read the outside file. Make sure the Apache `www-data` process can read it and cannot modify it.

## 4. Fresh database or existing database

For an empty DB only, import `database/fresh-install.sql`, then run `database/verify.sql`. For an existing DB, use the reviewed migration plan and backup procedure in [UPGRADE-EXISTING.md](UPGRADE-EXISTING.md). Do not apply fresh-install SQL to existing data.

An example import from the release directory:

```sh
sudo mariadb inventory_manager < database/fresh-install.sql
sudo mariadb inventory_manager < database/verify.sql
```

Create the first Admin at `https://it-password-manager.ditinus.com/setup.php` after the web config is installed. Setup is disabled once a user exists.

## 5. Apache VirtualHost preparation and controlled switch

First inspect the current vhost and preserve its SSL certificate directives and unrelated sites:

```sh
sudo apache2ctl -S
sudo cp -a /etc/apache2/sites-available/it-password-manager.ditinus.com.conf /etc/apache2/sites-available/it-password-manager.ditinus.com.conf.before-inventory-manager
```

In the existing vhost, change only the relevant `DocumentRoot` and matching `<Directory>` path to the new `app` directory, keep the current `SSLCertificateFile`, `SSLCertificateKeyFile`, aliases, and logging directives, and add:

```apache
SetEnv APP_ENV_FILE /etc/inventory-manager/production.env
<Directory /var/www/html/php/inventory-credential-new/app>
    Options -Indexes +FollowSymLinks
    AllowOverride FileInfo Options
    Require all granted
</Directory>
```

Do not create a second TLS vhost or replace the certificate. Confirm `.htaccess` rewrite support is allowed. Then:

```sh
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Check login, setup lockout, `/health.php`, orders, history, exports, credentials, and the relevant worker logs. The health route should not reveal connection details. All user traffic should be HTTPS.

## 6. File ownership

Keep PHP source and Composer vendor files root-owned and web-readable; keep secrets and attachments private. For this package the document root is `.../inventory-credential-new/app`, so its sibling `storage/` is outside the public root.

```sh
sudo chown -R root:www-data /var/www/html/php/inventory-credential-new
sudo find /var/www/html/php/inventory-credential-new -type d -exec chmod 0750 {} \;
sudo find /var/www/html/php/inventory-credential-new -type f -exec chmod 0640 {} \;
sudo chown -R www-data:www-data /var/lib/inventory-manager
sudo chmod 0700 /var/lib/inventory-manager/sessions /var/lib/inventory-manager/credential-attachments
```

## Roll back

Restore the vhost copy, run `sudo apache2ctl configtest`, and gracefully reload Apache. Database rollback is separate and can require restoring a verified backup. See [ROLLBACK.md](ROLLBACK.md).
