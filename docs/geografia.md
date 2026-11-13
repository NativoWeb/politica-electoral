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
