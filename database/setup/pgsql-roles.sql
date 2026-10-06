-- Creates the two database roles and the databases Governix - ITSM needs.
-- Run once as a PostgreSQL superuser:
--   psql -U postgres -h 127.0.0.1 -f database/setup/pgsql-roles.sql
--
-- autoservice_owner  owns every table; used only for migrations (connection "pgsql_migrate").
-- autoservice_app    used by the running app (connection "pgsql"). It is NOT the table owner,
--                    NOT superuser and has no BYPASSRLS, so row level security policies
--                    always apply to it. It gets DML privileges only.
-- Change the passwords outside local development.

CREATE ROLE autoservice_owner LOGIN PASSWORD 'autoservice_owner'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE autoservice_app LOGIN PASSWORD 'autoservice_app'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

CREATE DATABASE autoservice OWNER autoservice_owner;
CREATE DATABASE autoservice_test OWNER autoservice_owner;

\connect autoservice
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO autoservice_app;
ALTER DEFAULT PRIVILEGES FOR ROLE autoservice_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO autoservice_app;
ALTER DEFAULT PRIVILEGES FOR ROLE autoservice_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO autoservice_app;

\connect autoservice_test
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO autoservice_app;
ALTER DEFAULT PRIVILEGES FOR ROLE autoservice_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO autoservice_app;
ALTER DEFAULT PRIVILEGES FOR ROLE autoservice_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO autoservice_app;
