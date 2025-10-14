down:
	docker-compose -f docker-compose.dev.yml down

up:
	docker-compose -f docker-compose.dev.yml up -d --build
