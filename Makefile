down:
	docker-compose -f docker-compose.dev.yml down

up:
	docker-compose -f docker-compose.dev.yml up -d --build

zip:
	zip -r test-technique-tfay.zip * .[^.]*\
		-x '*.git/*'\
		-x '.env.dev'\
		-x '*vendor/*'\
		-x '*.phpunit.cache/*'
