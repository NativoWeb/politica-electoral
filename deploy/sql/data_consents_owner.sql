-- data_consents (Eventos especiales) is append-only proof of consent (Ley 1581). The migration adds a trigger that
-- rejects UPDATE/DELETE/TRUNCATE, but the app's DB user creates the table and, as its OWNER, could still
-- ALTER TABLE ... DISABLE TRIGGER, drop the trigger or replace the trigger function. This script hands the table
-- and the trigger function to a superuser and leaves the app user with SELECT and INSERT only.
--
-- Run ONCE, AFTER `php artisan migrate` created the table, as a superuser, in the app database:
--
--   sudo -u postgres psql -d politica_electoral -v app_user=electoral -f deploy/sql/data_consents_owner.sql
--
-- Optional: -v owner=<role> (default postgres). Re-running is safe (same end state).
--
-- AFTER RUNNING IT the app user can no longer ALTER or DROP data_consents, so BEFORE any migration that alters it,
-- and before `php artisan migrate:rollback` of 2026_10_31_000001_create_eventos_tables (its down() drops the trigger
-- function, data_consents and the tables data_consents references), run data_consents_owner_revert.sql first and
-- this script again after migrating. Migrations that do not touch data_consents or the tables it references are
-- not affected. The table has a UUID key: there are no sequences to grant.

\set ON_ERROR_STOP on

\if :{?app_user}
\else
\echo 'Falta -v app_user=<usuario de la aplicación> (en producción: electoral).'
\quit
\endif

\if :{?owner}
\else
\set owner postgres
\endif

BEGIN;
ALTER TABLE public.data_consents OWNER TO :"owner";
ALTER FUNCTION public.data_consents_append_only() OWNER TO :"owner";
REVOKE ALL ON public.data_consents FROM PUBLIC;
REVOKE ALL ON public.data_consents FROM :"app_user";
GRANT SELECT, INSERT ON public.data_consents TO :"app_user";
COMMIT;

SELECT tableowner AS data_consents_owner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'data_consents';
SELECT string_agg(privilege_type, ', ' ORDER BY privilege_type) AS app_user_privileges
FROM information_schema.role_table_grants
WHERE table_schema = 'public' AND table_name = 'data_consents' AND grantee = :'app_user';
