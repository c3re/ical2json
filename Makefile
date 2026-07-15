.PHONY: install prettier test clean precommit


precommit: prettier test

test: install
	cd htdocs ; composer install
	php htdocs/vendor/bin/phpunit

clean:
	rm -rf node_modules htdocs/vendor

prettier: node_modules
	./node_modules/.bin/prettier -w **/*.php **/*.js **/*.css **/*.html



install: htdocs/vendor node_modules

node_modules: package.json package-lock.json
	npm install
	touch node_modules

package-lock.json: package.json
	npm install
	touch package-lock.json


htdocs/vendor: htdocs/composer.json htdocs/composer.lock
	cd htdocs ; composer install --no-dev --optimize-autoloader
	touch htdocs/vendor

htdocs/composer.lock: htdocs/composer.json
	cd htdocs ; composer install --no-dev --optimize-autoloader
	touch htdocs/composer.lock

