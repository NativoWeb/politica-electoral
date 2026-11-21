# Módulo Reportes

Constructor de listas (Etapa B-1): fuente **Personas**, conteo en vivo, lista paginada con ficha editable y descarga Excel en streaming. Siguientes etapas: B-2 PDF institucional; B-3 fuentes Eventos/asistencia, Compromisos/actas y Empresarios/gremios; fase 2 reportes guardados y gráficos.

## Permisos

- `view.reportes`: entrar a `/reportes` y usar la API. La fuente Personas exige además `view.votantes` o `view.mapa`.
- `export.datos_personales`: permite elegir columnas sensibles (en pantalla y en descarga). Rige también para `/exportar/*`.
- Ninguno se asigna en migraciones/seeders; el superadmin los tiene por `*`.
- Decisión de Marly (2026-11-20): con el permiso, el contacto sale de **todas** las personas (sin reemplazo «Sin autorización»).

## Rutas

| Método y ruta | Cuerpo | Respuesta |
|---|---|---|
| `GET /reportes` | — | Inertia `Reportes/Index` |
| `POST /reportes/{fuente}/conteo` | `{spec}` | `{success, total}` |
| `POST /reportes/{fuente}/lista` | `{spec, pagina}` | `{success, total, pagina, por_pagina:50, columnas, filas:[{id, editable, celdas}]}` |
| `POST /reportes/{fuente}/excel` | `{spec}` | `.xlsx` en streaming; cabeceras `X-Reporte-Filas`, `X-Reporte-Total`, `X-Reporte-Recortado` |
| `GET /reportes/opciones/{barrios\|partidos\|profesiones\|referentes\|eventos}?q=&municipios[]=` | — | `{success, opciones:[{valor, etiqueta}]}` (máx. 50) |

`referentes` exige `q` ≥ 2 letras; `valor` es el id de la ficha.

Errores `{success:false, message}` (+ `errors` en 422, `motivo` en 429): 401 sin sesión; 403 sin permiso o columna sensible sin `export.datos_personales`; 422 spec inválido o columna fuera del catálogo; 429 `motivo` = `en_curso` | `seguidas` | `por_hora`, con `Retry-After`.

## Spec (v=1)

```json
{"v":1,"fuente":"personas",
 "filtros":{"municipio":["<uuid>"],"marcas":["gran_elector"],"edad":{"desde":40,"hasta":null}},
 "columnas":["nombre","municipio","telefono"],
 "orden":{"campo":"nombre","dir":"asc"},"busqueda":""}
```

- Filtro desconocido o valor fuera de la lista blanca → se descarta en silencio. Territorio y grupo político siempre se aplican en el servidor.
- «O» dentro de un filtro, «Y» entre filtros. Fallecidos e inactivos excluidos por defecto.
- El referente se elige por id de ficha; el servidor resuelve la cédula. Un referente fuera del grupo o del territorio del usuario se descarta (ni filtra ni aparece nombrado en el Excel).

## Props de la página

`{fuentes:[...], puedeDatosPersonales, topes:{excel, pdf}, porPagina, cargosPorTipo, cargoCategorias, municipios}`. `cargosPorTipo`/`cargoCategorias` son los mismos del Mapa político (`App\Services\FichaCatalogos`) y solo llegan con `view.mapa` (si no, `{}` y `[]`); `municipios` = los del grupo y territorio del usuario, para la ficha. Cada fuente: `clave, etiqueta, descripcion, total`, `filtros:[{clave, etiqueta, abierto, filtros:[Filtro]}]`, `columnas:[{clave, etiqueta, grupo, sensible, siempre, ordenable}]`, `presets:[{clave, etiqueta, por_defecto, columnas}]`, `atajos:[{clave, etiqueta, spec}]`, `ordenes:[{clave, dir, etiqueta}]`.

`Filtro = {clave, etiqueta, tipo, multiple, opciones?, ayuda?, opciones_de?, placeholder?, por_defecto?}`:

| tipo | Valor en `spec.filtros[clave]` |
|---|---|
| `chips` | lista de valores (`eventos_min` numérico) |
| `buscar` | lista; `opciones` se filtra en el navegador, `opciones_de` pide a `/reportes/opciones/…` |
| `rango` | `{desde, hasta}` (0–120 o null) |
| `casilla` | `true` |

## Filtros de Personas

- ¿Dónde viven?: `provincia`, `municipio`, `comuna` (depende del municipio), `barrio`, `zona`
- ¿Qué son?: `tipo`, `marcas`, `nivel_confianza`, `partido`
- ¿Quién los trajo?: `referente`, `tiene_referidos`
- Edad y datos personales: `edad`, `genero`, `profesion`, `escolaridad`, `estado_civil`
- Autorización y estado: `autoriza`, `estado`, `incluir_fallecidos`
- Datos por completar: `por_completar`, `origen`
- Eventos (solo `view.eventos`): `evento`, `eventos_min`
- `grupo`: solo si el usuario ve más de un grupo

## Columnas (🔒 = sensible)

| Grupo | Columnas |
|---|---|
| Identificación | `nombre` (siempre), `tipo_documento`, `cedula`🔒, `fecha_expedicion`🔒 |
| Contacto | `telefono`🔒, `email`🔒, `direccion`🔒, `contactos`🔒, `redes`🔒 |
| Ubicación | `municipio`, `provincia`, `comuna`, `barrio`, `zona` |
| Votación | `puesto_votacion`, `mesa_votacion`, `municipio_votacion` |
| Perfil | `genero`, `edad`, `fecha_nacimiento`🔒, `profesion`, `escolaridad`, `estado_civil` |
| En la campaña | `tipo`, `cargo`, `partido`, `nivel_confianza`, `marcas`, `votos` |
| Referente | `referente`, `referente_documento`🔒, `referidos` |
| Eventos | `eventos`, `ultimo_evento` |
| Sistema | `autoriza_datos`, `origen`, `por_completar`, `grupo`, `observacion`🔒 |

## Descargas (DescargaGuard)

- Una a la vez por usuario (candado de 180 s); 5 por minuto y 30 por hora, cupo compartido con `/exportar/*`, fijo en código.
- Fila de auditoría `EXPORT reportes.personas.excel` (`entity_type=reporte`) escrita antes de enviar el archivo, con `metadata` {fuente, formato, spec, columnas, filas, total, recortado, con_datos_personales, ruta}. La búsqueda se guarda solo con sus últimos 4 dígitos. `AuditActivity` no registra la descarga otra vez ni el conteo ni la lista (lecturas, una por chip tocado).
- `/exportar/*` audita `EXPORT exportar.{source}.{excel|pdf}` con las columnas que de verdad lleva el archivo (`nombre` si todas eran personales; las de Gobernador si es Gobernador) y `columnas_quitadas`.
- El Excel de Reportes deja vacías las celdas sin dato (no «—») y trae anchos legibles (nombre 35, el resto según su etiqueta, 12–40). Municipio y provincia salen de la unidad geográfica de la ficha (`geographic_unit_id`), con el texto guardado solo si no tiene unidad; el orden por municipio usa el mismo nombre.
- `/exportar/*` de Mapa y Votantes sin el permiso: la primera fila del archivo (y el PDF) dice «Este archivo no incluye cédula, teléfono ni dirección: requiere el permiso Descargar datos personales.» y la respuesta trae `X-Columnas-Quitadas: telefono,observacion` (claves quitadas, separadas por coma).
- Topes en Centro de configuración: `reportes.excel_max_filas` (50.000), `reportes.pdf_max_filas` (500).
- Pendiente de pasar por el guardián (fuera de B-1): `/eventos/{id}/asistentes.xlsx`, `/eventos/territorio/excel`, `/eventos/compromisos.xlsx`, `/empresarios/exportar.*`, PDF de ficha.

## Bench

Solo contra una BD cuyo nombre termine en `_load`: `php artisan reportes:bench --seed=130000` (datos sintéticos); `--purge` borra lo sembrado.

## ADR

- **Contexto:** el equipo pedía reportes a mano; las descargas no se auditaban ni tenían límite (H3) y `write.data` no se aplicaba en la API (H1).
- **Decisión:** catálogo declarativo por fuente en el backend con `ReportSpec` JSON versionado como único contrato; scopes existentes reutilizados sin tocar Votantes/Mapa; un guardián único de descargas aplicado también a las viejas; permisos nuevos; topes configurables; paginación por offset.
- **Descartado:** reusar `VotanteController::listing()` (atado al Request, filtra municipio por nombre); constructor de consultas genérico (más superficie de ataque, confuso); paginación por cursor (innecesaria con ~131k fichas).
- **Consecuencias:** cada filtro/columna nueva se agrega al catálogo con su test de lista blanca; el permiso nuevo cambia las descargas viejas; el spec se podrá guardar tal cual en la fase 2.
