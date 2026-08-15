<?php
/**
 * Business OS Desktop — Vendor Autoloader
 * Loads firebase/php-jwt (bundled) and PHPMailer (bundled).
 */

// firebase/php-jwt
$jwt_dir = __DIR__ . '/firebase/php-jwt/src/';
$jwt_files = ['JWT','Key','JWK','JWTExceptionWithPayloadInterface',
              'ExpiredException','BeforeValidException','SignatureInvalidException'];
foreach ($jwt_files as $f) {
    $path = $jwt_dir . $f . '.php';
    if (file_exists($path)) require_once $path;
}

// PHPMailer
spl_autoload_register(function(string $class): void {
    static $map = null;
    if ($map === null) {
        $d = __DIR__ . '/phpmailer/';
        $map = [
            'PHPMailer\\PHPMailer\\PHPMailer' => $d . 'PHPMailer.php',
            'PHPMailer\\PHPMailer\\SMTP'      => $d . 'SMTP.php',
            'PHPMailer\\PHPMailer\\Exception' => $d . 'Exception.php',
        ];
    }
    if (isset($map[$class])) require_once $map[$class];
});
