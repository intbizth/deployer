#!/usr/bin/env bash

# https://zach-adams.com/2015/01/apt-get-cant-connect-to-security-ubuntu-fix/
sudo bash -c 'echo "precedence ::ffff:0:0/96 100" >> /etc/gai.conf'

timedatectl set-timezone Asia/Bangkok

bash -c 'echo "LC_CTYPE=en_US.UTF-8" >> /etc/default/locale'
bash -c 'echo LC_ALL=en_US.UTF-8 >> /etc/default/locale'

# Keep upstart from complaining
dpkg-divert --local --rename --add /sbin/initctl
ln -sf /bin/true /sbin/initctl

export DEBIAN_FRONTEND=noninteractive

apt-get -y --allow-unauthenticated update
apt-get -y --allow-unauthenticated upgrade

# basic requirements
apt-get -y --allow-unauthenticated install \
    curl \
    git \
    unzip \
    nodejs \
    ruby \
    gem \
    ufw \
    htop \
    openssl \
    mysql-server \
    mysql-client \
    nginx \
    pwgen \
    supervisor

# docker
sudo curl -L https://github.com/docker/compose/releases/download/1.21.0/docker-compose-$(uname -s)-$(uname -m) -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
apt-get -y --allow-unauthenticated install \
    apt-transport-https \
    ca-certificates \
    software-properties-common
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo apt-key add -
apt-key fingerprint 0EBFCD88
add-apt-repository "deb [arch=amd64] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable"
apt-get -y --allow-unauthenticated update
apt-get -y --allow-unauthenticated install docker-ce
docker run hello-world

# php7.2 - https://thishosting.rocks/install-php-on-ubuntu/
apt-get install -y --allow-unauthenticated software-properties-common python-software-properties
add-apt-repository ppa:ondrej/php -y
add-apt-repository ppa:ondrej/nginx -y
apt-get -y --allow-unauthenticated update

# redis
apt-get -y --allow-unauthenticated install build-essential tcl
cd /tmp && curl -O http://download.redis.io/redis-stable.tar.gz && tar xzvf redis-stable.tar.gz
cd redis-stable
make && make install
mkdir /etc/redis && mkdir /var/lib/redis
cd ~

# sf requirements
apt-get -y --allow-unauthenticated install \
    php7.2 \
    php7.2-bz2 \
    php7.2-bcmath \
    php7.2-cli \
    php7.2-common \
    php7.2-curl \
    php7.2-fpm \
    php7.2-gd \
    php7.2-intl \
    php7.2-imap \
    php7.2-json \
    php7.2-mysql \
    php7.2-mbstring \
    php7.2-pspell \
    php7.2-recode \
    php7.2-sqlite3 \
    php7.2-tidy \
    php7.2-xmlrpc \
    php7.2-xml \
    php7.2-zip \
    php7.2-amqp \
    php7.2-gmp \
    php-imagick \
    php-apcu \
    php-pear \
    php-redis

# bugfix: https://github.com/Supervisor/supervisor/issues/735#issuecomment-219364268
sudo systemctl enable supervisor.service

# security
sed -i 's/Port 22/Port 25252/' /etc/ssh/sshd_config

ufw allow 25252     # ssh
ufw allow 80        # web
ufw allow 443       # web https
#ufw allow 1234      # redis
#ufw allow 4567      # supervisor
#ufw allow 7890      # mysql
#ufw allow out 9123      # mail
#ufw allow 8181      # websocket
ufw default deny incoming
ufw enable

mkdir -p /var/www/web
chown -R www-data:www-data /var/www

# github test
ssh -yvT git@github.com

# mysql setup
MYSQL_PASSWORD=`pwgen -c -n -1 24`

mysqladmin -u root password $MYSQL_PASSWORD
mysql -uroot -p$MYSQL_PASSWORD -e "GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' IDENTIFIED BY '$MYSQL_PASSWORD' WITH GRANT OPTION; FLUSH PRIVILEGES;DROP USER 'root'@'localhost';"

#This is so the passwords show up in logs.
echo mysql root password: $MYSQL_PASSWORD

# helper
REDIS_PASSWORD=`pwgen -c -n -1 24`
SUPERVISOR_PASSWORD=`pwgen -c -n -1 24`

echo redis password: $REDIS_PASSWORD
echo supervisor password: $SUPERVISOR_PASSWORD

shutdown -r now
