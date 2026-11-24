# Migración de Electo

Importa los CSV que exportó el sistema anterior **Electo** (votantes, contactos, mapa político, líderes, eventos,
asistentes, funcionarios y catálogos). Los CSV tienen datos personales: **nunca** se copian al repositorio, a los tests
ni a los logs. El reporte del importador solo lleva conteos, nombres de campos, códigos DIVIPOLA e ids de Electo.

## Piezas

| Pieza | Qué hace |
|---|---|
| Migración `2026_11_18_100001_add_electo_origin_columns` | `origen` / `origen_id` (único parcial) en `lideres`, `lider_contactos`, `lider_info_politica`, `events`, `event_attendees`; `origen` en `lider_municipios_influencia`; `lideres.origen_meta` y `lideres.migracion_flags` (jsonb, índice GIN); `events.migracion_flags`; `lider_contactos.whatsapp`; `lider_info_politica.periodo_desde/hasta` y `migracion_flags`; CHECK de `event_attendees.source` con `'electo'`; CHECK de `data_consents.channel` con `'migracion_electo'` **solo si el usuario de la app es dueño de la tabla** (en producción no lo es: ver SQL manual). |
| Migración `2026_11_18_100002_create_funcionario_and_electo_import_tables` | `funcionario_entidades`, `funcionario_contratos` (sin salario) y `electo_import_rows` (linaje: entidad + id de Electo → fila local, acción y motivo; sin datos personales). |
| `php artisan geo:cargar-divipola [archivo] [--dry-run]` | Carga los 32 departamentos y 1.035 municipios DIVIPOLA de fuera de Santander (fuente: DANE, datos.gov.co `gdxc-w37w`, copia en `database/data/geo/divipola_municipios.csv`). No toca Santander. A lo creado a mano (Cundinamarca, Bogota) solo le pone el código. Un municipio cuyo nombre se repite en otro departamento queda como "Nombre (Departamento)" para que las búsquedas por nombre de la plataforma no lo confundan con uno de Santander. |
| `php artisan electo:importar {carpeta} [--dry-run] [--solo=personas,contactos,mapa,eventos,funcionarios] [--lote=2000] [--campana=uuid] [--reporte=ruta]` | El importador. Todo va al grupo CD por defecto. |

## Reglas que aplica (decisiones de Marly, 2026-11-18)

- **Personas** (`votantes` → `lideres`, tipo `votante`; `lider` si Electo lo marca líder o tiene asignación de líder):
  - Cédula: solo dígitos y sin ceros a la izquierda. Un documento tipo 0 ("sin dato") que empieza en cero es un
    relleno de Electo (`00012345`): no se toma como cédula (marca `documento_sin_tipo`). Menos de 5 dígitos: `documento_invalido`.
  - Ya existe en el grupo por cédula → **solo se llenan los campos vacíos**; nada se sobrescribe. Lo distinto queda en
    `migracion_flags.contradicciones {campo: valor de Electo}` y el valor previo de lo llenado en `migracion_flags.electo_antes`.
  - Sin coincidencia por cédula pero mismo nombre y municipio que una ficha existente → se crea aparte con
    `posible_duplicado: [ids]` (nunca se fusiona solo).
  - Menores (Ley 1581 art. 7, decisión por defecto): no se toma la autorización de Electo (`autoriza_datos = false`,
    sin `data_consents`, marca `autorizacion_menor_omitida`).
  - Ficha existente sin `geographic_unit_id`: solo se completa el municipio si su texto está vacío o es el mismo;
    si es otro queda como contradicción. Si se llena la autorización desde Electo: marca `autoriza_datos_desde_electo`.
  - Menores (por fecha de nacimiento), fallecidos y sin municipio → `is_active = false` y marca. A una ficha existente
    solo se le pone la marca (no se desactiva).
  - Fechas `1900-01-01`, `0001-01-01`, futuras, de más de 110 años o imposibles → vacío y marca `fecha_invalida`.
  - Observación de más de 4.000 caracteres → recortada y marca `observacion_recortada`.
  - Catálogos que sí vinieron: profesión, escolaridad (a los valores de nuestras pantallas), estado civil y partido.
  - **Catálogos que no vinieron** (decisión de Marly 2026-11-18: no esperar a Electo): barrio, confianza, convenio y
    tipo de hoja de vida quedan **vacíos** en nuestra ficha (`nivel_confianza` = `sin_llamar`, su valor vacío), con el
    código de Electo en `migracion_flags.electo_codigos {campo: código}` y el campo en
    `migracion_flags.por_completar [campos]` para que el equipo lo complete con los filtros
    (`WHERE migracion_flags @> '{"por_completar": ["barrio"]}'`, índice GIN). Código vacío o `0` = Electo no tenía
    dato (no se marca). Origen del dato y usuario creador/editor no tienen campo nuestro: solo quedan en `electo_codigos`.
    Cuando llegue el catálogo de barrios, `data:electo-barrios {csv}` llena los barrios vacíos desde esos códigos y
    asigna la comuna (docs/geografia.md); el puesto de votación ya da comuna con `geo:asignar-comunas`.
  - Género: 1 = Femenino, 2 = Masculino, 3 = Otro (**inferido** en el diagnóstico), marca `genero_inferido` y código
    original en `electo_codigos.genero`.
  - **No se migra** `clavegl`.
  - Referente (`fkidpadre`): segunda pasada que llena `referente_documento/nombre/apellido` donde estén vacíos.
- **Autorización (Ley 1581)**: se respeta la de Electo (`autoriza_datos`). Como evidencia (débil) se inserta un
  `data_consents` por ficha autorizada, canal `migracion_electo`, sobre un `consent_texts` propio
  (`electo-migracion-1`: "Autorización registrada en el sistema anterior Electo (sin fecha ni texto)"), **inactivo y no
  aprobado** para que nunca sea el texto vigente de eventos ni del QR. `accepted_at` = fecha de creación del registro en
  Electo (no es la fecha real de la autorización: Electo no la guarda). `data_consents` es de solo inserción: no se
  modifica ninguna fila existente y lo insertado no se puede borrar.
- **Contactos**: celular/fijo solo dígitos sin 57; email en minúsculas y válido (los inválidos se omiten); marca
  WhatsApp; un solo principal por tipo; no se repite un valor que la ficha ya tenía. El principal llena
  `lideres.telefono` / `email` si están vacíos. Si una ficha existente tenía teléfono/correo y ninguno de Electo
  coincide → contradicción.
- **Mapa político**: `mapapolitico` → `lider_info_politica`, **todas** las filas (incluido estado 0): partido, aliado,
  votos y periodo. Cargo y estado sin catálogo quedan **vacíos** (`estado = ''`, sin interpretar) con sus códigos (y el
  del tipo 0-3) en `lider_info_politica.migracion_flags.electo_codigos` y `por_completar`. `lideres` de Electo (asignaciones) →
  `lider_municipios_influencia` con nota (detalle, periodo, activa) y cargo de la ficha si estaba vacío.
- **Eventos**: cerrados, tipo "Importado de Electo" (inactivo), organizador vinculado; barrio y tipo de evento de
  Electo (sin catálogo) en `events.migracion_flags.electo_codigos`, barrio en `por_completar`. Un evento de Electo con el mismo
  día y municipio que uno que ya tiene el grupo **no se crea** y sus asistentes no se importan (Barrancabermeja
  18-09-2026). Asistentes con `source = 'electo'`, `link_status = 'vinculado'`, `client_uuid` UUID v5 del id de Electo.
- **Funcionarios**: entidades y contratos; el salario se descarta al leer la fila. Solo el superadmin los ve
  (`App\Models\Concerns\SuperadminOnly`); no están en las vistas `agent_ro`.
- **Idempotente y reanudable**: cada lote se confirma por separado; lo que ya está en `electo_import_rows` como
  creado/fusionado se salta. `--dry-run` hace todo en una sola transacción que se revierte al final (mismo reporte).

## SQL manual en producción (como postgres)

`data_consents` es de `postgres` (deploy/sql/data_consents_owner.sql), así que la migración no puede ampliar su CHECK.
Después de `php artisan migrate` y **antes** de importar:

```sql
-- sudo -u postgres psql -d politica_electoral
BEGIN;
ALTER TABLE public.data_consents DROP CONSTRAINT data_consents_channel_check;
ALTER TABLE public.data_consents ADD CONSTRAINT data_consents_channel_check CHECK (channel IN ('qr', 'operador', 'migracion_electo'));
COMMIT;
```

`geographic_units` también es de `postgres`, pero `electoral` tiene INSERT y UPDATE (verificado con
`has_table_privilege` el 2026-11-18): `geo:cargar-divipola` no necesita SQL manual y no hace ALTER.

## Procedimiento en producción

1. **Respaldo** (verificar que restaura):
   `sudo -u postgres pg_dump -Fc politica_electoral > /root/backups/pre_electo_$(date +%Y%m%d_%H%M).dump`
   y `pg_restore -l` del archivo.
2. **Deploy** del código (revisar antes `git log` del servidor contra origin) y `php artisan migrate --force`.
3. **SQL manual** de `data_consents` (arriba). Comprobar:
   `SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'data_consents_channel_check';`
4. **Copiar los CSV** al servidor fuera del proyecto (p. ej. `/root/electo_csv/`, `chmod 700`). No dentro de `/var/www`.
5. **DIVIPOLA**: `php artisan geo:cargar-divipola --dry-run`, revisar (32 departamentos, 1.035 municipios, Santander 0
   cambios) y luego sin `--dry-run`. Después `php artisan cache:forget all_municipios` (lista de municipios en caché).
6. **Dry-run**: `php artisan electo:importar /root/electo_csv --dry-run`; comparar el reporte con el del ensayo.
7. **Carga**: `php artisan electo:importar /root/electo_csv` (ventana de bajo uso). Si se interrumpe, volver a
   correr el mismo comando: continúa.
8. **Compactar** (las pasadas de referente y teléfono/correo reescriben ~130.000 filas): en ventana de bajo uso,
   `VACUUM FULL ANALYZE lideres; VACUUM FULL ANALYZE lider_contactos;` (bloqueo exclusivo de unos segundos; en el
   ensayo 7 s y la base pasó de 816 MB a 373 MB).
9. **Verificación**: segunda corrida sin `--dry-run` → todo "ya_importado" y 0 creados; conteos:
   `SELECT tipo, is_active, count(*) FROM lideres WHERE origen = 'electo' GROUP BY 1, 2;`, contactos, eventos y
   asistentes `WHERE origen = 'electo'`; `ANALYZE`; abrir Votantes y Mapa Político (requiere la paginación del otro
   frente ya desplegada) y una ficha fusionada.
10. **Borrar los CSV** del servidor y guardar el reporte JSON (no tiene datos personales).

## Ensayo (2026-11-18, copia local de producción)

DIVIPOLA 1 s; dry-run 118 s; carga 121 s (personas 74 s, contactos 42 s, mapa 2 s, eventos 3 s); segunda corrida 16 s
sin crear nada; pico de memoria PHP 114 MB (172 MB en la segunda corrida). El dry-run dio exactamente los mismos
conteos que la carga. Base: 46 MB restaurada → 816 MB tras la carga → 373 MB tras `VACUUM FULL`.

## Reversión

- **Completa**: restaurar el respaldo del paso 1 (`pg_restore --clean --if-exists -d politica_electoral`), con la
  app en mantenimiento. Es la única que deshace también los `data_consents` (solo inserción).
- **Parcial (solo lo creado)**, en una transacción:

```sql
DELETE FROM event_attendees WHERE origen = 'electo';
DELETE FROM events WHERE origen = 'electo';            -- organizadores y barrios por cascada
DELETE FROM funcionario_contratos WHERE origen = 'electo';
DELETE FROM funcionario_entidades WHERE origen = 'electo';
DELETE FROM lider_municipios_influencia WHERE origen = 'electo';
DELETE FROM lider_info_politica WHERE origen = 'electo';
DELETE FROM lider_contactos WHERE origen = 'electo';
DELETE FROM lideres WHERE origen = 'electo';
DELETE FROM electo_import_rows;
```

  Las fichas existentes que se llenaron conservan en `migracion_flags.electo_antes` el valor previo de cada campo
  llenado (y `electo_llenado` para teléfono/correo); los `data_consents` insertados quedan (no se pueden borrar).
