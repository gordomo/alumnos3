#!/bin/sh
# Diagnostico READ-ONLY del estado de migraciones. No modifica NADA.
#
#   sh diagnostico_migraciones.sh "php bin/console"
#   sh diagnostico_migraciones.sh "docker compose exec -T app php bin/console"
#
# Para cada migracion pendiente revisa si sus cambios YA estan en el schema.
# Si ya estan -> hay que MARCARLA (--add), no ejecutarla.
# Si no estan -> hay que EJECUTARLA.
CONSOLE="${1:-php bin/console}"
DIR_MIG="${2:-migrations}"

$CONSOLE doctrine:migrations:list 2>/dev/null > /tmp/mig_raw.txt
grep -q "Version[0-9]" /tmp/mig_raw.txt || { echo "ERROR: no pude leer el listado."; exit 1; }

PENDIENTES=$(grep "not migrated" /tmp/mig_raw.txt | grep -oE 'Version[0-9]{14}')
NPEND=$(printf '%s' "$PENDIENTES" | grep -c 'Version')
NHUERF=$(grep -c "not available" /tmp/mig_raw.txt)

echo "== Estado =="
echo "   huerfanas (registradas sin archivo): $NHUERF  <- inofensivas, solo dan un warning"
echo "   PENDIENTES (archivo sin correr)    : $NPEND"
echo
[ "$NPEND" -eq 0 ] && { echo "Nada pendiente. Correr migrate es seguro."; exit 0; }

existe_tabla() {
  $CONSOLE dbal:run-sql "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$1'" 2>/dev/null | grep -qE '^ *1 *$'
}
existe_columna() {
  $CONSOLE dbal:run-sql "SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$1' AND column_name='$2'" 2>/dev/null | grep -qE '^ *1 *$'
}

MARCAR=""
EJECUTAR=""
for v in $PENDIENTES; do
  [ -z "$v" ] && continue
  F="$DIR_MIG/$v.php"
  [ -f "$F" ] || { echo "   $v: no encuentro $F, revisar a mano"; continue; }

  TOTAL=0; YA=0
  # tablas que crea
  for t in $(grep -oE 'CREATE TABLE (IF NOT EXISTS )?[a-z_]+' "$F" | awk '{print $NF}' | sort -u); do
    TOTAL=$((TOTAL+1)); existe_tabla "$t" && YA=$((YA+1))
  done
  # columnas que agrega (una por ALTER ... ADD col)
  for pair in $(grep -oE 'ALTER TABLE [a-z_]+ ADD [a-z_]+' "$F" | awk '{print $3":"$5}' | sort -u); do
    TOTAL=$((TOTAL+1)); existe_columna "${pair%%:*}" "${pair##*:}" && YA=$((YA+1))
  done

  if [ "$TOTAL" -eq 0 ]; then
    echo "   $v : no pude inferir cambios -> REVISAR A MANO"
  elif [ "$YA" -eq "$TOTAL" ]; then
    echo "   $v : YA APLICADA en el schema ($YA/$TOTAL) -> MARCAR"
    MARCAR="$MARCAR $v"
  elif [ "$YA" -eq 0 ]; then
    echo "   $v : NO aplicada (0/$TOTAL) -> EJECUTAR"
    EJECUTAR="$EJECUTAR $v"
  else
    echo "   $v : PARCIAL ($YA/$TOTAL) -> REVISAR A MANO, no automatizar"
  fi
done

echo
if [ -n "$MARCAR" ]; then
  echo "== 1) Marcar como aplicadas (no ejecutan SQL) =="
  for v in $MARCAR; do
    echo "   $CONSOLE doctrine:migrations:version 'DoctrineMigrations\\\\$v' --add --no-interaction"
  done
  echo
fi
if [ -n "$EJECUTAR" ]; then
  echo "== 2) Y despues ejecutar las que faltan de verdad:$EJECUTAR =="
  echo "   $CONSOLE doctrine:migrations:migrate --no-interaction"
  echo
fi
echo "== 3) Verificar que el schema quede igual a las entidades =="
echo "   $CONSOLE doctrine:schema:update --dump-sql --complete   # debe decir 'Nothing to update'"
