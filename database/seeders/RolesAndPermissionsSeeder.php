<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions by module
        $permissions = [
            'auth' => [
                ['code' => 'AUTH_LOGIN', 'name' => 'Iniciar sesión'],
                ['code' => 'AUTH_MFA_MANAGE', 'name' => 'Gestionar MFA'],
            ],
            'persons' => [
                ['code' => 'PERSON_VIEW', 'name' => 'Ver personas'],
                ['code' => 'PERSON_CREATE', 'name' => 'Crear personas'],
                ['code' => 'PERSON_EDIT', 'name' => 'Editar personas'],
                ['code' => 'PERSON_DELETE', 'name' => 'Eliminar personas'],
                ['code' => 'PERSON_VIEW_CONTACTS', 'name' => 'Ver contactos de personas'],
                ['code' => 'PERSON_MERGE', 'name' => 'Fusionar personas duplicadas'],
            ],
            'territory' => [
                ['code' => 'TERRITORY_VIEW', 'name' => 'Ver territorios'],
                ['code' => 'TERRITORY_EDIT', 'name' => 'Editar territorios'],
                ['code' => 'TERRITORY_MANAGE_ALIASES', 'name' => 'Gestionar aliases territoriales'],
            ],
            'elections' => [
                ['code' => 'ELECTION_VIEW', 'name' => 'Ver elecciones y resultados'],
                ['code' => 'ELECTION_CREATE', 'name' => 'Crear eventos electorales'],
                ['code' => 'ELECTION_EDIT', 'name' => 'Editar eventos electorales'],
                ['code' => 'RESULT_EDIT', 'name' => 'Editar resultados electorales'],
            ],
            'organizations' => [
                ['code' => 'ORG_VIEW', 'name' => 'Ver organizaciones políticas'],
                ['code' => 'ORG_CREATE', 'name' => 'Crear organizaciones'],
                ['code' => 'ORG_EDIT', 'name' => 'Editar organizaciones'],
                ['code' => 'ORG_MANAGE_ALIASES', 'name' => 'Gestionar aliases de organizaciones'],
            ],
            'analytics' => [
                ['code' => 'ANALYTICS_VIEW', 'name' => 'Ver analítica y comparador'],
                ['code' => 'ANALYTICS_EXPORT', 'name' => 'Exportar datos analíticos'],
            ],
            'reports' => [
                ['code' => 'REPORT_VIEW', 'name' => 'Ver reportes'],
                ['code' => 'REPORT_GENERATE', 'name' => 'Generar reportes'],
                ['code' => 'REPORT_EXPORT', 'name' => 'Exportar reportes'],
            ],
            'documents' => [
                ['code' => 'DOCUMENT_VIEW', 'name' => 'Ver documentos'],
                ['code' => 'DOCUMENT_UPLOAD', 'name' => 'Subir documentos'],
                ['code' => 'DOCUMENT_EDIT', 'name' => 'Editar documentos'],
            ],
            'import' => [
                ['code' => 'IMPORT_UPLOAD', 'name' => 'Subir archivos para importación'],
                ['code' => 'IMPORT_MAP', 'name' => 'Configurar mapping de importación'],
                ['code' => 'IMPORT_APPROVE', 'name' => 'Aprobar importaciones'],
                ['code' => 'IMPORT_COMMIT', 'name' => 'Ejecutar commit de importación'],
                ['code' => 'IMPORT_ROLLBACK', 'name' => 'Revertir importaciones'],
                ['code' => 'QUALITY_VIEW', 'name' => 'Ver panel de calidad'],
            ],
            'offline' => [
                ['code' => 'OFFLINE_DOWNLOAD', 'name' => 'Descargar paquetes offline'],
            ],
            'admin' => [
                ['code' => 'USER_MANAGE', 'name' => 'Gestionar usuarios'],
                ['code' => 'ROLE_MANAGE', 'name' => 'Gestionar roles y permisos'],
                ['code' => 'CATALOG_MANAGE', 'name' => 'Gestionar catálogos'],
                ['code' => 'AUDIT_VIEW', 'name' => 'Ver registros de auditoría'],
                ['code' => 'SYSTEM_CONFIG', 'name' => 'Configuración del sistema'],
            ],
        ];

        $permissionModels = [];
        foreach ($permissions as $module => $perms) {
            foreach ($perms as $perm) {
                $permissionModels[$perm['code']] = Permission::create([
                    'code' => $perm['code'],
                    'module' => $module,
                    'name' => $perm['name'],
                ]);
            }
        }

        // Roles with their permission codes
        $roles = [
            [
                'code' => 'R01_SUPERADMIN',
                'name' => 'Superadministrador',
                'description' => 'Gobierno técnico de plataforma',
                'level' => 5,
                'permissions' => array_keys($permissionModels), // All
            ],
            [
                'code' => 'R02_ADMIN_FUNCIONAL',
                'name' => 'Administrador funcional',
                'description' => 'Gestiona catálogos, usuarios y configuración de negocio',
                'level' => 4,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'PERSON_CREATE', 'PERSON_EDIT', 'PERSON_VIEW_CONTACTS',
                    'TERRITORY_VIEW', 'TERRITORY_EDIT', 'TERRITORY_MANAGE_ALIASES',
                    'ELECTION_VIEW', 'ELECTION_CREATE', 'ELECTION_EDIT',
                    'ORG_VIEW', 'ORG_CREATE', 'ORG_EDIT', 'ORG_MANAGE_ALIASES',
                    'ANALYTICS_VIEW', 'REPORT_VIEW', 'REPORT_GENERATE', 'REPORT_EXPORT',
                    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_EDIT',
                    'QUALITY_VIEW', 'USER_MANAGE', 'ROLE_MANAGE', 'CATALOG_MANAGE', 'AUDIT_VIEW',
                ],
            ],
            [
                'code' => 'R03_ADMIN_DATOS',
                'name' => 'Administrador de datos',
                'description' => 'Importa, normaliza, resuelve identidad y revierte lotes',
                'level' => 4,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'PERSON_CREATE', 'PERSON_EDIT', 'PERSON_MERGE',
                    'TERRITORY_VIEW', 'TERRITORY_EDIT', 'TERRITORY_MANAGE_ALIASES',
                    'ELECTION_VIEW', 'ELECTION_EDIT', 'RESULT_EDIT',
                    'ORG_VIEW', 'ORG_EDIT', 'ORG_MANAGE_ALIASES',
                    'ANALYTICS_VIEW', 'REPORT_VIEW', 'REPORT_GENERATE',
                    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_EDIT',
                    'IMPORT_UPLOAD', 'IMPORT_MAP', 'IMPORT_APPROVE', 'IMPORT_COMMIT', 'IMPORT_ROLLBACK',
                    'QUALITY_VIEW', 'AUDIT_VIEW',
                ],
            ],
            [
                'code' => 'R04_DIRECTIVO',
                'name' => 'Directivo / Alta gerencia',
                'description' => 'Consulta ejecutiva y reportes',
                'level' => 2,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'TERRITORY_VIEW', 'ELECTION_VIEW',
                    'ORG_VIEW', 'ANALYTICS_VIEW', 'REPORT_VIEW', 'REPORT_GENERATE',
                    'DOCUMENT_VIEW', 'OFFLINE_DOWNLOAD',
                ],
            ],
            [
                'code' => 'R05_ESTRATEGA',
                'name' => 'Estratega / Analista político',
                'description' => 'Analítica avanzada, comparadores y reportes',
                'level' => 3,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'PERSON_EDIT',
                    'TERRITORY_VIEW', 'ELECTION_VIEW',
                    'ORG_VIEW', 'ANALYTICS_VIEW', 'ANALYTICS_EXPORT',
                    'REPORT_VIEW', 'REPORT_GENERATE', 'REPORT_EXPORT',
                    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_EDIT',
                    'OFFLINE_DOWNLOAD',
                ],
            ],
            [
                'code' => 'R06_ANALISTA_ELECTORAL',
                'name' => 'Analista electoral',
                'description' => 'Resultados, contiendas, calidad e indicadores',
                'level' => 3,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'TERRITORY_VIEW',
                    'ELECTION_VIEW', 'ELECTION_EDIT', 'RESULT_EDIT',
                    'ORG_VIEW', 'ANALYTICS_VIEW', 'ANALYTICS_EXPORT',
                    'REPORT_VIEW', 'REPORT_GENERATE',
                    'DOCUMENT_VIEW', 'IMPORT_UPLOAD', 'QUALITY_VIEW', 'AUDIT_VIEW',
                    'OFFLINE_DOWNLOAD',
                ],
            ],
            [
                'code' => 'R07_GESTOR_INFO',
                'name' => 'Gestor de información',
                'description' => 'Crea/actualiza perfiles, actividades y documentos',
                'level' => 2,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'PERSON_CREATE', 'PERSON_EDIT',
                    'TERRITORY_VIEW', 'ELECTION_VIEW',
                    'ORG_VIEW', 'ANALYTICS_VIEW',
                    'REPORT_VIEW', 'REPORT_GENERATE',
                    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_EDIT',
                    'OFFLINE_DOWNLOAD',
                ],
            ],
            [
                'code' => 'R08_COORDINADOR',
                'name' => 'Coordinador territorial',
                'description' => 'Opera sobre territorios asignados',
                'level' => 2,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'PERSON_EDIT',
                    'TERRITORY_VIEW', 'TERRITORY_EDIT',
                    'ELECTION_VIEW', 'ORG_VIEW', 'ANALYTICS_VIEW',
                    'REPORT_VIEW', 'REPORT_GENERATE',
                    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD',
                    'OFFLINE_DOWNLOAD',
                ],
            ],
            [
                'code' => 'R09_CAMPO',
                'name' => 'Usuario de campo',
                'description' => 'Consulta y captura limitada en PWA',
                'level' => 1,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'PERSON_EDIT',
                    'TERRITORY_VIEW', 'ELECTION_VIEW', 'ORG_VIEW',
                    'ANALYTICS_VIEW', 'REPORT_VIEW',
                    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD',
                    'OFFLINE_DOWNLOAD',
                ],
            ],
            [
                'code' => 'R10_CONSULTA',
                'name' => 'Consulta ejecutiva',
                'description' => 'Solo lectura simplificada',
                'level' => 1,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'TERRITORY_VIEW',
                    'ELECTION_VIEW', 'ORG_VIEW', 'ANALYTICS_VIEW',
                    'REPORT_VIEW', 'REPORT_GENERATE',
                    'DOCUMENT_VIEW', 'OFFLINE_DOWNLOAD',
                ],
            ],
            [
                'code' => 'R11_AUDITOR',
                'name' => 'Auditor',
                'description' => 'Solo lectura de auditoría y evidencias',
                'level' => 3,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'TERRITORY_VIEW',
                    'ELECTION_VIEW', 'ORG_VIEW', 'ANALYTICS_VIEW',
                    'REPORT_VIEW', 'DOCUMENT_VIEW',
                    'QUALITY_VIEW', 'AUDIT_VIEW',
                ],
            ],
            [
                'code' => 'R12_REPORTES',
                'name' => 'Reportes / solo lectura',
                'description' => 'Consulta y ejecuta reportes preautorizados',
                'level' => 1,
                'permissions' => [
                    'AUTH_LOGIN', 'PERSON_VIEW', 'TERRITORY_VIEW',
                    'ELECTION_VIEW', 'ORG_VIEW', 'ANALYTICS_VIEW',
                    'REPORT_VIEW', 'REPORT_GENERATE',
                    'DOCUMENT_VIEW', 'OFFLINE_DOWNLOAD',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $permCodes = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::create($roleData);

            $permIds = collect($permCodes)
                ->map(fn ($code) => $permissionModels[$code]->id)
                ->toArray();

            $role->permissions()->attach($permIds);
        }
    }
}
