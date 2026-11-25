deploy:
	vendor/bin/envoy run deploy

serve:
	php artisan serve

test:
	vendor/bin/phpunit

sass:
	./compile-sass.sh
