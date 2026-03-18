# Deploying Laravel and MongoDB to CentOS 10 Stream

This guide outlines exactly how the `TOKENPAPSYSTEM` Laravel backend was deployed to the CentOS 10 Stream server.

## 1. Initial Server Setup & Dependencies
First, we connected via SSH and installed the necessary system utilities: Git to clone the repository, and Node.js.

```bash
# Update packages
dnf update -y

# Install Git and Node.js
dnf install -y git nodejs npm
```

## 2. Installing MongoDB (8.0)
CentOS 10 Stream requires adding the official MongoDB yum repository.

```bash
# Create MongoDB repo file
cat <<EOF > /etc/yum.repos.d/mongodb-org-8.0.repo
[mongodb-org-8.0]
name=MongoDB Repository
baseurl=https://repo.mongodb.org/yum/redhat/9/mongodb-org/8.0/x86_64/
gpgcheck=1
enabled=1
gpgkey=https://pgp.mongodb.com/server-8.0.asc
EOF

# Import GPG key and install MongoDB (using --nogpgcheck to bypass signature binding issues)
rpm --import https://pgp.mongodb.com/server-8.0.asc
dnf install -y --nogpgcheck mongodb-org

# Enable and start the MongoDB service
systemctl enable --now mongod
```

## 3. Installing the PHP MongoDB Extension
Laravel requires the MongoDB PHP driver to communicate with the database. We installed it via PECL.

```bash
# Install PHP development tools required for PECL compilation
dnf install -y php-pear php-devel gcc make openssl-devel

# Install the MongoDB extension via PECL
pecl install mongodb

# Add the extension to PHP's configuration
echo "extension=mongodb.so" > /etc/php.d/50-mongodb.ini

# Restart PHP-FPM to load the new module
systemctl restart php-fpm
```

## 4. Deploying the Laravel Application
We used git to clone the repository to `/var/www` and installed its dependencies using Composer.

```bash
# Create web directory and clone the backend
mkdir -p /var/www
cd /var/www
git clone https://github.com/BKerio/tokengenerator.git TOKENPAPSYSTEM
cd TOKENPAPSYSTEM

# Install PHP dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader
```

## 5. Laravel Configuration
We copied the [.env](file:///c:/xampp/htdocs/TOKENPAPSYSTEM/backend/.env) file, generated the application key, and configured the MongoDB connection using the recommended DSN format.

```bash
# Set up environment variables
cp .env.example .env
php artisan key:generate

# Update database configuration inside .env
sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=mongodb/' .env
echo "DB_DSN=mongodb://127.0.0.1:27017" >> .env
echo "DB_URI=mongodb://127.0.0.1:27017" >> .env
echo "DB_DATABASE=tokenpap_db" >> .env

# Run database migrations
php artisan migrate --force
```

## 6. Web Server & Permissions (Nginx & SELinux)
CentOS 10 Stream utilizes SELinux for advanced security. Nginx and PHP-FPM processes needed explicitly granted permissions to write to Laravel's cache/storage and communicate over the network.

```bash
# Resolve port 80 conflict if Apache is pre-installed
systemctl stop httpd
systemctl disable httpd

# Set proper directory ownership for Nginx and PHP-FPM
chown -R nginx:nginx /var/www/TOKENPAPSYSTEM/
chmod -R 777 storage bootstrap/cache

# Configure SELinux: allow web process to write to Laravel storage directories
chcon -Rt httpd_sys_rw_content_t storage bootstrap/cache

# Configure SELinux: allow web process to make network connections (crucial for MongoDB)
setsebool -P httpd_can_network_connect 1
```

## 7. Nginx Configuration
We created an Nginx virtual host for the API to pass traffic to `php-fpm`.

```bash
cat << 'EOF' > /etc/nginx/conf.d/tokenpap.conf
server {
    listen 80;
    server_name _;
    root /var/www/TOKENPAPSYSTEM/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";

    index index.php index.html;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

# Test configuration and start Nginx
nginx -t
systemctl enable --now nginx

# Ensure firewall allows HTTP
firewall-cmd --add-service=http --permanent
firewall-cmd --reload
```

## 8. Final Caching
Finally, any additional [.env](file:///c:/xampp/htdocs/TOKENPAPSYSTEM/backend/.env) updates required a config cache clear to be immediately reflected.

```bash
php artisan optimize:clear
```

## 9. Managing the [.env](file:///c:/xampp/htdocs/TOKENPAPSYSTEM/backend/.env) File
To safely update environment variables directly on the server, use built-in CLI editors like `nano` or `vi`.

### Using `nano`
```bash
cd /var/www/TOKENPAPSYSTEM
nano .env
```
- Edit the necessary variables.
- Press **`Ctrl + O`**, then **`Enter`** to save.
- Press **`Ctrl + X`** to exit.

Always clear the config cache after saving changes:
```bash
php artisan optimize:clear
```

## 10. Viewing the MongoDB Database
Depending on your preference, you can inspect the newly created collections via terminal or GUI.

### Option A: Using `mongosh` (Server Terminal)
While SSH'd into the server, run:
```bash
mongosh
```
Then execute database commands:
```js
use tokenpap_db
show collections
db.users.find().pretty()
```

### Option B: Using MongoDB Compass (Local GUI)
1. Download [MongoDB Compass](https://www.mongodb.com/products/tools/compass) on your local computer.
2. Click **Advanced Connection Options**, go to the **Proxy/SSH Tunnel** tab, and enter:
   - **Hostname:** `151.236.222.239`
   - **Username:** `root`
   - **Password:** (Your server password)
3. Connect and visually browse your `tokenpap_db` database remotely.
