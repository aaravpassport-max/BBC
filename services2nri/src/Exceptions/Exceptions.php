<?php
namespace S2NRI\Exceptions;

defined( 'ABSPATH' ) || exit;

class ValidationException extends \Exception {
    private array $errors;
    public function __construct( string $message, array $errors = [] ) {
        parent::__construct( $message );
        $this->errors = $errors;
    }
    public function getErrors(): array { return $this->errors; }
}

class NotFoundException extends \Exception {}
class ForbiddenException extends \Exception {}
class AuthException extends \Exception {}
