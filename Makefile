.PHONY: install prettier test

prettier:
	prettier -w .

test: htdocs/vendor
	cd htdocs ; composer install
	php htdocs/vendor/bin/phpunit


install: htdocs/vendor

htdocs/vendor: htdocs/composer.json htdocs/composer.lock
	cd htdocs ; composer install --no-dev --optimize-autoloader
	touch htdocs/vendor

htdocs/composer.lock: htdocs/composer.json
	cd htdocs ; composer install --no-dev --optimize-autoloader
	touch htdocs/composer.lock

