# Team Builder Clubes

El mismo sistema, servido como un segundo producto para clubes deportivos y sociales. **No es un
fork**: es el mismo repositorio desplegado dos veces. Un arreglo se hace una vez y se despliega en
los dos lugares.

## Qué cambia entre un despliegue y el otro

Una variable: `APP_VERTICAL`. De ahí salen tres cosas.

| | `instituto` | `club` |
|---|---|---|
| Paleta | azul `#2f7ef0` | naranja `#ea580c`, acento verde |
| Landing | "Gestioná tu instituto…" | "Gestioná tu club…" |
| Vocabulario | cursos, alumn@s | disciplinas, soci@s |

La paleta viaja como `data-vertical` en el `<html>` y todo color de marca sale de las variables de
`public/assets/css/tema.css`. Si una plantilla vuelve a escribir un `#2f7ef0` a mano, ese pedazo se
queda azul en la versión de clubes.

## Montar el sitio de clubes

Desde cero, en el servidor:

```bash
# 1. El código, en su propia carpeta
cd /www/wwwroot
git clone git@github.com:gordomo/alumnos3.git clubes.teambuilder.com.ar
cd clubes.teambuilder.com.ar
git checkout version-3-saas

# 2. El .env propio (ver más abajo qué tiene que decir)
cp .env .env.bak 2>/dev/null; nano .env

# 3. Levantar
docker compose up -d --build

# 4. Crear el esquema en la base nueva
docker compose exec -T app composer install --no-dev --optimize-autoloader --no-interaction
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T app php bin/console cache:clear
docker compose exec -T app sh -lc 'chown -R www-data:www-data var/'
```

Lo que tiene que decir su `.env`, distinto del de institutos:

```
APP_VERTICAL=club
APP_URL=https://clubes.teambuilder.com.ar
PUERTO_APP=8090
PUERTO_DB=3307
PUERTO_PHPMYADMIN=8091
MYSQL_DATABASE=clubes
DATABASE_URL="mysql://dreams_usr:LA_MISMA_CLAVE@db:3306/clubes?serverVersion=5.7"
TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR
```

El resto (`APP_SECRET`, `MAILER_DSN`, `APP_TIMEZONE`) se copia del otro.

**Los puertos son del host, no del contenedor.** Adentro de su red cada stack sigue hablando por
el 80 y el 3306; lo que no se puede repetir es el puerto publicado. Por eso 8090 y 3307.

**El nombre del proyecto de Compose sale del nombre de la carpeta**, así que los contenedores, la
red y el volumen de datos de cada despliegue son distintos y no se pisan. Es importante no renombrar
la carpeta.

Después queda armar en aaPanel el sitio `clubes.teambuilder.com.ar` apuntando al puerto 8090,
emitir el certificado y activar "Forzar HTTPS".

## Desplegar un cambio en los dos

```bash
cd /www/wwwroot/innovateglobal.es           && git pull origin version-3-saas && docker compose exec -T app php bin/console cache:clear && docker compose exec -T app sh -lc 'chown -R www-data:www-data var/'
cd /www/wwwroot/clubes.teambuilder.com.ar   && git pull origin version-3-saas && docker compose exec -T app php bin/console cache:clear && docker compose exec -T app sh -lc 'chown -R www-data:www-data var/'
```

Si el cambio trae migraciones, van en los dos. Si trae dependencias nuevas, el `composer install`
también.

## Lo que falta

- **El vocabulario**: hoy el sistema por dentro sigue diciendo "cursos" y "alumn@s". Son unas 1.900
  apariciones en 119 plantillas y hay que ir módulo por módulo, porque cambia el género y el plural
  ("el curso" → "la disciplina").
- **Las capturas de la landing**: muestran datos de instituto (deudas por curso, Guitarra Inicial).
  Hay que sacarlas de un club de verdad.
- **Los mails**: siguen con el azul escrito a mano, porque los clientes de correo no entienden las
  variables CSS. El color tiene que viajar como valor literal desde Twig.
- **La cuota social**: hoy toda deuda nace de inscribir a alguien a un curso. Un club cobra además
  una cuota por ser socio, independiente de las disciplinas. Es desarrollo nuevo.
