<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// SQLite fixtures for the electoral import tables (offices, contests, persons, candidacies, results, parties) plus
// lideres, nexos_familiares and audit_logs, with the columns the importers and the data fixes write.
trait BuildsElectoralSchema
{
    use WithCampaignColumns;

    protected function buildElectoralSchema(): void
    {
        $tables = [
            'geographic_units' => fn (Blueprint $t) => [$t->string('type'), $t->string('canonical_name'), $t->uuid('parent_id')->nullable()],
            'geographic_aliases' => fn (Blueprint $t) => [$t->uuid('geographic_unit_id'), $t->string('raw_alias')->nullable(), $t->string('normalized_alias'), $t->uuid('source_id')->nullable()],
            'offices' => fn (Blueprint $t) => [$t->string('name')],
            'electoral_events' => fn (Blueprint $t) => [
                $t->string('name'), $t->string('event_type')->nullable(), $t->date('election_date')->nullable(),
                $t->string('political_period')->nullable(), $t->string('authority')->nullable(), $t->string('scope')->nullable(), $t->string('status')->nullable(),
            ],
            'contests' => fn (Blueprint $t) => [
                $t->uuid('electoral_event_id'), $t->uuid('office_id')->nullable(), $t->uuid('geographic_unit_id')->nullable(),
                $t->string('name')->nullable(), $t->integer('seats')->nullable(), $t->string('list_type')->nullable(), $t->string('status')->nullable(),
            ],
            'persons' => fn (Blueprint $t) => [
                $t->string('first_name')->nullable(), $t->string('last_name')->nullable(), $t->string('full_name'), $t->string('normalized_name'),
                $t->string('identity_status')->nullable(), $t->string('data_classification')->nullable(), $t->uuid('merged_into_id')->nullable(), $t->softDeletes(),
            ],
            'person_aliases' => fn (Blueprint $t) => [
                $t->uuid('person_id'), $t->string('alias_name'), $t->string('normalized_alias'), $t->string('alias_type')->nullable(), $t->uuid('source_id')->nullable(),
            ],
            'person_contact_points' => fn (Blueprint $t) => [
                $t->uuid('person_id'), $t->string('type')->nullable(), $t->string('value_raw')->nullable(), $t->string('label')->nullable(),
                $t->string('data_classification')->nullable(), $t->uuid('source_id')->nullable(),
            ],
            'candidacies' => fn (Blueprint $t) => [$t->uuid('person_id')->nullable(), $t->uuid('contest_id'), $t->string('outcome')->nullable(), $t->string('status')->nullable()],
            'electoral_results' => fn (Blueprint $t) => [
                $t->uuid('contest_id')->nullable(), $t->uuid('candidacy_id')->nullable(), $t->uuid('organization_id')->nullable(), $t->uuid('geographic_unit_id')->nullable(),
                $t->string('metric_type')->nullable(), $t->string('grain')->nullable(), $t->integer('value')->nullable(), $t->string('raw_value')->nullable(),
                $t->uuid('source_id')->nullable(), $t->string('status')->nullable(),
            ],
            'political_organizations' => fn (Blueprint $t) => [
                $t->string('type')->nullable(), $t->string('canonical_name'), $t->string('acronym')->nullable(), $t->string('status')->nullable(), $t->uuid('merged_into_id')->nullable(),
            ],
            'organization_aliases' => fn (Blueprint $t) => [$t->uuid('organization_id'), $t->string('raw_alias')->nullable(), $t->string('normalized_alias'), $t->uuid('source_id')->nullable()],
            'candidacy_endorsements' => fn (Blueprint $t) => [
                $t->uuid('candidacy_id'), $t->uuid('organization_id')->nullable(), $t->string('endorsement_type')->nullable(), $t->boolean('is_primary')->default(true), $t->uuid('source_id')->nullable(),
            ],
            'office_tenures' => fn (Blueprint $t) => [
                $t->uuid('person_id'), $t->uuid('office_id')->nullable(), $t->uuid('geographic_unit_id')->nullable(), $t->uuid('candidacy_id')->nullable(),
                $t->date('start_date')->nullable(), $t->date('end_date')->nullable(), $t->string('status')->nullable(), $t->uuid('source_id')->nullable(),
            ],
            'lideres' => fn (Blueprint $t) => [
                $t->string('nombre'), $t->string('provincia')->nullable(), $t->string('municipio'), $t->string('cargo')->nullable(),
                $t->string('tipo')->default('directorio'), $t->string('partido')->nullable(), $t->text('observacion')->nullable(),
                $t->uuid('geographic_unit_id')->nullable(), $t->string('cedula')->nullable(), $t->boolean('is_active')->default(true),
            ],
            'nexos_familiares' => fn (Blueprint $t) => [$t->uuid('person_id'), $t->string('nombre'), $t->string('parentesco')->nullable()],
        ];

        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->uuid('id')->primary();
                $columns($t);
                $t->timestamps();
            });
        }

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable();
            $t->string('action', 80);
            $t->string('entity_type', 80)->nullable();
            $t->uuid('entity_id')->nullable();
            $t->json('metadata')->nullable();
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->string('correlation_id', 50)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        $this->addCampaignColumns();
    }

    protected function row(string $table, array $data): string
    {
        $id = $data['id'] ?? (string) Str::uuid();
        DB::table($table)->insert(['id' => $id, 'created_at' => now(), 'updated_at' => now()] + $data);

        return $id;
    }
}
