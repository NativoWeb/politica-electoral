# Empresarios y gremios

Módulo para registrar las **empresas** y los **gremios** de una campaña, sus **miembros** (personas de Votantes con un
rol), la afiliación **empresa ↔ gremio**, las **actas de reunión** con compromisos (el mismo módulo de actas de Eventos,
`docs/eventos-api.md` §14) y la **carga desde Excel**. Menú **Empresarios** → `/empresarios`.

## 1. Permisos (grupo "Empresarios" en Admin → Usuarios)

| Permiso | Qué permite |
|---|---|
| `view.empresarios` | Ver el listado, la ficha (datos, miembros, gremios/empresas, actas), descargar actas, ver compromisos de organizaciones y exportar Excel/PDF. |
| `manage.empresarios` | Todo lo anterior + crear, editar, borrar, miembros, catálogos de sectores, cargar Excel, actas (crear, editar, confirmar, borrar borradores) y seguimiento de sus compromisos. |
| `delete.actas` | (Existente) junto con `manage.empresarios`, eliminar un acta **confirmada** de una organización. |

Se otorgan como los demás: solo se da lo que se tiene; el superadmin tiene todo (`*`). El ítem de menú aparece con
`view.empresarios` o `manage.empresarios`. Un usuario cuyo único permiso de vista es este aterriza en `/empresarios`.
Prop compartida `auth.user.canManageEmpresarios`. No exige `write.data`.

**Aislamiento** (como `lideres`):

- Se ven las organizaciones de la campaña propia y de sus subcampañas; otra campaña → **404**.
- Solo se escribe en la campaña **propia**; una de subcampaña → **403** (solo lectura).
- Territorio (`territory_scope`): solo organizaciones con `municipio_id` dentro del territorio (detalle/escritura fuera → 403).
  Un usuario con territorio debe elegir municipio al crear. Una organización sin municipio solo la ven usuarios sin territorio.
- `campaign_id` nunca viene del request. Las fichas que se crean (miembros, participantes de actas) nacen en la campaña de la organización.
- En la ficha, los miembros fuera del territorio del usuario se muestran solo con nombre y rol (sin cédula, teléfono ni enlace).

## 2. Datos

`organizaciones` (soft delete): `tipo` (empresa|gremio), `nombre`, `nit` + `nit_dv`, representante (`representante_nombre`,
`representante_cedula`, `representante_celular`, `representante_lider_id` opcional), `telefono`, `correo`, `sitio_web`,
`sector_economico_id`, `sector_id`, `departamento` (por defecto Santander), `municipio_id` (provincia derivada), `direccion`,
`barrio`, `empleados` (entero ≥ 0) y/o `empleados_rango` (1-10, 11-50, 51-200, 201-500, 500+), `observaciones`, `is_active`.

- **NIT**: 6 a 10 dígitos (puntos y espacios permitidos), con o sin dígito de verificación tras un guion. Si viene el dígito
  se valida con el algoritmo de la DIAN (`App\Support\Nit`); sin guion todos los dígitos son la base. Único por campaña
  entre las no borradas (índice parcial `organizaciones_campaign_nit_unique`).
- **Representante legal**: texto; si se elige su ficha en Votantes (`representante_lider_id`), queda además como miembro con
  rol *Representante legal* (quitar el vínculo quita esa membresía). No se crean fichas desde este campo. Si al editar no se
  envía o no cambia, se conserva el vinculado sin volver a revisar el territorio.
- **Catálogos** (`org_catalogos`, tipo `sector_economico` | `sector`): valores iniciales compartidos (`campaign_id` null:
  Agropecuario … Otro; Público, Privado, Mixto, ONG, Solidario) que solo cambia el superadmin, más los propios de cada campaña
  (botón "Sectores"). Se desactivan, no se borran; una organización conserva un valor desactivado que ya tenía.

`organizacion_miembros` (organizacion_id, lider_id, rol, nota; único por organización+ficha+rol). Roles: representante_legal,
gerente, socio, afiliado, empleado, otro. Quitar un miembro no toca la ficha. Agregar: buscar la ficha (líder activo de la
campaña en el territorio) o escribir la persona; con la misma cédula en la campaña se vincula, si no **se crea la ficha** al
guardar (tipo directorio, cargo Líder, municipio escrito si es conocido y está en el territorio, si no el de la organización).

`organizacion_gremios` (empresa_id, gremio_id): una empresa en varios gremios de **su misma campaña**; el gremio lista sus
empresas. Cambiar el tipo borra las afiliaciones que ya no aplican (gremio → empresa: sus empresas afiliadas; empresa → gremio: sus gremios).

**Ficha de la persona**: pestaña / sección "Empresas y gremios" en PersonaPanel (Mapa) y VotanteDetail (Votantes) con sus
organizaciones y rol (`GET /empresarios/persona/{liderId}`, exige además `view.mapa` o `view.votantes`).

**Unificar fichas** (`MergeLideres` / "Unificar con otra persona"): las membresías y el representante pasan a la ficha que
queda (un mismo rol en la misma organización se conserva una vez). **Deshacer cargas/actas** (`FichaActivity`): una ficha
que es miembro o representante de una organización tiene actividad propia y no se borra.

## 3. Rutas

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /empresarios` | view | Inertia `Empresarios/Index`. Filtros: `provincia` (incluye Área Metropolitana), `municipio`, `tipo`, `sector_economico`, `sector`, `gremio` (el gremio y sus empresas), `buscar` (nombre, representante, NIT), `estado` (activas por defecto, `inactivos`, `todos`), `campana`. Máx. 2000 filas (`truncated`). `?abrir={id}` abre la ficha |
| `GET /empresarios/{id}` | view | JSON de la ficha: datos, `miembros`, `gremios` (empresa) o `empresas` (gremio), `can.edit` |
| `POST /empresarios` · `PUT /empresarios/{id}` · `DELETE /empresarios/{id}` | manage | crear (201), editar, borrar (soft delete; miembros, afiliaciones y actas quedan en la BD sin mostrarse) |
| `POST /empresarios/{id}/miembros` | manage | `{lider_id}` o `{nombre, cedula?, telefono?, municipio?}` + `{rol, nota?}` |
| `PUT /empresarios/miembros/{m}` · `DELETE /empresarios/miembros/{m}` | manage | rol/nota; quitar |
| `GET /empresarios/buscar/personas?q=` | manage | fichas activas de la campaña propia en el territorio (máx. 10) |
| `POST /empresarios/catalogos` · `PUT /empresarios/catalogos/{id}` | manage | agregar valor; renombrar/activar/desactivar |
| `GET /empresarios/exportar.xlsx` · `.pdf` | view | lo filtrado; Excel hasta 20 000 filas con NIT, cédula y celulares como **texto**; PDF hasta 500 |
| `GET /empresarios/plantilla.xlsx` | manage | plantilla de carga |
| `POST /empresarios/carga` | manage | multipart `archivo` (.xlsx/.xls/.csv ≤ 5 MB, ≤ 2000 filas) → `{id, url}` |
| `GET /empresarios/carga/{c}` | manage | Inertia `Empresarios/Importar` (vista previa) |
| `PATCH /empresarios/carga/{c}/filas/{f}` | manage | `{datos?: {...}, accion?: completar\|saltar}` corrige y vuelve a revisar |
| `POST /empresarios/carga/{c}/confirmar` · `DELETE /empresarios/carga/{c}` | manage | guarda / descarta |
| `GET /empresarios/{id}/actas` … | view / manage | las mismas rutas de actas de Eventos bajo `/empresarios/{id}/actas` (nueva, plantilla.xlsx, leer, sugerencias, store, update, destroy, eliminar, confirmar, archivo, acta.docx, acta.pdf) |

Escrituras sin el permiso → 403 JSON; una página GET sin permiso redirige a `/`.

## 4. Carga desde Excel

Columnas por **nombre del encabezado** (cualquier orden, sinónimos comunes): Tipo, Nombre o razón social, NIT, Representante
legal, Cédula representante, Celular (teléfono de la organización), Correo, Sector económico, Sector, Departamento, Municipio,
Dirección, Barrio o vereda, Número de empleados, Observaciones, Gremio al que pertenece. Obligatorias: Tipo y Nombre.

Cada fila se valida como el formulario (NIT con dígito, correo, sector/sector económico de la lista, municipio del territorio,
empleados número o rango, gremio existente en la campaña o que venga en el mismo archivo) y queda en `organizacion_import_rows`:

- **Nueva** → se crea.
- **Ya existe** (mismo NIT en la campaña, o mismo nombre normalizado en el mismo municipio) → por defecto **completar vacíos**:
  solo se llenan los campos vacíos de la existente y se agrega el gremio; **nunca** se cambia ni borra un dato que ya tenía.
  El usuario puede elegir **saltar**. Una existente fuera del territorio del usuario siempre se salta.
  *Decisión*: es lo más seguro — no se pierde información escrita a mano y tampoco se duplica la organización.
- **Mismo nombre en el mismo municipio pero otro NIT** que la existente → **error** ("Mismo nombre que «X» … pero otro NIT"):
  pueden ser dos organizaciones distintas o un NIT mal escrito; el usuario corrige.
- **NIT con el dígito pegado** (solo en la carga): 10 dígitos sin guion cuyo último dígito es el de verificación de los 9
  primeros (`8909039388`) se lee como `890903938-8`, así encuentra la existente. Si el último dígito no corresponde, se toma
  como un NIT de 10 dígitos. (Riesgo aceptado: un NIT real de 10 dígitos cuyo último dígito coincida por azar, ~1 de 11.)
- Al completar, NIT y dígito van **en pareja**: el NIT (con su dígito) solo se llena si la existente no tenía NIT; el dígito
  solo completa el **mismo** NIT.
- **Repetida en el archivo** (mismo NIT o nombre+municipio que una fila anterior) o con errores → **no se guarda** hasta corregirla.
- Una carga solo la abre quien la subió o un usuario sin territorio de la misma campaña (otro → 404).

Confirmar vuelve a revisar todo en una transacción. Auditoría: `ORGANIZACIONES_CARGA_SUBIDA`, `…_FILA` (solo nombres de campo),
`ORGANIZACIONES_IMPORTADAS` (conteos), `…_DESCARTADA`.

## 5. Actas de reunión de una organización

Las actas generalizan `event_actas` / `event_compromisos`: `organizacion_id` (nueva) o `event_id` (ahora nullable), con CHECK
de exactamente uno en PostgreSQL. Las actas de eventos no cambian (mismo contrato, mismas rutas). La página es la misma
(`Eventos/Acta`) con la prop `origen = {tipo: 'organizacion', base, volver, seccion, buscar}`.

- Borrador → confirmada, Word/PDF, lectura del .docx o de la plantilla Excel, compromisos con estado/avances/recordatorio: igual que en §14.
- Al **confirmar**: cada participante queda con su ficha en Votantes (elegida, misma cédula o nueva) y, si no era miembro
  con ningún rol, como **miembro con rol Otro** (`event_acta_participantes.created_miembro`). No hay asistentes.
  Resumen `{participantes, vinculados, fichas_creadas, miembros_nuevos, compromisos, responsables_vinculados}`.
- **Eliminar confirmada** (`delete.actas`): quita las membresías que creó y las fichas que creó si no tienen otra actividad.
- Archivos en `storage/app/private/empresarios/actas/{organizacion}/`.
- **Compromisos** (`/eventos/compromisos`, campanita y correo diario): muestran también los de organizaciones a quien tiene
  `view.empresarios`/`manage.empresarios` (los de eventos siguen exigiendo `view.eventos`/`manage.eventos`). Filtro `origen`
  (`evento` | `organizacion`) y `organizacion`; Excel con columna ORIGEN. Cambiar estado/avances de un compromiso de
  organización exige `manage.empresarios` en la campaña propia. El correo diario se envía a quien tiene `manage.eventos` o
  `manage.empresarios`.

## 6. Auditoría

Sin datos personales: el middleware `audit` guarda, para las rutas `empresarios.*`, solo los nombres de los campos enviados;
los controladores registran ids y conteos: `ORGANIZACION_CREADA` / `_ACTUALIZADA` (campos cambiados) / `_BORRADA`,
`ORGANIZACION_MIEMBRO_AGREGADO` / `_ACTUALIZADO` / `_QUITADO`, `ORG_CATALOGO_CREADO` / `_ACTUALIZADO`,
`ORGANIZACIONES_EXPORTADAS` (formato, filas, filtros usados), `ORGANIZACION_ACTA_*`.

## 7. Despliegue

1. `pg_dump` previo.
2. `git pull` + `composer install --no-dev --optimize-autoloader` (sin paquetes nuevos).
3. Recargar php-fpm y `php artisan migrate --force`: `2026_11_13_000001_create_organizaciones_tables` (tablas nuevas y
   valores iniciales de catálogos) y `2026_11_13_000002_add_organizacion_to_event_actas` (aditiva: columna nueva,
   `event_id DROP NOT NULL` con ALTER directo, CHECK de origen; no toca `data_consents` ni las vistas `agent_ro`).
   El `down()` de la segunda falla a propósito mientras existan actas de organizaciones.
4. `npm run build`, `php artisan optimize:clear && php artisan optimize`.
5. Otorgar `view.empresarios` / `manage.empresarios` en Admin → Usuarios a quien corresponda (nadie los tiene por defecto,
   salvo el superadmin).
