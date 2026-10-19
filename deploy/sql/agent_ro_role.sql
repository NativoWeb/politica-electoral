-- Read-only login for the WhatsApp agent: it can only SELECT the views in schema agent_ro.
-- Run AFTER `php artisan migrate` (the views are created by a migration), as the owner of the
-- schema/views (the app's DB user) or a superuser, in the app database:
--
--   psql -h HOST -U APP_USER -d DB_NAME -v agent_ro_password="$AGENT_RO_PASSWORD" -f deploy/sql/agent_ro_role.sql
--
-- Without -v the password is prompted interactively. Never write the password in this file.
-- Re-running is safe: it updates the password and re-applies the grants. Re-run it after any
-- `migrate:rollback` of the agent_ro views migration (dropping the schema drops its grants).
--
-- The role defaults (read-only transactions, 5s timeout) are only DEFAULTS: any session can
-- `SET statement_timeout = 0` or `BEGIN READ WRITE`. Writes still fail (no privileges, no TEMP),
-- but the agent's query executor MUST wrap every query exactly like this, one statement each:
--
--   BEGIN READ ONLY; SET LOCAL statement_timeout = '5s'; <one SELECT>; COMMIT;
--
-- This script revokes TEMPORARY on the database from PUBLIC (so agent_ro cannot create temp
-- tables or pg_temp functions) and grants it back explicitly to the user running the script
-- (the app user). Any OTHER role that needs temp tables must be granted TEMPORARY explicitly.

\set ON_ERROR_STOP on

\if :{?agent_ro_password}
\else
\prompt 'Password for role agent_ro: ' agent_ro_password
\endif

SELECT length(:'agent_ro_password') >= 16 AS password_ok \gset
\if :password_ok
\else
DO $$ BEGIN RAISE EXCEPTION 'agent_ro_password must be at least 16 characters'; END $$;
\endif

SELECT NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'agent_ro') AS create_role \gset
\if :create_role
CREATE ROLE agent_ro LOGIN PASSWORD :'agent_ro_password';
\else
ALTER ROLE agent_ro LOGIN PASSWORD :'agent_ro_password';
\endif

ALTER ROLE agent_ro NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT CONNECTION LIMIT 20;
ALTER ROLE agent_ro SET default_transaction_read_only = on;
ALTER ROLE agent_ro SET statement_timeout = '5s';

GRANT CONNECT ON DATABASE :"DBNAME" TO agent_ro;

GRANT TEMPORARY ON DATABASE :"DBNAME" TO CURRENT_USER;
REVOKE TEMPORARY ON DATABASE :"DBNAME" FROM PUBLIC;
REVOKE TEMPORARY ON DATABASE :"DBNAME" FROM agent_ro;

REVOKE ALL ON SCHEMA public FROM agent_ro;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM agent_ro;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM agent_ro;

GRANT USAGE ON SCHEMA agent_ro TO agent_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA agent_ro TO agent_ro;
-- Views added later to this schema by the same owner get SELECT automatically.
ALTER DEFAULT PRIVILEGES IN SCHEMA agent_ro GRANT SELECT ON TABLES TO agent_ro;
