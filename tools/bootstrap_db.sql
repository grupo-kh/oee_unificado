-- ============================================================
-- Bootstrap PostgreSQL para PLAN_ATTAINMENT (mantenimiento)
-- Ejecuta UNA SOLA VEZ con la contraseña del superusuario `postgres`:
--
--   "C:\Program Files\PostgreSQL\16\bin\psql.exe" -U postgres -h 127.0.0.1 -f tools\bootstrap_db.sql
--
-- Te pedirá la pass del usuario `postgres`. Después, deja que la app
-- ejecute migrations/001_init.sql (vía  php tools\install_postgres.php).
-- ============================================================

-- 1) Base de datos (UTF-8 + locale español)
CREATE DATABASE plan_attainment
    WITH OWNER = postgres
    ENCODING = 'UTF8'
    LC_COLLATE = 'Spanish_Spain.1252'
    LC_CTYPE   = 'Spanish_Spain.1252'
    TEMPLATE   = template0;

-- 2) Usuario de aplicación
--    Pass aleatoria de 32 hex chars; coincide con DB_PG_PASS de config/database.php
CREATE ROLE plan_attainment_app WITH LOGIN PASSWORD '1689dd083d482c297f3403ca6d83f3e9';

-- 3) Permisos sobre la base recién creada
GRANT CONNECT ON DATABASE plan_attainment TO plan_attainment_app;

\connect plan_attainment

GRANT USAGE, CREATE ON SCHEMA public TO plan_attainment_app;

-- 4) Permisos por defecto para futuras tablas/secuencias creadas por
--    el superusuario `postgres` (las migraciones las crearemos como app,
--    pero por si en el futuro alguien crea algo como postgres).
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO plan_attainment_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO plan_attainment_app;

-- ✓ Listo. Salir con \q
