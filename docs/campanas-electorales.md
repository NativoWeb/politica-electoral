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
      "avance_por_origen": {"auto": 30, "texto": 4, "direccion": 0, "puesto": 2200, "manual": 5},
      "sin_comuna": {"votantes": 12, "lideres": 0}
    },
    {
      "id": "uuid", "nombre": "Comuna 1 Norte", "tipo": "comuna", "codigo": "68001-C01", "padre_id": "uuid de Bucaramanga",
      "comunas": 0, "meta": null, "editable": true, "suma_comunas": null,
      "sugerencia": null, "sin_sugerencia": "Sin sugerencia: los resultados solo existen por municipio",
      "avance": {"votantes": 3, "lideres": 0},
      "avance_por_origen": {"auto": 1, "texto": 0, "direccion": 0, "puesto": 2, "manual": 0}, "sin_comuna": null
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
- `sin_comuna`: solo en municipios que tienen comunas (fichas sin comuna asignada). Las comunas más `sin_comuna`
  suman el `avance.votantes` del municipio.
- `avance_por_origen` (cifras de 1 a 4 → `null`, k = 5 como el Tablero): los votantes de la unidad que tienen comuna, por cómo se encontró (`auto` = barrio, `texto` =
  «COMUNA N» escrito, `direccion` = fase B, `puesto` = puesto de votación, `manual`; docs/geografia.md). La pantalla
  muestra «incluye N por puesto de votación».
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


# Fase 5: Tablero de la campaña

Sin migraciones; detrás del mismo `feature:campanas`. Servicio `App\Services\CampanasElectorales\TableroCampana`
(reutiliza `AvanceCampana::calcular` + una consulta agrupada por día). Constantes en `config/campanas_electorales.php`,
clave `tablero` (no en el Centro de configuración).

## ADR-F5 — decisiones

| # | Decisión | Por qué |
|---|----------|---------|
| F5-1 | El tablero se calcula al vuelo (sin tablas nuevas) con caché de 5 min. | 122.848 fichas: la consulta agrupada tarda ~190 ms; no hace falta materializar. |
| F5-2 | Clave de caché `campanas:tablero:v1:{id}:{huella}:{sha1(municipios permitidos o "todos")}`. La huella es sha1 de `updated_at`, estado, fecha, factores y alcance de la campaña **más el contenido de sus metas** (unidad y los tres valores). | Con solo `max(updated_at)`, borrar una meta o dos cambios en el mismo segundo reusarían cifras viejas; leer las metas es una consulta de pocas filas. El acceso (`ElectoralCampaignAccess::find`) se valida **antes** de leer la caché, y lo que depende de quién mira (`campana`, `soloLectura`, `can`) se arma fuera de ella. Las fichas nuevas aparecen a los 5 min o con `?actualizar=1`. |
| F5-3 | Nunca se usan las sugerencias: la meta es la escrita o la calculada desde la meta de votos. Ninguna respuesta del tablero trae la clave `sugerencia`. | El tablero mide contra lo que la campaña se propuso. |
| F5-4 | k = 5: las cifras de 1 a 4 salen `null` («menos de 5») en el desglose por origen de una comuna, en **todas** las semanas (de la campaña y de un territorio, también `antes`) y en esas celdas de los archivos. Los registrados y líderes contra la meta por territorio son exactos. | No señalar a pocas personas; los totales contra la meta son el dato de trabajo. La evolución de la campaña también lleva k para que el Excel sea igual al JSON, y porque una JAL es un solo territorio. |
| F5-5 | Totales: `registrados` cuenta todas las fichas de los territorios visibles; `pct` e `indice` del total se calculan solo con los territorios que tienen esa meta (`registrados_con_meta`), con totales (no promedio). `falta` es la **suma de lo que falta en cada territorio** (un territorio que pasó su meta no cubre a otro), y el ritmo usa esa suma (QA 2026-11-25, BUG-08). | Si no, las fichas de los territorios sin meta inflarían el avance (122.000 fichas contra 1.000 de meta), y los excedentes taparían lo que falta («Faltan 36» cuando por territorio faltan 68). |
| F5-9 | Un municipio con metas en sus comunas mide contra esa meta **solo las fichas de las comunas que tienen esa meta**; las fichas sin comuna y las de comunas sin meta van aparte (`sin_comuna`, `otras_comunas`). La tarjeta total sigue la misma regla (QA 2026-11-25, BUG-02). | Con el total del municipio, la Alcaldía salía «Meta cumplida» con la Comuna 3 en 0 de 30. |
| F5-10 | k = 5 también en el `avance_por_origen` del Detalle de F4 («incluye N por puesto de votación»), para que Detalle y Tablero digan lo mismo (BUG-06). | Mismo criterio de privacidad en las dos pantallas. |
| F5-6 | Semanas de lunes a domingo en hora de Bogotá (UTC−5), desde el lunes 2025-09-22. Lo anterior va a `antes`. | La carga de Electo fue el 2025-09-18. |
| F5-7 | El mapa usa los GeoJSON existentes; su permiso pasa a `view.mapa`, `view.campanas` o `manage.campanas`. Los colores se ponen en el cliente por `id`. | Ya tienen caché de 24 h + ETag, y nginx de producción comprime `application/geo+json` (`/etc/nginx/conf.d/gzip-electoral.conf`, revisado el 2026-11-25). |
| F5-8 | Exportes por `DescargaGuard` (acciones `campanas.tablero.excel` / `campanas.tablero.pdf`): auditoría antes del primer byte, 5 por minuto, una a la vez. No llevan datos personales, así que no exigen `export.datos_personales`. | El mismo control que el resto de descargas. |

## Fórmulas

- **Universo**: las fichas de F4 (grupo dueño sin subgrupos, `is_active`, no fallecidas), solo de los territorios que
  el usuario ve.
- **Meta efectiva**: `MV` = `votantes_meta`; si no hay, `round(votos_meta × factor_registro)` con
  `meta_calculada: true`; si tampoco, null. `ML` = `lideres_meta`; si no, `ceil(votos_meta / votos_por_lider)` con
  `meta_calculada: true`; si no, null. Un municipio con metas en sus comunas: su meta es la suma de las metas efectivas
  de las comunas; contra ella cuentan solo las fichas de las comunas con esa meta, y su `falta` es la suma de lo que
  falta en cada una de esas comunas. Las fichas sin comuna y las de comunas sin esa meta salen aparte en `sin_comuna` y
  `otras_comunas`.
- `pct = registrados / meta` (sin tope, 4 decimales; `null` si la meta es null o 0); `falta = max(meta − registrados, 0)`.
  Una meta de 0 cuenta como cumplida en el índice.
- **Índice** (0–100, entero): con las dos metas `round(100 × (0,6·min(pV,1) + 0,4·min(pL,1)))`; con una sola
  `round(100 × min(p,1))`; sin metas `null`.
- **Estado**: `sin_meta` (MV y ML null) → `insuficiente` (comuna o corregimiento cuyo municipio tiene menos del 60 % de
  sus fichas con comuna; un municipio nunca lo es; un municipio sin fichas no vuelve insuficientes a sus comunas) → `ok`.
- **Tramos** (0–4) por medida: `0` = 0–24, `1` = 25–49, `2` = 50–74, `3` = 75–99, `4` = 100 o más (el % entero hacia
  abajo); `null` si la medida es null.
- **Ranking**: entre los territorios de la capa del mapa (los municipios; las comunas en una campaña de un solo
  municipio con comunas) con estado `ok`, índice, y `MV ≥ 20` o `ML ≥ 3`. «Van mejor»: los 5 de mayor índice;
  «Necesitan apoyo»: los 5 de menor. Empate: más `falta` de votantes primero, luego el nombre. Con 10 o menos, una sola
  lista (`una_lista: true`, todos en `mejores` y `por_apoyar` vacío).

## Rutas del tablero

Todas con `auth` + `feature:campanas` + `permission:view.campanas|manage.campanas` + `ElectoralCampaignAccess::find`
(404 si el grupo no es visible o la campaña no cruza el territorio del usuario). Sin permiso: 403 `{message}` en JSON,
una visita normal vuelve a `/`.

| Método y ruta | Qué hace |
|---------------|----------|
| `GET /campanas-electorales/{id}/tablero` (`?actualizar=1`) | Inertia `CampanasElectorales/Tablero` (props = `data`) o JSON `{success: true, data}`. `?actualizar=1` salta la caché: 6 por minuto por usuario, la 7.ª → 429 `{success: false, message}`. |
| `GET /campanas-electorales/{id}/tablero/territorio/{unitId}` | JSON `{success: true, data}` con el detalle de un territorio (404 si no es de la campaña o el usuario no lo ve). |
| `GET /campanas-electorales/{id}/tablero/exportar.xlsx` | Excel (hojas Resumen, Territorios, Evolución). |
| `GET /campanas-electorales/{id}/tablero/exportar.pdf` | PDF (encabezado, 3 tarjetas, ranking, tabla con estado; sin mapa). |
| `GET /api/geo/municipios.geojson`, `GET /api/geo/comunas.geojson?municipio=68001` | Los de siempre; ahora con `view.mapa`, `view.campanas` o `manage.campanas`. |

Exportes: 429 `{message}` con «Pediste varias descargas seguidas…» (la 6.ª en un minuto) o «Ya estamos preparando una
descarga…» (dos a la vez, `motivo: en_curso`). Son GET para que funcionen en la PWA del iPhone.

## Contrato `data` del tablero

```json
{
  "campana": "objeto campana (igual que en Lista y Detalle)",
  "soloLectura": null,
  "can": {"edit": true},
  "generado_at": "2026-11-25T10:00:00-05:00",
  "formula": {"texto": "El índice va de 0 a 100: el 60 % sale del avance en votantes registrados y el 40 % del avance en líderes. Pasar la meta no suma más.",
              "peso_votantes": 0.6, "peso_lideres": 0.4},
  "umbrales": {"k": 5, "cobertura_comuna_min": 0.6, "ranking_min_votantes": 20, "ranking_min_lideres": 3, "pocos_registros": 50},
  "totales": {
    "votantes": {"registrados": 270, "registrados_con_meta": 270, "meta": 300, "meta_calculada": true, "pct": 0.9, "falta": 30},
    "lideres":  {"registrados": 5, "registrados_con_meta": 5, "meta": 14, "meta_calculada": true, "pct": 0.3571, "falta": 9},
    "indice": 68,
    "territorios": {"total": 87, "con_meta": 2, "sin_meta": 85, "insuficientes": 0}
  },
  "territorios": [
    {
      "id": "uuid", "nombre": "Girón", "tipo": "municipality", "padre_id": null, "codigo": "68307",
      "votantes": {"registrados": 50, "meta": 100, "meta_calculada": false, "pct": 0.5, "falta": 50},
      "lideres":  {"registrados": 5, "meta": 10, "meta_calculada": false, "pct": 0.5, "falta": 5},
      "indice": 50,
      "tramo": {"indice": 2, "votantes": 2, "lideres": 2},
      "estado": "ok", "motivo": null
    },
    {
      "id": "uuid", "nombre": "Bucaramanga", "tipo": "municipality", "padre_id": null, "codigo": "68001",
      "votantes": {"registrados": 30, "meta": 100, "meta_calculada": false, "pct": 0.3, "falta": 70},
      "lideres": {"registrados": 0, "meta": null, "meta_calculada": false, "pct": null, "falta": null},
      "indice": 30, "tramo": {"indice": 1, "votantes": 1, "lideres": null},
      "estado": "ok", "motivo": null,
      "cobertura_comuna": {"con": 50, "total": 100},
      "sin_comuna": {"votantes": 50, "lideres": null},
      "otras_comunas": {"votantes": 20, "lideres": null}
    },
    {
      "id": "uuid", "nombre": "Comuna 1 - Norte", "tipo": "comuna", "padre_id": "uuid de Bucaramanga", "codigo": "68001-C01",
      "votantes": {"registrados": 40, "meta": 100, "meta_calculada": false, "pct": 0.4, "falta": 60},
      "lideres": {"registrados": 0, "meta": null, "meta_calculada": false, "pct": null, "falta": null},
      "indice": 40, "tramo": {"indice": 1, "votantes": 1, "lideres": null},
      "estado": "insuficiente", "motivo": "Solo 50 de 100 fichas de Bucaramanga (50 %) tienen comuna",
      "por_origen": {"auto": 30, "texto": null, "direccion": 0, "puesto": 7, "manual": null}
    }
  ],
  "ranking": {"mejores": ["uuid"], "por_apoyar": ["uuid"], "una_lista": false,
              "nota": "Las metas muy pequeñas no entran al ranking"},
  "evolucion": {
    "desde": "2025-09-22", "semanas_defecto": 26,
    "antes": 90394,
    "antes_texto": "90.394 ya estaban registrados antes del 22 sep 2025 (carga inicial del sistema anterior)",
    "masiva_texto": "Incluye un día con más de 300 registros (posible carga de archivo)",
    "semanas": [{"inicio": "2025-09-22", "nuevos": 812, "masiva": true}, {"inicio": "2025-09-29", "nuevos": null, "masiva": false}],
    "promedio_4": 150,
    "ritmo_necesario": {"semanas": 48, "por_semana": 20,
                        "texto": "Faltan 48 semanas para el 31 oct 2027: hay que registrar 20 por semana. Las últimas 4 semanas: 150 por semana."}
  },
  "avisos": [{"tipo": "archivada | territorio_usuario | sin_metas | sin_lideres | cobertura_comuna", "texto": "…"}],
  "mapa": {"capa": "municipios | comunas | null", "con_comunas": [{"id": "uuid", "codigo": "68001", "nombre": "Bucaramanga"}],
           "sin_poligono": ["Nombre"], "texto": null}
}
```

- `territorios`: las filas que el usuario ve, en el orden de la tabla de metas (cada municipio y debajo sus comunas).
  Una usuaria con territorio solo recibe sus municipios y sus comunas, y los totales son solo de ellos.
- `cobertura_comuna` solo en municipios con comunas; `por_origen` solo en comunas y corregimientos (con k).
- `sin_comuna` y `otras_comunas` (`{votantes, lideres}`) solo en municipios cuya meta sale de sus comunas: fichas sin
  comuna y fichas de comunas sin esa meta, que **no** cuentan en `registrados`. Por medida, `registrados + sin_comuna +
  otras_comunas` = todas las fichas del municipio; en una medida sin meta valen `null` y `registrados` es el total.
  `totales.*.registrados` sí las suma (todas las fichas).
- `totales.territorios`: `total`, `con_meta` y `sin_meta` cuentan los territorios de primer nivel (municipios, o la
  comuna sola en una JAL); `insuficientes` cuenta comunas y corregimientos.
- `evolucion.semanas`: todas las semanas desde `desde` hasta la actual, también las de 0 (la pantalla muestra por
  defecto las últimas `semanas_defecto`; «Desde el inicio» muestra todas). `nuevos` y `antes` con k. `masiva` = la
  semana tiene un día (hora de Bogotá) con más de 300 fichas nuevas. `promedio_4` = promedio redondeado de las 4 semanas
  completas anteriores a la actual, con las cifras exactas. `ritmo_necesario` es null sin `election_date`, sin meta de
  votantes o con la elección ya pasada; `semanas = ceil(días hasta la elección / 7)`, `por_semana = ceil(falta / semanas)`.
  Con `falta = 0`: `por_semana = 0` y `texto` = «La meta de votantes ya se cumplió.».
- `avisos` (en este orden, solo los que aplican):
  - `archivada`: «Campaña archivada: el tablero muestra los datos de hoy.»
  - `territorio_usuario`: «Ves solo tus municipios: Girón, Lebrija.»
  - `sin_metas` (unos territorios con meta y otros sin): «75 de 87 territorios no tienen meta: aparecen en gris.»
  - `sin_lideres` (ninguna ficha del universo es líder): «Aún no hay fichas marcadas como líder. Márcalas con la
    casilla "Es líder" en la ficha. Mientras tanto, el índice llega como máximo a 60.»
  - `cobertura_comuna` (uno por municipio bajo el 60 % con comunas visibles): «Solo 1.948 de 37.055 fichas de
    Bucaramanga (5 %) tienen comuna: las comunas se ven rayadas hasta que haya más datos.»
  - Sin ninguna meta (`totales.territorios.con_meta = 0`) no hay aviso: la pantalla muestra el estado vacío «Esta
    campaña aún no tiene metas…» y el botón [Ir a las metas] solo con `can.edit`.
- `mapa.capa`: `municipios` con dos o más municipios visibles; `comunas` con un solo municipio visible que tiene comunas
  (Alcaldía de Bucaramanga); `null` en una JAL o un municipio sin comunas, y entonces `texto` = «Esta campaña es de un
  solo territorio: mira el avance arriba.». `con_comunas`: los municipios visibles con comunas (para «Ver comunas de X»
  se pide `/api/geo/comunas.geojson?municipio={codigo}`). `sin_poligono`: nombres de los territorios de la capa sin
  polígono en la base (la pantalla dice «Sin mapa: X (N fichas)» con las cifras de `territorios`).

### Detalle de un territorio — `GET /{id}/tablero/territorio/{unitId}`

```json
{"success": true, "data": {
  "territorio": "el mismo objeto de territorios[]",
  "semanas": [{"inicio": "2026-11-09", "nuevos": 12, "masiva": false}],
  "antes": null,
  "pocos_registros": false,
  "pocos_registros_texto": "Muy pocos registros para ver la evolución",
  "por_puesto_texto": "Incluye 12 por puesto de votación",
  "comunas": ["uuid"]
}}
```

`semanas` (desde 2025-09-22 hasta la actual) y `antes` con k; `pocos_registros` = el territorio tiene menos de 50
fichas (la pantalla no dibuja semanas). `por_puesto_texto` solo en comunas con `por_origen.puesto` mayor que 0 (null en
otro caso). `comunas`: ids de sus comunas visibles (vacío si no tiene).

### Archivos

- Excel `tablero-<campaña>.xlsx`: **Resumen** (campaña, fecha y hora de Bogotá, totales, índice, fórmula),
  **Territorios** (Municipio, Comuna, Meta votantes, Registrados, %, Faltan, Meta líderes, Líderes, %, Faltan, Índice,
  Estado, Meta calculada), **Evolución** (Semana, Fichas nuevas, Carga masiva). Las celdas de 1 a 4 dicen «menos de
  5». Fuentes al pie. Las cifras son las del JSON.
- PDF `tablero-<campaña>.pdf`: encabezado institucional, las 3 tarjetas, «Van mejor» / «Necesitan apoyo» y la tabla con
  el estado. Sin mapa.

Reversa de la fase: revertir el commit (no hay migraciones ni datos nuevos; la caché vence sola en 5 min).
