#!/bin/sh
# Database dedicato ai test di integrazione.
#
# La suite PHPUnit lo ricostruisce da zero a ogni esecuzione e si rifiuta di
# partire se il nome non contiene "test": e la salvaguardia che impedisce di
# azzerare per sbaglio i dati di sviluppo.
#
# E uno script e non un file .sql perche cosi il nome dell'utente arriva
# dall'ambiente del contenitore invece di essere scritto qui dentro: cambiare
# DB_USERNAME nel .env continua a funzionare, e nel repository non resta nessun
# nome utente o password.
set -e

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_test\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
