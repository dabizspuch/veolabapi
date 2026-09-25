<?php

namespace App\Exceptions;

/**
 * Excepción para reglas de negocio (relación inexistente, código duplicado, etc.).
 * Se traduce a una respuesta HTTP 422, no a un 500.
 */
class BusinessRuleException extends \Exception
{
}
