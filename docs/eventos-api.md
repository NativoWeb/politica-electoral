# Eventos especiales — contrato backend (Fase 1 + Fase 2: planilla PDF + Fase 3: cargue desde Excel + Fase 4: integración + Fase 5: planillas con IA, apagada)

Contrato entre el backend (Laravel) y el frontend (React + Inertia) del módulo **Eventos especiales**:
eventos de campaña ("Del Senado al barrio", giras, novenas, reuniones) con registro de asistentes por
**QR público** y por **operador**. Fuente de verdad: si el backend cambia, este archivo se actualiza.

Convenciones:

- Fechas/hora: ISO 8601 con zona (`2026-11-05T18:00:00-05:00`) en props y respuestas; en los payloads se aceptan
  `YYYY-MM-DD HH:mm`, `YYYY-MM-DDTHH:mm` o ISO 8601 (zona de la app: `America/Bogota`).
- Todos los ids son UUID.
- JSON de error: `{ "success": false, "message": "..." }` (409, 410), `{ "message": "..." }` (403, 404) o el
  formato estándar de Laravel `{ "message": "...", "errors": { clave: ["..."] } }` para **todo 422**, también las
  reglas de negocio de eventos, con estas claves: `status` (transición inválida),
  `evento` (borrar con asistentes), `ids` (asistentes ajenos al evento), `organizadores` (líder no válido),
  `ends_at`/`registration_closes_at` (fechas). En Inertia llegan como `errors.<clave>`. (Las claves `consent`,
  `consent_text_id`, `autoriza_datos` y `declaracion` ya no se usan desde 2026-11-07, §4.)
- **404** JSON genérico e idéntico para "no existe", "id no UUID" y "es de otra campaña no visible":
  `{ "message": "No se encontró lo que busca." }` (nunca nombra el modelo); en HTML, la página `errors/404`.
  **429** JSON por límite (`throttle`): `{ "success": false, "message": "Demasiadas solicitudes. Espere un momento e intente de nuevo." }`
  con `Retry-After`; en HTML, la página `errors/429`. El 429 del QR público por tope de evento conserva su propio mensaje.
- Peticiones Inertia (`router.post/put/delete`) reciben redirect + flash (`flash.success`) o `errors` en la página.
  Peticiones con `Accept: application/json` (axios/fetch) reciben JSON con el código indicado.

---

## 1. Permisos (en `users.permissions`, grupo "Eventos" en la pantalla de Usuarios)

| Permiso | Qué permite |
|---------|-------------|
| `view.eventos` | Ver el listado y el detalle de eventos (incluye asistentes con sus datos). |
| `manage.eventos` | Todo lo anterior + crear/editar/borrar eventos, abrir/cerrar/cancelar, regenerar el QR, aprobar/descartar asistentes, buscar organizadores y figuras, y registrar asistentes en modo operador. |
| `register.asistentes` | Ver el listado de eventos (sin asistentes) y entrar al **modo operador** a registrar asistentes. No ve el detalle con asistentes ni edita nada. No necesita `write.data`. |
| `delete.actas` | **Eliminar actas confirmadas** (§14), por ejemplo una confirmada por error, deshaciendo lo que creó. Solo sirve junto con `manage.eventos`. Se otorga como los demás (solo quien lo tiene; el superadmin lo tiene implícito). |

**Regla única:** gestionar eventos (crear, editar, borrar, abrir, cerrar, cancelar, regenerar QR, aprobar y descartar)
exige **solo `manage.eventos`** (más campaña propia y territorio); **no** exige `write.data` ni un rol de escritura.
Ninguna ruta de eventos lleva `can.write`. El frontend debe usar la prop compartida **`auth.user.canManageEventos`**
(o `can.manage` de cada página, que vale lo mismo) para mostrar esas acciones; nunca `canWrite`.

Superadmin (R01) tiene todo. Un usuario cuyo único permiso de vista es de eventos aterriza en `/eventos` al entrar a `/`.

Aislamiento por campaña (igual que Votantes):

- Se ven los eventos de la campaña propia **y de sus subcampañas**; un evento de otra campaña no visible → **404**.
- Solo se escriben eventos de la campaña **propia**; un evento de una subcampaña visible → **403**
  (`"Este registro pertenece a otra campaña: solo se puede consultar."`). Incluye abrir la página del operador y
  registrar asistentes (el operador trabaja solo en eventos de su propia campaña).
- Usuario con territorio (`territory_scope`): solo eventos cuyo `municipio_id` está en su territorio
  (listado filtrado; detalle/escritura fuera de territorio → **403** `"Este registro está fuera de tu territorio."`).
- `campaign_id` nunca viene del request: el evento nace en la campaña del usuario; asistentes, consentimientos y
  fichas aprobadas usan **la campaña del evento**.

---

## 2. Rutas

### Autenticadas (`auth`, `password.changed`, `audit`)

| Método | URI | Nombre | Permiso | Respuesta |
|--------|-----|--------|---------|-----------|
| GET | `/eventos` | `eventos.index` | `view.eventos\|manage.eventos\|register.asistentes` | Inertia `Eventos/Index` |
| GET | `/eventos/buscar/organizadores?q=` | `eventos.buscar.organizadores` | `manage.eventos` | JSON |
| GET | `/eventos/buscar/figuras?q=` | `eventos.buscar.figuras` | `manage.eventos` | JSON |
| POST | `/eventos/tipos` | `eventos.tipos.store` | `manage.eventos` | JSON 201 creado / 200 si ya existía uno global o de la campaña propia con el mismo nombre (sin mayúsculas ni tildes) |
| PUT | `/eventos/tipos/{id}` | `eventos.tipos.update` | `manage.eventos` | JSON 200; solo tipos de la campaña propia (globales: solo R01); 422 "Ya existe un tipo con ese nombre" si repite otro tipo usable activo; 403 / 404 |
| DELETE | `/eventos/tipos/{id}` | `eventos.tipos.destroy` | `manage.eventos` | JSON 200 `deleted: true` si ningún evento (ni borrado) lo usa; si no, lo desactiva (`deactivated: true`) y los eventos lo conservan |
| GET | `/eventos/{id}` | `eventos.show` | `view.eventos\|manage.eventos` | Inertia `Eventos/Show` |
| GET | `/eventos/{id}/operador` | `eventos.operador` | `register.asistentes\|manage.eventos` | Inertia `Eventos/Operador` |
| POST | `/eventos/{id}/asistentes` | `eventos.asistentes.store` | `register.asistentes\|manage.eventos` | JSON 201 / 200 |
| GET | `/eventos/{id}/planilla.pdf?paginas=N` | `eventos.planilla` | `register.asistentes\|manage.eventos` | PDF (descarga), ver §9 |
| POST | `/eventos` | `eventos.store` | `manage.eventos` | 201 JSON / redirect a `/eventos/{id}` |
| PUT | `/eventos/{id}` | `eventos.update` | `manage.eventos` | 200 JSON / redirect back |
| DELETE | `/eventos/{id}` | `eventos.destroy` | `manage.eventos` | 200 JSON / redirect a `/eventos` |
| POST | `/eventos/{id}/abrir` | `eventos.open` | `manage.eventos` | 200 JSON / redirect back |
| POST | `/eventos/{id}/cerrar` | `eventos.close` | `manage.eventos` | 200 JSON / redirect back |
| POST | `/eventos/{id}/cancelar` | `eventos.cancel` | `manage.eventos` | 200 JSON / redirect back |
| POST | `/eventos/{id}/token` | `eventos.token` | `manage.eventos` | 200 JSON / redirect back |
| POST | `/eventos/{id}/asistentes/aprobar` | `eventos.asistentes.approve` | `manage.eventos` | 200 JSON / redirect back |
| POST | `/eventos/{id}/asistentes/descartar` | `eventos.asistentes.discard` | `manage.eventos` | 200 JSON / redirect back |
| POST | `/eventos/{id}/asistentes/recuperar` | `eventos.asistentes.recover` | `manage.eventos` | 200 JSON / redirect back (§6.1) |
| POST | `/eventos/{id}/asistentes/desvincular` | `eventos.asistentes.unlink` | `manage.eventos` | 200 JSON / redirect back (§6.1) |
| POST | `/eventos/{id}/asistentes/eliminar` | `eventos.asistentes.destroy` | `manage.eventos` | 200 JSON / redirect back (§6.1) |
| PUT | `/eventos/{id}/asistentes/{asistente}` | `eventos.asistentes.update` | `manage.eventos` | 200 JSON / redirect back (§6.1) |
| GET | `/eventos/{id}/cargas/{import}/deshacer` | `eventos.cargas.preview` | `manage.eventos` | 200 JSON (§6.2) |
| DELETE | `/eventos/{id}/cargas/{import}` | `eventos.cargas.destroy` | `manage.eventos` | 200 JSON / redirect back (§6.2) |
| GET | `/eventos/{id}/plantilla.xlsx` | `eventos.importar.plantilla` | `manage.eventos` | Excel (descarga), ver §11 |
| GET | `/eventos/{id}/importar` | `eventos.importar` | `manage.eventos` | Inertia `Eventos/Importar` (paso 1) |
| POST | `/eventos/{id}/importar` | `eventos.importar.subir` | `manage.eventos` | 201 JSON / redirect a `/eventos/{id}/importar/{import}` |
| GET | `/eventos/{id}/importar/{import}?estado=&page=` | `eventos.importar.ver` | `manage.eventos` | Inertia `Eventos/Importar` |
| PUT | `/eventos/{id}/importar/{import}/columnas` | `eventos.importar.columnas` | `manage.eventos` | 200 JSON / redirect back |
| PATCH | `/eventos/{id}/importar/{import}/filas/{fila}` | `eventos.importar.fila` | `manage.eventos` | 200 JSON / redirect back |
| POST | `/eventos/{id}/importar/{import}/confirmar` | `eventos.importar.confirmar` | `manage.eventos` | 200 JSON / redirect back |
| DELETE | `/eventos/{id}/importar/{import}` | `eventos.importar.descartar` | `manage.eventos` | 200 JSON / redirect a `/eventos/{id}/importar` |
| GET | `/eventos/{id}/asistentes.xlsx?link_status=&source=&q=` | `eventos.asistentes.excel` | `view.eventos\|manage.eventos` | Excel (descarga), ver §12 |
| GET | `/eventos/persona/{liderId}` | `eventos.persona` | `view.eventos\|manage.eventos` **y** `view.mapa\|view.votantes` | JSON, ver §12 |
| GET | `/eventos/territorio?municipio=&barrio=&page=&pagina_lideres=` | `eventos.territorio` | `view.eventos\|manage.eventos` | Inertia `Eventos/Territorio`, ver §12 |
| GET | `/eventos/territorio/excel?municipio=&barrio=` | `eventos.territorio.excel` | `view.eventos\|manage.eventos` | Excel (descarga), ver §12 |

`{id}` que no es UUID → 404. Sin el permiso: GET de página → redirect a `/`; JSON o no-GET → 403.

### Públicas (sin login; layout propio)

| Método | URI | Nombre | Respuesta |
|--------|-----|--------|-----------|
| GET | `/e/{token}` | `eventos.publico.show` | Inertia `Eventos/Publico` (200, o 410 si no está abierto) |
| POST | `/e/{token}` | `eventos.publico.store` | Inertia `Eventos/Publico` 200, o JSON 200 con `Accept: application/json` |

Límites del POST público:

- **60 por minuto por IP** (middleware `throttle:eventos-publico`; alto a propósito: en un evento muchos asistentes
  comparten el wifi del sitio o el NAT del operador móvil). Cuenta todos los envíos.
- **3000 por hora por evento**, contado en el controlador **solo con registros reales** (después del honeypot, el
  `form_token` y la validación), así que el tráfico de bots no cierra el formulario. Al llegar a 2400 se escribe un
  warning en el log (`Eventos: registro público cerca del tope por hora`).

Pasado cualquiera de los dos → **429** (`Retry-After` en cabecera). Los bots se frenan con el honeypot, el tiempo
mínimo de 4 s y la caducidad del `form_token` (6 h).

---

## 3. Estados del evento

`borrador` → `abierto` → `cerrado`; `cancelado` desde cualquiera salvo cancelado (final).
`cerrado` → `abierto` se permite (reabrir) con las mismas condiciones de abrir.

| Acción | Desde | Regla |
|--------|-------|-------|
| abrir | `borrador`, `cerrado` | — (desde 2026-11-07 no exige texto de autorización, §4) |
| cerrar | `abierto` | — |
| cancelar | `borrador`, `abierto`, `cerrado` | — |
| borrar | cualquiera | Solo si el evento **no tiene asistentes**; si tiene → 422 (`errors.evento`) `"El evento ya tiene asistentes: cancélelo en lugar de borrarlo."` (borrado lógico, `deleted_at`). |

Transición inválida → **422** (`errors.status`).

**QR público acepta registros** solo si: `status = abierto` **y** no está vencido. Vencido = pasado `registration_closes_at`; si es null, pasado `ends_at + 6 h`; si también es null,
pasado `starts_at + 24 h`.

**Operador acepta registros** si `status` ∈ {`borrador`, `abierto`} (no cerrado/cancelado/borrado). No le aplica el
vencimiento (puede pasar registros tarde).

---

## 4. Autorización de datos — RETIRADA el 2026-11-07 por decisión de la campaña

Marly, informada del riesgo frente a la Ley 1581 (la responsabilidad queda en la campaña), decidió el **2026-11-07**
quitar la autorización de datos de **todo** el módulo Eventos:

- **QR público**: sin casilla de autorización y sin exigir texto aprobado; el QR abre desde ya. Se mantienen honeypot,
  `form_token`, límites y la respuesta idéntica exista o no la persona.
- **Operador** (online y cola offline): sin casilla ni texto. Un registro encolado antes con `consent_text_id` /
  `autoriza_datos` sigue sincronizando: el servidor **ignora** esos campos (no valida si el texto cambió).
- **Excel** (y planillas con IA, apagadas): sin columna «Autorizó», sin la declaración de planillas firmadas y sin
  texto aprobado; todo archivo es una **lista** (§11) y todas las filas válidas se importan.
- **Asistentes nuevos**: `autoriza_datos = false`, `consent_id = null`, `link_status = pendiente` (se aprueban a
  Votantes como siempre; la ficha queda con `lideres.autoriza_datos = false`). No se escribe en `data_consents`.
- `data_consents` (append-only, dueño `postgres`) **no se toca ni se borra**: conserva lo registrado antes y
  simplemente deja de crearse. `consent_texts` y `php artisan eventos:consent-text` siguen existiendo, pero nada se
  bloquea por ellos.
- La regla de contacto del agente (solo datos de contacto de quien autorizó, `lideres.autoriza_datos`) **no cambia**.

Datos anteriores: los asistentes que quedaron como `sin_autorizacion` (listas cargadas antes) pasan a `pendiente`
con el SQL de despliegue de §11 ("Paso de despliegue 2026-11-07").

---

## 5. Páginas Inertia y props

### `Eventos/Index` — `GET /eventos`

Query (todos opcionales): `provincia` (uuid), `municipio` (uuid), `barrio` (texto, contiene, sin tildes),
`tipo` (uuid event_type), `status`, `desde` (YYYY-MM-DD, starts_at ≥), `hasta` (YYYY-MM-DD, starts_at ≤ fin del día),
`q` (nombre del evento; sin distinguir mayúsculas ni tildes), `campana` (uuid, solo si hay más de una campaña visible), `page`.

```jsonc
{
  "eventos": {                      // paginador Laravel (20 por página), orden starts_at desc
    "data": [ EventoResumen ],
    "current_page": 1, "last_page": 3, "per_page": 20, "total": 47, "links": [ ... ]
  },
  "filters": { "provincia": null, "municipio": null, "barrio": null, "tipo": null, "status": null,
               "desde": null, "hasta": null, "q": null, "campana": null },
  "provincias": [ { "id": "...", "name": "Área Metropolitana", "municipios": [ { "id": "...", "name": "Girón" } ] } ],
  "tipos": [ { "id": "...", "nombre": "Del Senado al barrio" } ],
  "statuses": ["borrador", "abierto", "cerrado", "cancelado"],
  "can": { "manage": true, "view": true, "register": true }
}
```

Las `provincias` vienen filtradas por el territorio del usuario (solo municipios permitidos).
`visibleCampaigns` ya llega como prop compartida (solo si hay >1).

`EventoResumen`:

```jsonc
{
  "id": "uuid", "nombre": "Del Senado al barrio — Girón", "status": "abierto",
  "tipo": { "id": "uuid", "nombre": "Del Senado al barrio" },
  "starts_at": "2026-11-05T18:00:00-05:00", "ends_at": null, "registration_closes_at": null,
  "sitio_encuentro": "Parque principal",
  "provincia": { "id": "uuid", "nombre": "Soto" }, "municipio": { "id": "uuid", "nombre": "Girón" },
  "barrios": ["El Poblado", "Centro"],
  "campaign": { "id": "uuid", "nombre": "Centro Democrático" },
  "editable": true,                 // campaña propia (y manage.eventos)
  "asistentes_total": 120           // null si el usuario solo tiene register.asistentes
}
```

### `Eventos/Show` — `GET /eventos/{id}`

Query de la tabla de asistentes: `link_status`, `source` (`qr|operador|excel`), `q` (nombre o cédula), `page`.
`kpis` trae además `excel` (asistentes cargados desde Excel, §11) y `ocr`. `source` del asistente
puede ser `excel` (en pantalla: "Por Excel: <quien confirmó>").

```jsonc
{
  "evento": EventoDetalle,
  "asistentes": {                   // paginador (20 por página), orden created_at desc
    "data": [ Asistente ], "current_page": 1, "last_page": 1, "per_page": 50, "total": 3, "links": [ ... ]
  },
  "kpis": { "total": 3, "vinculados": 1, "pendientes": 1, "aprobados": 1, "descartados": 0, "qr": 2, "operador": 1 },
  "filters": { "link_status": null, "source": null, "q": null },
  "tipos": [ { "id": "uuid", "nombre": "Gira" } ],                 // para el formulario de edición
  "provincias": [ ... ],                                           // igual que en Index
  "cargas": [                                                      // cargas confirmadas con asistentes; [] si !can.edit
    { "id": "uuid", "kind": "excel|ocr", "nombre": "lista.xlsx", "created_by": "Sandra", "confirmed_at": "...", "asistentes": 269 }
  ],
  "can": { "manage": true, "register": true, "edit": true }        // edit = manage && campaña propia
}
```

`EventoDetalle` = `EventoResumen` + :

```jsonc
{
  "descripcion": "texto | null",
  "consent_text_id": "uuid | null",     // texto vigente cuando se abrió por última vez
  "public_url": "https://.../e/<token>", // para el QR; null si el usuario no tiene manage.eventos ni register.asistentes
  "public_open": true,                  // el QR acepta registros en este momento
  "organizadores": [ { "id": "uuid", "lider_id": "uuid|null", "nombre": "...", "cedula": "...|null", "telefono": "...|null", "rol": "...|null" } ],
  "figuras": [ { "id": "uuid", "person_id": "uuid|null", "nombre": "...", "cargo": "...|null" } ],
  "created_by": { "id": "uuid", "name": "..." },
  "created_at": "..."
}
```

`Asistente`:

```jsonc
{
  "id": "uuid", "nombres": "Ana", "apellidos": "Pérez", "cedula": "1098765432",
  "celular": "3001234567", "fijo": null, "fecha_nacimiento": "1980-04-02", "direccion": null,
  "barrio": "El Poblado", "nota": null,
  "source": "qr",                    // qr | operador
  "link_status": "pendiente",        // vinculado | pendiente | sin_cedula | aprobado | descartado
  "lider_id": "uuid|null",           // ficha en Votantes (vinculado/aprobado)
  "import_id": "uuid|null",          // carga de Excel/OCR que lo creó
  "registered_by": { "id": "uuid", "name": "..." } | null,
  "created_at": "..."
}
```

### `Eventos/Operador` — `GET /eventos/{id}/operador`

```jsonc
{
  "evento": { "id", "nombre", "status", "starts_at", "sitio_encuentro", "municipio": {...}, "provincia": {...},
              "tipo": {...}, "barrios": ["..."], "public_url": "...", "accepts_registrations": true },
  "registrados": 37,                                  // total del evento
  "mis_registros": [ { "id": "uuid", "nombres": "...", "apellidos": "...", "created_at": "..." } ], // últimos 20 del operador
  "client_uuid": "uuid"                                // sugerido para el primer envío (el front puede generar los suyos)
}
```

Si el evento no acepta registros (cerrado/cancelado), la página se muestra igual con `accepts_registrations: false`.

### `Eventos/Publico` — `GET /e/{token}` (layout propio, sin `auth`)

```jsonc
{
  "estado": "abierto",                // abierto | cerrado   (cerrado ⇒ HTTP 410 y sin formulario)
  "evento": { "nombre": "...", "descripcion": "...|null", "tipo": "Del Senado al barrio",
              "starts_at": "...", "ends_at": "...|null", "sitio_encuentro": "...",
              "municipio": "Girón", "provincia": "Soto" },
  "barrios": ["El Poblado", "Centro"], // sugerencias; el barrio es texto libre
  "form_token": "cadena opaca",        // devolver tal cual en el POST
  "client_uuid": "uuid",               // devolver tal cual en el POST (idempotencia ante doble envío)
  "submitted": false,
  "message": null
}
```

Token desconocido o evento borrado → **404** (página 404 estándar).

---

## 6. Payloads JSON

### Crear / editar evento — `POST /eventos`, `PUT /eventos/{id}`

```jsonc
{
  "nombre": "Del Senado al barrio — Girón",         // requerido, ≤ 200
  "descripcion": "texto",                            // opcional, ≤ 5000
  "event_type_id": "uuid",                           // requerido: tipo global o de la campaña propia
  "starts_at": "2026-11-05 18:00",                   // requerido
  "ends_at": "2026-11-05 21:00",                     // opcional, ≥ starts_at
  "registration_closes_at": "2026-11-05 22:00",      // opcional, ≥ starts_at
  "sitio_encuentro": "Parque principal",             // requerido, ≤ 255
  "municipio_id": "uuid",                            // requerido, municipio; la provincia se deriva
  "barrios": ["El Poblado", "Centro"],               // opcional, ≤ 50, cada uno ≤ 120 (se deduplican sin tildes)
  "organizadores": [                                 // opcional, ≤ 50
    { "lider_id": "uuid|null", "nombre": "Juan Gómez", "cedula": "...|null", "telefono": "...|null", "rol": "Coordinador|null" }
  ],
  "figuras": [                                       // opcional, ≤ 20
    { "person_id": "uuid|null", "nombre": "Senador X", "cargo": "Senador|null" }
  ]
}
```

- `lider_id` debe ser un líder **activo de la campaña propia** (y del territorio del usuario); si no → 422.
- `person_id` debe existir en `persons`; si no → 422.
- En `PUT` todo es opcional, pero un campo obligatorio que **viene** vacío (`""`, espacios o `null`) → 422 con el
  mismo mensaje que al crear (p. ej. `errors.nombre: ["Escriba el nombre del evento."]`); `descripcion`, `ends_at` y
  `registration_closes_at` sí se pueden vaciar.
- En `PUT`, `barrios`, `organizadores` y `figuras` **reemplazan** las listas si vienen en el payload; si una clave no
  viene, esa lista no se toca. `status`, `campaign_id` y `public_token` se ignoran.
- Municipio fuera del territorio del usuario → 403.

Respuestas JSON: `POST` → **201** `{ success: true, message: "Evento creado.", data: EventoDetalle }`;
`PUT` → **200** `{ success: true, message: "Evento actualizado.", data: EventoDetalle }`.
Inertia: `POST` redirige a `/eventos/{id}`; `PUT` vuelve atrás con `flash.success`.

### Abrir / cerrar / cancelar / regenerar token

Sin body. JSON → **200** `{ success: true, message: "...", data: { status, public_url, public_open } }`.
Regenerar token invalida el QR anterior (el viejo da 404).

### Registrar asistente (operador) — `POST /eventos/{id}/asistentes`

```jsonc
{
  "client_uuid": "uuid",               // requerido: idempotencia (reintento offline = misma respuesta)
                                       // consent_text_id / autoriza_datos (clientes viejos): se ignoran (§4)
  "nombres": "Ana",                    // requerido, ≤ 100
  "apellidos": "Pérez Gómez",          // requerido, ≤ 100
  "cedula": "1.098.765.432",           // requerido; se normaliza a dígitos; 5–12 dígitos
  "celular": "300 123 4567",           // requerido; 10 dígitos que empiezan por 3 (acepta +57/espacios/guiones)
  "barrio": "El Poblado",              // requerido, ≤ 120
  "fijo": "6076543210",                // opcional, 7–10 dígitos
  "fecha_nacimiento": "1980-04-02",    // opcional; AAAA-MM-DD, fecha real del calendario, mayor de 18 años
  "direccion": "Cra 1 # 2-3"           // opcional, ≤ 255
}
```

Respuestas:

- **201** `{ success: true, message: "Asistente registrado.", data: { id, client_uuid, duplicate: false } }`
- **200** `{ success: true, message: "Esta persona ya estaba registrada en el evento.", data: { id, client_uuid, duplicate: true } }`
  (mismo `client_uuid` o misma cédula en el mismo evento; no se crea nada nuevo).
- **409** `{ success: false, message: "Ese registro pertenece a otro evento." }` si el `client_uuid` ya se usó en otro evento.
- **410** `{ success: false, message: "El evento ya no recibe registros." }` (cerrado/cancelado).
- **422** validación (`errors`).
  `message` resume en español: el primer error más `(y 1 error más)` / `(y N errores más)` (`lang/es.json`).
  Mensajes de `fecha_nacimiento` (uno solo por envío, en este orden): formato distinto de `AAAA-MM-DD` →
  `"La fecha de nacimiento debe tener el formato AAAA-MM-DD."`; fecha que no existe (`2026-02-31`, `1981-02-29`) →
  `"Esa fecha no existe."`; antes de 1900 → `"Revise la fecha de nacimiento."`; menor de 18 →
  `"El registro es solo para mayores de 18 años."`. Igual en el registro público.
- **403/404** aislamiento (ver §1).

La respuesta **no** indica si la cédula ya existía en Votantes (el operador no lo ve; el detalle sí, con `view.eventos`).

### Registro público — `POST /e/{token}`

Mismo body que el operador **más**:

```jsonc
{ "form_token": "<de las props>", "website": "" }   // website = honeypot: debe ir vacío y oculto
```

- Respuesta de éxito **siempre idéntica**, sea cédula nueva, ya existente en Votantes o ya registrada en el evento:
  JSON `200 { success: true, message: "¡Gracias, quedó registrado!" }`; Inertia → `Eventos/Publico` con
  `submitted: true` y `message: "¡Gracias, quedó registrado!"`.
- Honeypot lleno, `form_token` ausente/inválido/de otro evento, envío antes de 4 s desde que se cargó la página o
  `form_token` de más de **6 horas** → la misma respuesta 200, sin guardar nada (si el asistente dejó la página
  abierta más de 6 h, el front debe recargarla antes de enviar).
- **422** validación (p. ej. sin `nombres` → `errors.nombres`). En Inertia llegan como `errors`.
- **410** evento no abierto/vencido: JSON `{ success: false, message: "Este evento ya no recibe registros." }`;
  Inertia → `Eventos/Publico` con `estado: "cerrado"`.
- **404** token desconocido. **429** rate limit.

### Aprobar / descartar asistentes — `POST /eventos/{id}/asistentes/aprobar|descartar`

```jsonc
{ "ids": ["uuid", "uuid"] }          // requerido, 1–200 asistentes de ESTE evento
```

Aprobar (solo `pendiente`; los demás se omiten):

- Si la cédula ya existe en Votantes de la campaña del evento → se vincula (`vinculado`, `lider_id`), **sin tocar la ficha**.
- Si no → crea la ficha en `lideres` en la **campaña del evento** con `tipo = "asistente_evento"`, municipio del
  evento, barrio/dirección/fecha de nacimiento del asistente, `telefono = celular`, `autoriza_datos = true` **solo si
  el consentimiento fue sobre un texto aprobado** (registros anteriores a 2026-11-07; desde entonces queda `false`), `observacion` vacía (nunca el
  nombre del evento: la ficha la leen usuarios sin `view.eventos`; el vínculo queda en `event_attendees.lider_id`) y el fijo como contacto; el asistente
  pasa a `aprobado` con `lider_id`.
- Aprobar dos veces no duplica.

Respuesta: **200** `{ success: true, message: "...", data: { aprobados: 1, vinculados: 1, omitidos: 0 } }`.

Descartar (solo `pendiente`): pasa a `descartado`. **200** `{ success: true, message: "...", data: { descartados: 2, omitidos: 0 } }`.

Ids que no son del evento → 422 (`errors.ids`). Sin `manage.eventos` → 403.

### 6.1 Acciones por asistente (2026-11-10)

Todas exigen `manage.eventos`, evento de la **campaña propia** (de una subcampaña visible → 403; invisible → 404) y
dentro del territorio del usuario (fuera → 403). En pantalla: botón «Acciones» en cada asistente y, con casillas,
selección de pendientes y descartados (Eliminar / Recuperar / Descartar / Pasar a Votantes según lo elegido).

- **Editar** — `PUT /eventos/{id}/asistentes/{asistente}`, cuerpo completo (lo que no llega queda `null`):
  `{ nombres, apellidos, cedula, celular, fijo, fecha_nacimiento, direccion, barrio, nota }`. Reglas de `AttendeeInput`
  (edad mínima 14, cédula 5–12 dígitos, celular 3xxxxxxxxx, fijo 7–10 dígitos, `nota` ≤ 255). Cédula, celular y barrio son
  opcionales **solo** si el asistente llegó sin ellos (listas sin cédula). Cédula de otro asistente del mismo evento →
  422 `errors.cedula`. **Solo antes de pasar a Votantes**: un asistente `aprobado` o `vinculado` → 422
  `errors.asistente` («corrija sus datos en su ficha»); en pantalla esos asistentes no tienen «Editar» sino
  «Ver ficha» (abre el `PersonaPanel` del Mapa Político; solo con `view.mapa`, y solo si la ficha está en su
  territorio). Editar **nunca** modifica la ficha de Votantes. Asistente de otro evento → 404.
  **200** `{ success, message, data: { id, campos: ["nombres", ...] } }` (campos que cambiaron).
- **Recuperar** — `POST .../asistentes/recuperar` `{ ids }`: `descartado` → `pendiente`; los demás se omiten.
  **200** `data: { recuperados, omitidos }`.
- **Desvincular** — `POST .../asistentes/desvincular` `{ ids }`: `aprobado`/`vinculado` → `pendiente` con
  `lider_id = null`. **La ficha se queda en Votantes.** **200** `data: { desvinculados, omitidos }`.
- **Eliminar** — `POST .../asistentes/eliminar` `{ ids }`: borra los asistentes en cualquier estado. **Nunca borra
  fichas de Votantes** (la confirmación lo dice cuando hay aprobados/vinculados). Un asistente con una fila en
  `data_consents` (append-only, con FK) no se borra y se cuenta en `conservados`. **200** `data: { eliminados, conservados }`.

`ids` igual que aprobar: 1–200, todos de este evento (si no → 422 `errors.ids`).

### 6.2 Deshacer una carga de Excel / OCR (2026-11-10)

`GET /eventos/{id}/cargas/{import}/deshacer` (vista previa para la confirmación) y `DELETE /eventos/{id}/cargas/{import}`
(`UndoEventImport`). Solo cargas `confirmado` de este evento (otra → 404). Mismos permisos que §6.1.

- Borra los asistentes con `event_attendees.import_id = {import}` (salvo los que tengan `data_consents`).
- Fichas **creadas** al aprobar asistentes de esa carga (asistente `aprobado`, ficha `tipo = asistente_evento` de la
  campaña del evento): se borran **solo si no tienen otra actividad**: ninguna otra asistencia (otro evento u otro
  asistente), organizador de evento, contactos aparte del fijo que copió la aprobación, información política,
  seguimientos, municipios de influencia, nexos, referidos (otra ficha con `referente_documento` = su cédula),
  etiquetas, favoritos, envíos de WhatsApp, `data_consents`, participante o responsable de un acta (desde 2026-11-11), ni edición posterior (`updated_at` ≠ `created_at`). Las
  demás se conservan. Las fichas que ya existían y solo se **vincularon** nunca se tocan.
- La carga (`event_imports`, con sus filas y páginas) y su archivo también se borran: el mismo archivo se puede volver a subir.
- Todo en una transacción con bloqueo de la carga y de las fichas; el plan se recalcula bajo el bloqueo.

Vista previa **200** `data: { asistentes, con_autorizacion, fichas_borrar, fichas_conservar, vinculadas }`; el DELETE
devuelve lo mismo con el mensaje («Se conservaron X fichas que ya tienen otra información.»).

### Búsquedas

`GET /eventos/buscar/organizadores?q=gom` (≥ 2 caracteres; líderes activos de la campaña propia y del territorio, por
nombre o cédula, máx. 10):

```jsonc
{ "success": true, "data": [ { "id": "uuid", "nombre": "Juan Gómez", "cedula": "...", "telefono": "...", "municipio": "Girón" } ] }
```

`GET /eventos/buscar/figuras?q=uri` (≥ 3 caracteres; personas electorales, máx. 10). Cada persona trae su
**última candidatura** (la de fecha de elección más reciente) para distinguir homónimos; `null` si nunca fue candidata:

```jsonc
{ "success": true, "data": [
  { "id": "uuid", "nombre": "ÁLVARO URIBE VÉLEZ", "ultimo_cargo": "Senado", "ultimo_anio": 2026, "ultimo_lugar": null },
  { "id": "uuid", "nombre": "JUAN PÉREZ", "ultimo_cargo": "Alcaldía", "ultimo_anio": 2023, "ultimo_lugar": "Girón" }
] }
```

`ultimo_cargo` = nombre del cargo (Alcaldía, Gobernación) o de la corporación (Senado, Concejo…); `ultimo_lugar` =
municipio/departamento de la contienda (null en circunscripción nacional).

---

## 7. Tablas (resumen)

`event_types`, `events` (soft delete), `event_barrios`, `event_organizers`, `event_figures`, `event_attendees`,
`consent_texts`, `data_consents` (append-only; su `lider_id` conserva el id histórico con el que se registró: al
unificar líderes no se reasigna, mientras que `event_attendees.lider_id` y `event_organizers.lider_id` sí pasan al
líder que sobrevive). Todas con PK UUID. Únicos: `events.public_token`,
`event_attendees.client_uuid`, `event_attendees (event_id, cedula)` parcial (cédula no nula).
`link_status`: `vinculado | pendiente | sin_cedula | aprobado | descartado | sin_autorizacion` (`sin_cedula` queda reservado;
`sin_autorizacion` es histórico: no se escribe desde 2026-11-07 y el SQL de §11 lo pasa a `pendiente`). `event_attendees.source`: `qr | operador | excel | ocr` (CHECK en PostgreSQL; `excel` lo agrega
la migración `2026_11_01_000001` y `ocr` la `2026_11_03_000001` (§13); ninguna toca `data_consents`). Fase 3 agrega `event_imports`, `event_import_rows` y
`event_attendees.import_id` (FK nullable a `event_imports`) (§11).

### Paso de despliegue obligatorio: dueño de `data_consents` (PostgreSQL)

El usuario de la app crea `data_consents` y, como dueño, podría desactivar el trigger append-only. Tras
`php artisan migrate` que cree la tabla, un superusuario ejecuta **una vez**:

```bash
sudo -u postgres psql -d politica_electoral -v app_user=electoral -f deploy/sql/data_consents_owner.sql
```

Deja la tabla y la función del trigger a nombre de `postgres` y al usuario de la app solo `SELECT, INSERT` (no hay
secuencias: PK UUID). Desde ese momento el usuario de la app **no puede** ALTER/DROP sobre `data_consents`: antes de
una migración que la altere, o de `migrate:rollback` de `2026_10_31_000001_create_eventos_tables` (su `down()` borra la
tabla, la función y las tablas a las que referencia), ejecutar `deploy/sql/data_consents_owner_revert.sql` con el mismo
comando, migrar y volver a ejecutar `data_consents_owner.sql`. El resto de migraciones no se ven afectadas.
`deploy/` está en `.gitignore`: los dos scripts se agregan con `git add -f` (como `agent_ro_role.sql`).

## 8. Auditoría

- Rutas autenticadas: middleware `audit` (nombres de ruta legibles; entidades `evento` y `evento_asistente`; cédula,
  celular y fijo quedan con los últimos 4 dígitos; barrio, dirección y fecha de nacimiento como `[omitido]`; nombres y
  apellidos en claro, igual que el `nombre` del resto de módulos).
- `RegisterAttendee` deja además su propia fila: `EVENTO_ASISTENTE_QR` / `EVENTO_ASISTENTE_OPERADOR`, entidad
  `evento_asistente`, `user_id` null en el QR, metadata `{ event_id, channel, resultado: nuevo|repetido }`, **sin cédula**.
- Aprobar/descartar: `EVENTO_ASISTENTES_APROBADOS` / `EVENTO_ASISTENTES_DESCARTADOS` con ids y conteos.
- Acciones por asistente (§6.1): `EVENTO_ASISTENTES_RECUPERADOS` / `_DESVINCULADOS` / `_ELIMINADOS` con ids y conteos.
  Editar: el middleware registra `PUT eventos.asistentes.update` **solo con los nombres de los campos enviados**
  (`campos`) y `after_state { attendee_id, campos }`: nunca los valores (ni nombres).
- Deshacer carga (§6.2): `EVENTO_CARGA_DESHECHA`, entidad `evento`, metadata `{ import_id, kind, lider_ids_borrados,
  asistentes, con_autorizacion, fichas_borrar, fichas_conservar, vinculadas }`, sin datos personales.
- Descarga de la planilla: `EVENTO_PLANILLA_DESCARGADA`, entidad `evento`, metadata
  `{ route: "eventos.planilla", paginas, consent_text_id }` (el middleware `audit` no registra GET).
- Cargue desde Excel (§11): el middleware `audit` registra `POST eventos.importar.subir`, `PUT eventos.importar.columnas`,
  `PATCH eventos.importar.fila`, `POST eventos.importar.confirmar` y `DELETE eventos.importar.descartar` (entidad
  `evento_importacion`) **solo** con `columnas` (índices), `decision`, `declaracion` y `campos` (nombres de los campos
  enviados): nunca los valores corregidos ni el archivo. `columnas` (índices de columna, sin datos personales) se guarda
  tal cual: está en `config/audit.php` → `mask.keep`, para que `{"cedula": 2}` no se convierta en `cedula_last4`. Además: `EVENTO_PLANTILLA_EXCEL_DESCARGADA` (entidad `evento`),
  `EVENTO_IMPORTACION_SUBIDA` `{ event_id, sha256, filas }`, `EVENTO_IMPORTACION_COLUMNAS` `{ campos }`,
  `EVENTO_IMPORTACION_CONFIRMADA` `{ nuevos, ya_estaban, declaro_planillas, consent_text_id }`,
  `EVENTO_IMPORTACION_DESCARTADA` y `EVENTO_IMPORTACIONES_LIMPIEZA` (comando, sin usuario), entidad `evento_importacion`. Los asistentes de un Excel no dejan una fila por persona.

---

## 9. Planilla imprimible — `GET /eventos/{id}/planilla.pdf`

Hoja para registrar asistentes a mano cuando no hay señal ni celular, y que luego se transcribe (operador) o se
fotografía para lectura automática (Fase 5).

- **Permiso:** `manage.eventos` o `register.asistentes`. Aislamiento **como el detalle** (lectura): campaña propia
  y subcampañas visibles → 200; otra campaña no visible, evento borrado o id no UUID → **404**; fuera del territorio →
  **403**. Sin el permiso: con `Accept: application/json` → 403; visita normal → redirect a `/` (regla general del
  middleware `permission`). Sin sesión → `/login`. Cualquier estado del evento (también cerrado/cancelado).
- **HEAD** (la app instalada sondea antes de navegar): mismas comprobaciones de acceso (404/403; sin permiso → 403),
  responde 200 `application/pdf` con `Content-Disposition` y **sin cuerpo**; no renderiza ni audita. Cupo propio de
  60/min por usuario, aparte de las 10 descargas GET.
- **`?paginas=N`**: por defecto 3; se acota a 1–20 (un valor no entero usa 3). 12 filas por página.
- **Límite:** 10 descargas por minuto **por usuario** (limitador `eventos-planilla`, propio para no compartir cuenta con
  otras rutas `throttle`); la 11.ª → **429** con `Retry-After`. Protege la memoria del VPS (≈120 MB por PDF de 20 páginas).
- **Respuesta:** `application/pdf`, `Content-Disposition: attachment; filename=planilla-{slug del nombre}-{AAAA-MM-DD}.pdf`.
  Carta horizontal. 20 páginas ≈ 210 KB y ≈ 120 MB de memoria pico (el controlador sube `memory_limit` a 256M).
- **Contenido por página:** tipo y nombre del evento, fecha y hora (inicio–fin, America/Bogota), lugar, municipio
  (provincia), barrios; "Página X de Y"; código corto del evento (8 primeros caracteres del id) y **QR**.
  Tabla de 12 filas numeradas: Nombres y Apellidos (MAYÚSCULAS), Cédula (10 casillas), Celular (10), Tel. fijo (7),
  Fecha de nacimiento `DD / MM / AAAA` (8 casillas), Dirección, Barrio y Firma (constancia de asistencia); filas con
  sombreado alterno; marcas de esquina en L negras en las 4 esquinas para enderezar fotos.
  Pie: solo la línea de trazabilidad con el id completo del evento. Desde 2026-11-07 (§4) no hay casilla «Autorizo»,
  ni instrucción de autorizar, ni texto de autorización / "TEXTO PROVISIONAL" en el pie.
- **QR:** PNG (chillerlan/php-qrcode, ECC M) con el JSON `{"e":"<event id>","p":<página>}`. **Nunca** lleva el token
  público. Para la Fase 5: leer el QR, ubicar el evento por `e` y la página por `p`; el total de páginas va impreso.
- **Frontend:** `Eventos/Show` muestra el enlace "Planilla para imprimir" con `can.register` y estado ≠ cancelado.
  El modo operador (otro desarrollador) puede enlazar la misma URL: `register.asistentes` basta.

---

## 10. Cola offline: cabecera `X-Offline-Owner`

La sincronización de la cola offline (`runSync` en `resources/js/lib/offlineDb.js`) debe enviar en **cada** petición
`X-Offline-Owner: <ownerKey del cambio>`, es decir el `owner` guardado con el cambio = `userKey(user.id)`
(`resources/js/lib/userKey.js`: SHA-256 hex de `"electoral:" + id`, o `"id:" + id` sin WebCrypto).

Rutas con el middleware `offline.owner` (`App\Http\Middleware\EnsureOfflineOwner`):

| Método | URI |
|--------|-----|
| POST | `/eventos/{id}/asistentes` |
| POST | `/mapa-politico/crear-lider` |
| PUT | `/mapa-politico/persona/{id}` |
| POST | `/mapa-politico/persona/{id}/nexos` |
| DELETE | `/mapa-politico/nexos/{id}` |

- **Sin la cabecera:** comportamiento de siempre (el uso en línea no cambia).
- **Con la cabecera y coincide** con la cuenta de la sesión (se compara en minúsculas): sigue a los chequeos normales.
- **Con la cabecera y no coincide** (otra cuenta, vacía o basura): **409** sin procesar nada (no guarda, no audita):
  `{ "success": false, "code": "owner_mismatch", "message": "Estos cambios se guardaron sin conexión con otra cuenta. Inicie sesión con esa cuenta para enviarlos." }`
- El chequeo corre **justo después de `auth`** y **antes** de `permission`, `can.write`, el aislamiento por campaña y la
  validación: la cola de otra cuenta recibe siempre 409, nunca un 403/404/422 que la haga descartar. Sin sesión → 401
  (JSON) primero.
- **Frontend:** hoy `isDefinitiveRejection()` trata **todo 409** como rechazo definitivo (marca rechazado y borra el
  registro local). Un 409 con `code: "owner_mismatch"` **no** es definitivo: hay que detener la sync y conservar la cola
  (como un 401/419). El 409 de `/eventos/{id}/asistentes` sin `code` (client_uuid de otro evento) sí sigue siendo definitivo.

---

## 11. Cargue de asistentes desde Excel (Fase 3)

Solo `manage.eventos`, en eventos de la **campaña propia** y del territorio (igual que editar: otra campaña no visible →
404, subcampaña visible → 403, fuera de territorio → 403). Evento `cancelado` → 422 `errors.evento`
(`"El evento está cancelado: no se pueden cargar asistentes."`); se puede cargar en `borrador`, `abierto` y `cerrado`
(las planillas suelen llegar después del evento). Sin cola: todo corre en la petición (límites abajo).

**Sin autorización desde 2026-11-07 (decisión de la campaña, §4):** todo archivo de Excel es una **lista** (ver
"Listas" más abajo): no hay columna «Autorizó» obligatoria (si el archivo la trae, se ignora), no hay declaración
de planillas firmadas ni se exige texto aprobado, y **todas las filas válidas se importan**. Quien tiene cédula
registrada (o se confirma por teléfono + nombre) se vincula al historial de su ficha; el resto queda `pendiente` de
aprobar para pasar a Votantes. `POST .../confirmar` ya no lee `declaracion` (un cliente viejo puede mandarla: se
ignora). Las cargas en staging subidas antes con «Autorizó» (filas `sin_autorizacion = false`) se confirman con su
validación completa, también sin declaración ni texto. No se escribe en `data_consents`.

### Paso de despliegue 2026-11-07 (datos, PostgreSQL de producción)

Los asistentes de listas cargadas antes (`link_status = sin_autorizacion`, 118 en prod el 2026-11-07, sin ficha)
pasan a pendientes para poder aprobarlos. Ver antes, aplicar en una transacción y comprobar:

```sql
-- 1) cuántos (dry run)
SELECT count(*) FROM event_attendees WHERE link_status = 'sin_autorizacion' AND lider_id IS NULL;
-- 2) aplicar
BEGIN;
UPDATE event_attendees SET link_status = 'pendiente', updated_at = now()
 WHERE link_status = 'sin_autorizacion' AND lider_id IS NULL;
-- el número de filas debe coincidir con el paso 1; si no, ROLLBACK;
COMMIT;
```

No toca `data_consents` ni `lideres`. Los que no tienen cédula se aprueban creando una ficha sin cédula.

### Plantilla — `GET /eventos/{id}/plantilla.xlsx`

Hoja **Asistentes** con encabezados fijos: `Nombres`, `Apellidos`, `Cédula`, `Celular`, `Teléfono fijo`,
`Fecha de nacimiento (DD/MM/AAAA)`, `Dirección`, `Barrio`, `Municipio`, `Liderazgo o actividad`
(estas dos opcionales). Columnas C–F (cédula, celular, fijo y fecha) con formato **texto** en las filas 2–2001 (Excel
no muestra `1,09877E+09` ni quita ceros). Hoja **Instrucciones** con cómo llenarla (sin texto de autorización desde
2026-11-07).
Nombre: `plantilla-asistentes-<evento>.xlsx`.

### Flujo y estados de `event_imports.status`

1. `POST /eventos/{id}/importar` (multipart, campo `archivo`): `.xlsx`, `.xls` o `.csv` (por extensión; el contenido lo
   valida el lector), **máx. 5 MB** (o lo que permita PHP, ver límites) y **2000 filas** con datos. Se guarda en el disco
   **privado** (`storage/app/private/eventos/importaciones/{event}/{import}.{ext}`, sin URL pública) con su `sha256`,
   se leen las filas a staging y se adivinan las columnas → `subido`.
   - CSV: separador `;`, `,` o tabulador (el más frecuente en la primera línea); UTF-8 o Windows-1252.
   - Fila de encabezados: la que más encabezados conocidos tenga entre las 10 primeras filas con datos (admite títulos
     arriba y columnas vacías a la izquierda; los encabezados se comparan sin tildes, mayúsculas, signos ni saltos de línea).
   - **Libro con varias hojas** (una por jornada/municipio): se guarda la lista de hojas (`event_imports.sheets`:
     `[{ nombre, titulo }]`, `titulo` = el primer texto largo de las primeras filas, p. ej. `JORNADA "DEL CONGRESO AL
     BARRIO" VIERNES 23.10.2026…`) y se carga la hoja cuyo nombre coincide con el municipio del evento (si ninguna,
     `Asistentes` o la primera). `PUT /eventos/{id}/importar/{import}/hoja` `{ "hoja": "LEBRIJA" }` (no confirmada,
     throttle `eventos-importar`) vuelve a leer **esa** hoja del archivo guardado, reemplaza las filas y vuelve a
     `subido` (hay que revisar columnas otra vez); hoja inexistente → 422 `errors.hoja`. **Un evento = una hoja**: el
     mismo archivo puede subirse a otro evento y tomar otra hoja. Auditoría `EVENTO_IMPORTACION_HOJA` (solo conteo).
     CSV y libros de una sola hoja: `hoja` y `hojas` vacíos.
   - Más de 2000 filas → 422. Se detecta aunque haya filas vacías en medio: en `.xlsx/.xls` cualquier celda (incluso
     solo con formato) por debajo de la fila 2011 cuenta como exceso; en CSV, cualquier línea **no vacía** por debajo.
     Mensaje: `"El archivo tiene más de 2000 filas. Divídalo en varios archivos y cárguelos uno por uno. Si no son tantas
     personas, borre las filas que sobren al final de la hoja."`.
   - `.xlsx` inflado (zip bomb): antes de abrirlo se revisa el zip; si descomprimido pasa de **50 MB** o una entrada de
     1 MB o más está comprimida más de **100:1** → 422 `"El archivo es demasiado grande por dentro para leerlo. Copie solo
     las filas de los asistentes en un libro nuevo de Excel, guárdelo como .xlsx e intente de nuevo."`.
   - Mismo archivo (mismo `sha256`) ya subido a **este** evento: no se vuelve a guardar. Si esa carga ya se confirmó →
     JSON 200 `{ success: false, message: "Este archivo ya fue cargado en este evento. No se volvió a importar.",
     data: { id, status, repetido: true, filas } }` (Inertia: redirect a esa carga con `flash.error`); si no se había
     confirmado → `"Este archivo ya estaba subido: puede seguir donde lo dejó."`.
   - Otros 422 en `errors.archivo`: tipo, tamaño, archivo ilegible, sin filas.
   - Cuerpo por encima de `post_max_size` de PHP → **413** antes de llegar a la app: JSON
     `{ success: false, message, errors: { archivo: ["El archivo es demasiado grande para subirlo. Divídalo en varios
     archivos más pequeños e intente de nuevo."] } }`; en navegación, la página `errors/413` en español. La pantalla
     revisa el tamaño antes de enviar, así que normalmente no se llega a este caso.
   - Éxito: **201** `{ success: true, message: "Archivo recibido: N filas. Revise las columnas.", data: { id, status: "subido", repetido: false, filas } }`
     ("1 fila" en singular).
   - Columnas adivinadas: primero el **último mapeo revisado de la campaña** (por nombre de encabezado), después
     sinónimos (`CC`, `C.C.`, `Documento`, `No. cédula`, `Cédula de ciudadanía`, `No. documento identidad (cédula)` →
     cédula; `Nombre completo`, `Nombres y apellidos` → nombre completo; `Tel`, `Teléfono`, `Móvil`, `Celular`,
     `Celular /fijo`, `WhatsApp` → celular; un segundo teléfono → fijo; `Fecha de cumpleaños` → fecha de nacimiento;
     `Autorizó`, `Autoriza`, `Firmó`, `Acepta` → autorizó; `Municipio`, `Ciudad` → municipio; `Liderazgo`, `Actividad`,
     `Rol`, `Observación` → liderazgo). Una sola columna `Nombre` sin columna de apellidos se
     toma como nombre completo.
2. `PUT .../columnas` `{ "columnas": { campo: índice de columna | null } }` con campos `nombres`, `apellidos`,
   `nombre_completo`, `cedula`, `celular`, `fijo`, `fecha_nacimiento`, `direccion`, `barrio`, `autoriza`, `municipio`,
   `liderazgo`. Obligatorios: (`nombres` + `apellidos`) o `nombre_completo`; **con** `autoriza`, además `cedula` y
   `celular`; **sin** `autoriza` (lista sin autorización), `cedula` o `celular` (`"Elija la columna de la cédula o la del
   teléfono: sin ninguna no se puede saber si la persona ya está en la plataforma."`); una columna no puede usarse dos
   veces (422 `errors.columnas`). Aplica el mapeo a **todas** las filas (borra correcciones previas), valida y clasifica
   → `revisado`; este mapeo queda guardado para la próxima carga de la campaña. **200** `{ success, message, data: { counts } }`.
3. `PATCH .../filas/{fila}` (solo en `revisado`): cualquiera de `nombres`, `apellidos`, `cedula`, `celular`, `fijo`,
   `fecha_nacimiento`, `direccion`, `barrio`, `municipio`, `liderazgo` y/o `decision: "importar" | "omitir"`, o
   `sugerencia: "confirmar" | "rechazar"` (lista sin autorización, ver abajo). **`autoriza` no es editable** (se
   ignora si llega): vale lo que dice la planilla. Una fila `no_autorizo` **no se puede cambiar** (ni celdas ni
   decisión): 422 `errors.fila` `"Esta fila no se puede cambiar: en «Autorizó» no dice SÍ, la persona no autorizó el
   tratamiento de sus datos."`. Un valor malo **no** es 422: se guarda y la fila queda con su error.
   `importar` en una fila que no es `valida` → 422 `errors.decision` (p. ej. `"Esta fila no se puede importar: en
   «Autorizó» no dice SÍ: la persona no autorizó el tratamiento de sus datos."`). Revalida la fila y reclasifica el
   archivo. **200** `{ success, message, data: { fila: Fila, counts } }`.
4. `POST .../confirmar` `{ "declaracion": true }` (desde `revisado`/`confirmado`; una lista sin autorización se confirma
   con `{}`: no pide declaración ni texto aprobado). Con filas con SÍ por importar y sin la declaración → 422
   `errors.declaracion` (`"Para importar, marque «Tengo las planillas firmadas que respaldan las autorizaciones de este
   archivo»."`). Sin texto de autorización **aprobado** → 422 `errors.consent` (ver arriba). Registra **solo** las filas `valida`
   (que ya exigen SÍ) con decisión distinta de `omitir` y sin asistente, por lotes de **200** en transacción, a través
   de `RegisterAttendee::handleMany` (mismas reglas que `handle`, con una consulta y un insert por tabla por lote; lo ya
   registrado o un choque de índice único pasa por `handle`): `source = "excel"`, `import_id` = la carga,
   `registered_by` = quien confirma, `client_uuid` = UUID v5 de (`import_id`, `fila-<n>`). Confirmar otra vez no crea
   nada (`nuevos: 0`). **200** `{ success, message, data: { nuevos, ya_estaban } }` (mensaje `"Se registraron N
   asistentes nuevos, con autorización de datos."`, en singular con 1). Los nuevos quedan `pendiente`
   (o `vinculado` si la cédula ya existe en Votantes de la campaña): pasar a Votantes sigue siendo manual (§6, aprobar).
   - `event_imports.status/consent_text_id/consent_declared/confirmed_by/confirmed_at` se fijan en la **primera**
     confirmación y no cambian después.
   - Cada fila importada guarda los suyos: `event_import_rows.declared`, `confirmed_by`, `confirmed_at`.
5. `DELETE /eventos/{id}/importar/{import}` (no confirmada): borra la carga, sus filas y el archivo.
   Una carga `confirmado` no se puede cambiar ni descartar (422 `errors.importacion`).

### Validación por fila (mismas reglas que QR/operador, `AttendeeInput`, con estas diferencias)

- Cédula: se normaliza (`App\Support\Cedula`) y además se arreglan los números de Excel: celda numérica
  (`1098765432` leído como `1098765432.0`), `"1098765432.0"` (5 o más dígitos antes del punto) y notación científica
  **exacta** (tantos decimales como el exponente: `"1.098765432E9"` → `1098765432`); `"1.098.765.432"` queda
  `1098765432`. Debe tener **5 a 10 dígitos**.
- Notación científica **redondeada** (Excel ya perdió dígitos: `1,09877E+09`) en cédula, celular o fijo → error de esa
  celda: `"La cédula llegó en notación científica (1,09877E+09); escríbala completa."` (y lo mismo para `"El celular…"`,
  `"El teléfono fijo…"`, "escríbalo completo"). Se corrige en la vista previa.
- Celular obligatorio (10 dígitos, empieza por 3; acepta +57, espacios, guiones). Fijo opcional (7–10 dígitos).
- Nombres y apellidos obligatorios. `Nombre completo` se divide si no vienen por separado: 2–3 palabras → 1 nombre +
  el resto apellidos; 4 o más → 2 nombres + el resto. Una sola palabra → error en apellidos (corregible).
- Fecha: `DD/MM/AAAA`, `D-M-AAAA`, `DD.MM.AAAA`, `AAAA-MM-DD` o fecha de Excel; formato malo → `"La fecha de nacimiento
  debe tener el formato DD/MM/AAAA."`; inexistente → `"Esa fecha no existe."`; menor de 18 → mensaje de siempre.
- Barrio **opcional** (en QR/operador es obligatorio). Dirección opcional.
- `Autorizó`: `SÍ/SI/S/X/1/VERDADERO` = sí. Cualquier otra cosa (NO, vacío, "quizás") = **no autorizó**.

Estados de fila (`event_import_rows.status`), en este orden de prioridad: `no_autorizo` (no dice SÍ: nunca importable,
no se puede seleccionar ni corregir), `error`, `duplicada` (misma cédula que una fila importable anterior del archivo;
una fila `no_autorizo` o con error no "ocupa" la cédula), `en_evento` (la cédula ya está registrada en el evento),
`valida` y `pendiente` (sin revisar). `match_lider_id` = líder de la **campaña del evento** con esa cédula ("Ya estaba en
la plataforma"). Decisión: `crear` (válida nueva), `vincular` (válida en la plataforma) u `omitir` (el resto, o elegida
por el operador).

`counts`: `{ total, autorizaron, no_autorizaron, validas, en_plataforma, nuevas, errores, duplicadas, en_evento,
a_importar, omitidas, importadas }` (`validas = nuevas + en_plataforma`; `autorizaron = total - no_autorizaron`;
`omitidas` = válidas marcadas "No importar").

### Prueba de la autorización en `data_consents` (Ley 1581) — HISTÓRICO: solo cargas confirmadas antes del 2026-11-07

- **Canal `operador`, no `excel`.** `data_consents` es de dueño `postgres` en producción y su CHECK solo admite
  `qr|operador`; ampliarlo exigiría correr `data_consents_owner_revert.sql`, migrar y volver a correr
  `data_consents_owner.sql`. Se usa el canal ya permitido (la autorización la recogió un operador en papel) y la
  migración de Fase 3 **no toca** `data_consents`.
- **Trazabilidad del origen Excel** sin depender del staging: `data_consents.attendee_id` → `event_attendees`
  (`source = 'excel'`, **`import_id`** → `event_imports`, que conserva `file_sha256`, el archivo, `consent_text_id`,
  `consent_declared`, `confirmed_by`, `confirmed_at`) y `event_import_rows` (`attendee_id`, `declared`, `confirmed_by`,
  `confirmed_at`, número de fila).
- `data_consents.accepted_at`, `ip` y `user_agent` son los de la **confirmación** en la plataforma (quién declaró tener
  las planillas, desde qué navegador y cuándo), **no** el día ni el lugar en que la persona firmó la planilla de papel.
  El texto guardado es el vigente **aprobado** de la campaña al confirmar (con el provisional no se puede confirmar), así
  que al pasarlos a Votantes quedan con `autoriza_datos = true`.

### Retención del staging — `php artisan eventos:limpiar-importaciones`

`raw` y `mapped` contienen datos personales. El comando, programado en `routes/console.php` a diario a las 03:30:
- borra las cargas **no confirmadas** con más de **7 días** (filas y archivo);
- a las cargas **confirmadas** hace más de **30 días** les pone `raw` y `mapped` en null y marca
  `event_imports.staging_purged_at`; conserva número de fila, estado, decisión, `attendee_id`, `declared`, quién y
  cuándo confirmó, el archivo y su `sha256`. La página de esa carga avisa que el contenido de las filas ya se borró.
- Deja una fila de auditoría `EVENTO_IMPORTACIONES_LIMPIEZA` con los conteos (sin datos personales).

**Paso de despliegue (cron):** hoy producción **no** tiene el cron del scheduler. Hace falta agregar en el servidor
(usuario `www-data`): `* * * * * cd /var/www/electoral && php artisan schedule:run >> /dev/null 2>&1`. Sin él, ni esta
limpieza ni `cache:prune-expired` corren solas (se pueden correr a mano).

### Página `Eventos/Importar`

```jsonc
{
  "evento": { "id", "nombre", "status", "starts_at", "municipio", "campaign": { "id", "nombre" }, "acepta_cargas": true },
  "consent": { "id", "version", "approved" } | null,   // null o approved=false ⇒ no se puede subir ni importar (aviso en rojo)
  "importacion": null | {
    "id", "nombre", "status": "subido|revisado|confirmado", "filas": 50, "fila_encabezados": 1,
    "encabezados": ["Nombres", "..."], "columnas": { "cedula": 2, "nombres": 0 }, "counts": { } | null,
    "declaro_planillas": true, "datos_borrados": false, "created_at", "confirmed_at"
  },
  "muestra": [ { "0": "Ana", "2": 1098765432 } ],    // 3 primeras filas tal como se leyeron (solo en "subido")
  "filas": null | paginador (50) de Fila,            // ?estado=nuevas|plataforma|error|no_autorizo|duplicada|en_evento|omitidas
  "filters": { "estado": null },
  "campos": [ { "key": "nombres", "label": "Nombres" } ],
  "limites": { "filas": 2000, "mb": 5 },
  "recientes": [ { "id", "nombre", "status", "filas", "importadas", "created_at", "created_by" } ]   // 10 últimas del evento
}
```

`Fila`: `{ id, fila (número en el Excel), status, decision, en_plataforma, importada, corregida, valores: { campo: texto },
errores: { campo: mensaje } }` (`valores` vacío después de la limpieza de 30 días).

UI: conteos (con SÍ, válidas, ya en la plataforma, no autorizaron, con error, repetidas, ya en el evento), filtros por
estado incluidos "No autorizaron" y "No importar", resumen de lo que queda fuera al confirmar ("Quedan fuera: 2 con
error, 1 repetida en el archivo, 1 marcada «No importar», 3 que no autorizaron (no dice SÍ)…"), el botón **Importar N
personas** queda deshabilitado hasta marcar la declaración y pide confirmación ("Se importarán N personas. Esta acción no
se puede deshacer."). Textos de 16 px o más (18 px en lo que lee el operador) y enlaces/botones de 44 px de alto o más.

### Listas sin autorización (sin cédula y/o sin «Autorizó») — decisiones A y B de Marly, 2026-11-05

> **Cambio 2026-11-07:** desde ese día todo archivo es una lista y quien no está en la plataforma queda `pendiente`
> (aprobable), ya no `sin_autorizacion` "solo en la lista del evento". Lo de abajo sobre autorización es histórico.

Listas reales (Word/Excel con `MUNICIPIO | NOMBRE | LIDERAZGO | TELÉFONO`, o las de jornadas con `NOMBRES Y APELLIDOS |
NO. DOCUMENTO IDENTIDAD (CEDULA) | CELULAR /FIJO | FECHA DE CUMPLEAÑOS | DIRECCIÓN | BARRIO | ACTIVIDAD`) no traen
autorización. Se aceptan **solo como asistencia**: nunca crean ni modifican fichas y nunca registran autorizaciones.

- **Cuándo:** al revisar columnas, si no hay columna `autoriza` o está vacía en todas las filas, todas las filas quedan
  `event_import_rows.sin_autorizacion = true`. Si al menos una fila dice algo en `Autorizó`, el archivo sigue el flujo
  de siempre (vacío = `no_autorizo`).
- **Validación:** solo el nombre es obligatorio (`"Escriba el nombre de la persona."`); cédula opcional (si viene, 5 a 10
  dígitos; notación científica redondeada = error); teléfono en texto libre (se leen todos los números: espacios,
  puntos, guiones, `+57` y dos números en la celda); una fecha de nacimiento inválida o de menor de 18 **no** es error:
  se descarta. `liderazgo`/actividad se guarda como `event_attendees.nota` (máx. 255), **no** cambia el cargo de la ficha.
- **Estados** (además de `error`, `duplicada` —misma cédula, misma ficha o mismo nombre+teléfono en el archivo— y
  `en_evento` —cédula, ficha o nombre+teléfono ya en el evento—):
  - `valida` + `vincular`: la cédula existe en la campaña del evento, o el operador confirmó la sugerencia → al
    confirmar, asistente `link_status = vinculado` con `lider_id` (aparece en la sección Eventos de la ficha).
  - `sugerida` (decisión `omitir`, **no importable**): sin cédula que coincida, mismo teléfono (solo dígitos, últimos 10,
    en `lideres.telefono` o `lider_contactos.valor`) **y** al menos una palabra del nombre en común (sin tildes; se ignoran
    palabras de 1–2 letras y `del/las/los/san…`), entre los líderes activos de la campaña del evento en el municipio del
    evento o en el de la fila (columna `Municipio`, solo si está en el territorio del usuario). Un líder con **otra**
    cédula nunca se sugiere. `suggested_lider_id` guarda el candidato; la fila trae
    `sugerencia: { id, nombre, municipio, cargo, estado: null|"confirmada"|"rechazada" }` (solo si el líder está en el
    territorio de quien mira). **Nunca se vincula sin el clic del operador.** Solo para usuarios con `view.votantes` o
    `view.mapa` (muestra datos de la ficha): sin esos permisos no se sugiere a nadie y la fila queda `sin_ficha`. Si dos
    fichas empatan (mismo número de palabras en común) no se sugiere ninguna. Confirmar exige que el líder siga en la
    campaña del evento y en el territorio del usuario (si no, 422 `errors.sugerencia`).
  - `PATCH .../filas/{fila}` `{ "sugerencia": "confirmar" }` → `valida` (vincular); `{ "sugerencia": "rechazar" }` →
    `sin_ficha`. Fila sin sugerencia → 422 `errors.sugerencia`. Corregir nombre o teléfono borra la respuesta (se
    vuelve a sugerir). Auditoría `EVENTO_IMPORTACION_SUGERENCIA` `{ event_id, fila, resultado }` (sin datos personales).
  - `sin_ficha` + `crear`: no está en la plataforma → al confirmar, asistente **sin ficha**: `lider_id = null`,
    `link_status = sin_autorizacion`, `autoriza_datos = false`, sin `consent_id` ni fila en `data_consents`.
    **No se puede aprobar** a Votantes (ApproveAttendees solo toma `pendiente`) ni aparece en Votantes/Mapa; queda en la
    lista del evento ("Sin autorización: queda solo en la lista del evento") hasta que la persona autorice por QR u
    operador.
  - **Autorizar después:** si un asistente sin autorización (de una lista) **con cédula** se registra luego en el mismo
    evento por QR, operador o Excel con SÍ, ese mismo registro se completa (transacción con bloqueo): fila en
    `data_consents`, `consent_id`, `autoriza_datos = true`, `link_status = vinculado` si tiene (o ahora encuentra por
    cédula) su ficha, o `pendiente` (aprobable) si no; solo se llenan los campos vacíos. Responde como registro nuevo
    (auditoría `resultado: "autorizo"`); en el Excel con SÍ esa fila es `valida` (no `en_evento`). Sin cédula no hay
    forma segura de reconocerlo: el registro con autorización queda aparte.
- **Confirmar:** respuesta `data: { nuevos, ya_estaban, historial, solo_evento }`; mensaje `"Se registraron N asistentes
  sin autorización de datos: X quedaron en el historial de su ficha y Y quedaron solo en la lista del evento."`.
  `event_import_rows.declared = false`; `event_imports.consent_text_id = null`, `consent_declared = false`. Idempotente
  por `client_uuid`, por cédula en el evento y por ficha en el evento.
- **`counts` nuevos:** `sin_autorizacion` (filas de lista), `sugeridas`, `sin_ficha`, `a_importar_con_autorizacion`
  (filas con SÍ por importar: si es 0 la pantalla no pide declaración ni texto aprobado). `a_importar` y `omitidas`
  incluyen `sin_ficha`; `autorizaron = total − no_autorizaron − sin_autorizacion`. Filtros nuevos `?estado=sugerida|sin_ficha`.
- **`importacion` (props):** `sin_autorizacion` (bool), `hoja`, `hojas`. **Fila:** `sin_autorizacion`, `sugerencia`.
- **Pantalla del evento (`Eventos/Show`):** filtro "Sin autorización", KPI `kpis.sin_autorizacion`, cada asistente trae
  `nota` y `ficha: { cargo, lider }` (cargo de la ficha vinculada y si es líder registrado —no una ficha creada desde un
  evento—) **solo** para usuarios con `view.votantes` o `view.mapa` y dentro de su territorio; si no, `ficha: null`.
  `consentimiento_provisional` es `false` para asistentes sin autorización. Exporte: columna texto `"Sin autorización"`.
- **Migración** `2026_11_05_000001_add_list_import_to_eventos` (aditiva, no toca `data_consents`):
  `event_attendees.nota`, `event_imports.sheet_name/sheets`, `event_import_rows.sin_autorizacion/suggested_lider_id/
  suggestion`; en PostgreSQL rehace los CHECK de `event_import_rows.status` (+ `sugerida`, `sin_ficha`),
  `event_import_rows.suggestion` y `event_attendees.link_status` (+ `sin_autorizacion`). Dueño de esas tablas en prod:
  `electoral` (verificado 2026-11-05), así que no requiere los scripts de `data_consents`.

### Límites y rendimiento

Síncrono (no hay worker de colas en producción): `memory_limit` se sube a 256M solo si el actual es menor (nunca se
baja) y `set_time_limit(120)` en subir, columnas y confirmar. Throttle `eventos-importar` (20/min por usuario) en subir,
columnas y confirmar, y `eventos-importar-fila` (120/min por usuario) en corregir/omitir una fila y en descartar: es una
petición por clic, así que 20/min frenaría a quien revisa una planilla larga.

**Paso de despliegue (PHP-FPM):** producción tiene `upload_max_filesize = 2M` (nginx ya permite 50M). El límite
efectivo es el menor entre 5 MB y lo que permita PHP (`ImportUploadRequest::maxKb()`), y la pantalla, la plantilla y
los mensajes lo dicen; para que sean 5 MB, en `/etc/php/8.3/fpm/php.ini` poner `upload_max_filesize = 6M` (con
`post_max_size = 8M`, que ya está) y `systemctl reload php8.3-fpm`.

Medido con 2000 filas, todas con SÍ (test `EventImportPostgresTest::test_two_thousand_rows_on_postgresql`, PostgreSQL 16
local en Docker): subir 0,3 s, revisar 1,0 s, confirmar 0,5 s; pico de memoria del proceso de test ~102 MB.

---

## 12. Integración con Votantes, Mapa Político y vista por territorio (Fase 4)

**Regla de permisos (decisión de la Fase 4):** saber a qué eventos fue una persona es dato sensible (opinión
política, Ley 1581) y en el módulo solo lo ve quien tiene `view.eventos` o `manage.eventos` (el detalle con
asistentes). Por eso, **sin `view.eventos`/`manage.eventos` no se muestra nada de eventos** fuera del módulo: ni la
pestaña/sección "Eventos" de la ficha, ni el filtro ni la columna "N eventos" de Votantes, ni la vista por territorio
(tampoco el conteo). `register.asistentes` solo no basta. En el frontend: `canSeeEventAttendees(auth.user)`
(`resources/js/lib/eventosIntegracion.js`).

Alcance de todo lo de esta sección (`App\Services\Eventos\EventScope`): eventos **no borrados** de las campañas
**visibles** (propia + subcampañas) y, si el usuario tiene `territory_scope`, cuyo `municipio_id` está en su territorio.
Nada de campañas no visibles llega a la pantalla ni al Excel.

### Ficha de la persona — `GET /eventos/persona/{liderId}`

Eventos a los que la ficha (líder) **asistió** (`event_attendees.lider_id`) o que **organizó**
(`event_organizers.lider_id`). Permisos: `view.eventos|manage.eventos` **y** `view.mapa|view.votantes`.
Líder inexistente, id no UUID o de una campaña no visible → **404**; líder fuera del territorio → **403**; sin permiso →
**403** (JSON). Un evento fuera del territorio del usuario no se lista aunque la ficha sí esté en él.

```jsonc
{ "success": true, "data": {
    "total": 2,          // eventos distintos
    "asistio": 2, "organizo": 1,
    "eventos": [         // una fila por participación (asistió y organizó el mismo evento = 2 filas), starts_at desc
      { "id": "uuid", "nombre": "...", "tipo": "Gira", "status": "cerrado", "starts_at": "ISO", "municipio": "Girón",
        "barrios": ["San Martín"], "campaign_id": "uuid",
        "participacion": "asistente|organizador",
        "rol": "Asistente" | "Organizador" | "Organizador (Coordinadora)",
        "origen": "qr|operador|excel" | null,   // solo para asistente
        "url": "/eventos/{id}" }
    ] } }
```

Frontend: pestaña **Eventos** en `PersonaPanel` (Mapa Político; solo fichas `source = lider` y en línea) y sección
**Eventos** en `VotanteDetail` (/votantes); ambas cargan al abrirse (`Components/Eventos/PersonaEventos.jsx`).

### Votantes — filtros, columna y contadores

- `?evento=<uuid>` → "Asistió al evento…": fichas con una fila en `event_attendees` de ese evento. Un evento no visible
  (otra campaña, borrado, fuera del territorio) o un valor que no es UUID **se ignora** (como `campana`).
- `?asistentes_eventos=1` → "Asistentes a eventos (cualquiera)": asistió a algún evento visible. Con `evento` manda
  `evento`. Organizar un evento **no** cuenta como asistir.
- Los contadores (`counters.total`, `gran_elector`, `con_referidos`) y el export (`/exportar/excel|pdf?source=votantes`,
  que reenvía la misma query) respetan el filtro: mismas filas que la pantalla.
- Cada fila trae `eventos_count` (eventos visibles distintos a los que asistió); la tabla muestra la insignia
  "N eventos". Sin `view.eventos` la clave no viene y `evento`/`asistentes_eventos` se ignoran.
- Props nuevas de `Votantes`: `canViewEventos` (bool) y `eventos` (`[{ id, nombre, starts_at, municipio }]`, los 300
  más recientes visibles; `[]` sin permiso). `filters` devuelve `evento` y `asistentes_eventos` solo si se aplicaron.

### Mapa Político — tipo "Asistentes a eventos"

**Sin `view.eventos`/`manage.eventos` no existe este tipo** (decisión del orquestador): para ese usuario las fichas
`asistente_evento` son líderes normales en todo el Mapa Político: salen en la sección "Líderes" con la etiqueta
"Líderes", la ficha dice `tipo_registro = "Líderes"`, `tipo=asistentes_evento` se ignora, el filtro de tipo no ofrece la
opción, `cargosPorTipo` no trae la clave, el export las etiqueta "Líderes" y la copia offline las incluye como líderes.
Si ese usuario guarda la ficha, sigue siendo `asistente_evento` (salvo que marque "Nuevos Líderes"). Ningún JSON ni
prop le devuelve el valor crudo: `EventScope::visibleTipo()` lo cambia por `directorio` en `/votantes` (props y
`GET /votantes/{id}`) y en `/lideres/{municipio}`; ficha del Mapa, offline, búsqueda de líderes, referidos y export
de Votantes no envían `tipo`. Lo que sigue aplica
solo a quien **sí** tiene el permiso:

- Las fichas creadas al aprobar asistentes (`lideres.tipo = asistente_evento`) son un tipo propio,
  **apagado por defecto**: `tipo=todos` (o sin `tipo`) **no** las incluye, así que los conteos no cambian. Salen de
  "Líderes" y de "Directorio Municipal" (y de la copia offline del municipio).
- Se activan con `tipo=asistentes_evento` (valor del filtro; etiqueta **"Asistentes a eventos"** en filtros, chips,
  sección, columna Tipo y exports). Con todos los tipos marcados la página vuelve a agrupar por secciones e incluye la
  sección "Asistentes a eventos" (lista plana, como Líderes). `cargosPorTipo.asistentes_evento` = cargos de líderes.
- Opciones de barrio (`barrios`): con el tipo apagado no incluyen los barrios de fichas `asistente_evento`. Para quien no
  tiene el permiso sí los incluyen, porque ahí esas fichas son líderes listados y su barrio debe poder filtrarse.
- La ficha muestra `tipo_registro = "Asistentes a eventos"` y, solo en esas fichas, la casilla "Asistentes a eventos" en
  Tipo. Al guardar sigue siendo asistente mientras esa casilla siga marcada y no se marque "Líderes" ni "Nuevos Líderes";
  marcar "Líderes" o desmarcar la casilla la convierte en líder. "Crear líder" no ofrece este tipo.
- Verificado con la BD local: los conteos por sección de los 87 municipios y de la vista general son idénticos antes y
  después del cambio (en producción hoy no hay fichas `asistente_evento`).

### Vista por territorio — `GET /eventos/territorio` (Inertia `Eventos/Territorio`)

Query: `municipio` (uuid de municipio; fuera del territorio → **403**; otro valor → sin selección), `barrio` (texto
opcional, ≤ 120), `page` (asistentes, 20 por página), `pagina_lideres` (líderes, 25 por página).
Enlace "Ver por territorio" en `/eventos` (con `can.view`).

**Barrio normalizado:** se compara la clave `AttendeeInput::barrioKey` = minúsculas, sin tildes y con espacios
colapsados (la misma que ya guardan `event_barrios.nombre_normalizado` y `event_attendees.barrio_normalizado`);
"San Martín", "SAN  MARTIN" y "san martin" son el mismo barrio. `lideres.barrio` no tiene columna normalizada: se
toman las grafías del municipio que dan esa clave.

```jsonc
{
  "filters": { "municipio": "uuid|null", "barrio": "San Martín|null" },     // barrio con espacios colapsados
  "municipio": { "id", "nombre": "Girón", "provincia": "Área Metropolitana" } | null,
  "provincias": [ ... ],                       // como en Index, limitadas al territorio
  "barrios": [ { "nombre": "San Martín", "key": "san martin", "total": 5 } ],  // sugerencias del municipio: una por clave,
                                               // con la grafía más usada (lideres.barrio, event_barrios, event_attendees.barrio)
  "resultado": null | {
    "kpis": { "lideres": 2, "eventos": 1, "asistentes": 4, "personas": 4, "en_votantes": 3 },
    "lideres": paginador de { id, nombre, cedula, telefono, barrio, cargo, partido, campaign_id },
    "eventos": [ { id, nombre, tipo, status, starts_at, campaign_id, asistentes, asistentes_barrio|null, url } ],  // máx. 200
    "asistentes": paginador de { id, nombres, apellidos, cedula, celular, barrio, source, link_status, en_votantes,
                                  evento: { id, nombre, starts_at }, campaign_id }
  }
}
```

- **Líderes:** fichas activas del municipio (con barrio: las de ese barrio), de campañas visibles, **sin** las de tipo
  `asistente_evento` (esas personas salen en Asistentes).
- **Eventos:** del municipio; con barrio, los que tienen ese barrio en sus barrios (`event_barrios`).
  `asistentes` = total del evento; `asistentes_barrio` = los que viven en ese barrio (null sin barrio).
- **Asistentes:** de los eventos del municipio; con barrio, los que viven en él (`event_attendees.barrio_normalizado`).
- **KPIs:** `asistentes` = registros de asistencia; `personas` = cédulas distintas; `en_votantes` = fichas distintas
  vinculadas.

### Exportes Excel

Ambos usan `GenericExport` (las celdas que empiezan por `=`, `+`, `-` o `@` se guardan con `'` delante) y dejan
auditoría (el middleware `audit` no registra GET), sin datos personales en la metadata.
`GenericExport` escribe como **texto** (tipo `s` y formato `@`) las columnas `cedula`, `documento`,
`referente_documento`, `celular`, `fijo` y `telefono` en todos los Excel que lo usan (también Mapa Político y Votantes;
el export de Votantes ahora trae `cedula` disponible para `?columns=`).

- **Asistentes del evento** — `GET /eventos/{id}/asistentes.xlsx` (botón "Descargar Excel" en el detalle). Mismo
  acceso que el detalle (404/403 de campaña y territorio; sin permiso: JSON 403, visita → `/`) y **mismos filtros**
  (`link_status`, `source`, `q`), sin paginar. Columnas: Nombres, Apellidos, Cédula, Celular, Teléfono fijo, Fecha de
  nacimiento, Dirección, Barrio, Autorizó datos, Texto de autorización (Aprobado/Provisional), Origen (QR/Operador/Excel),
  Registrado por, Estado de vínculo, En Votantes, Fecha de registro. Nombre `asistentes-<evento>.xlsx`. Auditoría
  `EVENTO_ASISTENTES_EXPORTADOS` `{ route, filas, filtros }`.
- **Territorio** — `GET /eventos/territorio/excel?municipio=&barrio=` (sin municipio → 404). Libro con 3 hojas,
  **Líderes**, **Eventos** y **Asistentes**, con las mismas consultas que la pantalla sin paginar (eventos sin el tope
  de 200). Columna Campaña en las tres hojas, solo si el usuario ve más de una campaña. Nombre `territorio-<municipio>[-<barrio>].xlsx`. Auditoría
  `EVENTOS_TERRITORIO_EXPORTADO` `{ route, municipio_id, barrio (bool), lideres, eventos, asistentes }`.
- El botón "Exportar" del encabezado (que exporta el Mapa Político) ya no aparece en las páginas `/eventos/*`.

### Importación desde Excel: aislamiento antes de validar (OBS-07)

`ImportUploadRequest`, `ImportMappingRequest`, `ImportRowRequest` e `ImportConfirmRequest` comprueban en `authorize()`
(`AuthorizesEventImport`) el evento (campaña propia, territorio) y que la carga sea de ese evento **antes** de validar
el cuerpo: otra campaña → **404** y subcampaña visible → **403** aunque el cuerpo sea inválido (antes respondía 422).

### Índices

Migración `2026_11_02_000001_add_lider_indexes_to_event_tables`: `event_attendees(lider_id)` y
`event_organizers(lider_id)`. No toca `data_consents` (no hace falta el script de dueño).

### Correcciones del QA de la Fase 4

- `GET /mapa-politico/persona/{id}`: `cargos` nunca trae `null` (una ficha sin cargo, p. ej. creada al aprobar un
  asistente, manda `[]`). Las fichas de asistentes siguen naciendo con `cargo = null` (no se inventa un cargo).
  `PersonaPanel` solo usa la copia guardada en el teléfono cuando la petición falla (sin señal, error del servidor); si la
  ficha llega pero no se puede mostrar, dice "La ficha llegó pero no se pudo mostrar. Avise al equipo técnico.".
- Mapa Político sin `view.eventos`: `?tipo=asistentes_evento` se quita como si no viniera (si era el único tipo, queda
  `todos`); `filters.tipo` devuelve el tipo efectivo (`MapaPoliticoController::effectiveTipos`).
- `/votantes`: los contadores cuentan las mismas filas que el listado, con **todos** los filtros activos (búsqueda,
  municipio, partido, confianza, etiquetas, estado, campaña, eventos).
- Búsquedas con `LIKE` (Votantes, Mapa Político, Auditoría y el filtro de barrio del Mapa): `_` y `%` se buscan como
  caracteres (se escapa primero la barra invertida).
- `/votantes`: los paneles de filtros de escritorio y móvil usan ids distintos; el filtro de evento activo se ve como
  chip ("Asistió a: …" / "Asistentes a eventos") con botón para quitarlo, aunque el panel esté cerrado.
- `/eventos/territorio`: con el barrio vacío se muestran los barrios sugeridos.

---

## 13. Lectura de planillas impresas con IA (Fase 5) — CONSTRUIDA PERO APAGADA

Fotos (o PDF) de la planilla impresa de §9, llenas a mano, que una IA (Claude, de Anthropic) transcribe; una persona
revisa **todo** contra la foto y confirma igual que el Excel de §11. **Nada** llega a `event_attendees` antes de esa
confirmación. Está apagada por defecto y así debe quedar hasta cumplir los pasos de activación (abajo).

### Interruptores

| Interruptor | Dónde | Apagado → |
|-------------|-------|-----------|
| Global `EVENTOS_OCR_ENABLED` (por defecto `false`) | `.env` → `config/eventos.php` (`eventos.ocr.enabled`) | Todas las rutas de §13 → **404** (middleware `eventos.ocr`, antes de permisos), y las rutas de §11 sobre una carga de tipo `ocr` → 404. El botón no aparece. |
| Por campaña `campaigns.ocr_enabled` (por defecto `false`) | `php artisan eventos:ocr-campana {campaña} --activar` o `--desactivar` (solo consola, R01/devops; deja `EVENTO_OCR_CAMPANA_ACTIVADA/DESACTIVADA` en la auditoría; sin opciones muestra el estado) | Rutas de §13 → **403** `"La lectura de planillas con IA no está activada para esta campaña."`. El botón no aparece. |

La campaña es la **del evento** (una subcampaña necesita su propio interruptor). `Eventos/Show` recibe `can.ocr`
(= `manage.eventos` + campaña propia + los dos interruptores) y solo entonces muestra **"Leer planillas con IA"**.

**Sin clave** (`ANTHROPIC_API_KEY` vacía) con los interruptores encendidos: la pantalla abre y dice
`"La lectura con IA no está lista: falta configurar la clave del proveedor (ANTHROPIC_API_KEY). Avise al administrador de la plataforma."`;
subir y leer responden **422** `errors.ocr` con ese texto (nunca 500) y no se guarda nada.

### Modelo y costos

- `EVENTOS_OCR_MODEL`, por defecto **`claude-opus-5-5`** (el más capaz de uso general hoy). Alternativa más barata:
  **`claude-sonnet-5-5`** (mitad de precio por token). Decidir con el piloto (abajo). Claude Fable **no** sirve: exige
  retención de datos de 30 días, incompatible con ZDR.
- `EVENTOS_OCR_EFFORT` (`low|medium|high|xhigh|max`, por defecto `medium`); `low` es más rápido y barato.
- Precios (USD por millón de tokens: entrada / salida / escritura de caché / lectura de caché) en
  `config/eventos.php` → `eventos.ocr.prices`: Opus 5.5 4 / 20 / 5 / 0,20; Sonnet 5.5 2 / 10 / 2,50 / 0,20. El costo
  que registra la plataforma es una **estimación** con esa tabla (no la factura). Revisar
  https://platform.claude.com/docs/en/about-claude/pricing antes de decidir.
- Llamada: SDK oficial `anthropic-ai/sdk` (PHP, v1.3), `beta->messages->create` con salida estructurada (JSON schema de
  `App\Services\Eventos\Ocr\PlanillaPrompt`), `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`: si el
  modelo declina por políticas, la API reintenta con su modelo de respaldo en la misma llamada; se registra el modelo
  que respondió), `timeout` `EVENTOS_OCR_TIMEOUT` (150 s) y **sin reintentos automáticos** (`maxRetries: 0`: cada
  llamada se paga; la pantalla ofrece "Intentar de nuevo"). Un PDF va con caché de prompt, así que sus páginas 2..N
  cuestan poco de entrada. Errores 404/422 de la API (modelo o parámetros inválidos) → `"La configuración del modelo de
  IA no es válida. Avise al administrador de la plataforma."` (no reintentable).
- Precios también para `claude-opus-4-8`, el respaldo por defecto de Opus 5.5. Un modelo sin precio en la tabla se
  registra con costo 0 y deja un warning en el log (`Eventos OCR: el modelo no tiene precio…`).
- Tope de costo: `EVENTOS_OCR_MAX_PAGINAS_DIA` (300) **llamadas a la IA** por campaña y día (hora de Bogotá), cuenten o
  no como página leída (fallidas, rechazadas por la IA o incompletas incluidas; `event_import_pages.api_calls` y
  `last_call_at`). Al pasarlo: `"La campaña ya hizo N lecturas con IA hoy, el máximo diario. Continúe mañana."`.
- Máximo **3 llamadas por página** (`OcrImportService::MAX_CALLS_PER_PAGE`): a la tercera falla la página queda
  `rechazada` con `"… Esta página ya se intentó leer 3 veces: tome otra foto y súbala en una carga nueva, o registre a
  las personas en el modo operador."` y "Intentar de nuevo" ya no la lee.
- Una respuesta que se cobró pero no sirve (cortada por `max_tokens`, rechazada, JSON ilegible) suma sus tokens y su
  costo a la página y a la auditoría de esa llamada.

### Rutas (`manage.eventos`, middleware `eventos.ocr`; campaña propia, territorio y `campaigns.ocr_enabled`)

| Método | URI | Nombre | Respuesta |
|--------|-----|--------|-----------|
| GET | `/eventos/{id}/importar/ocr` | `eventos.importar.ocr.index` | Inertia `Eventos/Planillas` (paso 1) |
| POST | `/eventos/{id}/importar/ocr` | `eventos.importar.ocr.subir` | JSON 201 (throttle `eventos-ocr` 60/min) |
| GET | `/eventos/{id}/importar/ocr/{import}?pagina=&estado=` | `eventos.importar.ocr.ver` | Inertia `Eventos/Planillas` |
| POST | `/eventos/{id}/importar/ocr/{import}/procesar` | `eventos.importar.ocr.procesar` | JSON 200 (throttle `eventos-ocr`) |
| GET | `/eventos/{id}/importar/ocr/{import}/paginas/{pagina}/archivo` | `eventos.importar.ocr.archivo` | La foto/PDF, `inline` |

Corregir filas, confirmar y descartar usan las rutas de §11 (`PATCH .../importar/{import}/filas/{fila}`,
`POST .../confirmar`, `DELETE .../importar/{import}`), que aplican los mismos interruptores a una carga `ocr`.
`GET /eventos/{id}/importar/{import}` de una carga `ocr` redirige a su URL de §13; `PUT .../columnas` → 404.
Otra campaña no visible → 404; subcampaña visible → 403; fuera del territorio → 403; sin `manage.eventos` → 403 (JSON)
o redirect a `/` (página). Evento cancelado → 422 `errors.evento`. Sin texto de autorización **aprobado** → 422
`errors.consent` `"Falta el texto de autorización aprobado por la campaña; no se pueden leer planillas hasta tenerlo."`.

### Flujo

1. **Fotos.** Un archivo por petición (`foto`, multipart; `importacion` = id de la carga a la que se agrega, vacío = carga
   nueva): la pantalla las envía una tras otra ("Subiendo 3 de 7…"), así el límite de PHP nunca es la suma.
   - Fotos de más de **24 megapíxeles** (ancho × alto leído de la cabecera, antes de decodificarla con GD) → 422
     `"La foto tiene demasiada resolución para leerla (más de 24 megapíxeles). Tómela de nuevo con menos resolución."`.
     Medido en el peor caso (decodificar + copia del tamaño de la API + rotación EXIF): 24 MP usan +118 MB (pico 146 MB
     con Laravel) de los 256 MB de una petición de lectura; un celular toma 12–16 MP. Esa medición es con el GD
     *bundled* de PHP (local). **Producción usa el GD del sistema (libgd 2.3.3)**, que reserva los píxeles fuera de
     `memory_limit`: allí `memory_limit` no frena la imagen y cada lectura de una foto de 24 MP ocupa ~120–150 MB de RAM
     real del proceso PHP-FPM (el VPS tiene 2 GB y `pm.max_children = 5`: no leer varias cargas a la vez). El test de
     memoria se salta solo cuando GD no es *bundled*.
   - PDF cuyas páginas no se pueden contar (objetos comprimidos) → 422 `"No se pudieron contar las páginas de este PDF.
     Suba fotos de las páginas (JPG o PNG)."`; más de 3 MB por página → 422 `"Este PDF pesa demasiado por página. Suba
     fotos de las páginas (JPG o PNG)."`.
   - Tipos por **contenido** (finfo), no por nombre: JPG, PNG, WEBP o PDF. HEIC → 422 con
     `"Las fotos HEIC del iPhone no se pueden leer. Súbalas desde la galería …"` (convertir HEIC exige Imagick con libheif,
     que el servidor no tiene; el iPhone las convierte a JPG al subirlas desde el navegador). Otro tipo →
     `"Solo se aceptan fotos JPG, PNG o WEBP, o un PDF."`.
   - Máx. **10 MB** por archivo (o lo que permita PHP, ver activación) y **20 páginas** por carga (un PDF cuenta sus
     páginas al subirlo).
   - Disco **privado** `storage/app/private/eventos/ocr/{evento}/{carga}/{n}.{ext}`, con su `sha256`. La misma foto (mismo
     `sha256`) ya subida a **este** evento → 422 `"Esta foto ya se subió a este evento (nombre): no se vuelve a leer."`.
   - **201** `{ success, message, data: { id, paginas, total_paginas } }`. La carga queda `subido` (`event_imports.kind = 'ocr'`).
2. **Lectura con IA**, una página por petición (`POST .../procesar`, cuerpo opcional `reintentar` / `continuar`). La
   pantalla **no** empieza sola (leer cuesta): botón "Leer N páginas con IA", barra de progreso y peticiones sucesivas.
   Cada petición: `set_time_limit(timeout + 60)`, `memory_limit` ≥ 256M. Concurrencia: un candado por carga de
   `timeout + 120` s (más que una llamada, que no se reintenta sola; si hay otra lectura en curso → `data.ocupado = true`,
   la pantalla espera 3 s); la llamada se reserva con un UPDATE condicional (`status = 'pendiente'` y el mismo
   `api_calls`), las filas se guardan solo si el UPDATE final a `procesada` encuentra la página aún `pendiente` (si no,
   no se guarda nada y la auditoría dice `descartada_ya_leida`), y `event_import_rows` tiene único `(page_id, page_row)`.
   - **QR**: se decodifica en el servidor (chillerlan, sobre una copia de 1600 px, ~0,7 s) **antes** de llamar a la IA.
     Si es de otro evento → página `rechazada` `"Esta página es de otro evento (código XXXXXXXX): no se leyó. Súbala en la carga de ese evento."`
     sin gastar en la IA. Si el QR no se lee (foto movida, PDF), vale el id completo que la IA lee en la línea de pie
     ("Evento <id> · Página X de Y") o el código de 8 caracteres; si dice otro evento → `rechazada` y **no** se guardan
     sus filas; si no se lee ninguno → la página se procesa con el aviso
     `"No se pudo leer el código del evento en esta página: confirme en la imagen que es de este evento."`.
   - La foto se decodifica **una sola vez**, se reduce primero a 2576 px de lado mayor (y se libera el original), después
     se endereza (EXIF) y de esa copia salen la imagen para la API (JPEG de hasta 3,7 MB; en base64 queda por debajo de los
     5 MB por imagen de la API) y la copia de 1600 px para el QR. Esto ocurre **antes** de reservar la llamada: si la foto
     no se puede preparar, la página queda con error sin gastar intentos ni el tope diario. El original se guarda tal cual.
   - La IA responde, por cada fila con algo escrito (las vacías se omiten): `nombres, apellidos, cedula, celular, fijo,
     fecha_nacimiento, direccion, barrio` (texto o null) y `firma_presente` (booleano, solo informativo), cada uno con
     `confianza` 0–1; más `es_planilla`, `evento_id`, `codigo_evento`, `pagina`, `total_paginas`, `paginas_documento`.
     `es_planilla = false` → página `rechazada` `"Esta imagen no parece una planilla de asistencia de la plataforma: no se leyó."`.
   - Las filas van a `event_import_rows` como una fila de Excel ya mapeada (`mapped`, `errors` con `ImportRowValidator`)
     + `page_id`, `page_row` (N.º impreso) y `confidence` (jsonb por campo). `row_number = (página − 1) × 12 + N.º`.
   - Fallo de la API (sin conexión, ocupada, tiempo agotado, respuesta incompleta o ilegible, rechazo) → página `error`
     con un mensaje en español sin datos; **200** `{ success: false, message, data: progreso }`. La pantalla ofrece
     "Intentar de nuevo" (`reintentar`) o "Seguir sin las páginas que fallaron" (`continuar`).
   - Respuesta: `{ success, message, data: { status, total, pendientes, procesadas, rechazadas, errores, filas, costo_usd, listo, ocupado } }`.
     Cuando no queda ninguna página pendiente (ni con error, salvo `continuar`) se clasifican las filas (cruce con
     líderes de la campaña por cédula, repetidas, ya en el evento) y la carga pasa a `revisado` (`listo = true`).
3. **Revisión humana obligatoria** (`Eventos/Planillas`): pestañas por página (filas leídas y cuántas tienen celdas
   dudosas), a un lado la foto (o el PDF en esa página) servida por la ruta autenticada y al otro las filas de esa
   página. Celdas con confianza `< EVENTOS_OCR_UMBRAL` (0,85) en **amarillo** ("Dudoso: compárelo con la foto"),
   inválidas en **rojo** (mismas reglas que §11: cédula 5–10 dígitos, celular obligatorio, fecha DD/MM/AAAA, mayor de
   edad…). "Ya estaba en la plataforma" si la cédula es de un líder de la campaña del evento. Corregir una celda la
   revalida y pone su confianza en 1 (ya no es dudosa). Una fila válida cuya **cédula** tiene confianza bajo el umbral
   arranca en **"No importar"** hasta que alguien la corrija (vuelve a "Importar") o marque "Importar" a mano (se respeta
   aunque se corrijan otras filas). Filtros de §11.
   - **Sin regla de consentimiento desde 2026-11-07 (§4):** ni «Autorizo» (ya no está en la planilla; si una planilla
     vieja la trae, el prompt la ignora) ni la firma deciden si una fila se importa; una duda de la IA en la firma no
     cuenta como celda dudosa.
4. **Confirmar**: `POST .../importar/{import}/confirmar` (sin declaración ni texto aprobado desde 2026-11-07).
   Registra con `RegisterAttendee::handleMany`: `source = 'ocr'`, `import_id`, sin fila en `data_consents`. Los nuevos
   quedan `pendiente` o `vinculado`; pasar a Votantes sigue siendo manual. Tras confirmar se borran los archivos que no
   tienen ninguna página leída (de otro evento, no planilla, fallidas); las filas `no_autorizo` históricas se vacían.

### Tablas (migración `2026_11_03_000001_add_ocr_to_eventos`, aditiva; no toca `data_consents`)

- `campaigns.ocr_enabled` boolean default false.
- `event_imports.kind` `excel|ocr` (CHECK en PostgreSQL). En una carga `ocr`: `file_path` = carpeta de sus fotos,
  `file_sha256` = hash de `"ocr:" + id` (solo para el único `(event_id, file_sha256)`), `headers = []`.
- `event_import_pages`: `import_id` (cascade), `event_id`, `sequence`, `original_name`, `file_path`, `file_sha256`,
  `mime`, `page_in_file`, `file_pages`, `status` (`pendiente|procesada|rechazada|error`, CHECK), `message`,
  `qr_event_id`, `printed_page`, `qr_origin` (`codigo` = QR, `ia` = leído por la IA), `rows_found`, `model`,
  `input_tokens`, `output_tokens`, `cache_write_tokens`, `cache_read_tokens`, `cost_usd` (suma de todas las llamadas de
  la página), `duration_ms`, `attempts`, `api_calls`, `last_call_at`, `processed_at`. Únicos `(import_id, sequence)` y
  `(event_id, file_sha256, page_in_file)`. **Sin datos personales.**
- `event_import_rows.page_id` (cascade), `page_row`, `confidence` (jsonb); único `(page_id, page_row)`.
- `event_attendees.source` CHECK `qr|operador|excel|ocr`. El `down()` falla mientras haya asistentes `ocr` (a propósito)
  y no borra las fotos del disco.
- Dry-run en producción (2026-11-01, repetido tras la revisión con `api_calls`, `last_call_at` y el único
  `(page_id, page_row)`; F3 y F4 aún sin desplegar): las migraciones `2026_11_01_000001`, `2026_11_02_000001`
  y `2026_11_03_000001` aplicadas como rol `electoral` dentro de `BEGIN … ROLLBACK` sin errores; después no existen ni
  las tablas ni la columna; archivo temporal borrado.

### Auditoría (sin datos personales: nunca lo que se leyó)

- Middleware `audit` (rutas `eventos.importar.ocr.*` y las de §11): solo los **nombres** de los campos enviados.
- `EVENTO_OCR_ARCHIVO_SUBIDO` `{ event_id, tipo: foto|pdf, paginas, sha256_archivo }`.
- `EVENTO_OCR_PAGINA_LEIDA` (una por intento) `{ event_id, pagina, resultado: procesada|rechazada|error|descartada_ya_leida,
  model, input_tokens, output_tokens, cache_write_tokens, cache_read_tokens, cost_usd` (de esa llamada)`, duration_ms, filas,
  qr: coincide|ilegible|otro_evento|no_planilla|demasiados_intentos, qr_origen, reintentable }`; un error cobrado lleva
  sus tokens y costo; una página rechazada después de leerla, `cost_usd_pagina`.
- `EVENTO_OCR_LECTURA_TERMINADA` `{ event_id, paginas, filas, modelos, input_tokens, output_tokens, cache_write_tokens,
  cache_read_tokens, costo_usd, duracion_ms }`.
- Confirmación: `EVENTO_IMPORTACION_CONFIRMADA` con `tipo: "ocr"`. Descarte: `EVENTO_IMPORTACION_DESCARTADA`.

### Privacidad y retención (Ley 1581 de 2012)

- **Transmisión internacional**: cada foto (con los datos de todas las personas de la página, también las que no
  autorizaron) se envía a **Anthropic** (EE. UU.) para leerla. Asistir a un evento político es dato **sensible**
  (opinión política, art. 5). Por eso está apagada por defecto. Antes de encenderla hace falta: contrato de
  transmisión de datos con Anthropic como encargado (DPA), **Zero Data Retention (ZDR)** habilitado en la organización de
  la API, y que el texto de autorización de la campaña mencione el envío a un proveedor tecnológico en el exterior.
- Fotos en disco **privado**, nunca por URL pública: solo por `eventos.importar.ocr.archivo` con las mismas comprobaciones
  (sesión, `manage.eventos`, campaña propia, territorio, interruptores), `Cache-Control: private, no-store`,
  `X-Content-Type-Options: nosniff` y, en las fotos, `Content-Security-Policy: sandbox`. Los PDF van sin `sandbox`
  porque Chrome no muestra un PDF bajo CSP sandbox (su contenido se comprobó como PDF al subirlo). Sin sesión → `/login`
  (401 en JSON).
- Lo leído de quien **no autorizó** se vacía al confirmar; el resto se vacía a los 30 días (abajo).
- `php artisan eventos:limpiar-importaciones` (diario 03:30; necesita el cron del scheduler, §11):
  - cargas **no confirmadas** de más de **7 días** → se borran con su carpeta de fotos;
  - cargas **confirmadas** hace más de **30 días** → `raw`/`mapped` de sus filas en null (lo leído por la IA), como §11;
  - **decisión**: las fotos de una carga confirmada **se conservan** como respaldo de las autorizaciones firmadas
    (art. 9: el responsable debe conservar prueba de la autorización); al confirmar se borran las fotos que no
    respaldan a nadie (páginas de otro evento, no planillas o fallidas). Esas fotos también muestran filas de personas
    que no autorizaron: el acceso queda restringido como arriba y la planilla en papel sigue siendo el original. Si la
    campaña fija un plazo de retención, se agrega al comando.
- Ni el log ni la auditoría guardan la respuesta de la IA ni las imágenes; el log solo registra la clase del error y el
  código HTTP cuando la API falla.

### Piloto — `php artisan eventos:ocr-piloto {carpeta} {verdad.csv} [--model=] [--json=informe.json] [--force]`

Lee todas las fotos/PDF de una carpeta con el modelo indicado (por defecto `EVENTOS_OCR_MODEL`) y compara con un CSV de
verdad escrito por una persona. **No** necesita `EVENTOS_OCR_ENABLED` ni toca tablas de eventos ni la auditoría, pero
**sí envía las imágenes a Anthropic** (pregunta antes, salvo `--force`): con planillas reales, solo después del DPA/ZDR.

- CSV (`,` o `;`, UTF-8) con las columnas `archivo`, `pagina` (página del archivo; 1 en una foto), `fila` (N.º 1–12),
  `nombres`, `apellidos`, `cedula`, `celular`, `fijo`, `fecha_nacimiento`, `direccion`, `barrio` y
  `firma` (SI/NO). Una columna `autorizo` de un CSV viejo se ignora. Una fila por persona escrita.
- Informe: por página (filas, QR leído o no, modelo que respondió, tokens, costo estimado, segundos, error); filas en la
  verdad / detectadas / omitidas / sobrantes; **precisión por campo** (cédula, celular, nombres, apellidos, fecha,
  autorizó, firma, y fijo, dirección, barrio) comparando dígitos, fechas normalizadas y texto en mayúsculas sin tildes; y
  **errores con confianza alta** (los que la revisión no resaltaría: el número a vigilar); total y costo/segundos por página.
- Ejemplo para probar el comando: `docs/ocr-piloto-ejemplo/` (2 planillas **sintéticas** generadas por la plataforma con
  datos **falsos** en texto escrito a máquina + `verdad.csv`; se regeneran con `php artisan eventos:ocr-piloto-ejemplo {carpeta}`).
  **No** sirve para medir la precisión real (letra a mano).
- Para decidir el modelo: 20 planillas reales fotografiadas como en campo, su verdad transcrita por una persona, correr
  el piloto con `--model=claude-opus-5-5` y con `--model=claude-sonnet-5-5`, y comparar precisión de cédula/celular,
  errores con confianza alta, autorizó/firma, segundos y costo por página.

### Activación paso a paso (cuando Marly lo decida)

1. Firmar el DPA con Anthropic y pedir **ZDR** para la organización de la API; crear una API key de esa organización
   (workspace propio, con límite de gasto mensual en la consola de Anthropic).
2. Cargar con `php artisan eventos:consent-text {campaña} {archivo} --text-version=… --approve` un texto de autorización
   que diga, además de lo actual, algo como: *"Autorizo que la fotografía de esta planilla sea procesada por un proveedor
   tecnológico ubicado fuera de Colombia (Anthropic, Estados Unidos), como encargado, únicamente para transcribir mis
   datos a la plataforma de la campaña."* Reimprimir las planillas (el pie muestra la versión).
3. Piloto: `php artisan eventos:ocr-piloto {carpeta} {verdad.csv} --model=…` con 20 planillas reales (ver arriba).
   Elegir modelo, effort y umbral.
4. Servidor (PHP-FPM): hoy `upload_max_filesize = 2M` y `post_max_size = 8M`; con eso una foto de más de 2 MB se rechaza
   (la pantalla y el mensaje dicen el límite real). Para fotos de celular poner `upload_max_filesize = 12M` y
   `post_max_size = 16M` en `/etc/php/8.3/fpm/php.ini` y `systemctl reload php8.3-fpm`. nginx ya tiene
   `client_max_body_size 50M` y `fastcgi_read_timeout 300` (una página tarda hasta `EVENTOS_OCR_TIMEOUT` + el QR).
   GD y exif ya están instalados. Con `pm.max_children = 5`, cada lectura ocupa un proceso PHP mientras espera a la IA:
   no leer varias cargas a la vez.
5. `.env`: `ANTHROPIC_API_KEY=…`, `EVENTOS_OCR_MODEL=…`, `EVENTOS_OCR_ENABLED=true` (y si se quiere `EVENTOS_OCR_EFFORT`,
   `EVENTOS_OCR_UMBRAL`, `EVENTOS_OCR_MAX_PAGINAS_DIA`); `php artisan optimize:clear && php artisan optimize`.
6. `php artisan eventos:ocr-campana {campaña} --activar` solo para las campañas que cumplieron lo anterior.
7. Primera carga real acompañada: revisar en la auditoría `EVENTO_OCR_LECTURA_TERMINADA` el costo por página.
   Para apagar: `EVENTOS_OCR_ENABLED=false` (todo 404 tras `optimize`) o `--desactivar` por campaña.

---

## 14. Actas de reunión y seguimiento de compromisos (2026-11-10)

Un evento tiene una o varias **actas de reunión** con el formato de las actas de la campaña: título, fecha, Municipio,
Sitio, Hora; **PARTICIPANTES** (tabla NOMBRE | PERFIL | MUNICIPIO | TELEFONO); **PUNTOS DEL ORDEN DEL DÍA** (lista, con
introducción opcional); **DESARROLLO DE LA REUNIÓN** (un texto por punto); **COMPROMISOS** (tabla NOMBRE | RESPONSABLE |
TELEFONO, uno o varios responsables por compromiso) y el cierre ("Siendo las 12:00 m., se dio por terminada la reunión.").
Cada compromiso se sigue con estado, fecha límite, recordatorio y avances.

**Borrador → confirmada** (cambio de Marly 2026-11-10). Toda acta nace como `borrador`: se edita todo (encabezado,
participantes, puntos, desarrollo, compromisos, responsables, archivo original) y se puede **borrar** completa. Mientras
es borrador **no** se crean fichas, ni asistentes, ni se vinculan responsables: solo se guardan los datos y las
elecciones de "¿Es esta persona?" (`lider_id` = ficha elegida, `sugerencia_rechazada` = ficha a la que se dijo que no,
para no volver a preguntar; una pregunta sin responder queda pendiente). **"Confirmar acta"** aplica en una transacción
todo lo de "Participantes → Votantes y asistentes" y "Responsables" y deja el acta `confirmada` (`confirmed_at`,
`confirmed_by`): desde ahí no se edita (422 `acta`; no hay "reabrir"), solo se descarga y se hace el
seguimiento de sus compromisos. Solo quien tiene además **`delete.actas`** puede eliminarla (ver "Eliminar un acta
confirmada"); sin ese permiso el DELETE responde 403. La pantalla pregunta todas las sugerencias pendientes antes de confirmar; si por API se
confirma con alguna sin responder, cuenta como "no es esta persona" (participante → ficha nueva; responsable → texto).
Los compromisos de un borrador no se listan ni reciben avances (404).

### Permisos y aislamiento

- Ver actas, descargarlas, ver compromisos y su historial: `view.eventos` o `manage.eventos`.
- Crear/editar actas, cambiar estados y registrar avances: `manage.eventos` en la **campaña propia** del evento
  (subcampaña visible = solo lectura → 403; campaña no visible → 404) y dentro del **territorio** del usuario (403).
  Un evento **cancelado** no recibe ni cambia actas (422 `evento`).
- `register.asistentes` solo no ve actas (403). La ficha de la persona (`/eventos/persona/{liderId}/compromisos`)
  exige además `view.mapa` o `view.votantes`, como `/eventos/persona/{liderId}`.
- Archivos (original del acta y adjuntos de avances) en el disco privado `local` (`storage/app/private/eventos/actas/{evento}/`,
  `…/eventos/compromisos/{compromiso}/`), nombre UUID, servidos solo por rutas autenticadas con los mismos chequeos.
- Auditoría sin datos personales (solo ids y conteos): `EVENTO_ACTA_CREADA`, `EVENTO_ACTA_ACTUALIZADA`, `EVENTO_ACTA_CONFIRMADA`,
  `EVENTO_ACTA_BORRADA`, `EVENTO_ACTA_CONFIRMADA_BORRADA`, `EVENTO_ACTA_ARCHIVO`, `EVENTO_COMPROMISO_RECORDATORIO`, `EVENTO_COMPROMISO_ARCHIVO_DESCARGADO`,
  `EVENTO_ACTA_DESCARGADA` (`formato`: docx/pdf/original), `EVENTO_COMPROMISO_ESTADO` (`de`, `a`), `EVENTO_COMPROMISO_AVANCE`,
  `EVENTO_COMPROMISOS_EXPORTADOS`.

### Rutas

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /eventos/{id}/actas` | view | JSON `[{id, titulo, fecha, compromisos, abiertos, archivo, created_by}]` (sección "Actas de reunión" del detalle) |
| `GET /eventos/{id}/actas/nueva` | manage | Inertia `Eventos/Acta` con `defaults` del evento y `asistentes` (máx. 3000) |
| `GET /eventos/{id}/actas/{acta}` | view | Inertia `Eventos/Acta` con `acta` (`can.edit` solo campaña propia y evento no cancelado) |
| `POST /eventos/{id}/actas/leer` | manage | multipart `archivo` (≤ 10 MB, o el límite de PHP si es menor). `.docx` → `{tipo: 'docx', acta: {…, avisos[]}}` para prellenar; PDF/JPG/PNG → `{tipo: 'adjunto', acta: null}`. No guarda nada. 422 `archivo` si no es ninguno de esos (por contenido, no por nombre) |
| `POST /eventos/{id}/actas/sugerencias` | manage | `{participantes: [{nombre, telefono, cedula}], responsables: [{nombre, telefono}]}` → `{participantes: {i: lider}, responsables: {i: lider}}`, `lider = {id, nombre, municipio, cargo, por: 'cedula'\|'telefono'}` |
| `POST /eventos/{id}/actas` | manage | crea un **borrador** (201) `{data: acta, message}`; nada pasa a Votantes ni a los asistentes |
| `PUT /eventos/{id}/actas/{acta}` | manage | actualiza un **borrador** (mismo cuerpo; reemplaza participantes y compromisos). Confirmada → 422 `acta` |
| `DELETE /eventos/{id}/actas/{acta}` | manage | borra un **borrador** con participantes, compromisos, responsables y archivo. Confirmada: exige además `delete.actas` (si no, 403) y deshace lo que creó (abajo) |
| `GET /eventos/{id}/actas/{acta}/eliminar` | manage + delete.actas | vista previa de eliminar un acta **confirmada**: `data: {participantes, asistentes, asistentes_conservados, fichas_borrar, fichas_conservar, vinculadas, compromisos, responsables, avances}`. Borrador → 422 `acta` |
| `POST /eventos/{id}/actas/{acta}/confirmar` | manage | confirma: `{data: acta, resumen: {participantes, vinculados, fichas_creadas, asistentes_nuevos, compromisos, responsables_vinculados}, message}`. Ya confirmada → 422 `acta`; una ficha elegida que dejó de ser válida → 422 `participantes.N.lider_id` y no se escribe nada |
| `PUT /eventos/compromisos/{compromiso}/recordatorio` | manage | `{recordar_el: YYYY-MM-DD\|null}` (también en compromisos de actas confirmadas) |
| `GET /eventos/compromisos/alertas` | view | campanita: `{total, vencidos, por_vencer, recordatorios, items[≤15]: {id, tipo, descripcion, estado, fecha_limite, recordar_el, evento, url}}`, caché 5 min por usuario (se borra cuando el usuario cambia un estado o recordatorio) || `POST /eventos/{id}/actas/{acta}/archivo` | manage | multipart `archivo` (.docx, PDF, JPG o PNG): guarda o reemplaza el original (solo borrador) |
| `GET /eventos/{id}/actas/{acta}/archivo` | view | descarga el original (`X-Content-Type-Options: nosniff`) |
| `GET /eventos/{id}/actas/{acta}/acta.docx` | view | Word generado con el formato del ejemplo (ZipArchive, sin paquete nuevo) |
| `GET /eventos/{id}/actas/{acta}/acta.pdf` | view | PDF (dompdf, vista `eventos/acta.blade.php`); throttle `eventos-planilla` |
| `GET /eventos/compromisos` | view | Inertia `Eventos/Compromisos`, 20 por página |
| `GET /eventos/compromisos.xlsx` | view | Excel con los mismos filtros (teléfonos como texto) |
| `GET /eventos/compromisos/{compromiso}` | view | JSON con `avances` (historial, más reciente primero) |
| `PUT /eventos/compromisos/{compromiso}/estado` | manage | `{estado, nota?}`; escribe un avance automático (`estado_anterior` → `estado_nuevo`). 422 si es el mismo estado |
| `POST /eventos/compromisos/{compromiso}/avances` | manage | multipart `{nota?, archivo?}` (al menos uno; archivo foto JPG/PNG o PDF ≤ 10 MB) |
| `GET /eventos/compromisos/avances/{avance}/archivo` | view | descarga el adjunto (auditado: `EVENTO_COMPROMISO_ARCHIVO_DESCARGADO`) |
| `GET /eventos/persona/{liderId}/compromisos` | view + view.mapa\|view.votantes | compromisos donde la ficha es responsable confirmado (sección "Compromisos" bajo "Eventos" en PersonaPanel y VotanteDetail) |

Cuerpo de `POST/PUT` actas: `titulo` (obligatorio), `fecha` (`YYYY-MM-DD`), `municipio`, `sitio`, `hora_inicio`/`hora_fin`
(`HH:MM`), `introduccion`, `orden_dia: [string]`, `desarrollo: [{titulo, texto}]` (título vacío = introducción),
`participantes: [{nombre, perfil, municipio, telefono, cedula, lider_id?, attendee_id?, sugerencia_rechazada?}]`,
`compromisos: [{descripcion, fecha_limite?, recordar_el?, responsables: [{nombre, telefono, lider_id?, sugerencia_rechazada?}]}]`, `cierre`.

### Participantes → Votantes y asistentes (decisión 2 de Marly)

Al **confirmar**, para cada participante, en este orden:

1. `lider_id` confirmado por el usuario en "¿Es esta persona?" (debe ser líder activo de la campaña del evento dentro del
   territorio; si no, 422 `participantes.N.lider_id` y no se guarda nada).
2. La ficha del asistente elegido de la lista (`attendee_id`, debe ser asistente de este evento).
3. La misma cédula en la campaña del evento (se vincula sin preguntar; se busca en toda la campaña, no solo en el
   territorio del usuario: la cédula es única por campaña y vincular solo guarda el id).
4. Si no: **se crea la ficha** en la campaña del EVENTO: `tipo = directorio`, `cargo` = perfil normalizado
   ("SENADOR DE LA REPUBLICA" → "Senador de la Republica"; vacío o "LÍDER …" → "Líder"), municipio del participante si es
   un municipio conocido dentro del territorio del usuario (si no, el del evento), primer celular en `telefono` y los
   demás en `lider_contactos`, `autoriza_datos = false`.

Todos quedan como **asistentes** del evento (`event_attendees.source = 'acta'`, `link_status` `vinculado` si ya existía la
ficha o `aprobado` si se creó, `nota` = perfil). No se duplica: si ya hay un asistente del evento con esa ficha o esa
cédula, se usa ese (y si no tenía ficha, se le vincula). Sugerencia por teléfono: `AttendeeMatcher` (solo dígitos,
últimos 10, en `lideres.telefono` y `lider_contactos.valor`, + al menos una palabra del nombre; empate = sin sugerencia),
entre los líderes activos de la campaña del evento en el territorio del usuario (todos los municipios si no tiene).
Quitar un participante del acta **no** borra su ficha ni su asistencia.

### Eliminar un acta confirmada (permiso `delete.actas`, 2026-11-11)

Para un acta confirmada por error. Botón "Eliminar acta" en el encabezado del acta confirmada, solo con `can.delete_confirmed`
(`manage.eventos` + `delete.actas`, campaña propia del evento y territorio). La confirmación muestra antes los conteos reales
de `GET …/eliminar`. `DELETE /eventos/{id}/actas/{acta}` (`DeleteConfirmedActa`), en una transacción con bloqueo del acta, de
los asistentes y de las fichas (el plan se recalcula bajo el bloqueo):

- Borra el acta con participantes, compromisos, responsables y avances (cascada) y, después del commit, su archivo original
  y los adjuntos de los avances.
- Quita del evento los asistentes que **entraron por esta acta** (`event_acta_participantes.created_attendee`, `source = 'acta'`),
  salvo los que tengan `data_consents` o que otra acta use como participante. Un asistente que ya estaba y solo se vinculó
  **no** se borra (queda como quedó, vinculado a su ficha).
- Borra las fichas que **la confirmación creó** (`created_lider`) solo si no tienen otra actividad (`FichaActivity`, la misma
  regla que "Deshacer carga" §6.2: otra asistencia, organizador, contactos aparte de los celulares que copió la confirmación,
  información política, seguimientos, municipios de influencia, nexos, referidos, etiquetas, favoritos, envíos de WhatsApp,
  `data_consents`, participante o responsable de **otra** acta, o edición posterior). Las demás se conservan. Las fichas que
  ya existían y solo se vincularon nunca se tocan. `data_consents` no se toca.
- Actas confirmadas antes de 2026-11-11 (sin las marcas `created_*`): se trata como "no creó nada"; solo se borra el acta.
- Respuesta: `{success, message, data: <mismos conteos>}`. Auditoría `EVENTO_ACTA_CONFIRMADA_BORRADA` con `acta_id`, ids de
  asistentes y fichas borrados y conteos (sin nombres ni teléfonos).

### Responsables (decisión 3)

Solo se vinculan a una ficha si el usuario confirma la sugerencia (`lider_id`, mismas reglas); si no, quedan como texto
(nombre + teléfono) y **nunca** se crea ficha. Un responsable que también es participante nuevo del mismo acta no se
vincula solo (su ficha se crea al confirmar, cuando el acta ya no se edita): queda como texto.

### Seguimiento (decisión 4)

- Estados: `pendiente`, `en_curso`, `cumplido`, `no_cumplido` (CHECK en PostgreSQL). Nuevo compromiso = `pendiente`.
- **Vencido** = `fecha_limite` anterior a hoy (Bogotá) y estado `pendiente` o `en_curso`.
- Cada cambio de estado escribe un avance (`estado_anterior`, `estado_nuevo`, nota opcional, usuario, fecha). Los avances
  no se editan ni se borran desde la app.
- Solo los compromisos de actas **confirmadas** tienen seguimiento (pantalla, ficha, campanita y correo).
- `recordar_el` (opcional): ese día y mientras siga abierto aparece como "Recordatorio".

### Pantalla Compromisos (decisión 5)

Botón "Compromisos" en `/eventos` (y "Ver compromisos" en la sección de actas del detalle). Filtros: `estado`,
`vencidos=1`, `responsable` (texto; sin tildes en PostgreSQL), `municipio` (del evento), `evento`, `desde`/`hasta`
(**fecha de la reunión** del acta), `q` (texto del compromiso). Orden: abiertos primero, luego por fecha límite (sin
fecha al final). Excel `compromisos.xlsx`: EVENTO, MUNICIPIO, ACTA, FECHA DEL ACTA, COMPROMISO, RESPONSABLES, TELÉFONOS
(texto), FECHA LÍMITE, ESTADO, VENCIDO, AVANCES.

### Lectura del .docx (`ActaDocxReader`)

Solo prellena; el usuario revisa y guarda. Recorre `word/document.xml` (≤ 15 MB descomprimido, sin red; un XML con
`<!DOCTYPE` o `<!ENTITY` se rechaza antes de leerlo, Word nunca los escribe) y detecta las secciones por su título (con o sin número, sin tildes, con `:` final o no):
PARTICIPANTES/ASISTENTES, PUNTOS DEL ORDEN DEL DÍA/ORDEN DEL DÍA, DESARROLLO DE LA REUNIÓN, COMPROMISOS. Encabezado:
primera línea = título, línea con fecha (`08 DE NOVIEMBRE 2026`, `08/11/2026`), `Municipio:`, `Sitio:`/`Lugar:`, `Hora:`
(`10.45 A.M.`, `12:00 m`). Tablas: columnas por el encabezado (NOMBRE, PERFIL/CARGO, MUNICIPIO, TELEFONO/CELULAR,
CEDULA; NOMBRE/COMPROMISO/DESCRIPCION, RESPONSABLE, TELEFONO, FECHA). En compromisos, un responsable por línea de la
celda con el teléfono de la misma línea; si no cuadran, en orden, y un nombre partido en dos líneas ("ALEJANDRA" /
"NAVAS") se une cuando así cuadran nombres y teléfonos. "(Fecha límite: dd/mm/aaaa)" al final de la descripción se lee
como fecha límite (así lo escribe el Word generado). Lo que no encuentra va en `avisos`.

Prueba de solo lectura contra el acta real de Rionegro (08 nov): 19 participantes (18 con teléfono), 7 puntos del orden
del día + introducción, 8 textos de desarrollo (introducción + 7 puntos), 7 compromisos con 9 responsables, todos con
teléfono; fecha, municipio, sitio, horas 10:45–12:00 y cierre leídos.

### Tablas (migración `2026_11_10_000001_create_event_actas_tables`, aditiva; no toca `data_consents`)

`event_actas` (event_id, campaign_id, estado borrador|confirmada, confirmed_at, confirmed_by, titulo, fecha, municipio, sitio, hora_inicio, hora_fin, introduccion, orden_dia jsonb,
desarrollo jsonb, cierre, archivo_path/mime/nombre, created_by, updated_by), `event_acta_participantes` (acta_id, orden,
nombre, perfil, municipio, telefono, cedula, lider_id, sugerencia_rechazada, attendee_id, created_lider, created_attendee — estas dos
por la migración aditiva `2026_11_11_000001_add_created_flags_to_event_acta_participantes`, se llenan al confirmar), `event_compromisos` (acta_id,
event_id, campaign_id, orden, descripcion, fecha_limite, recordar_el, estado), `event_compromiso_responsables`
(compromiso_id, orden, nombre, telefono, lider_id, sugerencia_rechazada), `event_compromiso_avances` (compromiso_id, nota, estado_anterior, estado_nuevo, archivo_*, user_id, created_at).
Amplía `event_attendees_source_check` con `'acta'` (en producción hoy: qr, operador, excel, ocr). El `down()` falla a
propósito en PostgreSQL mientras existan asistentes con `source = 'acta'`.

### Alertas: campanita y resumen diario por correo

`CompromisoAlertas` calcula, para el usuario actual (campañas visibles y territorio), los compromisos **abiertos**
(`pendiente`, `en_curso`) de actas **confirmadas**: **vencidos** (fecha límite antes de hoy), **por vencer** (fecha límite
de hoy a 3 días) y **recordatorios** (`recordar_el` hoy o antes). Cada uno cuenta una vez, en ese orden de prioridad.

- **Campanita** en el encabezado (escritorio y móvil) para `view.eventos` o `manage.eventos`: número total y, al abrirla,
  lista corta (vencidos, por vencer, recordatorios) con enlace `/eventos/compromisos?abrir={id}` (abre el seguimiento).
  Una petición cada 5 minutos para toda la app (caché en el navegador + 5 min por usuario en el servidor).
- **`php artisan compromisos:resumen-diario [--dry-run]`**, programado a las 7:00 a. m. (America/Bogota) en
  `routes/console.php` (necesita el cron `schedule:run`, que ya existe en producción para www-data). Envía a cada usuario
  activo con `manage.eventos`, correo válido y campaña activa un correo (`emails/compromisos-resumen`) con sus vencidos,
  por vencer y recordatorios (hasta 50) y el enlace a la pantalla; si no tiene ninguno, no se envía. Si `MAIL_MAILER` es
  `log`/`array` o SMTP sin host, registra "correo no configurado" y termina sin error. `--dry-run` muestra por usuario
  (solo id y conteos) a quién se enviaría. El log solo lleva conteos e ids. Un envío fallido se registra y el comando
  sigue con los demás (sale con código de error).
- Producción (revisado 2026-11-10, sin mostrar valores): `MAIL_MAILER=smtp` con host, puerto, usuario, clave y remitente
  definidos.

### Despliegue

Sin paquetes nuevos de Composer. `php artisan migrate --force`, `npm run build`, `php artisan optimize:clear && php artisan
optimize`. El permiso `delete.actas` (2026-11-11) trae la migración aditiva `2026_11_11_000001_add_created_flags_to_event_acta_participantes`
(dos booleanos con default false; no toca `data_consents` ni las vistas `agent_ro`). Para subir actas o fotos de hasta 10 MB en producción, PHP-FPM necesita `upload_max_filesize ≥ 12M` y
`post_max_size ≥ 16M` (hoy 2M/8M: con eso el límite real es 2 MB y los mensajes lo dicen).
