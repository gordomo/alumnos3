# Pruebas de regresión

## Ayuda y búsqueda

```sh
docker compose exec -T app php tests/regression_ayuda.php
```

No necesita base de datos, sesiones ni proveedor de IA. Usa las plantillas reales y verifica consultas representativas, acentos/sinónimos, ramas por rol, separación de permisos y verticales con una caché compartida, e invalidación inmediata al cambiar el manual.

La implementación se divide en:

- `AyudaCatalogo`: temas y permisos compartidos con el controlador del manual.
- `AyudaFuente`: renderiza exclusivamente el bloque `contenido`, resuelve las condiciones del rol y el vocabulario, elimina imágenes y extrae secciones con sus referencias.
- `AyudaBusqueda`: ordena coincidencias locales; devuelve texto del manual, extracto y enlace, sin generar respuestas.

Se puede probar desde `/ayuda?q=saldo%20a%20favor`. `/ayuda/buscar?q=...` entrega las mismas fuentes en JSON y exige sesión. Roles, identificadores de usuarios e institutos enviados por el cliente no determinan los permisos. Las respuestas no se almacenan en cachés compartidas. Solo se reutiliza el índice estático del manual, separado por permisos efectivos, vertical, contenido y ruta base. No incluye preguntas ni datos de cuentas.

Al actualizar el manual:

1. Conservar los `id` de los encabezados publicados, aunque cambie el título o su posición. Cada encabezado nuevo necesita un `id` único dentro de su tema.
2. Mantener las importaciones de macros dentro del bloque `contenido`, para poder renderizarlo por separado.
3. Mantener ese bloque independiente de datos de cuentas y entidades. Las pruebas funcionan sin Doctrine ni una variable `app` con un usuario real.
4. Conservar los permisos en el catálogo y las condiciones de rol en Twig. No duplicar el manual en archivos mantenidos a mano.

El índice se construye desde las plantillas vigentes y se reutiliza. Un hash de los contenidos invalida la caché al editar temas, macros o vocabulario, sin esperar a su vencimiento; no requiere sincronización ni base vectorial. Es recuperación de documentación; el chat usa esta base y agrega Gemini, límites de uso y controles propios. No debe tratarse un resultado de búsqueda como una respuesta generada ni asumir que describe la configuración particular de un instituto.

La interfaz pide `/ayuda/resultados?q=...` para reemplazar únicamente los resultados; conserva GET normal si JavaScript no está disponible. El fragmento también exige sesión y responde `private, no-store`. La prueba de interacción puede ejecutarse con `node tests/ayuda_busqueda_ui.js`: cubre respuestas fuera de orden, atrás/adelante, limpieza, timeout y sesión vencida.

## Créditos

`tests/regression_creditos.php` requiere una copia del esquema en una base `alumnos_test_*` o `alumnos_auditoria_*`, indicada mediante `DATABASE_URL`. Rechaza la base habitual y revierte las filas de la prueba al terminar. Verifica créditos parciales y totales, aislamiento por alumno, consultas masivas, cuotas futuras y cobros negociados.


## Chat de ayuda con IA

```sh
docker compose exec -T app php tests/regression_ayuda_ia.php
docker compose exec -T app php tests/regression_ayuda_ia_cuotas.php
node tests/ayuda_chat_ui.js
```

La primera prueba no llama a Gemini ni necesita base: cubre acceso básico/premium,
modo prueba, interruptor global, institución inactiva, aislamiento de fuentes al
cambiar roles, referencias inválidas, fallos del proveedor y campos comerciales
reservados al superadministrador (incluido un envío falsificado).

La prueba de cuotas usa MySQL con una **tabla temporal de la conexión** que oculta
la tabla persistente durante el test. No toca los contadores reales. Verifica límites
por institución, globales y por minuto, rollback de reservas rechazadas y renovación
de ventanas. Requiere haber aplicado la migración para arrancar el kernel normalmente.

La prueba de interfaz usa DOM y XHR simulados: consentimiento, CSRF, renderizado como
texto, rechazo de enlaces externos, consultas duplicadas, borrado y respuestas tardías,
timeout y sesión vencida. No sustituye una revisión gráfica en navegador.

Prueba manual: en una institución habilitada, abrir `/ayuda`, aceptar las condiciones
de prueba y preguntar «cómo registrar un pago». Comprobar fuentes y respuesta; después
probar una pregunta ajena al sistema y roles sin acceso a cobranza. Ninguna prueba
externa se ejecuta automáticamente para evitar consumir cuota gratuita en cada corrida.

## Filtros de profesores

`docker compose exec -T app php tests/regression_profesores_filtros.php` usa tablas
temporales MySQL que ocultan las tablas reales solo en la conexión del test.
Verifica combinación de nombre y curso, orden, inclusión de docentes sin curso y
rechazo de cursos ajenos incluso con una asociación cruzada. No modifica datos reales.
No hay filtro activo/inactivo: ese estado no existe en la entidad Profesor.


## Forma de pago en el listado de profesores

`docker compose exec -T app php tests/regression_profesores_pago_resumen.php` no
usa base de datos ni calcula liquidaciones. Cubre las cuatro modalidades generales,
los acuerdos por curso, reglas apagadas o incompletas, valores heredados y ceros
explícitos, y el reemplazo o suma del fijo mensual. Verifica que no se muestren
valores de hora o viáticos que no se usan en la modalidad efectiva. La prueba de
filtros también verifica el aislamiento de la consulta masiva de reglas de pago.
