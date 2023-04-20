.PHONY: install prettier

prettier:
	prettier -w .


install: htdocs/vendor

htdocs/vendor: htdocs/composer.json htdocs/composer.lock
	cd htdocs ; composer install --no-dev --optimize-autoloader
	touch htdocs/vendor

htdocs/composer.lock: htdocs/composer.json
	cd htdocs ; composer install --no-dev --optimize-autoloader
	touch htdocs/composer.lock

