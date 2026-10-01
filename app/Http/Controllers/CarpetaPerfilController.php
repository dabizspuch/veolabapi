<?php

namespace App\Http\Controllers;

/**
 * Perfiles de usuario con los que se comparte una carpeta de la gestión
 * documental (DOCDYP), para las carpetas en modo P. Veolab los graba desde
 * las propiedades de la carpeta. La API no aplica privilegios.
 */
class CarpetaPerfilController extends RelationController
{
    protected string $table = 'DOCDYP';

    protected array $keys = [
        'carpeta_delegacion' => 'DIR3DEL',
        'carpeta_codigo'     => 'DIR3COD',
        'perfil_delegacion'  => 'PER3DEL',
        'perfil_codigo'      => 'PER3COD',
    ];

    protected array $mapping = [
        'carpeta_delegacion' => 'DIR3DEL',
        'carpeta_codigo'     => 'DIR3COD',
        'perfil_delegacion'  => 'PER3DEL',
        'perfil_codigo'      => 'PER3COD',
    ];

    protected array $entities = [
        'carpeta' => ['DOCDIR', 'DIR1COD', 'int', 'La carpeta no existe'],
        'perfil'  => ['ACCPER', 'PER1COD', 'int', 'El perfil no existe'],
    ];

    protected string $auditOwner = 'carpeta';
    protected string $auditField = 'DOCDYP';
}
