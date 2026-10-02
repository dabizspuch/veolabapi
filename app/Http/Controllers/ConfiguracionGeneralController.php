<?php

namespace App\Http\Controllers;

use App\Support\VeolabLicense;
use Illuminate\Support\Facades\DB;

/**
 * Configuración general de Veolab (ACCPAR, una fila con codigo = 1). Solo
 * lectura: se mantiene en las pantallas Configurar... de Veolab (acceso,
 * auditoría, email, documentos, copias, IGEO).
 *
 * No se exponen las contraseñas (PARCPAE del SMTP, PARCIGC de RabbitMQ): solo
 * si están puestas. Tampoco la licencia cifrada (PARCLBD, PARCCLV): en su
 * lugar, el tipo de licencia leído y si aplican las restricciones Verifactu.
 */
class ConfiguracionGeneralController extends BaseController
{
    protected string $table = 'ACCPAR';
    protected array $keys = [
        'codigo' => 'PAR1COD',
    ];
    protected ?string $delegationKey = null;

    private const LICENSES = [
        VeolabLicense::GRATUITA              => 'Gratuita',
        VeolabLicense::PROFESIONAL           => 'Profesional',
        VeolabLicense::EMPRESARIAL           => 'Empresarial',
        VeolabLicense::EMPRESARIAL_VERIFACTU => 'Empresarial Verifactu',
    ];

    protected array $mapping = [
        'codigo'                     => 'PAR1COD',
        // Versión e instalación
        'version_bd'                 => 'PARCVBD',
        'version_autorizada'         => 'PARCVAU',
        'ruta_instalador'            => 'PARCRUT',
        'version_office'             => 'PARCVAO',
        'ruta_ayuda'                 => 'PARCAYU',
        // Empresa registrada
        'empresa'                    => 'PARCEMP',
        'telefono'                   => 'PARCTEL',
        'direccion'                  => 'PARCDIR',
        'provincia'                  => 'PARCPRO',
        'poblacion'                  => 'PARCPOB',
        'codigo_postal'              => 'PARCCOP',
        'email'                      => 'PARCEMA',
        'web'                        => 'PARCWEB',
        // Acceso (ConfigurarAcceso)
        'autenticacion_normal'       => 'PARBAUN',
        'autenticacion_veolab'       => 'PARBAUV',
        'autenticacion_windows'      => 'PARBAUW',
        'forzar_contrasena'          => 'PARBFOC',
        'cambio_anual_contrasena'    => 'PARBCAM',
        'contrasenas_seguras'        => 'PARBSEG',
        // Auditoría (0 desactivada, 1 acceso, 2 registro, 3 campo)
        'nivel_auditoria'            => 'PARNAUN',
        'auditar_lecturas'           => 'PARBAUL',
        'auditoria_verifactu'        => 'PARBAUF',
        // Copias de seguridad (periodicidad D días / H horas)
        'ruta_copias'                => 'PARCRCS',
        'encriptar_copias'           => 'PARBECS',
        'copias_automaticas'         => 'PARBCSA',
        'equipo_copias'              => 'PARCEQU',
        'inicio_copias'              => 'PARTFIC',
        'periodicidad_copias'        => 'PARNPEC',
        'tipo_periodicidad_copias'   => 'PARCPEC',
        'limitar_copias'             => 'PARBMCS',
        'numero_copias'              => 'PARNNCS',
        // Gestión documental
        'documentos_version_dual'    => 'PARBDUA',
        'documentos_comprimir'       => 'PARBZIP',
        // Email (sistema M Mapi, O Outlook, C Cdo)
        'sistema_correo'             => 'PARCSIC',
        'email_remitente'            => 'PARCCDE',
        'smtp_servidor'              => 'PARCSMT',
        'smtp_puerto'                => 'PARCPUE',
        'smtp_usuario'               => 'PARCUSE',
        'smtp_autenticacion'         => 'PARBAUT',
        'smtp_ssl'                   => 'PARBSSL',
        // Rendimiento y archivo
        'tamano_pagina'              => 'PARNPAG',
        'lectura_automatica_listados' => 'PARBLEA',
        'archivo_automatico'         => 'PARBARA',
        'equipo_archivo'             => 'PARCEQA',
        'dias_archivo'               => 'PARNDIA',
        'secuencias_sql_server'      => 'PARBSEQ',
        // IGEO (RabbitMQ)
        'igeo_ip'                    => 'PARCIGI',
        'igeo_puerto'                => 'PARCIGP',
        'igeo_virtual_host'          => 'PARCIGV',
        'igeo_usuario'               => 'PARCIGU',
        'igeo_serie'                 => 'PARCIGS',
        'igeo_delegacion'            => 'PARCIGD',
        'igeo_segundos'              => 'PARNSEC',
        'delegacion_central'         => 'PARCCDC',
    ];

    protected function appendRelatedData(array $rows): array
    {
        $db = DB::connection('dynamic');
        $secrets = $db->table('ACCPAR')->whereIn('PAR1COD', array_column($rows, 'codigo'))
            ->get(['PAR1COD', 'PARCPAE', 'PARCIGC'])->keyBy('PAR1COD');
        $type = VeolabLicense::type('dynamic', $db->getDatabaseName());

        foreach ($rows as &$row) {
            $secret = $secrets[$row['codigo']] ?? null;
            $row['smtp_tiene_contrasena'] = (string) ($secret->PARCPAE ?? '') !== '' ? 'T' : 'F';
            $row['igeo_tiene_contrasena'] = (string) ($secret->PARCIGC ?? '') !== '' ? 'T' : 'F';
            $row['licencia'] = $type === null ? null : (self::LICENSES[$type] ?? null);
            $row['restricciones_verifactu'] = VeolabLicense::isVerifactu('dynamic', $db->getDatabaseName()) ? 'T' : 'F';
        }

        return $rows;
    }
}
