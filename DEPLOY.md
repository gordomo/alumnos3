# Despliegue

## Publicar la prueba de ayuda con IA (noviembre 2026)

Este despliegue incluye las correcciones previas del manual, créditos, permisos y PDF,
y el buscador y chat nuevos. Incluye una dependencia nueva (Dompdf) y una migración
nueva. El procedimiento histórico de suscripciones más abajo no debe ejecutarse
como receta de esta publicación.

1. Confirmar instalación y revisar `git status` en el servidor. Registrar el commit
   anterior y respaldar la base con las credenciales del propio servidor, fuera del
   directorio público. Conservar su `.env`, `.env.local`, compose y uploads.
2. Construir la imagen y preparar las dependencias bloqueadas por `composer.lock`
   antes del corte. `.dockerignore` excluye credenciales, uploads y cachés. La
   instalación de Composer de la imagen omite scripts porque el entorno no se
   incorpora a la imagen. El volumen de código del compose requiere instalar
   también sus dependencias: `composer install --no-dev --optimize-autoloader
   --no-interaction --no-scripts` dentro de `app`.
3. Durante un corte breve del servicio web, aplicar únicamente
   `DoctrineMigrations\Version20261109170000` con `doctrine:migrations:execute
   'DoctrineMigrations\Version20261109170000' --up --no-interaction`. Revisar antes
   que `plan`, `ayuda_ia_prueba` y `ayuda_ia_cuota` no existan. Si están aplicados,
   comprobar su definición y el registro de versión; no repetir ni ejecutar otras
   migraciones pendientes automáticamente.
4. Configurar la prueba de Gemini según la sección final: `AYUDA_IA_ENABLED=1` y
   `AYUDA_IA_SOLO_PRUEBAS=1`. No copiar todo el entorno local ni sus identificadores
   de institución. Ninguna institución se habilita por la migración.
5. Limpiar y calentar la caché como `www-data`, levantar la imagen nueva de `app`
   y verificar login, ayuda, PDF y los controles del chat. Habilitar la prueba solo
   para la institución elegida desde el superadministrador.
6. Verificar que un plan básico mantenga la búsqueda y rechace `POST /ayuda/chat`.
   Si Gemini falla o alcanza cuota, los artículos deben seguir disponibles.

Rollback del código: volver al commit/release anterior, reinstalar sus dependencias
y limpiar la caché. Conservar las columnas y la tabla aditivas de esta migración;
no ejecutar un rollback masivo de las migraciones históricas. Para apagar únicamente
la IA usar `AYUDA_IA_ENABLED=0`.


## Rendimiento de PHP en Docker

La imagen habilita OPcache y `php-custom.ini` conserva `validate_timestamps=1` y `revalidate_freq=0`: el bytecode se reutiliza y los archivos se comprueban en cada petición para seguir editando en desarrollo. OPcache no almacena páginas ni respuestas de usuarios. La CLI permanece sin caché de bytecode.

Al desplegar este cambio de imagen, usar `docker compose up -d --build app`. Cambiar solo el archivo INI no carga una extensión Zend que la imagen no haya habilitado. Si se cambia únicamente la configuración INI en un contenedor ya actualizado, reiniciar `app` para que Apache la relea.

## Correcciones de ayuda, créditos y permisos (noviembre 2026)

Este cambio agrega `dompdf/dompdf`: ejecutar `composer install --no-dev --optimize-autoloader --no-interaction` antes de limpiar la caché. No agrega migraciones ni modifica saldos existentes por una tarea masiva. Los créditos ya aplicados se reflejan en el cálculo al consultar; los saldos disponibles siguen aplicándose manualmente.

Verificar después del despliegue: factura PDF válida, informes del profesor limitados a sus cursos, crédito parcial y total reflejado en el cobro, y contadores del alumno superiores a diez movimientos. La prueba `tests/regression_creditos.php` requiere una copia de base llamada `alumnos_test_*` o `alumnos_auditoria_*`; revierte sus filas al terminar y rechaza la base habitual.

Los pasos y referencias a migraciones que siguen corresponden al despliegue anterior de suscripciones.

## ⚠️ Antes que nada: NO correr `doctrine:migrations:migrate` a ciegas

La base y el repo **divergieron** en algún momento: hay migraciones registradas en la
base cuyo archivo ya no está en el repo, y archivos cuya migración corrió con otro
número de versión. Un `migrate` directo falla:

```
[error] Migration Version20260303151056 failed during Execution.
        Error: "Table 'curso_horario' already exists"
```

Por eso el paso 3 de abajo es obligatorio. Se verificó en el entorno local: el
`migrate` directo aborta, y el procedimiento con sincronización previa funciona.

---

## Procedimiento

### 1. Backup de la base

```bash
mysqldump -u USUARIO -p --single-transaction --routines --triggers BASE > backup_$(date +%F_%H%M).sql
```

Barato y es la red de seguridad de todo lo que sigue.

### 2. Traer el código

```bash
git pull origin version-3-saas
```

No hace falta `composer install`: no cambiaron las dependencias.

### 3. Diagnóstico de migraciones (read-only, no modifica nada)

```bash
sh bin/diagnostico-migraciones.sh "php bin/console"
```

Si el PHP del server no es `php`, pasarle el binario correcto, por ejemplo
`sh bin/diagnostico-migraciones.sh "php81 bin/console"`.

Para cada migración pendiente revisa si sus cambios **ya están en el schema** y
clasifica:

| Salida | Significa | Qué hacer |
|---|---|---|
| `YA APLICADA en el schema (N/N)` | corrió con otro número de versión | **marcarla**, no ejecutarla |
| `NO aplicada (0/N)` | falta de verdad | **ejecutarla** |
| `PARCIAL (X/N)` | mitad y mitad | revisar a mano, no automatizar |

El script imprime los comandos exactos para cada caso. Revisalos antes de correrlos.

### 4. Sincronizar y migrar

Primero marcar las que ya están aplicadas (esto **no ejecuta SQL**, solo registra):

```bash
php bin/console doctrine:migrations:version 'DoctrineMigrations\VersionXXXXXXXXXXXXXX' --add --no-interaction
```

Y después ejecutar lo que falte de verdad:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

La única que debería **ejecutarse** en este despliegue es
`Version20261006120000` (las solicitudes de alta). Crea la tabla
`solicitud_instituto`, agrega `instituto.fecha_alta` y la completa con la fecha
de la primera factura de cada instituto. No borra ni modifica ninguna otra fila.

Este despliegue **también lleva `composer install`**: se agregó monolog.

### 5. Verificar el schema

```bash
php bin/console doctrine:schema:update --dump-sql --complete
```

Tiene que decir **"Nothing to update"**. Si lista sentencias, algo quedó
desincronizado: no seguir, revisar.

### 6. Limpiar cache

```bash
php bin/console cache:clear
```

Correrlo como el mismo usuario que sirve el sitio, o los archivos quedan con dueño
equivocado y el sitio tira 500. Si pasa:

```bash
chown -R www-data:www-data var/
```

### 7. Chequeo post-deploy

```bash
php bin/console lint:container
php bin/console app:avisar-suscripciones --dry-run --todos
```

El segundo no manda ningún mail: lista los institutos que estarían en algún
escalón de la suscripción. Si tira excepción, el estado no se calcula bien.

Y a mano, con un usuario admin de instituto:

- Dashboard, listado de alumnos, cursos, profesores, pagos, configuración → 200.
- `/instituto/facturas/` → la pantalla de suscripción, con el estado y las
  pestañas de pago.
- Con el super admin: `/admin/billing-config/` y editar un instituto, para ver
  el bloque "Suscripción" de la ficha.

---

## Qué cambia para los usuarios

**Para los institutos que están al día, nada.** El banner y el bloqueo solo
aparecen cuando hay una factura vencida, y para eso la factura necesita fecha de
vencimiento.

Las facturas que ya existen **no tienen** fecha de vencimiento (la columna nace
vacía), así que después del deploy **ninguna limita a nadie**: se siguen viendo
como pendientes y nada más. Recién las que se emitan de acá en adelante llevan
vencimiento y entran en la escalera.

Lo que sí cambia al entrar:

- "Mis Facturas" pasa a llamarse **Suscripción**, con el estado, cómo pagar
  (transferencia, efectivo, Mercado Pago) y cómo se calcula el monto.
- Para el super admin, "Gestión de Facturas" pasa a ser **Cobranzas**, y la
  configuración de facturación es una sola pantalla de ajustes en lugar de un
  CRUD que dejaba crear filas duplicadas.
- Cada instituto puede tener su propio precio por alumn@, un mínimo mensual y
  la marca de exento, desde su ficha.

Antes de emitir la primera factura con vencimiento conviene:

1. Cargar los **datos de transferencia** en `/admin/billing-config/`. Si quedan
   vacíos, al instituto se le dice que nos escriba para pedirlos.
2. Revisar los cuatro plazos: vencimiento, aviso previo, gracia y bloqueo.
3. Marcar como **exentos** los institutos que no se facturan, y cancelar las
   facturas viejas de institutos de prueba: si no, el total "a cobrar" de
   Cobranzas no significa nada.
4. Programar el aviso diario en el cron:

   ```
   0 9 * * * cd /www/wwwroot/innovateglobal.es && docker compose exec -T app php bin/console app:avisar-suscripciones
   ```

   Manda un mail el día que el instituto cambia de escalón, no todos los días.

## Pendiente que este despliegue NO resuelve

Los saldos a favor que el bug del recargo generó **antes** de este fix siguen
cargados como crédito disponible. El fix evita nuevos, pero no revierte los viejos.
Para revisarlos:

```sql
SELECT sf.id, a.apellido, a.nombre, c.nombre AS curso, sf.fecha,
       sf.monto, sf.monto_disponible, p.mes, p.ano, p.monto AS pago
FROM saldo_favor sf
JOIN alumno a ON a.id = sf.alumno_id
LEFT JOIN curso c ON c.id = sf.curso_id
LEFT JOIN alumnos_pagos p ON p.id = sf.pago_origen_id
WHERE sf.tipo = 'sobrepago' AND sf.monto_disponible > 0
ORDER BY sf.fecha DESC;
```

Los sospechosos son los que coinciden con el 5% o 10% del precio del curso.

## Rollback

```bash
git checkout <commit-anterior>
php bin/console cache:clear
```

Las columnas nuevas quedan pero no molestan: sin el código, nada las consulta. Si
hace falta volver el schema, `doctrine:migrations:migrate prev` revierte solo la de
la suscripción (es aditiva, así que su `down()` es seguro). El baseline
`Version20250101000000` tiene el `down()` vacío a propósito: revertirlo borraría las
tablas centrales con todos los datos.


## Prueba interna del asistente de ayuda con Gemini

Aplicar `DoctrineMigrations\Version20261109170000` antes de publicar el código. Agrega
`instituto.plan` (básico para todas las instituciones existentes), `ayuda_ia_prueba`
(apagado por defecto) y contadores persistentes en `ayuda_ia_cuota`. No cambia precios,
exenciones, facturas ni otras condiciones de cobro.

Guardar la configuración en `.env.local` (ignorado por Git), legible por Apache:

```dotenv
GEMINI_API_KEY=clave_creada_en_google_ai_studio
GEMINI_MODEL=gemini-3.5-flash-lite
AYUDA_IA_ENABLED=1
AYUDA_IA_SOLO_PRUEBAS=1
AYUDA_IA_INSTITUTO_DIA=100
AYUDA_IA_GLOBAL_DIA=200
AYUDA_IA_GLOBAL_MINUTO=6
```

La API key debe pertenecer a un proyecto sin facturación habilitada para esta prueba
gratuita. Las cuotas reales dependen del proyecto y del modelo en Google AI Studio;
los límites locales no garantizan que Google acepte todas las solicitudes. Verificar
la disponibilidad del modelo en ese proyecto; se puede cambiar `GEMINI_MODEL` sin
modificar código. Referencias: https://ai.google.dev/gemini-api/docs/pricing y
https://ai.google.dev/gemini-api/docs/rate-limits .

En Administración → Institutos → Editar, el **superadministrador** elige el plan y
puede marcar «Habilitar prueba interna de IA». Mientras `AYUDA_IA_SOLO_PRUEBAS=1`,
solo esa marca habilita el chat, incluso en premium: no se abre la prueba gratuita
a clientes por accidente. En el entorno local se habilitó únicamente la institución
de demostración asociada a `admin@demo.test`. Para desactivar todo el chat usar
`AYUDA_IA_ENABLED=0`; el manual y la búsqueda continúan funcionando.

Después de validar calidad y contratar un servicio apropiado para clientes,
`AYUDA_IA_SOLO_PRUEBAS=0` permite usar IA a premium o a instituciones marcadas para
prueba. El plan es una habilitación funcional, no un cambio automático de tarifa.

El chat solicita aceptación antes de enviar la pregunta y explica el tratamiento
del nivel gratuito de Google. Solo se envían la pregunta (hasta 300 caracteres) y
tres fragmentos del manual visible (hasta 3.000 caracteres cada uno). No se envían
identificadores de cuentas/instituciones, entidades, el resto del manual ni el
historial. Lo que el usuario escriba sí se envía: no ingresar datos personales.
Cada pregunta es independiente; la conversación solo vive en la página actual.

Los contadores se reservan atómicamente antes de cada llamada; incluyen llamadas
fallidas y negativas del modelo, y sobreviven a limpiar la caché o recrear el app.
Las ventanas usan UTC (día desde las 00:00 UTC; minuto calendario). Se comparte la
cuota de institución entre todos sus usuarios y roles. La cuota global cubre esta
instalación: si otra instalación comparte la misma API key, ambas consumen además
la cuota de Google. Para mantenimiento, pueden borrarse filas cuyo `vence` sea
anterior a `UNIX_TIMESTAMP()`; no hay preguntas ni respuestas almacenadas allí.

Un error, timeout, cuota agotada o respuesta inválida ofrece artículos del manual.
Los enlaces proceden exclusivamente de la fuente local. Se valida la estructura,
la finalización y los IDs citados; eso no demuestra por sí solo la exactitud de
la respuesta. La restricción temática combina búsqueda y reglas del modelo:
requiere evaluación con preguntas reales antes de habilitar clientes.


Ajuste de la prueba del 10/11/2026: ambos sitios usan 100 consultas por institución
al día, 200 por sitio al día y 6 por sitio por minuto. Al compartir proyecto Gemini,
los máximos de ambas instalaciones suman 400 por día y 12 por minuto; otros usos del
proyecto (incluido localhost) también consumen la cuota del proveedor. Estos son
límites internos configurables, no la cuota que informa Google. Un bloqueo por
frecuencia ahora indica segundos de espera; uno diario indica alcance y renovación.
No hace falta borrar contadores al aumentar los límites: los usos previos se conservan.

### Páginas públicas por institución (2026-11-10)

Aplicar únicamente la migración aditiva `DoctrineMigrations\Version20261110183000` con
`php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261110183000' --up --no-interaction`.
Después, `php bin/console app:paginas-publicas:preparar` reserva las direcciones para las instituciones
activas, sin publicar ninguna y sin cambiar las que ya existen. Es idempotente. Tomar respaldo antes
como en los demás despliegues con cambios de esquema; no ejecutar todas las migraciones históricas.

Cada administrador configura su página en **Configuración → Página pública**: presentación,
color principal, color de acento, precios visibles y publicación. La vista previa usa los cambios
guardados y está protegida por sesión. Nombre, logo, dirección, teléfono y email salen de Información
general. La dirección `APP_URL/nombre-instituto` se conserva aunque cambie el nombre; nombres repetidos
o reservados reciben un sufijo. Verificar que `APP_URL` sea el dominio HTTPS de cada instalación.

El catálogo público contiene exclusivamente cursos de esa institución habilitados, abiertos y sin
fecha de finalización vencida, según su zona horaria. No publica alumnos, profesores, pagos ni usuarios.
Desactivar precios elimina los importes de la respuesta, no solo de su apariencia. Una institución dada
de baja o una página en borrador devuelve 404. El acceso público usa un firewall que coincide únicamente
con la ruta de la landing; las rutas privadas conservan su autenticación. Las páginas se entregan sin
caché compartida para reflejar inmediatamente los cambios de visibilidad.

Verificación local: `php tests/regression_landing_publica.php` (MySQL, tablas temporales) y
`php tests/regression_landing_config.php` (render, formulario y rechazo de POST sin CSRF), además de
`php bin/console lint:container` y `php bin/console lint:twig templates/instituto_pagina_publica`.
