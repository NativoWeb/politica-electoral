# Campañas electorales (Fase 4: núcleo)

Contiendas que un grupo político prepara (Gobernación 2027, Alcaldía de Bucaramanga 2027…). No confundir con el grupo
político (`campaigns`, modelo `Campaign`): aquí el modelo es `ElectoralCampaign`.

Todo está detrás de `feature:campanas` (ajuste `modulos.campanas_electorales` del Centro de configuración + beta users
del `.env`): apagado, **todas** las rutas responden 404, también al superadmin, y la casilla «Es líder» no se muestra
ni se guarda.

## Tablas

| Tabla | Qué guarda |
|-------|-----------|
| `electoral_campaigns` | Una campaña por grupo dueño (`campaign_id`, lo pone el servidor). Cargo, año, candidato (ficha electoral o texto), partido, alcance (`department`, `municipality`, `comuna`), factores de sugerencia y estado `active`/`archived`. Nombre único (sin mayúsculas) entre las activas del grupo. No se borran. |
| `electoral_campaign_territories` | Las unidades del alcance: el departamento, los municipios o la comuna/corregimiento. |
| `electoral_campaign_goals` | Meta por unidad (municipio, comuna o corregimiento): `votos_meta`, `lideres_meta`, `votantes_meta` (enteros ≥ 0 o null). Un municipio lleva meta propia **o** metas en sus comunas, nunca ambas. |
| `lideres.es_lider` | Casilla manual «Es líder (cuenta para las metas)». Nadie se marca solo. Independiente del tipo `directorio` y del cargo «Líder». |

Reversa: `php artisan migrate:rollback --step=2` (quita las tres tablas y la columna `es_lider` con sus índices).

## Acceso (`ElectoralCampaignAccess`)

- **Ver**: el grupo dueño está en `CurrentCampaign::visibleIds()` (respeta el selector del superadmin). Fuera de eso → 404.
- **Editar**: además `manage.campanas`, grupo propio (`isOwn`), usuario sin `territory_scope` y campaña activa
  (archivada → 422 al editar o cargar metas). Un subgrupo se ve en solo lectura → 403 al escribir.
- **Usuario con territorio**: solo lectura; ve las campañas que cruzan sus municipios y solo las filas de sus
  municipios (y sus comunas).
- **Grupo sin resultados electorales** (`seesElectoralResults()` falso): ninguna fila trae la clave `sugerencia`, el
  buscador de candidatos responde 403 y no puede elegir `candidate_person_id` (422).

## Territorio por cargo

| cargo | scope_level | territorio_ids |
|-------|-------------|----------------|
| `alcaldia`, `concejo` | `municipality` | exactamente 1 municipio de Santander |
| `jal` | `comuna` | exactamente 1 comuna o corregimiento |
| `gobernacion`, `asamblea` | `department` | vacío (el servidor pone Santander) |
| `senado`, `camara` | `department` o `municipality` | vacío con `department`; 1 o más municipios con `municipality` |

Filas de la tabla de metas: con `department`, los 87 municipios de Santander; con `municipality`, los municipios
elegidos; en ambos casos, debajo de cada municipio, sus comunas y corregimientos (hoy solo Bucaramanga: 17 + 3).
Con `comuna`, solo esa comuna.

## Sugerencia de metas

Al vuelo, nunca guardada ni impuesta: el campo de meta queda vacío hasta que la persona escribe o pulsa «Usar sugerido».

1. Elección comparable: la más reciente con contienda del mismo cargo (Alcaldía, Concejo y Asamblea 2023; Senado y
   Cámara 2026). Gobernación: el total del departamento sale de Gobernación 2023; por municipio no hay resultados de
   Gobernación, así que se usan los votos del partido a Asamblea 2023 y el texto lo dice. JAL y comunas: «Sin
   sugerencia: los resultados solo existen por municipio».
2. Base: los votos del candidato en esa contienda y municipio si su ficha electoral tiene esa candidatura; si no, los
   del partido. Votos del partido por corporación (igual que las pantallas):
   - Asamblea: votos solo por la lista + votos de todos sus candidatos; el texto dice «a Asamblea 2023 (lista y
     candidatos)». Ojo: `/asamblea/{id}` solo lista los 30
     candidatos con más votos, así que su suma en pantalla puede quedar por debajo (Girón 2023: 5.548 en pantalla,
     6.769 del partido).
   - Senado y Cámara: el «TOTAL» del partido cargado del Excel (ya incluye a sus candidatos); sin ese total, la suma
     de sus candidatos.
   - Alcaldía, Concejo y Gobernación: suma de sus candidatos (aval principal `is_primary`), como `/concejo/{id}`.
3. `votos = round(base × factor_votos)`; `votantes = round(meta_votos_o_sugerida × factor_registro)`;
   `lideres = ceil(meta_votos_o_sugerida / votos_por_lider)`.

Factores por campaña (por defecto y límites en `config/campanas_electorales.php`): `factor_votos` 1,10 (0,50–3,00),
`factor_registro` 1,00 (0,10–3,00), `votos_por_lider` 50 (5–1.000).

## Avance

Solo fichas del grupo dueño (sin subgrupos), activas y no fallecidas: **votantes** = fichas en el territorio
(municipio por `geographic_unit_id`, comuna por `comuna_id`); **líderes** = las mismas con `es_lider`. En un municipio
con comunas se añade «Sin comuna asignada: N fichas».

## Rutas

Todas con `auth` + `feature:campanas`. JSON: `{success, message, data}`; errores de validación 422
`{message, errors: {campo: [texto]}}`; reglas de negocio 422 (archivada, ya archivada/activa, nombre repetido al
reactivar) `{success: false, message}`; 403 y 404 `{message}` en JSON y la página de error en una visita normal (sin
permiso de la ruta, una visita normal vuelve a `/`).

| Método y ruta | Permiso | Qué hace |
|---------------|---------|----------|
| `GET /campanas-electorales` (`?archivadas=1`) | view o manage | Lista (Inertia `CampanasElectorales/Lista`; JSON con `Accept: application/json`) |
| `GET /campanas-electorales/nueva` | manage | Formulario (Inertia `CampanasElectorales/Formulario`) |
| `GET /campanas-electorales/opciones/candidatos?q=` | manage | Buscador de candidatos (403 sin resultados electorales) |
| `POST /campanas-electorales` | manage + `grupo.propio` | Crear → 201 |
| `GET /campanas-electorales/{id}` | view o manage | Detalle (Inertia `CampanasElectorales/Detalle`; JSON igual en `data`) |
| `GET /campanas-electorales/{id}/editar` | manage + editar | Formulario con la campaña |
| `PUT /campanas-electorales/{id}` | manage + editar | Guardar cambios |
| `POST /campanas-electorales/{id}/archivar` | manage + grupo propio | Archivar |
| `POST /campanas-electorales/{id}/reactivar` | manage + grupo propio | Reactivar (422 si ya hay una activa con ese nombre) |
| `PUT /campanas-electorales/{id}/metas` | manage + editar | Lote de metas (≤ 300 filas, todo o nada) |
| `GET /campanas-electorales/{id}/metas/plantilla` | manage + grupo propio | Plantilla .xlsx |
| `POST /campanas-electorales/{id}/metas/importar` | manage + editar | Cargar el .xlsx (todo o nada) |
| `GET /campanas-electorales/{id}/historial` | view o manage | Cambios (audit_logs), los 100 más recientes |

`campaign_id` del cuerpo se ignora siempre.

## Contrato para el frontend

### Objeto `campana` (tarjeta de la Lista, cabecera del Detalle)

```json
{
  "id": "uuid",
  "name": "Gobernación 2027",
  "cargo": "gobernacion",
  "cargo_label": "Gobernación",
  "election_year": 2027,
  "election_date": "2027-10-31",
  "status": "active",
  "archived_at": null,
  "candidato": {"person_id": "uuid|null", "nombre": "Nombre del candidato|null"},
  "partido": {"id": "uuid", "nombre": "Centro Democrático"},
  "scope_level": "department",
  "territorio": {"resumen": "87 municipios", "unidades": [{"id": "uuid", "nombre": "Santander", "tipo": "department"}]},
  "metas": {"cargadas": 12, "total": 87},
  "factores": {"factor_votos": 1.1, "factor_registro": 1.0, "votos_por_lider": 50},
  "grupo": {"id": "uuid", "nombre": "Centro Democrático", "propio": true},
  "solo_lectura": false,
  "created_at": "2026-11-23T10:00:00-05:00",
  "updated_at": "2026-11-23T10:00:00-05:00"
}
```

`partido` y `candidato.nombre` pueden ser null. `metas.total` = territorios de primer nivel (municipios, o 1 con
alcance de comuna); `metas.cargadas` = cuántos tienen alguna meta (propia o en sus comunas).

### Lista — props de `CampanasElectorales/Lista`

```json
{
  "campanas": ["campana…"],
  "filtros": {"archivadas": false},
  "can": {"manage": true},
  "createBlocked": "Estás viendo «Alejandro Cárdenas». Para crear, incluye tu grupo… | null",
  "truncated": false
}
```

JSON (`GET` con `Accept: application/json`): `{success: true, data: {campanas, filtros, can, createBlocked, truncated}}`.
Textos de pantalla: vacío con manage «Aún no hay campañas. Crea la primera con "Nueva campaña".», sin manage «Aún no
hay campañas.»; subgrupo «Grupo político: X · Solo lectura» (`grupo.propio = false`).

### Formulario — props de `CampanasElectorales/Formulario`

```json
{
  "campana": null,
  "opciones": {
    "cargos": [{"value": "gobernacion", "label": "Gobernación", "scopes": ["department"]},
               {"value": "senado", "label": "Senado", "scopes": ["department", "municipality"]}],
    "anio": {"min": 2024, "max": 2040, "defecto": 2027},
    "departamento": {"id": "uuid", "nombre": "Santander"},
    "municipios": [{"id": "uuid", "nombre": "Bucaramanga", "provincia": "Soto"}],
    "comunas": [{"id": "uuid", "nombre": "Comuna 1 Norte", "tipo": "comuna", "municipio_id": "uuid", "municipio": "Bucaramanga"}],
    "partidos": [{"id": "uuid", "nombre": "Centro Democrático"}],  // solo los que avalaron una candidatura, uno por nombre
    "factores": {"factor_votos": {"defecto": 1.1, "min": 0.5, "max": 3}, "factor_registro": {"defecto": 1, "min": 0.1, "max": 3},
                 "votos_por_lider": {"defecto": 50, "min": 5, "max": 1000}}
  },
  "puedeBuscarCandidatos": true,
  "createBlocked": null
}
```

En edición `campana` trae además los campos del formulario: `{id, name, cargo, election_year, election_date,
candidate_person_id, candidate_name, candidato_nombre, political_organization_id, scope_level, territorio_ids,
factor_votos, factor_registro, votos_por_lider, status}`.

**Cuerpo de `POST` y `PUT /campanas-electorales/{id}`**

```json
{
  "name": "Gobernación 2027", "cargo": "gobernacion", "election_year": 2027, "election_date": null,
  "candidate_person_id": null, "candidate_name": "Nombre escrito a mano",
  "political_organization_id": "uuid|null", "scope_level": "department", "territorio_ids": [],
  "factor_votos": 1.1, "factor_registro": 1.0, "votos_por_lider": 50
}
```

Respuesta: 201 (crear) / 200 `{success: true, message: "Campaña creada." | "Cambios guardados.", data: {campana}}`.
Errores 422 bajo cada campo: `name` «Escribe el nombre» / «Ya tienes una campaña activa con ese nombre»; `cargo`
«Elige el cargo»; `territorio_ids` «Elige un municipio» / «Para Alcaldía elige un solo municipio» / «Elige una comuna o
corregimiento» (también si llega un municipio) / «Ese territorio no está en Santander»; `election_year`; `election_date` «La fecha debe ser del año de
la elección»; `candidate_person_id`; `political_organization_id`; factores; `grupo` (selector del superadmin,
`CurrentCampaign::CREAR_EN_OTRO_GRUPO`); `territorio` «Hay metas en territorios que quedarían fuera…» al reducir el
territorio de una campaña con metas.

**Buscador** `GET /campanas-electorales/opciones/candidatos?q=ana` (mínimo 2 letras, 20 resultados):
`{success: true, data: [{id, nombre, candidaturas: [{cargo: "Alcaldía", anio: 2023, territorio: "Bucaramanga", partido: "…"}]}]}`.

### Detalle — props de `CampanasElectorales/Detalle` (y `data` del JSON de `GET /{id}`)

```json
{
  "campana": "campana…",
  "can": {"edit": true, "archive": true, "reactivate": false, "plantilla": true},
  "soloLectura": null,
  "sugerencias": {"disponible": true, "aviso": null, "comparable": "Gobernación 2023"},
  "filas": [
    {
      "id": "uuid de la unidad", "nombre": "Bucaramanga", "tipo": "municipality", "codigo": "68001",
      "padre_id": null, "comunas": 20,
      "meta": {"votos_meta": 1000, "lideres_meta": null, "votantes_meta": null},
      "editable": true,
      "suma_comunas": null,
      "sugerencia": {"votos": 1100, "lideres": 22, "votantes": 1100, "base": 1000,
                     "texto": "Sugerido: 1.100 (1.000 votos de Centro Democrático a Asamblea 2023 × 1,10) (no hay Gobernación por municipio)"},
      "sin_sugerencia": null,
      "avance": {"votantes": 38821, "lideres": 0},
      "sin_comuna": {"votantes": 12, "lideres": 0}
    },
    {
      "id": "uuid", "nombre": "Comuna 1 Norte", "tipo": "comuna", "codigo": "68001-C01", "padre_id": "uuid de Bucaramanga",
      "comunas": 0, "meta": null, "editable": true, "suma_comunas": null,
      "sugerencia": null, "sin_sugerencia": "Sin sugerencia: los resultados solo existen por municipio",
      "avance": {"votantes": 3, "lideres": 0}, "sin_comuna": null
    }
  ],
  "totales": {"votos_meta": 1000, "lideres_meta": 0, "votantes_meta": 0, "avance": {"votantes": 38833, "lideres": 0},
              "sugerencia": {"votos": 143696, "base": 130633, "texto": "Sugerido: 143.696 (130.633 votos de … × 1,10)"}}
}
```

- `meta`: null si no hay meta guardada para la unidad.
- `editable` false en un municipio cuyas comunas tienen metas: muestra `suma_comunas` y no se edita.
- `sugerencia`: **la clave no existe** cuando `sugerencias.disponible` es false (grupo sin resultados electorales; el
  `aviso` dice «Tu grupo no tiene acceso a resultados electorales: escribe las metas a mano»). Con acceso, es null
  cuando no hay sugerencia y `sin_sugerencia` dice por qué. `lideres` y `votantes` se calculan con la meta de votos
  escrita si la hay, si no con los votos sugeridos.
- `sin_comuna`: solo en municipios que tienen comunas (fichas sin comuna asignada).
- `totales`: suma de los municipios (meta propia o suma de comunas); `totales.sugerencia` igual que `sugerencia`.
- `soloLectura`: null o `{"motivo": "subgrupo" | "territorio" | "archivada" | "permiso", "texto": "Solo lectura: …"}`
  (`subgrupo` = la campaña es de otro grupo político, también el superadmin fuera del suyo).
- `can.plantilla`: manage + grupo propio + sin territorio (también en una archivada); `can.archive` /
  `can.reactivate` según el estado.

### Lote de metas — `PUT /campanas-electorales/{id}/metas`

```json
{"metas": [{"geographic_unit_id": "uuid", "votos_meta": 1200, "lideres_meta": null, "votantes_meta": 1300}]}
```

Una clave ausente no cambia ese valor; `null` lo borra (si las tres quedan vacías se quita la meta). Máximo 300
filas, todo o nada. 200 → `{success: true, message: "Metas guardadas", data: {filas, totales, campana}}`.
422 → `errors["metas.3.votos_meta"] = ["Escribe un número entero, 0 o más"]` (también para "abc", -5 y 1,5; más de 10.000.000 → «Escribe un número entero entre 0 y 10.000.000.»); `errors.metas = ["Bucaramanga tiene metas por comuna: …"]` si el municipio y sus comunas quedarían con
metas a la vez; `errors["metas.0.geographic_unit_id"]` si la unidad no es de la campaña; 422 si la campaña está
archivada.

### Plantilla y carga de Excel

- `GET /{id}/metas/plantilla` → `metas-<campaña>.xlsx`, una fila por territorio. Columnas: `CÓDIGO` (DANE del
  municipio o `68001-C01` de la comuna), `TERRITORIO`, `MUNICIPIO`, `VOTOS SUGERIDOS`, `LÍDERES SUGERIDOS`,
  `VOTANTES SUGERIDOS` (vacías sin resultados electorales), `VOTOS META`, `LÍDERES META`, `VOTANTES META` (vacías).
- `POST /{id}/metas/importar` (multipart, campo `archivo`, solo .xlsx, ≤ 2 MB). Celda vacía = sin cambio. Todo o nada.
  200 → `{success: true, message: "Se cargaron 37 metas", data: {cargadas: 37, filas, totales, campana}}`.
  422 → `{success: false, message: "No se cargó nada. Corrige estas filas:", errors: {archivo: ["Fila 4: \"abc\" no es un número", …]}}`.

### Historial — `GET /{id}/historial`

```json
{"success": true, "data": [
  {"id": "uuid", "fecha": "2026-11-23T10:00:00-05:00", "usuario": "Ana", "accion": "electoral_campaign.goals.updated",
   "accion_label": "Metas guardadas",
   "cambios": [{"territorio": "Bucaramanga", "campo": "votos_meta", "campo_label": "Votos meta", "antes": null, "despues": 1200}]}
]}
```

Acciones: `electoral_campaign.created`, `.updated`, `.archived`, `.reactivated`, `.goals.updated`, `.goals.imported`.

### «Es líder» en las fichas (solo con el módulo encendido)

- `POST /votantes`, `PUT /votantes/{id}`, `POST /mapa-politico/crear-lider`, `PUT /mapa-politico/persona/{id}`: aceptan
  `es_lider` (boolean). Se cambia **solo si la clave viene** con true/false; ausente o null no toca el valor. Con el
  módulo apagado se ignora.
- `GET /votantes/{id}` y `GET /mapa-politico/persona/{id}` devuelven `es_lider` solo con el módulo encendido.
- Prop compartida `features.esLider` (todas las páginas): true si el módulo está encendido para el usuario, sin exigir
  permiso de campañas; los formularios de fichas (CrearLiderModal, PersonaPanel, Votantes) muestran la casilla con ella.
- Filtro de Votantes: `?es_lider=1`, una tarjeta más como «Gran elector» (prop `filters.es_lider`; contador
  `counters.es_lider` sobre la misma base que las otras tarjetas, sin contar el filtro de tarjeta). La prop
  `campanasActivas` (boolean) dice si mostrar la casilla, la tarjeta y el filtro; con el módulo apagado no llega
  `counters.es_lider` ni `es_lider` en las filas.
- Reportes (Personas): filtro «Marcados como líder»; en la columna Marcas de cada persona, «Es líder».
- Unificar fichas: si cualquiera de las dos es líder, la que queda es líder.
