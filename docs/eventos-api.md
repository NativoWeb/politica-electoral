# Eventos especiales — contrato backend (Fase 1 + Fase 2 backend: planilla PDF)

Contrato entre el backend (Laravel) y el frontend (React + Inertia) del módulo **Eventos especiales**:
eventos de campaña ("Del Senado al barrio", giras, novenas, reuniones) con registro de asistentes por
**QR público** y por **operador**. Fuente de verdad: si el backend cambia, este archivo se actualiza.

Convenciones:

- Fechas/hora: ISO 8601 con zona (`2026-11-05T18:00:00-05:00`) en props y respuestas; en los payloads se aceptan
  `YYYY-MM-DD HH:mm`, `YYYY-MM-DDTHH:mm` o ISO 8601 (zona de la app: `America/Bogota`).
- Todos los ids son UUID.
- JSON de error: `{ "success": false, "message": "..." }` (409, 410), `{ "message": "..." }` (403, 404) o el
  formato estándar de Laravel `{ "message": "...", "errors": { clave: ["..."] } }` para **todo 422**, también las
  reglas de negocio de eventos, con estas claves: `consent` (abrir sin texto aprobado), `status` (transición inválida),
  `evento` (borrar con asistentes), `ids` (asistentes ajenos al evento), `organizadores` (líder no válido),
  `ends_at`/`registration_closes_at` (fechas), `consent_text_id` (texto cambiado). En Inertia llegan como `errors.<clave>`.
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
| abrir | `borrador`, `cerrado` | La campaña del evento debe tener un texto de autorización **activo y aprobado**. Si no → **422** (`errors.consent`) `"La campaña no tiene un texto de autorización de datos aprobado. El QR público no se puede abrir hasta que lo esté; mientras tanto el operador puede registrar con el texto provisional."` |
| cerrar | `abierto` | — |
| cancelar | `borrador`, `abierto`, `cerrado` | — |
| borrar | cualquiera | Solo si el evento **no tiene asistentes**; si tiene → 422 (`errors.evento`) `"El evento ya tiene asistentes: cancélelo en lugar de borrarlo."` (borrado lógico, `deleted_at`). |

Transición inválida → **422** (`errors.status`).

**QR público acepta registros** solo si: `status = abierto` **y** el texto vigente de la campaña está aprobado **y**
no está vencido. Vencido = pasado `registration_closes_at`; si es null, pasado `ends_at + 6 h`; si también es null,
pasado `starts_at + 24 h`.

**Operador acepta registros** si `status` ∈ {`borrador`, `abierto`} (no cerrado/cancelado/borrado). No le aplica el
vencimiento (puede pasar registros tarde). Con un texto **provisional** (no aprobado) puede registrar; la página
lo marca (`consent.approved = false`) y el consentimiento queda guardado con ese texto y su hash.

---

## 4. Texto de autorización (Ley 1581)

- Lo trae la campaña. Texto vigente de una campaña = su `consent_texts` activo más reciente; si no tiene ninguno,
  se usa el **texto provisional global** (`campaign_id` null, `approved = false`, sembrado por la migración).
- Fase 1 no tiene pantalla para administrarlo. Se carga por consola (R01/devops):
  `php artisan eventos:consent-text {campaña: slug o id} {archivo.txt} --text-version=v1 [--approve]`
  (crea una versión nueva, la deja activa y desactiva las anteriores de esa campaña).
- Cada registro guarda una fila append-only en `data_consents` con `consent_text_id`, `text_sha256`, cédula, canal,
  IP, user agent y fecha. En PostgreSQL UPDATE/DELETE/TRUNCATE sobre `data_consents` fallan (trigger + REVOKE).

Objeto `consent` en props: `{ id, version, cuerpo, approved }`.

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

Query de la tabla de asistentes: `link_status`, `source` (`qr|operador`), `q` (nombre o cédula), `page`.

```jsonc
{
  "evento": EventoDetalle,
  "asistentes": {                   // paginador (50 por página), orden created_at desc
    "data": [ Asistente ], "current_page": 1, "last_page": 1, "per_page": 50, "total": 3, "links": [ ... ]
  },
  "kpis": { "total": 3, "vinculados": 1, "pendientes": 1, "aprobados": 1, "descartados": 0, "qr": 2, "operador": 1 },
  "filters": { "link_status": null, "source": null, "q": null },
  "consent": { "id": "uuid", "version": "provisional-1", "cuerpo": "...", "approved": false },
  "tipos": [ { "id": "uuid", "nombre": "Gira" } ],                 // para el formulario de edición
  "provincias": [ ... ],                                           // igual que en Index
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
  "barrio": "El Poblado", "autoriza_datos": true,
  "consentimiento_provisional": false, // true = aceptó un texto NO aprobado (operador con texto provisional):
                                       // al aprobarlo a Votantes la ficha queda con autoriza_datos = false
  "source": "qr",                    // qr | operador
  "link_status": "pendiente",        // vinculado | pendiente | sin_cedula | aprobado | descartado
  "lider_id": "uuid|null",           // ficha en Votantes (vinculado/aprobado)
  "registered_by": { "id": "uuid", "name": "..." } | null,
  "created_at": "..."
}
```

### `Eventos/Operador` — `GET /eventos/{id}/operador`

```jsonc
{
  "evento": { "id", "nombre", "status", "starts_at", "sitio_encuentro", "municipio": {...}, "provincia": {...},
              "tipo": {...}, "barrios": ["..."], "public_url": "...", "accepts_registrations": true },
  "consent": { "id": "uuid", "version": "...", "cuerpo": "...", "approved": false }, // approved=false → mostrar "TEXTO PROVISIONAL"
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
  "consent": { "id": "uuid", "version": "v1", "cuerpo": "..." },   // null si estado = cerrado
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
  "consent_text_id": "uuid",           // requerido: el `consent.id` mostrado; si cambió → 422 (recargar)
  "autoriza_datos": true,              // requerido y aceptado
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
- **422** validación (`errors`) o texto de autorización cambiado (`errors.consent_text_id`).
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
- **422** validación (p. ej. sin `autoriza_datos` → `errors.autoriza_datos`). En Inertia llegan como `errors`.
- **410** evento no abierto/vencido o texto sin aprobar: JSON `{ success: false, message: "Este evento ya no recibe registros." }`;
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
  el consentimiento fue sobre un texto aprobado** (con el texto provisional queda `false`), `observacion = "Registrado en el evento <nombre> (<fecha>)."` y el fijo como contacto; el asistente
  pasa a `aprobado` con `lider_id`.
- Aprobar dos veces no duplica.

Respuesta: **200** `{ success: true, message: "...", data: { aprobados: 1, vinculados: 1, omitidos: 0 } }`.

Descartar (solo `pendiente`): pasa a `descartado`. **200** `{ success: true, message: "...", data: { descartados: 2, omitidos: 0 } }`.

Ids que no son del evento → 422 (`errors.ids`). Sin `manage.eventos` → 403.

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
`link_status`: `vinculado | pendiente | sin_cedula | aprobado | descartado` (`sin_cedula` queda reservado para
fases de importación; en Fase 1 la cédula es obligatoria).

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
- Descarga de la planilla: `EVENTO_PLANILLA_DESCARGADA`, entidad `evento`, metadata
  `{ route: "eventos.planilla", paginas, consent_text_id }` (el middleware `audit` no registra GET).

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
  Fecha de nacimiento `DD / MM / AAAA` (8 casillas), Dirección, Barrio, casilla Autorizo y Firma; filas con
  sombreado alterno; marcas de esquina en L negras en las 4 esquinas para enderezar fotos.
  Pie: resumen del texto de autorización vigente de la campaña (cortado en una frase, con `[…]`) y su **versión**;
  si el texto no está aprobado (o no hay) imprime **"TEXTO PROVISIONAL — no válido como autorización"**; indica que el
  texto completo se lee a la persona y se pide al organizador; línea de trazabilidad con el id completo del evento.
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
