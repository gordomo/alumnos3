# Despliegue

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
`Version20260731110150` (las tablas de calificaciones). Es aditiva: crea
`evaluacion`, `calificacion` y `concepto_calificacion`, y agrega columnas a
`instituto_configuracion` y `alumno_curso_historico`, todas con `DEFAULT`.

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
php bin/console debug:router | grep -cE "evaluacion|calificaciones"   # esperado: 18
php bin/console debug:router | grep -c "^  [a-z_]"                        # esperado: 136
```

Y a mano, con un usuario admin de instituto:

- Dashboard, listado de alumnos, cursos, profesores, pagos, configuración → 200.
- `/instituto/evaluaciones/` → debe mostrar "el instituto todavía no usa
  calificaciones" (la feature nace apagada).
- Asistencias de alumnos y de profesores: guardar y confirmar que persiste.

---

## Qué cambia para los usuarios

**Nada, hasta que se configure.** Todos los institutos quedan con
`modo_calificacion = 'ninguno'`, así que la sección de notas está oculta para
profesores y alumnos, y el cierre de curso sigue decidiéndose solo por asistencia y
pago.

Para activarla en un instituto: **Configuración del Instituto → Configuración
Académica → Escala de Calificación**. El interruptor "las notas influyen en la
aprobación" es aparte y también nace apagado.

Sí cambia, sin necesidad de configurar nada:

- Las tarjetas Hoy/Semana/Mes/Año de pagos ahora muestran números (antes daban 0).
- Se pueden pagar hasta 12 meses adelantados.
- Los badges de curso dicen la verdad: "N cuotas vencidas" donde antes decía
  "Curso pagado completo".
- Cobrar una cuota vencida con recargo ya no genera saldo a favor espurio.
- En la pantalla de asistencias de profesores, marcar una falta ahora es un botón
  (antes era un link). Mismo lugar, mismo comportamiento.
- Se eliminaron dos pantallas duplicadas sin uso: `/admin/user` y
  `/instituto/deuda`. La gestión de usuarios sigue en `/instituto/user`.

---

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

Las tablas nuevas quedan pero no molestan: sin el código, nada las consulta. Si hace
falta volver el schema, `doctrine:migrations:migrate prev` revierte solo la de
calificaciones (es aditiva, así que su `down()` es seguro). El baseline
`Version20250101000000` tiene el `down()` vacío a propósito: revertirlo borraría las
tablas centrales con todos los datos.
