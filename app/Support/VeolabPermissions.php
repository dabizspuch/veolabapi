<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Permisos de los perfiles de usuario (ACCPYF) como la pestaña de
 * funcionalidades de FichaPerfil.
 *
 * Las funcionalidades (ACCFUN) forman un árbol de dos niveles: grupos (nivel
 * 1, código de 3 letras) y funcionalidades (nivel 2, código que empieza por
 * el del grupo). Solo se muestran las de módulo genérico ('') o de un módulo
 * activo (ACCMOD.MODBACT) y licenciado, y las de grupos visibles.
 *
 * PYFNACC es una máscara de bits (Sesiones.bas): 1 = acceso, 2 = escritura,
 * 4..256 = privilegio especial 1..7 (uno solo). Lectura = 1, escritura = 3.
 * Con acceso, Veolab graba siempre un especial (el 0 se guarda como el 1).
 * El grupo se graba con 3 si alguna de sus funcionalidades tiene acceso.
 */
class VeolabPermissions
{
    public const ACCESO = 0x1;
    public const ESCRITURA = 0x2;
    public const ESPECIAL1 = 0x4;
    public const GRUPO = self::ACCESO | self::ESCRITURA;

    /**
     * Árbol visible: ['groups' => [cod => fila], 'functions' => [cod => fila]]
     * en el orden de Veolab (nivel, FUNNORD). Cada fila lleva codigo,
     * descripcion, grupo, modulo, ambito, exportacion y principal; las
     * funcionalidades además 'especiales' (opciones de privilegio especial).
     */
    public static function tree(): array
    {
        $db = DB::connection('dynamic');
        $database = $db->getDatabaseName();

        $rows = $db->table('ACCFUN')
            ->leftJoin('ACCMYF', 'ACCFUN.FUN1COD', '=', 'ACCMYF.FUN3COD')
            ->leftJoin('ACCMOD', 'ACCMYF.MOD3COD', '=', 'ACCMOD.MOD1COD')
            ->orderBy('ACCFUN.FUNNNIV')->orderBy('ACCFUN.FUNNORD')->orderBy('ACCFUN.FUN1COD')
            ->get(['ACCFUN.FUN1COD', 'ACCFUN.FUNCDES', 'ACCFUN.FUNNNIV', 'ACCFUN.FUNNEXP', 'ACCFUN.FUNCWOE',
                'ACCFUN.FUN2COD', 'ACCMOD.MOD1COD', 'ACCMOD.MODBACT']);

        $texts = self::texts($rows->pluck('FUNCDES')->filter()->unique()->all());
        $special = self::specialTexts();

        $groups = [];
        $functions = [];
        $licensed = [];
        foreach ($rows as $row) {
            $module = (string) ($row->MOD1COD ?? '');
            if ($module !== '') {
                if ($row->MODBACT !== 'T') {
                    continue;
                }
                $licensed[$module] ??= VeolabLicense::moduleLicensed('dynamic', $database, $module);
                if (! $licensed[$module]) {
                    continue;
                }
            }

            $code = (string) $row->FUN1COD;
            $item = [
                'codigo'      => $code,
                'descripcion' => $texts[(string) $row->FUNCDES] ?? $code,
                'grupo'       => substr($code, 0, 3),
                'modulo'      => $module !== '' ? $module : null,
                'ambito'      => $row->FUNCWOE,
                'exportacion' => (int) $row->FUNNEXP,
                'principal'   => (string) $row->FUN2COD !== '' ? (string) $row->FUN2COD : null,
            ];

            if ((int) $row->FUNNNIV === 1) {
                $groups[$code] ??= $item;
            } elseif (isset($groups[$item['grupo']]) && ! isset($functions[$code])) {
                $item['especiales'] = $special[$code] ?? [];
                $functions[$code] = $item;
            }
        }

        // Orden del árbol de Veolab: cada grupo seguido de sus funcionalidades.
        $ordered = [];
        foreach (array_keys($groups) as $group) {
            foreach ($functions as $code => $item) {
                if ($item['grupo'] === $group) {
                    $ordered[$code] = $item;
                }
            }
        }

        return ['groups' => $groups, 'functions' => $ordered];
    }

    /** Valores PYFNACC del perfil: funcionalidad => máscara. */
    public static function values(string $delegation, int $profile): array
    {
        return DB::connection('dynamic')->table('ACCPYF')
            ->where('DEL3COD', $delegation)->where('PER3COD', $profile)
            ->pluck('PYFNACC', 'FUN3COD')->map(fn ($v) => (int) $v)->all();
    }

    /** 'E' (escritura), 'L' (lectura) o null (sin acceso). */
    public static function access(int $value): ?string
    {
        if (($value & self::ESCRITURA) === self::ESCRITURA) {
            return 'E';
        }

        return ($value & self::ACCESO) === self::ACCESO ? 'L' : null;
    }

    /** Privilegio especial 1..7 (el primer bit, como RecorrerNodos), 0 si no hay, null sin acceso. */
    public static function special(int $value): ?int
    {
        if (self::access($value) === null) {
            return null;
        }
        for ($level = 1; $level <= 7; $level++) {
            if ($value & (self::ESPECIAL1 << ($level - 1))) {
                return $level;
            }
        }

        return 0;
    }

    /** Máscara de un acceso y especial (FichaPerfil.Grabar: el especial 0 graba el 1). */
    public static function encode(?string $access, int $special): int
    {
        $value = match ($access) {
            'E'     => self::ACCESO | self::ESCRITURA,
            'L'     => self::ACCESO,
            default => 0,
        };
        if ($value === 0) {
            return 0;
        }

        return $value | (self::ESPECIAL1 << (max($special, 1) - 1));
    }

    /** Textos de idioma (IDICAD, idioma 1 = español): clave => texto. */
    private static function texts(array $keys): array
    {
        if (! $keys) {
            return [];
        }

        return DB::connection('dynamic')->table('IDICAD')
            ->where('IDI3COD', 1)->whereIn('CAD1COD', array_values($keys))
            ->pluck('CADCDES', 'CAD1COD')->map(fn ($t) => (string) $t)->all();
    }

    /**
     * Opciones de privilegio especial por funcionalidad: ESP_<fun>0<i>, de 0
     * hasta la primera que falte (ConstruirListaEspeciales).
     */
    private static function specialTexts(): array
    {
        $texts = DB::connection('dynamic')->table('IDICAD')
            ->where('IDI3COD', 1)->where('CAD1COD', 'like', 'ESP\_%')
            ->pluck('CADCDES', 'CAD1COD')->all();

        $out = [];
        foreach ($texts as $key => $text) {
            if (! preg_match('/^ESP_(.+)0(\d)$/', $key, $m)) {
                continue;
            }
            $out[$m[1]][(int) $m[2]] = (string) $text;
        }

        foreach ($out as $function => $options) {
            $list = [];
            for ($i = 0; $i <= 9 && trim($options[$i] ?? '') !== ''; $i++) {
                $list[] = ['especial' => $i, 'descripcion' => $options[$i]];
            }
            $out[$function] = $list;
        }

        return $out;
    }
}
