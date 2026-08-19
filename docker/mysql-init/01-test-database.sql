-- Database dedicato ai test di integrazione.
-- La suite PHPUnit lo ricostruisce da zero a ogni esecuzione e si rifiuta di
-- partire se il nome non contiene "test": e la salvaguardia che impedisce di
-- azzerare per sbaglio i dati di sviluppo.
CREATE DATABASE IF NOT EXISTS baraonda_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON baraonda_test.* TO 'baraonda'@'%';
FLUSH PRIVILEGES;
