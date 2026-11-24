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
| `bucaramanga_puestos_2023.csv` | 88 puestos de votación de Bucaramanga con comuna, dirección y latitud/longitud (sin datos personales), más la columna `alias` curada a mano | **Registraduría Nacional del Estado Civil – «Divipole Elecciones Territoriales 2023 con georreferenciación», datos.gov.co conjunto mv2e-prx5, licencia CC BY-SA 4.0** (descargado el 2026-11-24 de `https://www.datos.gov.co/resource/mv2e-prx5.json?departamento=SANTANDER&municipio=BUCARAMANGA`) |

Toda pantalla, exportación o reporte que muestre estos polígonos o cifras debe citar:

> Fuente: Área Metropolitana de Bucaramanga (AMB) – LIMITES_COMUNAS; Alcaldía de Bucaramanga – Subsecretaría de Medio
> Ambiente (veredas); Planeación Bucaramanga – lista de barrios; datos.gov.co kn95-8dei (CC BY-SA 4.0).

Y lo que muestre la comuna obtenida por puesto de votación, o los puestos mismos:

> Fuente: Registraduría Nacional del Estado Civil – Divipole Elecciones Territoriales 2023 con georreferenciación,
> datos.gov.co mv2e-prx5 (CC BY-SA 4.0).

CC BY-SA 4.0 exige atribución y compartir igual las obras derivadas de esos conjuntos (la población por barrio; la
lista de puestos con su comuna y sus alias, que se comparte con la misma licencia).

### Modelo

- `geographic_units` filas `type = 'comuna'` / `'corregimiento'`, `parent_id` = el municipio, `level = 4`,
  `official_code = '68001-C01'` … `'68001-R3'`, `geom` + `geom_simplified` (0,0001°, ~11 m) y `metadata` (jsonb nuevo):
  `numero`, `poblacion` (AMB), `poblacion_2024` (suma datos.gov), `veredas`, `fuente`. La vista `agent_ro.v_geo` las
  ve con su `type`.
- `barrios`: `municipio_id`, `comuna_unit_id`, `nombre`, `nombre_normalizado` (único por municipio, índice GIN
  `pg_trgm`), `fuente` (`amb|planeacion|datosgov`), `poblacion`, `geom` (solo AMB).
- `barrio_aliases`: texto normalizado → barrio o comuna, **por grupo político** (`campaign_id`), con `created_by`.
- `lideres`, `event_attendees`, `organizaciones`: `comuna_id` (FK `geographic_units`) y `comuna_source`
  (`auto|texto|direccion|puesto|manual`, ver la cascada), añadidas con `ADD COLUMN` (las vistas `agent_ro.*` no se
  tocan; la migración 2026_11_24_000001 solo reemplaza el CHECK). Un asistente está donde está su evento
  (`events.municipio_id`).
- `puestos_votacion`: `municipio_id`, `nombre`, `nombre_normalizado` (único por municipio, GIN `pg_trgm`),
  `comuna_unit_id`, `comuna_origen` (`dataset|poligono`), `direccion`, `geom` (POINT 4326), `fuente`, `anio`, `alias`
  (jsonb).
- `comuna_cambios`: cada cambio de `geo:asignar-comunas` (`lote`, `tabla`, `registro_id`, antes y después de
  `comuna_id`/`comuna_source`), para revertir por lote.

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

### Cascada (una sola regla: `ComunaAssigner::changesFor`)

Prioridad, de más fuerte a más débil: **manual > auto (barrio) > texto > dirección (fase B, sin activar) > puesto**.
La señal más fuerte presente gana y reemplaza a una automática más débil; si desaparece (se borra el barrio, cambia el
puesto) toma su lugar la siguiente: una ficha con barrio C05 y puesto C12 queda C05/`auto`; sin barrio, C12/`puesto`.
`manual` nunca se toca (con comuna o sin ella). La usan el guardado de la ficha (`Lider`: barrio, municipio, dirección,
puesto y municipio de votación), el refresco del Mapa (`refreshRow`), «volver a automática» del PATCH y el comando.

- **auto**: el barrio (sección «Barrio» más abajo).
- **texto**: «COMUNA N» escrito en el barrio o la dirección (`COMUNA 5`, `COMUNA NO. 5`, `COMUNA #05`), si nombra un
  solo número que existe como comuna del municipio y ese texto no menciona otro municipio (`COMUNA 5 FLORIDABLANCA` y
  `COMUNA 25` no asignan; barrio «COMUNA 3» con dirección «COMUNA 5» tampoco).
- **direccion**: reservado para la fase B (geocodificación por malla vial del AMB); hoy no asigna nada.
- **puesto** (solo fichas): si `municipio_votacion` es el municipio de la ficha (nombre, abreviatura o código DANE;
  vacío = no se usa) y la ficha vive en un municipio con comunas. El puesto se busca entre los `puestos_votacion` del
  municipio por nombre normalizado o alias exacto; si no, por similitud de trigramas ≥ 0,6 que le saque ≥ 0,1 al mejor
  puesto **de otra comuna** (dos sedes de la misma comuna no son ambiguas). Ambiguo, sin coincidencia, vacío o «0» →
  no asigna y va al reporte. La similitud se calcula en PHP (`Trigram`, igual a `pg_trgm.similarity`, comprobado en
  ComunaCascadaPostgresTest): la lista es corta (88) y así el resultado es el mismo en SQLite y PostgreSQL.
  Normalización (`PuestoNormalizer`): sin tildes, puntuación como espacio, iniciales unidas (`I.E.` = `IE`),
  `INSTITUCION EDUCATIVA` = `IE`, `COLEGIO` = `COL`, `ESCUELA` = `ESC`, `UNIVERSIDAD` = `UNIV`, sin prefijo `PUESTO`.

### Carga de puestos: `php artisan geo:import-puestos --municipio=68001 [--archivo=] [--dry-run]`

- Lee `database/data/geo/bucaramanga_puestos_2023.csv` (columnas `departamento, municipio, puesto, comuna, direccion,
  latitud, longitud, alias`; alias separados por `|`). Aborta sin escribir si falta una columna, un puesto es de otro
  municipio, dos puestos se escriben igual o un alias ya es nombre o alias de otro puesto. Necesita PostGIS.
- Comuna: la del dataset (`03COMUNA 3 SAN FRANCISCO` → C03, `18CORREGIMIENTO 1` → R1); si viene vacía, el polígono de
  comuna que contiene el punto (`comuna_origen = poligono`: las dos cárceles). Si el polígono contradice al dataset gana
  el dataset y se reporta: con el archivo actual 6 discrepancias (Bosconia: dataset C02, polígono R2; Colegio Santander
  sede C: C03 / C02; 4 puntos fuera de toda comuna); 80 coinciden.
- Alias curados: siglas y nombres de uso (`UTS`, `UIS`, `UNAB`, `INEM SEDE A`…, `CARCEL MODELO`). **«INEM» a secas no
  es alias**: hay sedes del INEM en las comunas 8, 9, 10 y 11, así que queda sin asignar. Tampoco «SENA», «DAMASO
  ZAPATA» ni «AURELIO MARTINEZ MUTIS» (sedes en comunas distintas).
- Upsert por nombre normalizado, nunca borra (reporta los que están en la BD y no en el archivo). Transacción;
  `--dry-run` de solo lectura. Idempotente: la segunda corrida da 88 sin cambio. Audita `geo.import_puestos` con el
  sha256 del archivo.

### Asignación: `php artisan geo:asignar-comunas --municipio=68001 [--tabla=lideres] [--dry-run]`

Aplica la cascada a las fichas, asistentes y organizaciones del municipio, de a 500 registros. Reporte por tabla:
registros, manual respetada, por barrio, por texto, por puesto, sin señal, cambian y sin cambio; los cambios por origen
(`sin comuna → puesto: 2200`); los puestos no usados (ambiguo, sin coincidencia, vota en otro municipio) y los textos
de barrio y de puesto que no cruzaron. `--dry-run` corre en una transacción de solo lectura: cero escrituras.

La corrida real guarda **solo los cambios** en `comuna_cambios` con un `lote` (UUID que imprime y queda en la
auditoría `geo.asignar_comunas`); sin cambios no hay lote. Medido en local con 39.000 fichas sintéticas (transacción
revertida): 36 s y 82 MB la primera corrida (27.000 cambios, uno por uno: cada UPDATE exige la comuna y el origen leídos, así una ficha cambiada mientras se leía no se pisa ni se registra), 1,3 s la siguiente sin cambios.

### Reversa: `php artisan geo:revertir-comunas --lote=<uuid> [--dry-run]`

Devuelve cada registro del lote a la comuna y el origen que tenía antes, salvo los que cambiaron después (a mano o por
otra corrida), que se dejan y se cuentan como omitidos. Lote inexistente → error (código ≠ 0). Audita
`geo.revertir_comunas`.

Rollback de la migración 2026_11_24_000001: aborta con mensaje mientras quede algún registro con origen `texto`,
`direccion` o `puesto` (revertir antes los lotes); luego borra `puestos_votacion` y `comuna_cambios` y deja el CHECK
como antes (`auto|manual`).

### Barrios de Electo: `php artisan data:electo-barrios {csv} [--dry-run] [--revertir]`

Con el catálogo de barrios de Electo (CSV con `;` o `,`: código `codigo|id|idbarrio…`, nombre `nombre|barrio…` y
municipio DANE `municipio|fkmunicipio…`) llena `lideres.barrio` **solo si está vacío**, en las fichas que guardaron el
código en `migracion_flags.electo_codigos.barrio`, si el código es del mismo municipio de la ficha. Marca
`migracion_flags.barrio_desde_electo {codigo, valor}` y quita `barrio` de `por_completar`. Omite los nombres vacíos o de
relleno (`SIN BARRIO`, `NO REGISTRA`…) y los códigos «sin barrio» de `config/geo.php`
(`GEO_ELECTO_BARRIO_SIN_BARRIO`, por defecto `1404`, 13.841 fichas en prod) salvo que el catálogo los nombre como un
barrio real del municipio. Luego corre `geo:asignar-comunas --tabla=lideres` en cada municipio con comunas tocado.
`--revertir` vacía el barrio solo donde sigue siendo el que se escribió, devuelve `barrio` a `por_completar` y vuelve a
correr la cascada. Pendiente: que Marly entregue el archivo del catálogo.

### Barrio (señal `auto`)

Orden: alias del grupo político para ese texto exacto → nombre normalizado exacto → si el texto menciona otro municipio
(`LA PAZ, FLORIDABLANCA`, `…, PIEDECUESTA`, `BOGOTA`) no se asigna → `pg_trgm` ≥ 0,6 que le saque ≥ 0,1 al mejor
candidato **de otra comuna** (dos barrios parecidos de la misma comuna no son ambigüedad para la comuna). Un nombre de
municipio que también es parte de un barrio (`SAN MIGUEL`, `GIRARDOT`, `RINCON DE LA PAZ`) no cuenta como otro
municipio. Nunca sobrescribe `manual`; limpia la comuna de un registro que ya está en otro municipio. Reporta
asignados / ambiguos / sin match / otro municipio / sin barrio con sus textos; audita `geo.asignar_comunas`.

Además, al crear o editar una ficha (`Lider` por Eloquent y las escrituras del Mapa por query builder), un asistente
(`EventAttendee`) o una organización (`Organizacion`) cuyo barrio, dirección o municipio (y en la ficha el puesto o el
municipio de votación) cambió, la comuna se recalcula sola con la cascada (sin pisar `manual`). Las cargas masivas por query builder (comandos de importación, inserción en lote de asistentes) no
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
  `{comuna_id: uuid|null}` → `comuna_source = 'manual'`; `{automatica: true}` → vuelve a la de la cascada (barrio,
  texto o puesto).

### UI

- Admin → Territorio → pestaña **Barrios y comunas** (`?tab=barrios`): la cola y la acción Asignar (barrio o solo
  comuna).
- Ficha (panel del Mapa y `VotanteDetail`, sección Ubicación): selector **Comuna / corregimiento** solo si el municipio
  tiene comunas; se guarda al elegir, muestra el origen («Por barrio», «Escrita», «Por puesto de votación», «Por
  dirección», «Manual»; `ComunaCatalog::ORIGENES` y `resources/js/lib/comunas.js`) y, si es manual, permite volver a
  automática.
- Votantes: filtro **Comuna** solo cuando el municipio elegido tiene comunas (`comunasPorMunicipio`), con «Sin comuna»
  (`comuna=sin_comuna`) y las comunas/corregimientos. Sin municipio o con un valor que no es UUID se ignora. La
  exportación del encabezado usa el mismo listing.
- Reportes (Personas): opción «Sin comuna» por municipio con comunas (`sin_comuna:{municipio_id}`, «O» con las comunas
  elegidas) y columna **Origen de la comuna**.
