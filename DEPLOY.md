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
`Version20260901160000` (la suscripción de los institutos). Es aditiva y sin
riesgo: agrega columnas nullable o con `DEFAULT` a `billing_config`,
`billing_invoice` e `instituto`. No toca ni una fila existente.

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
