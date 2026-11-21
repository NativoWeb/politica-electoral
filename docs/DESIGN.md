# DESIGN.md — Inteligencia Electoral Santander

Guía de diseño para pantallas nuevas. Nace con el módulo Reportes (B-1, nov-2026) y vale para lo que se construya
después. La gente que usa la plataforma es **mayor y con poca experiencia digital**: si dudas, elige más grande,
más simple y con palabras de todos los días.

## Dirección

Estilo Registraduría: azul institucional sobre gris claro, sobrio y neutral (D-10: sin colores de partido en la
interfaz). Se siente como un formulario oficial bien hecho, no como un tablero de analista. Un solo acento
(amarillo bandera) y solo para el foco del teclado y el paso actual.

## Tokens (`resources/css/app.css`, bloque `@theme`)

| Token | Valor | Uso |
|---|---|---|
| `bg` | `#F5F5F5` | Fondo de la página; también el relleno de los campos de texto (se ven "hundidos") |
| `panel` | `#FFFFFF` | Tarjetas, secciones, barra de conteo |
| `ink` | `#1A1A2E` | Texto principal |
| `ink-soft` | `#4A4A68` | Texto de apoyo, ayudas (contraste 8:1, sirve para texto) |
| `ink-faint` | `#8E8EA0` | **No usar para texto** en pantallas nuevas (3,3:1, no pasa AA) |
| `line` | `#E8E8EE` | Bordes suaves y divisores |
| `line-strong` | `#D0D0DC` | Borde de controles apagados (chips, campos) |
| `primary` | `#003B71` | Botón principal, chip encendido, títulos de módulo |
| `primary-light` / `primary-dark` | `#0056A6` / `#002B54` | Hover / presionado |
| `accent` | `#FFD100` | Anillo de foco (`ring-4 ring-accent`) y número del paso actual |
| `good` / `good-light` | `#28A745` / `#D4EDDA` | Confirmaciones ("Listo. Se descargó…") |
| `danger` / `danger-light` | `#DC3545` / `#F8D7DA` | Errores |

Tipografía: **Archivo** (`font-heading`) para títulos y números grandes; **IBM Plex Sans** (`font-sans`) para todo
lo demás. Números que cambian: `tabular-nums`.

## Escala

- Texto base **18 px** (`text-lg`). **Nada por debajo de 16 px** (`text-base`) en pantallas nuevas.
- Títulos: h1 `text-4xl` (36), paso `text-3xl` (30), sección `text-xl`/`text-2xl` en Archivo bold.
- Jerarquía con peso y color antes que con tamaño: dato `font-semibold text-ink`, apoyo `text-ink-soft`.
- Espaciado en múltiplos de 4 px; entre grupos, aire de verdad (`space-y-7`, `mt-8`).
- Radios: `rounded-md` botones y campos, `rounded-lg` tarjetas, `rounded-full` chips, `rounded-xl` diálogos.
- Profundidad: bordes de 2 px (`border-2 border-line`). Sombra solo en lo que flota (barra fija, diálogos).

## Controles

| Control | Clases | Notas |
|---|---|---|
| Botón | `min-h-14 px-6 text-lg font-semibold rounded-md` | 56 px de alto. Primario `bg-primary text-white`; secundario `bg-panel border-2 border-primary text-primary`. Ícono siempre con texto. `active:scale-[0.98]` |
| Chip | `min-h-12 px-5 text-lg rounded-full border-2` | Ver patrón abajo |
| Campo de texto | `min-h-14 text-lg rounded-md border-2 border-line-strong bg-bg focus:border-primary` | Etiqueta visible encima, nunca solo placeholder |
| Casilla | `w-6 h-6` dentro de una fila `min-h-14` toda tocable (`<label>`) | |
| Foco | `focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent` | Constante `focoCls` en `Components/Reportes/ui.jsx` |

Reglas: **sin `autoFocus` en táctil** (en iPhone el teclado tapa la pantalla; ver `SearchableDropdown`);
áreas táctiles de 48 px o más; cada estado (cargando, vacío, error) con frase propia y una acción para salir de él.

## Patrón: chip

Un botón que se prende y se apaga. Se usa para filtros ("Gran elector", "Bucaramanga") y atajos de columnas.

- Apagado: `bg-panel border-line-strong text-ink`, hover `border-primary`.
- Encendido: `bg-primary border-primary text-white font-semibold` + check SVG a la izquierda.
- Siempre `type="button"` y `aria-pressed`. El grupo va en `role="group"` con `aria-labelledby` a su título.
- Varios encendidos en un mismo filtro = **"O"** ("verás a las personas de cualquiera de ellos"). Entre filtros = "Y".
- Opción única (Estado, Autorizó): el valor por defecto se ve encendido cuando no hay nada elegido; tocarlo limpia el filtro.
- Chip de resumen ("Estás viendo: [Bucaramanga ✕]"): encendido, con X, `aria-label="Quitar Bucaramanga"`.
- Componente: `Components/Reportes/ui.jsx` → `Chip`; grupo: `ChipGroup.jsx`.

## Patrón: barra de conteo

Barra fija abajo mientras se arma una lista (`Components/Reportes/BarraConteo.jsx`). Es el centro de la pantalla:
le dice a la persona, en cada toque, cuánta gente queda.

- Posición: `fixed bottom-0` en escritorio (a la derecha del menú, `lg:left-[200px]`); en el teléfono encima de la
  barra de pestañas: `bottom-[calc(72px+env(safe-area-inset-bottom))]` (la barra de pestañas crece con el área
  segura del iPhone, así que esta también). La página deja espacio abajo (`pb-64 sm:pb-44`).
- Número: `font-heading text-3xl font-bold tabular-nums` + "personas"; contenedor `aria-live="polite"`.
- Contando: el número anterior a `opacity-50` + giro + "Contando…" (nunca un cero falso).
- Error: «No pudimos contar. Revisa tu conexión.» + [Intentar de nuevo].
- Cero: «Nadie cumple todos estos filtros. Prueba quitando uno.» + [Quitar «último filtro»]; "Siguiente" deshabilitado.
- Pide al servidor 400 ms después del último toque y cancela la petición anterior (`useDebouncedRequest` en `lib/reportes.js`).

## Patrón: pasos (stepper)

Cuatro botones numerados, todos tocables (`aria-current="step"` en el actual). Paso actual: fondo `primary`,
número sobre `accent`. Cambiar de paso crea una entrada del historial (Atrás vuelve al paso anterior); cambiar un
filtro la reemplaza. Al cambiar de paso el foco va a un texto oculto "Paso N de 4: …" para el lector de pantalla.

## Estado en la URL

Todo lo que la persona elige vive en la URL (`?fuente=…&paso=…&f=…&c=…&o=…&q=…&p=…`): recargar, ir atrás o
cerrar una ficha nunca pierde lo elegido. Lectura y escritura solo en `lib/reportes.js` (`leerEstado`,
`urlDeEstado`), con `router.push`/`router.replace` de Inertia (visitas del lado del cliente, sin ir al servidor).

## Textos

- Tú, frases cortas, verbos de todos los días: "Descargar Excel", "Quitar todos los filtros", "Intentar de nuevo".
- Nada de jerga: ni "query", ni "filtro aplicado", ni "registros", ni códigos de error. "Personas", "datos", "lista".
- Los errores dicen qué pasó y qué hacer: «Pediste varias descargas seguidas. Espera un minuto y vuelve a intentarlo.»
