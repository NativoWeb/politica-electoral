-- Reverts data_consents_owner.sql: the app user owns data_consents and its trigger function again, so a migration
-- that alters the table, or `php artisan migrate:rollback` of 2026_10_31_000001_create_eventos_tables, can run.
-- The append-only trigger stays in place. Run as a superuser, then data_consents_owner.sql again after migrating:
--
--   sudo -u postgres psql -d politica_electoral -v app_user=electoral -f deploy/sql/data_consents_owner_revert.sql

\set ON_ERROR_STOP on

\if :{?app_user}
\else
\echo 'Falta -v app_user=<usuario de la aplicación> (en producción: electoral).'
\quit
\endif

BEGIN;
ALTER TABLE public.data_consents OWNER TO :"app_user";
ALTER FUNCTION public.data_consents_append_only() OWNER TO :"app_user";
REVOKE UPDATE, DELETE, TRUNCATE ON public.data_consents FROM PUBLIC;
COMMIT;

SELECT tableowner AS data_consents_owner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'data_consents';
