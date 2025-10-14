down:
	docker-compose down

up:
	docker-compose up -d --build

zip:
	zip -r test-technique-tfay.zip * .[^.]*\
		-x '*.git/*'\
		-x '.env.dev'\
		-x '*vendor/*'\
		-x '*.phpunit.cache/*'
