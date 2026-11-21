# Módulo Reportes

Constructor de listas (Etapa B-1): fuente **Personas**, conteo en vivo, lista paginada con ficha editable y descarga Excel en streaming. Etapa B-2: PDF institucional. Etapa B-3: fuentes **Eventos y asistencia**, **Compromisos y actas** y **Empresarios y gremios**, con el mismo contrato, y las descargas viejas de Eventos y Empresarios pasan por el guardián. Fase 2: reportes guardados y gráficos.

## Fuentes

| clave | etiqueta | Permiso además de `view.reportes` (sin él: la fuente no llega en las props y la API responde 403) |
|---|---|---|
| `personas` | Personas | `view.votantes` o `view.mapa` |
| `asistencias` | Eventos y asistencia | `view.eventos` o `manage.eventos` (`EventScope::canSeeAttendees`) |
| `compromisos` | Compromisos y actas | `view.eventos`/`manage.eventos` (compromisos de eventos) o `view.empresarios`/`manage.empresarios` (de organizaciones); cada origen solo con su permiso, como `/eventos/compromisos` |
| `empresarios` | Empresarios y gremios | `view.empresarios` o `manage.empresarios` (`OrganizacionScope::canView`) |

Todas aplican en el servidor el grupo político (propio + subgrupos), el territorio del usuario y, en eventos y organizaciones, los borrados (`deleted_at`) nunca aparecen. Ninguna fuente de B-3 muestra resultados electorales, así que el interruptor `ve_resultados_electorales` no cambia lo que listan.

## Permisos

- `view.reportes`: entrar a `/reportes` y usar la API. Cada fuente exige además su permiso (tabla de arriba).
- `export.datos_personales`: permite elegir columnas sensibles (en pantalla y en descarga). Rige también para `/exportar/*`.
- Ninguno se asigna en migraciones/seeders; el superadmin los tiene por `*`.
- Decisión de Marly (2026-11-20): con el permiso, el contacto sale de **todas** las personas (sin reemplazo «Sin autorización»).

## Rutas

| Método y ruta | Cuerpo | Respuesta |
|---|---|---|
| `GET /reportes` | — | Inertia `Reportes/Index` |
| `POST /reportes/{fuente}/conteo` | `{spec}` | `{success, total}` |
| `POST /reportes/{fuente}/lista` | `{spec, pagina}` | `{success, total, pagina, por_pagina:50, columnas, filas:[{id, editable, accion, celdas}]}` |
| `POST /reportes/{fuente}/excel` | `{spec}` | `.xlsx` en streaming; cabeceras `X-Reporte-Filas`, `X-Reporte-Total`, `X-Reporte-Recortado` |
| `POST /reportes/{fuente}/pdf` | `{spec}` | `application/pdf`; mismas cabeceras que el Excel y, si se pidieron más de 8 columnas, `X-Reporte-Columnas-Omitidas` (claves separadas por coma) |
| `GET /reportes/opciones/{filtro}?fuente=&q=&municipios[]=` | — | `{success, opciones:[{valor, etiqueta}]}` (máx. 50) |

`referentes` exige `q` ≥ 2 letras; `valor` es el id de la ficha.

Opciones por fuente (`filtro` = el `opciones_de` del filtro). El navegador manda `fuente` = la clave de la fuente abierta; sin `fuente` responde la primera fuente disponible que tenga ese filtro (Personas primero: así sigue funcionando el front de B-1). `fuente` que el usuario no puede usar → 403; que no existe → 422; filtro que esa fuente no tiene → 404.

| fuente | `filtro` |
|---|---|
| `personas` | `barrios`, `partidos`, `profesiones`, `referentes`, `eventos` |
| `asistencias` | `eventos`, `barrios` (barrios escritos por los asistentes), `municipios` (los de los eventos visibles) |
| `compromisos` | `responsables` (nombres de responsables), `municipios` |
| `empresarios` | `municipios` (los de las organizaciones visibles) |

### Fila de la lista

`{id, editable, accion, celdas}`. `editable` dice si la fila misma se puede editar (solo Personas). `accion` dice qué abre tocar la fila, o `null` si nada:

| tipo | forma | quién la manda |
|---|---|---|
| `ficha` | `{tipo:'ficha', id:<lider id>, editable:bool}` | Personas (siempre, `editable` = el de la fila); Asistencias si el asistente está vinculado a una ficha que el usuario puede abrir (tiene `view.votantes` o `view.mapa`, y la ficha es de su grupo y su territorio); `editable` con la misma regla de Personas |
| `compromiso` | `{tipo:'compromiso', id, url:'/eventos/compromisos?abrir=<id>'}` | Compromisos |
| `organizacion` | `{tipo:'organizacion', id:<organización>, url:'/empresarios?abrir=<id>'}` | Empresarios (en la vista Miembros, la organización del miembro) |

Cambio de contrato de B-3: `accion` es nuevo; los clientes de B-1 que solo leen `id`/`editable` siguen funcionando.

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
- `busqueda` mira el nombre sin importar tildes ni mayúsculas (`unaccent(lower())` en PostgreSQL; igual en todas las fuentes y en las opciones); la cédula solo con `export.datos_personales`. En títulos, filtros en palabras, portada y Excel, una búsqueda con 5 o más dígitos sale como «Búsqueda por documento terminado en 5432» (nunca entera); el nombre del archivo no lleva la búsqueda.
- El referente se elige por id de ficha; el servidor resuelve la cédula. Un referente fuera del grupo o del territorio del usuario se descarta (ni filtra ni aparece nombrado en el Excel).

## Props de la página

`{fuentes:[...], puedeDatosPersonales, topes:{excel, pdf}, porPagina, cargosPorTipo, cargoCategorias, municipios}`. Solo llegan las fuentes que el usuario puede usar, en el orden de la tabla de Fuentes. `cargosPorTipo`/`cargoCategorias` son los mismos del Mapa político (`App\Services\FichaCatalogos`) y solo llegan con `view.mapa` (si no, `{}` y `[]`); `municipios` = los del grupo y territorio del usuario, para la ficha (solo si el usuario puede abrir fichas: `view.votantes` o `view.mapa`). Cada fuente: `clave, etiqueta, descripcion, total, unidad:{singular, plural, todos, femenino}`, `filtros:[{clave, etiqueta, abierto, filtros:[Filtro]}]`, `columnas:[{clave, etiqueta, grupo, sensible, siempre, ordenable, vistas?}]`, `presets:[{clave, etiqueta, por_defecto, columnas, vista?}]`, `atajos:[{clave, etiqueta, spec}]`, `ordenes:[{clave, dir, etiqueta}]` (la primera es la de por defecto). `unidad` sirve para los textos: «Tu lista tiene 1.646 {plural}», «Ninguno: {todos}.», «las primeras» si `femenino`, «los primeros» si no. `total` es el conteo con los filtros por defecto (en Empresarios, la vista Organizaciones).

`vistas` (solo Empresarios): las vistas en que vale la columna; si falta, vale en todas. Una columna de otra vista en el spec → 422 «Una de las columnas pedidas no existe.». Sin `export.datos_personales` no llegan los presets de contacto («Contacto básico», «Con contacto», «Con teléfono del responsable»): el de por defecto es uno sin columnas sensibles. `vista` en un preset: la vista para la que está pensado; hay un preset `por_defecto` por vista (el que se usa al cambiar de vista y cuando el spec llega sin columnas).

`Filtro = {clave, etiqueta, tipo, multiple, opciones?, ayuda?, opciones_de?, placeholder?, por_defecto?, obligatorio?}`. `obligatorio: true` (el filtro `vista` de Empresarios): siempre tiene valor; si falta o no es válido, el servidor usa `por_defecto`.

| tipo | Valor en `spec.filtros[clave]` |
|---|---|
| `chips` | lista de valores (`eventos_min` numérico) |
| `buscar` | lista; `opciones` se filtra en el navegador, `opciones_de` pide a `/reportes/opciones/…?fuente=<clave>` |
| `rango` | `{desde, hasta}` (0–120 o null) |
| `casilla` | `true` |
| `fechas` | `{desde:'AAAA-MM-DD'\|null, hasta:'AAAA-MM-DD'\|null}` (fechas de Bogotá, ambos extremos incluidos; si `desde` > `hasta` se invierten) o un periodo relativo `{relativo:'ultimos_7'\|'ultimos_30'\|'proximos_7'}` que se calcula el día en que se pide (así un reporte guardado sigue diciendo «el último mes»). `opciones` trae los relativos como botones: `[{valor:{relativo:'ultimos_30'}, etiqueta:'Último mes'}]`. Fecha inválida → se descarta. |

Periodos relativos (hoy = fecha de Bogotá): `ultimos_7` = de hoy − 7 días a hoy; `ultimos_30` = de hoy − 30 días a hoy; `proximos_7` = de hoy a hoy + 7 días.

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

## Fuente Eventos y asistencia (`asistencias`, B-3)

Una fila por asistente y evento (`event_attendees` de los eventos que el usuario puede abrir: `EventScope::events()`). Son todos los asistentes que muestra la pantalla del evento, en cualquier estado de vínculo (paridad: el reporte filtrado por el evento X tiene los mismos asistentes que `/eventos/X`). `id` de la fila = id del asistente; `accion` = ficha si está vinculado a una ficha que el usuario puede abrir. Unidad: asistente/asistentes.

- Filtros — ¿Qué evento?: `evento` (buscar, `opciones_de: 'eventos'`), `tipo_evento` (chips: tipos de los eventos visibles), `fecha` (fechas, fecha del evento; botones Última semana / Último mes). ¿Dónde?: `municipio` (buscar, municipio del evento, `opciones` en la página), `barrio` (buscar, `opciones_de: 'barrios'`, barrio escrito por el asistente, igual exacto). Ficha: `sin_ficha` (casilla: asistentes no vinculados a ninguna ficha). `grupo` si ve más de un grupo.
- Columnas: `nombre` (Persona, siempre), `cedula`🔒, `celular`🔒, `evento`, `tipo_evento`, `fecha` (fecha y hora del evento en Bogotá, «21/11/2026 3:40 p. m.»), `municipio` (del evento), `barrio`, `comuna` (si existe la columna), `origen` (QR, Operador, Excel, Planilla leída con IA, Acta de reunión, Electo), `vinculado` (Sí/No: tiene ficha), `grupo` (solo si ve más de un grupo). Los asistentes no tienen correo: no hay columna `email`.
- Atajos: `ultimo_mes` «Asistentes del último mes» (`fecha:{relativo:'ultimos_30'}`), `sin_ficha` «Asistentes sin ficha».
- Órdenes: `fecha` desc (por defecto), `fecha` asc, `nombre` asc, `evento` asc, `municipio` asc.
- `busqueda`: nombre y apellidos; la cédula solo con `export.datos_personales`.

## Fuente Compromisos y actas (`compromisos`, B-3)

Los compromisos de actas **confirmadas** que lista `/eventos/compromisos` (`CompromisoListing`): de eventos solo con `view.eventos`/`manage.eventos`, de organizaciones solo con `view.empresarios`/`manage.empresarios`; grupo, territorio, eventos y organizaciones no borrados. `accion` = `{tipo:'compromiso', id, url:'/eventos/compromisos?abrir=<id>'}`. Unidad: compromiso/compromisos.

- Filtros — Estado: `estado` (chips: pendiente, en_curso, cumplido, no_cumplido), `vencimiento` (chips: `vencidos` = abierto con fecha límite antes de hoy; `por_vencer` = abierto con fecha límite de hoy a hoy + `compromisos.dias_por_vencer` días; las mismas reglas de `CompromisoAlertas`), `fecha_limite` (fechas; botones Próximos 7 días…). ¿Quién y dónde?: `responsable` (buscar, `opciones_de: 'responsables'`, nombre exacto), `origen` (chips: `evento`, `organizacion`; solo los que el usuario ve), `municipio` (buscar, del evento o de la organización). `grupo` si ve más de un grupo.
- Columnas: `descripcion` (Compromiso, siempre), `estado`, `vencimiento` (Vencido / Por vencer / vacío), `fecha_limite`, `responsables` (nombres, « / »), `telefono_responsable`🔒 («Ana Pérez: 3001234567; Beto: 3109990000»: cada teléfono con su responsable, solo los que tienen), `origen` (Evento / Organización), `origen_nombre` (nombre del evento o de la organización), `acta` (título y fecha del acta), `municipio`, `grupo` (si ve más de uno).
- Atajos: `vencidos` «Compromisos vencidos», `esta_semana` «Vencen esta semana» (abiertos con `fecha_limite:{relativo:'proximos_7'}`).
- Órdenes: `estado` (por defecto, como la pantalla: abiertos primero y luego por fecha límite, sin fecha al final), `fecha_limite` asc, `fecha_limite` desc, `municipio`.
- `busqueda`: descripción del compromiso (sin tildes ni mayúsculas en PostgreSQL).

## Fuente Empresarios y gremios (`empresarios`, B-3)

Las organizaciones de `OrganizacionScope` (grupo, territorio por municipio, nunca las borradas). Filtro obligatorio `vista` (chips, una sola): `organizaciones` (por defecto, una fila por organización) o `miembros` (una fila por miembro de una organización visible). `accion` = `{tipo:'organizacion', id:<organización>, url:'/empresarios?abrir=<id>'}` en ambas vistas. Unidad: organización/organizaciones o miembro/miembros según la vista.

- Filtros: `vista`; `tipo` (chips: empresa, gremio), `sector_economico` y `sector` (chips: los del catálogo usados por las organizaciones visibles), `municipio` (buscar), `compromisos_abiertos` (casilla: tiene compromisos pendientes o en curso de actas confirmadas), `estado` (chips, una: activas por defecto, inactivas, todas; como la pantalla). `grupo` si ve más de un grupo.
- Columnas vista `organizaciones`: `nombre` (siempre), `tipo`, `nit`, `sector_economico`, `sector`, `municipio`, `empleados`, `representante`, `representante_cedula`🔒, `representante_celular`🔒, `correo`🔒, `miembros` (cuántos), `grupo`.
- Columnas vista `miembros`: `nombre` (la persona, siempre), `organizacion`, `rol`, `cedula`🔒, `celular`🔒, `municipio` (de la organización), `grupo`. Como en la ficha de la organización, de un miembro cuya ficha está fuera del territorio del usuario solo salen nombre y rol (cédula y celular vacíos).
- Atajos: `empresas` «Empresas», `gremios` «Gremios», `miembros` «Miembros de organizaciones», `con_compromisos` «Con compromisos abiertos».
- Órdenes: `nombre` asc (por defecto), `nombre` desc, `municipio`, `organizacion` (en la vista Organizaciones es lo mismo que el nombre).
- `busqueda`: organizaciones → nombre, representante o NIT (3+ dígitos); miembros → nombre de la persona u organización.

## PDF (B-2)

- Vista `resources/views/reportes/lista.blade.php`, A4 horizontal, DejaVu Sans. Portada: banda #003B71 con el mismo título del Excel, grupo político del usuario y logo del Centro Democrático (solo para su grupo y subgrupos, incrustado desde `public/img/partidos`), «Generado por Nombre, 20/11/2026 3:40 p. m.» (hora de Bogotá), filtros en palabras, total, los 10 municipios con más personas + «Otros» (sobre la lista completa, no solo las filas impresas) y el aviso de recorte «Este PDF muestra las primeras N de M personas. Para tenerlas todas, descarga el Excel.».
- Tabla: máximo 8 columnas en el orden elegido (las demás se nombran en `X-Reporte-Columnas-Omitidas` y en `metadata.columnas_omitidas`), encabezado repetido en cada página, zebra, 9 pt. Se arma en tablas de 50 filas: una sola tabla larga le cuesta a DomPDF ~1,6 veces la memoria.
- Pie en todas las páginas (canvas `page_text`, sin PHP en la plantilla): «Contiene datos personales: uso exclusivo del grupo político (Ley 1581).» y «Página X de Y». Siempre, también sin columnas sensibles: el nombre ya es dato personal.
- `isRemoteEnabled`, `isPhpEnabled` e `isJavascriptEnabled` apagados; los datos solo con `{{ }}`.
- El permiso de columnas sensibles se revisa sobre todo lo pedido (también lo que pasa de la octava columna).

## Descargas (DescargaGuard)

- Una a la vez por usuario (candado de 180 s); 5 por minuto y 30 por hora, cupo compartido con `/exportar/*`, fijo en código.
- Fila de auditoría `EXPORT reportes.personas.excel` o `EXPORT reportes.personas.pdf` (`entity_type=reporte`) escrita antes de enviar el archivo, con `metadata` {fuente, formato, spec, columnas, filas, total, recortado, con_datos_personales, ruta}. La búsqueda se guarda solo con sus últimos 4 dígitos. `AuditActivity` no registra la descarga otra vez ni el conteo ni la lista (lecturas, una por chip tocado).
- `/exportar/*` audita `EXPORT exportar.{source}.{excel|pdf}` con las columnas que de verdad lleva el archivo (`nombre` si todas eran personales; las de Gobernador si es Gobernador) y `columnas_quitadas`.
- El Excel de Reportes deja vacías las celdas sin dato (no «—») y trae anchos legibles (nombre 35, el resto según su etiqueta, 12–40). Municipio y provincia salen de la unidad geográfica de la ficha (`geographic_unit_id`), con el texto guardado solo si no tiene unidad; el orden por municipio usa el mismo nombre.
- `/exportar/*` de Mapa y Votantes sin el permiso: la primera fila del archivo (y el PDF) dice «Este archivo no incluye datos personales (cédula, teléfonos, correo, dirección u observaciones) porque no tienes el permiso Descargar datos personales.» (genérico a propósito: las columnas exactas van en la cabecera) y la respuesta trae `X-Columnas-Quitadas: telefono,observacion` (claves quitadas, separadas por coma).
- Topes en Centro de configuración: `reportes.excel_max_filas` (50.000), `reportes.pdf_max_filas` (500; de 50 a 700, ver Bench). El cupo (una a la vez, 5/min, 30/hora) es el mismo para Excel y PDF.
- Las fuentes de B-3 auditan `EXPORT reportes.{asistencias|compromisos|empresarios}.{excel|pdf}` con la misma `metadata`. El PDF dice en la portada su unidad («120 asistentes», «Ninguno: todos los compromisos.»).
- B-3: las descargas viejas de Eventos y Empresarios pasan por el mismo guardián (candado, cupo compartido, auditoría antes del archivo). Primero se revisan permiso, grupo y territorio (404/403 no gastan cupo). Sin `export.datos_personales` salen sin las columnas personales (`DescargaGuard::COLUMNAS_SENSIBLES`, que ahora incluye `representante_cedula`, `representante_celular` y `correo`), con la línea de aviso encima de los encabezados (en cada hoja) y la cabecera `X-Columnas-Quitadas`:

| Ruta (nombre) | Auditoría | Columnas personales |
|---|---|---|
| `GET /eventos/{id}/asistentes.xlsx` (`eventos.asistentes.excel`) | `EXPORT eventos.asistentes.excel` {fuente, formato, evento_id, columnas, columnas_quitadas, filas, filtros, con_datos_personales} | cédula, celular, fijo, fecha de nacimiento, dirección |
| `GET /eventos/territorio/excel` (`eventos.territorio.excel`) | `EXPORT eventos.territorio.excel` {… municipio_id, barrio (bool), lideres, eventos, asistentes} | hojas Líderes (cédula, teléfono) y Asistentes (cédula, celular) |
| `GET /eventos/compromisos.xlsx` (`eventos.compromisos.excel`) | `EXPORT eventos.compromisos.excel` | teléfonos de los responsables |
| `GET /empresarios/exportar.xlsx` y `.pdf` (`empresarios.exportar.excel`/`.pdf`) | `EXPORT empresarios.exportar.excel` / `.pdf` | cédula y celular del representante, teléfono, correo, dirección, observaciones |

  Las acciones viejas (`EVENTO_ASISTENTES_EXPORTADOS`, `EVENTOS_TERRITORIO_EXPORTADO`, `EVENTO_COMPROMISOS_EXPORTADOS`, `ORGANIZACIONES_EXPORTADAS`) ya no se escriben desde la web; el agente de WhatsApp sigue con la suya.
- Pendiente de pasar por el guardián: PDF de ficha y la exportación del agente (`AgentEventoController`).

## Bench

Solo contra una BD cuyo nombre termine en `_load`: `php artisan reportes:bench --seed=130000` (datos sintéticos); `--purge` borra lo sembrado.

PDF (2026-11-20, datos sintéticos, 8 columnas con textos de 2 líneas, proceso de test con 58 MB de base): 500 filas 3,2 s y 172 MB; 700 filas con observación de ~300 caracteres 6,9 s y 254 MB; 1.000 filas 262 MB (330 MB con observación); 2.000 filas 20 s y 428 MB. Por eso el máximo del ajuste bajó de 2.000 a 700: DomPDF guarda cada celda en memoria y `raiseMemoryLimit()` da 256 MB. Un valor guardado por encima de 700 se ignora (rige el de por defecto, 500).

## ADR

- **Contexto:** el equipo pedía reportes a mano; las descargas no se auditaban ni tenían límite (H3) y `write.data` no se aplicaba en la API (H1).
- **Decisión:** catálogo declarativo por fuente en el backend con `ReportSpec` JSON versionado como único contrato; scopes existentes reutilizados sin tocar Votantes/Mapa; un guardián único de descargas aplicado también a las viejas; permisos nuevos; topes configurables; paginación por offset.
- **Descartado:** reusar `VotanteController::listing()` (atado al Request, filtra municipio por nombre); constructor de consultas genérico (más superficie de ataque, confuso); paginación por cursor (innecesaria con ~131k fichas).
- **Consecuencias:** cada filtro/columna nueva se agrega al catálogo con su test de lista blanca; el permiso nuevo cambia las descargas viejas; el spec se podrá guardar tal cual en la fase 2.
