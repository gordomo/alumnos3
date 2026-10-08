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

`docker-compose.yml` **está en el .gitignore**: cada despliegue tiene el suyo y no viaja en el
repositorio. Por eso institutos publica 8021/8022/8023 y el del club hay que escribirlo a mano.
Es a propósito: los puertos y la base son de la máquina, no del código.

```bash
# 1. El código, en su propia carpeta. aaPanel ya creó la carpeta con sus archivos, así que se
#    clona al lado y se mueve adentro.
cd /www/wwwroot
git clone git@github.com:gordomo/alumnos3.git clubes-tmp
cd clubes-tmp && git checkout version-3-saas && cd ..
cd clubes.teambuilder.com.ar && mkdir -p _aapanel && mv 404.html 502.html index.html .htaccess _aapanel/
shopt -s dotglob && mv /www/wwwroot/clubes-tmp/* . && rmdir /www/wwwroot/clubes-tmp && shopt -u dotglob

# 2. El .env propio (ver más abajo qué tiene que decir)
cp /www/wwwroot/innovateglobal.es/.env .env && nano .env

# 3. El docker-compose.yml propio (ver más abajo) y levantar
nano docker-compose.yml
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

Y su `docker-compose.yml`, que es el de institutos con los puertos tomados del `.env`:

```yaml
services:
  app:
    build: .
    ports:
      - "${PUERTO_APP}:80"
    environment:
      TZ: America/Argentina/Buenos_Aires
      APP_TIMEZONE: America/Argentina/Buenos_Aires
    volumes:
      - .:/var/www/html
      - ./php-custom.ini:/usr/local/etc/php/conf.d/php-custom.ini
    networks: [symfony-network]
    depends_on: [db]

  db:
    image: mysql:5.7
    environment:
      TZ: America/Argentina/Buenos_Aires
      MYSQL_ROOT_PASSWORD: ${MYSQL_PASSWORD}
      MYSQL_DATABASE: ${MYSQL_DATABASE}
      MYSQL_USER: ${MYSQL_USER}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
    ports:
      - "${PUERTO_DB}:3306"
    networks: [symfony-network]
    volumes:
      - db_data:/var/lib/mysql

  phpmyadmin:
    image: phpmyadmin/phpmyadmin
    restart: always
    ports:
      - "${PUERTO_PHPMYADMIN}:80"
    environment:
      TZ: America/Argentina/Buenos_Aires
      PMA_HOST: db
      MYSQL_ROOT_PASSWORD: ${MYSQL_PASSWORD}
      MYSQL_DATABASE: ${MYSQL_DATABASE}
    networks: [symfony-network]
    volumes:
      - ./php-custom.ini:/usr/local/etc/php/conf.d/php-custom.ini

volumes:
  db_data:

networks:
  symfony-network:
```

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
