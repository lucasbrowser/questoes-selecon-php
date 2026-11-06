<?php
// Copie para config.php (mesma pasta que "src/" e "public/") e preencha com os dados do MySQL do cPanel.
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'seuusuario_bancoselecon');   // no cPanel o nome vem com o prefixo da conta
define('DB_USER', 'seuusuario_selecon');
define('DB_PASS', 'sua-senha');
define('DEBUG', false);                        // true = mostra detalhes de erro (use só para diagnosticar)
// define('PDFTOTEXT', '/usr/bin/pdftotext');  // só necessário se a hospedagem tiver poppler e você importar PDFs no servidor
