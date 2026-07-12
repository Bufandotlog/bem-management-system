<?php
header('Content-Type: text/plain');
echo "Testing Environment Variables:\n\n";

echo "1. \$_ENV array:\n";
print_r($_ENV);

echo "\n2. \$_SERVER array (filtered DB_):\n";
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'DB_') === 0 || $k === 'APP_ENV') {
        echo "$k = $v\n";
    }
}

echo "\n3. getenv() calls:\n";
echo "DB_HOST = " . var_export(getenv('DB_HOST'), true) . "\n";
echo "APP_ENV = " . var_export(getenv('APP_ENV'), true) . "\n";

echo "\n4. phpinfo() excerpt for environment:\n";
ob_start();
phpinfo(INFO_ENVIRONMENT | INFO_VARIABLES);
$info = ob_get_clean();
echo substr($info, 0, 1000) . "...\n";
