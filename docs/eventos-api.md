# Eventos especiales — contrato backend (Fase 1 + Fase 2: planilla PDF + Fase 3: cargue desde Excel + Fase 4: integración)

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

Query de la tabla de asistentes: `link_status`, `source` (`qr|operador|excel`), `q` (nombre o cédula), `page`.
`kpis` trae además `excel` (asistentes cargados desde Excel; todos con autorización, ver §11). `source` del asistente
puede ser `excel` (en pantalla: "Por Excel: <quien confirmó>").

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
  el consentimiento fue sobre un texto aprobado** (con el texto provisional queda `false`), `observacion` vacía (nunca el
  nombre del evento: la ficha la leen usuarios sin `view.eventos`; el vínculo queda en `event_attendees.lider_id`) y el fijo como contacto; el asistente
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
`link_status`: `vinculado | pendiente | sin_cedula | aprobado | descartado` (`sin_cedula` queda reservado; en Fases
1–3 la cédula es obligatoria). `event_attendees.source`: `qr | operador | excel` (CHECK en PostgreSQL; `excel` lo agrega
la migración `2026_11_01_000001`, que no toca `data_consents`). Fase 3 agrega `event_imports`, `event_import_rows` y
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

---

## 11. Cargue de asistentes desde Excel (Fase 3)

Solo `manage.eventos`, en eventos de la **campaña propia** y del territorio (igual que editar: otra campaña no visible →
404, subcampaña visible → 403, fuera de territorio → 403). Evento `cancelado` → 422 `errors.evento`
(`"El evento está cancelado: no se pueden cargar asistentes."`); se puede cargar en `borrador`, `abierto` y `cerrado`
(las planillas suelen llegar después del evento). Sin cola: todo corre en la petición (límites abajo).

**Regla de autorización (igual que QR y operador; decisión del orquestador en ausencia de Marly):** solo se registra a
quien autorizó el tratamiento de sus datos. Una fila es importable **solo si `Autorizó` = SÍ**, y confirmar **exige** la
declaración "Tengo las planillas firmadas que respaldan las autorizaciones de este archivo". Por tanto **todo asistente
importado queda con autorización** (`autoriza_datos = true` y su fila en `data_consents`). No existe importar "sin
autorización".

**Texto aprobado obligatorio (como el QR público):** subir un archivo y confirmar exigen que el texto de autorización
vigente de la campaña esté **aprobado**. Con solo el texto provisional (o sin texto) → **422** `errors.consent`
`"Falta el texto de autorización aprobado por la campaña; no se puede cargar Excel hasta tenerlo."`, sin guardar nada
(ni archivo ni staging). La pantalla muestra ese aviso en rojo una sola vez y deshabilita subir y confirmar. Plantilla,
listado y revisión de columnas/filas sí funcionan (para preparar el archivo mientras se aprueba el texto).

### Plantilla — `GET /eventos/{id}/plantilla.xlsx`

Hoja **Asistentes** con encabezados fijos: `Nombres`, `Apellidos`, `Cédula`, `Celular`, `Teléfono fijo`,
`Fecha de nacimiento (DD/MM/AAAA)`, `Dirección`, `Barrio`, `Autorizó (SÍ/NO)`. Columnas C–F (cédula, celular, fijo y
fecha) con formato **texto** en las filas 2–2001 (Excel no muestra `1,09877E+09` ni quita ceros) y lista `SÍ,NO` en
`Autorizó`. Hoja **Instrucciones** con cómo llenarla ("solo se cargan las personas con SÍ") y el texto de autorización
vigente de la campaña con su **versión**; si es provisional, una advertencia en amarillo.
Nombre: `plantilla-asistentes-<evento>.xlsx`.

### Flujo y estados de `event_imports.status`

1. `POST /eventos/{id}/importar` (multipart, campo `archivo`): `.xlsx`, `.xls` o `.csv` (por extensión; el contenido lo
   valida el lector), **máx. 5 MB** (o lo que permita PHP, ver límites) y **2000 filas** con datos. Se guarda en el disco
   **privado** (`storage/app/private/eventos/importaciones/{event}/{import}.{ext}`, sin URL pública) con su `sha256`,
   se leen las filas a staging y se adivinan las columnas → `subido`.
   - CSV: separador `;`, `,` o tabulador (el más frecuente en la primera línea); UTF-8 o Windows-1252.
   - Fila de encabezados: la que más encabezados conocidos tenga entre las 10 primeras (admite un título arriba).
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
     sinónimos (`CC`, `C.C.`, `Documento`, `No. cédula`, `Cédula de ciudadanía` → cédula; `Nombre completo`, `Nombres y
     apellidos` → nombre completo; `Tel`, `Teléfono`, `Móvil`, `Celular`, `WhatsApp` → celular; un segundo teléfono →
     fijo; `Autorizó`, `Autoriza`, `Firmó`, `Acepta` → autorizó). Una sola columna `Nombre` sin columna de apellidos se
     toma como nombre completo.
2. `PUT .../columnas` `{ "columnas": { campo: índice de columna | null } }` con campos `nombres`, `apellidos`,
   `nombre_completo`, `cedula`, `celular`, `fijo`, `fecha_nacimiento`, `direccion`, `barrio`, `autoriza`. Obligatorios:
   `cedula`, `celular`, **`autoriza`** y (`nombres` + `apellidos`) o `nombre_completo`; una columna no puede usarse dos
   veces (422 `errors.columnas`). Aplica el mapeo a **todas** las filas (borra correcciones previas), valida y clasifica
   → `revisado`; este mapeo queda guardado para la próxima carga de la campaña. **200** `{ success, message, data: { counts } }`.
3. `PATCH .../filas/{fila}` (solo en `revisado`): cualquiera de `nombres`, `apellidos`, `cedula`, `celular`, `fijo`,
   `fecha_nacimiento`, `direccion`, `barrio` y/o `decision: "importar" | "omitir"`. **`autoriza` no es editable** (se
   ignora si llega): vale lo que dice la planilla. Una fila `no_autorizo` **no se puede cambiar** (ni celdas ni
   decisión): 422 `errors.fila` `"Esta fila no se puede cambiar: en «Autorizó» no dice SÍ, la persona no autorizó el
   tratamiento de sus datos."`. Un valor malo **no** es 422: se guarda y la fila queda con su error.
   `importar` en una fila que no es `valida` → 422 `errors.decision` (p. ej. `"Esta fila no se puede importar: en
   «Autorizó» no dice SÍ: la persona no autorizó el tratamiento de sus datos."`). Revalida la fila y reclasifica el
   archivo. **200** `{ success, message, data: { fila: Fila, counts } }`.
4. `POST .../confirmar` `{ "declaracion": true }` (desde `revisado`/`confirmado`). Sin la declaración → 422
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

### Prueba de la autorización en `data_consents` (Ley 1581)

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
opcional, ≤ 120), `page` (asistentes, 50 por página), `pagina_lideres` (líderes, 25 por página).
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
