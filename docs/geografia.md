# Geografía: polígonos y códigos DANE de Santander

## Fuente

| | |
|---|---|
| Archivo | `database/data/geo/santander_municipios.geojson` (87 municipios, EPSG:4326, ~3,1 MB) |
| Productor | Departamento Administrativo Nacional de Estadística (DANE) |
| Capa | Marco Geoestadístico Nacional (MGN) 2025, nivel Municipio (`MPIO_NANO` = 2024 en los atributos) |
| Servicio | `https://geoportal.dane.gov.co/mparcgis/rest/services/MGN2025/Serv_CapasMGN_2025/FeatureServer/317` |
| Consulta | `query?where=DPTO_CCDGO='68'&outFields=DPTO_CCDGO,MPIO_CCDGO,MPIO_CDPMP,DPTO_CNMBRE,MPIO_CNMBRE,MPIO_CRSLCION,MPIO_TIPO,MPIO_NAREA,MPIO_NANO&outSR=4326&f=geojson` |
| Descarga | 2026-11-12 |
| Códigos | `MPIO_CDPMP` = código DIVIPOLA de 5 dígitos (68001 Bucaramanga, 68081 Barrancabermeja…) |

**Licencia / atribución:** datos abiertos del DANE. El DANE autoriza el uso de la información de su portal
citando la fuente; toda pantalla, exportación o reporte que muestre estos polígonos debe llevar:

> Fuente: Departamento Administrativo Nacional de Estadística – DANE, Marco Geoestadístico Nacional (MGN) 2025. www.dane.gov.co

### Simplificación del archivo

La descarga completa pesaba 9,9 MB (241.429 vértices). Se simplificó con mapshaper 0.6.121, que trabaja sobre la
topología compartida (los límites entre municipios vecinos siguen coincidiendo):

```bash
npx mapshaper@0.6 mgn68_full.geojson -simplify 60% keep-shapes -clean -o precision=0.000001 format=geojson santander_municipios.geojson
```

Al cargar, `geom` guarda esta geometría y `geom_simplified` una copia con `ST_SimplifyPreserveTopology` a 0,0005°
(~55 m), que es la que dibuja el mapa.

Alternativa revisada: capa "Municipios de Santander" del AMB
(`https://mapa.amb.gov.co/server/rest/services/VISOR/MAPA_BASE_VECTORIAL_105_Definitivo/MapServer/1`, campos
`NMG`/`CCDE`/`CCMU`). El comando también entiende esos campos, pero la fuente oficial es el DANE.

## Carga: `php artisan geo:import-municipios {archivo?} [--dry-run] [--parcial]`

- Toma los features con código que empieza por `68` y los cruza con los `municipality` que cuelgan del departamento
  Santander (`official_code = '68'`), directamente o por su provincia. Orden del cruce: código ya cargado → nombre
  (`lower(unaccent())`) → tabla de excepciones (`ImportGeoMunicipios::ALIASES`) → similitud `pg_trgm` ≥ 0,55 sin
  empate.
- Llena `official_code`, `geom` (`ST_Multi(ST_CollectionExtract(ST_MakeValid(...), 3))`, SRID 4326) y
  `geom_simplified`; luego cada provincia de Santander y el departamento con `ST_Union` de sus municipios.
- No crea ni borra registros. Reporta los features sin municipio, los municipios sin polígono y los registros
  `municipality` fuera de Santander o con nombre repetido (en prod: **Bogota**, bajo Distrito capital / Cundinamarca;
  por eso hay 88 municipios para 87 de Santander).
- Una provincia solo se recalcula si todos sus municipios quedan con polígono, y el departamento solo si todos los de
  Santander quedan con polígono; si no, lo avisa y conserva la geometría que tenían. `--parcial` las recalcula igual.
  Una unión vacía nunca borra una geometría existente.
- Si el departamento no tiene `official_code` se le fija `68`; si tiene otro, aborta. Si ningún feature cruza, aborta.
- Todo en una transacción; si un código ya lo tiene otro municipio, una geometría queda inválida o un municipio queda
  fuera del polígono del departamento, no guarda nada. `--dry-run` corre en una transacción de solo lectura.
- Idempotente: correrlo otra vez deja los mismos datos (el cruce va por código).
- Al terminar borra la caché del endpoint.

## Endpoint: `GET /api/geo/municipios.geojson?simplified=1`

- Sesión web con `auth`, `password.changed`, `audit` y permiso `view.mapa`; sin permiso responde 403 (JSON).
- `FeatureCollection` de los municipios activos de Santander con polígono. Cada feature: `id` (uuid) y
  `properties` `{id, nombre, official_code, provincia, provincia_id}`. `simplified=1` (por defecto) usa
  `geom_simplified`; `simplified=0` la geometría completa. Coordenadas con 5 decimales.
- Cacheado 24 h en el store por defecto (Redis en prod) con ETag; responde 304 a `If-None-Match`.

## Comunas y corregimientos de Bucaramanga (fase 3)

### Fuentes (`database/data/geo/`, descargadas el 2026-11-12, datos públicos)

| Archivo | Contenido | Fuente / atribución |
|---|---|---|
| `bucaramanga_comunas.geojson` | 20 features EPSG:4326: 17 comunas `C01`–`C17` (número, nombre, `poblacion_fuente` = `PERSONAS_S`) y 3 corregimientos `R1`–`R3` (unión de sus veredas, con la lista de veredas) | Comunas: **Área Metropolitana de Bucaramanga (AMB) – LIMITES_COMUNAS** (`PARQUES/LIMITES_COMUNAS/MapServer/1`). Corregimientos: **Alcaldía de Bucaramanga – Subsecretaría de Medio Ambiente (veredas)** (`LIMITES_POLITICOS/Veredas`, disueltas por corregimiento) |
| `bucaramanga_barrios_amb.geojson` | 220 polígonos de barrio con `NOMBRE_BAR` y `COD_COMUNA` | **Área Metropolitana de Bucaramanga (AMB)**, capa de barrios (mapa.amb.gov.co) |
| `bucaramanga_barrios_planeacion.csv` | 250 filas oficiales comuna/corregimiento → barrio/vereda | **Planeación Bucaramanga – lista de barrios** |
| `bucaramanga_barrios_datosgov.csv` | 263 barrios con comuna, categoría y población 2024 | **datos.gov.co, conjunto kn95-8dei, licencia CC BY-SA 4.0** |

Toda pantalla, exportación o reporte que muestre estos polígonos o cifras debe citar:

> Fuente: Área Metropolitana de Bucaramanga (AMB) – LIMITES_COMUNAS; Alcaldía de Bucaramanga – Subsecretaría de Medio
> Ambiente (veredas); Planeación Bucaramanga – lista de barrios; datos.gov.co kn95-8dei (CC BY-SA 4.0).

CC BY-SA 4.0 exige atribución y compartir igual las obras derivadas de ese conjunto (la población por barrio).

### Modelo

- `geographic_units` filas `type = 'comuna'` / `'corregimiento'`, `parent_id` = el municipio, `level = 4`,
  `official_code = '68001-C01'` … `'68001-R3'`, `geom` + `geom_simplified` (0,0001°, ~11 m) y `metadata` (jsonb nuevo):
  `numero`, `poblacion` (AMB), `poblacion_2024` (suma datos.gov), `veredas`, `fuente`. La vista `agent_ro.v_geo` las
  ve con su `type`.
- `barrios`: `municipio_id`, `comuna_unit_id`, `nombre`, `nombre_normalizado` (único por municipio, índice GIN
  `pg_trgm`), `fuente` (`amb|planeacion|datosgov`), `poblacion`, `geom` (solo AMB).
- `barrio_aliases`: texto normalizado → barrio o comuna, **por grupo político** (`campaign_id`), con `created_by`.
- `lideres`, `event_attendees`, `organizaciones`: `comuna_id` (FK `geographic_units`) y `comuna_source`
  (`auto|manual`), añadidas con `ADD COLUMN` (las vistas `agent_ro.*` no se tocan). Un asistente está donde está su
  evento (`events.municipio_id`).

Un municipio sin comunas (cualquier otro, también los creados fuera de Santander) funciona igual que antes: sin
selector y sin comuna.

### Normalización del barrio (`App\Services\Geo\BarrioNormalizer`)

Igual para el catálogo y para lo escrito en la ficha: sin tildes, mayúsculas, sin prefijos `BARRIO`, `B/`,
`CONJUNTO…`, `RESIDENCIAL`, `EDIFICIO`, `URBANIZACION`, `VEREDA`, `SECTOR`; se corta desde `CASA 43`, `TORRE 2`,
`APTO 301`, `MZ`, `LOTE`, `BLOQUE`…; romanos I–XX → arábigos (`CORDONCILLO I` = `CORDONCILLO 1`); sin ceros a la
izquierda (`05 DE ENERO` = `5 DE ENERO`); se quita el propio municipio (`BUCARAMANGA`, `B/MANGA`, `BGA`).

### Carga: `php artisan geo:import-comunas --municipio=68001 [--dry-run] [--comunas= --barrios= --planeacion= --datosgov=]`

- Exige que el municipio tenga polígono (`geo:import-municipios`). Valida cada comuna (válida tras `ST_MakeValid`, no
  vacía, `ST_PointOnSurface` dentro del municipio, código libre): si una falla no guarda nada.
- Barrios por prioridad: (1) polígono AMB, cuya comuna es la que contiene la mayor parte del polígono (gana sobre el
  `COD_COMUNA` de la capa); si menos del 20 % cae dentro de alguna comuna la geometría no decide y se usa el atributo
  o la siguiente fuente; (2) lista de Planeación; (3) datos.gov (aporta también la población). Si dos fuentes
  discrepan gana la de mayor prioridad y se reporta. Con los archivos actuales: 311 barrios/veredas (218 AMB, 55
  Planeación, 38 datos.gov) y 13 discrepancias (p. ej. Quebrada La Iglesia I: AMB dice comuna 17, el polígono está
  50,9 % en la 11; Bosconia: polígono fuera de las comunas, Planeación dice comuna 2).
- Upsert por código / nombre normalizado; nunca borra y nunca reemplaza una geometría por NULL; una geometría solo se
  reescribe si cambió (la simplificación no es repetible byte a byte). Transacción; `--dry-run` en transacción de
  solo lectura. Idempotente. Audita `geo.import_comunas` (archivos con sha256) y limpia la caché del endpoint.

### Asignación: `php artisan geo:asignar-comunas --municipio=68001 [--tabla=lideres] [--dry-run]`

Orden: alias del grupo político para ese texto exacto → nombre normalizado exacto → si el texto menciona otro municipio
(`LA PAZ, FLORIDABLANCA`, `…, PIEDECUESTA`, `BOGOTA`) no se asigna → `pg_trgm` ≥ 0,6 que le saque ≥ 0,1 al mejor
candidato **de otra comuna** (dos barrios parecidos de la misma comuna no son ambigüedad para la comuna). Un nombre de
municipio que también es parte de un barrio (`SAN MIGUEL`, `GIRARDOT`, `RINCON DE LA PAZ`) no cuenta como otro
municipio. Nunca sobrescribe `manual`; limpia la comuna de un registro que ya está en otro municipio. Reporta
asignados / ambiguos / sin match / otro municipio / sin barrio con sus textos; audita `geo.asignar_comunas`.

Además, al crear o editar una ficha (`Lider` por Eloquent y las escrituras del Mapa por query builder), un asistente
(`EventAttendee`) o una organización (`Organizacion`) cuyo barrio o municipio cambió, la comuna se recalcula sola (sin
pisar `manual`). Las cargas masivas por query builder (comandos de importación, inserción en lote de asistentes) no
la calculan: correr `geo:asignar-comunas` después.

Con los 64 textos de barrio de las fichas de Bucaramanga en prod (copia local, solo barrio + municipio): 50 con barrio
→ 34 asignados, 3 otro municipio, 13 sin coincidencia (a la cola); 14 sin barrio.

### Endpoints

- `GET /api/geo/comunas.geojson?municipio=68001[&simplified=0]`: como el de municipios (`view.mapa`, caché 24 h,
  ETag/304). Features con `properties {id, nombre, codigo, tipo, numero, poblacion, municipio_id, municipio}`. Un
  municipio sin comunas da colección vacía; `municipio` debe ser un código DANE de 5 dígitos (422).
- `GET /admin/territorio/barrios-sin-comuna?municipio_id=` (`view.admin`): textos normalizados sin comuna del **propio
  grupo político** y territorio (fichas activas, asistentes no descartados, organizaciones no borradas; sin los
  `manual`), con conteos, ejemplos, estado y candidatos. Fuera del territorio 403; municipio sin comunas 404.
- `POST /admin/territorio/barrios/alias` (`view.admin` + rol de escritura) `{municipio_id, texto, barrio_id | comuna_id}`:
  guarda el alias del grupo y reasigna en lote (como `auto`) todos los registros del grupo en ese municipio con ese
  texto normalizado, salvo los `manual`. Audita `geo.barrio_alias`. No es pantalla de plataforma: cada grupo resuelve
  sus textos (la pestaña de UI vive en Admin → Territorio, que hoy solo abre la plataforma).
- `PATCH /votantes/{id}/comuna` (`view.mapa|view.votantes` + rol de escritura; grupo propio y territorio)
  `{comuna_id: uuid|null}` → `comuna_source = 'manual'`; `{automatica: true}` → vuelve a la del barrio.

### UI

- Admin → Territorio → pestaña **Barrios y comunas** (`?tab=barrios`): la cola y la acción Asignar (barrio o solo
  comuna).
- Ficha (panel del Mapa y `VotanteDetail`, sección Ubicación): selector **Comuna / corregimiento** solo si el municipio
  tiene comunas; se guarda al elegir, muestra si fue automática o manual y permite volver a automática.
