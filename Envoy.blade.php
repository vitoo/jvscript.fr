@servers(['web' => 'oracle'])

@task('deploy')
    cd /var/www_repo/jvscript.io
    sudo su
    git pull
    php8.3 /usr/local/bin/composer install
    php8.3 artisan migrate --force
    php8.3 artisan view:clear
    sudo chown -R www-data:www-data storage bootstrap public/assets
@endtask
