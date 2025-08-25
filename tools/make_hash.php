<?php
// Uso: php tools/make_hash.php "LaTuaPasswordSuperSegreta"
if ($argc < 2) { echo "Usage: php tools/make_hash.php \"password\"\n"; exit(1); }
$pw = $argv[1];
$hash = password_hash($pw, PASSWORD_BCRYPT);
echo $hash . PHP_EOL;
