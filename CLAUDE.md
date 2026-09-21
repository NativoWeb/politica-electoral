# CLAUDE.md — Inteligencia Electoral Santander

## Project Overview

**Plataforma de Inteligencia Político-Electoral de Santander** — sistema de consulta, análisis e inteligencia sobre datos electorales históricos para directivos y analistas políticos.

Stack: **Laravel 12 + React 19 (Inertia.js) + PostgreSQL 16/PostGIS 3.4 + Redis**

Documento maestro de requerimientos: `C:\Users\Marly Rangel\Downloads\Documento_Maestro_Requerimientos_Plataforma_Inteligencia_Politica_Electoral_Santander.docx`

Datos fuente (Excel): `C:\Users\Marly Rangel\Downloads\Panel Electoral\`

## Commands

```bash
# Setup
composer install && npm install
cp .env.example .env && php artisan key:generate

# Docker (PostgreSQL + PostGIS + Redis)
docker-compose up -d

# Migrations
php artisan migrate
php artisan migrate:fresh --seed

# Dev servers
composer dev                    # PHP + Vite + queue
php artisan serve              # Solo PHP
npm run dev                    # Solo Vite

# Tests
php artisan test
php artisan test --filter=NombreTest

# Format
./vendor/bin/pint
```

## Database (PostgreSQL 16 + PostGIS)

Extensions: `uuid-ossp`, `pg_trgm`, `postgis`, `unaccent`

### Core Tables

| Group | Tables |
|-------|--------|
| Auth & IAM | `users`, `roles`, `permissions`, `role_permissions`, `sessions` |
| Geography | `geographic_units` (PostGIS MULTIPOLYGON), `geographic_aliases` |
| Persons | `persons`, `person_aliases`, `person_contact_points`, `person_facts`, `relationships`, `person_merge_transactions` |
| Organizations | `political_organizations`, `organization_aliases`, `coalitions`, `coalition_members` |
| Electoral | `electoral_events`, `contests`, `offices`, `corporations`, `electoral_districts`, `electoral_lists`, `candidacies`, `candidacy_endorsements`, `office_tenures`, `organization_memberships` |
| Results | `electoral_results`, `turnout_metrics` |
| Import/ETL | `source_files`, `mapping_templates`, `import_jobs`, `import_staging_rows`, `source_records` |
| Documents | `documents`, `document_versions`, `document_associations` |
| Activities | `activities`, `activity_participants`, `tags`, `taggables` |
| System | `audit_logs` (append-only), `favorites`, `recent_views`, `dataset_versions` |

### Key DB Rules

- All PKs are UUIDs (`uuid_generate_v4()`).
- PostGIS columns on `geographic_units` (MULTIPOLYGON) and `activities` (POINT).
- Fuzzy search via GIN trigram indexes (`pg_trgm`) on `persons.normalized_name`, `person_aliases.normalized_alias`, `geographic_aliases.normalized_alias`, `political_organizations.canonical_name`, `organization_aliases.normalized_alias`.
- `audit_logs` is append-only: `REVOKE UPDATE, DELETE`.
- `electoral_results.value` must be INTEGER >= 0 or NULL. Never store 0 when data is missing.
- Person identity is stable. Candidacy, OfficeTenure, OrganizationMembership are temporal relations — changing party or running in another election never creates a new Person.

### Design Decisions (from Documento Maestro)

- **D-01**: Person != Candidacy != Result (separate entities)
- **D-02**: Source files are immutable; all transforms happen in staging with lineage
- **D-03**: Modular monolith, not microservices
- **D-04**: PostgreSQL + PostGIS as core
- **D-05**: Search-first, progressive disclosure
- **D-07**: RBAC + ABAC authorization
- **D-08**: Selective offline (PWA packages by municipality)
- **D-10**: Politically neutral UI, no voter profiling

## Architecture

### Frontend

React 19 + Inertia.js + Tailwind CSS + Recharts + Leaflet. Visual direction: "Sala de Datos" (IBM Plex Sans, Archivo, navy/institutional palette).

### Backend Modules

```
app/
├── Models/           # Eloquent models (UUID PKs, HasUuids trait)
├── Services/         # Business logic
├── Http/Controllers/ # Thin controllers → Services
├── Http/Resources/   # API Resources with field-level auth
└── Jobs/             # Import processing, report generation
```

### 12 Roles (from doc section 2.2)

R01 Superadmin, R02 Admin funcional, R03 Admin datos, R04 Directivo, R05 Estratega, R06 Analista electoral, R07 Gestor información, R08 Coordinador territorial, R09 Campo, R10 Consulta ejecutiva, R11 Auditor, R12 Solo reportes.
