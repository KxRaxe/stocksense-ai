-- Runs once, when the pgdata volume is first initialised.
-- Existing volumes: docker compose exec db createdb -U stocksense stocksense_test
CREATE DATABASE stocksense_test OWNER stocksense;
