<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAudit;
use App\Support\VeolabCodes;
use App\Support\VeolabManagementReview;
use App\Support\VeolabQuality;
use App\Support\VeolabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Revisión por la dirección, fase 1 (CALRDI, módulo CAL, desde Veolab 3;
 * ISO/IEC 17025:2017 8.9; funcionalidad CAL_RDI en Veolab). Sigue el modelo
 * común con el escritorio (App\Support\VeolabManagementReview).
 *
 *  - Clave delegación + serie + código; el código sale del contador ACCCLT
 *    de la serie con las reglas de ACCCFC 'CALRDI', como las auditorías.
 *  - Estado B borrador → C celebrada → P aprobada, y A anulada desde
 *    cualquiera salvo aprobada; reabrir (de C o P a B). Celebrada exige la
 *    fecha de la reunión, algún asistente (de la plantilla o externo) y el
 *    análisis de los quince temas; aprobada, quién la aprueba (pone la fecha
 *    de aprobación si falta). Aprobada o anulada: solo lectura.
 *  - Al crear: en borrador, con el periodo por defecto (desde el día
 *    siguiente al fin de la última revisión no anulada hasta hoy; sin
 *    ninguna, el año anterior), el título «Revisión por la dirección <año>»
 *    y los quince temas.
 *  - Embebidos: asistentes (CALRDA) y delegaciones (CALRDL) sustituyen la
 *    lista; temas (CALRDX) cambian solo los temas y campos indicados.
 *  - Solo el responsable de calidad (especial 1 de CAL_RDI) escribe: con
 *    usuario (usuario_* del cuerpo al crear; ?usuario_delegacion=&
 *    usuario_codigo= al modificar, recoger datos o eliminar) se comprueba;
 *    sin él, la API actúa como responsable de calidad.
 *  - Eliminar: solo en borrador y sin decisiones ni documentos.
 *  - POST /revisiones-direccion/recoger-datos y GET
 *    /revisiones-direccion/decisiones (collectData() y decisions()).
 */
class RevisionDireccionController extends BaseController
{
    protected string $table = 'CALRDI';
    protected ?string $sinceVersion = VeolabVersion::V3;

    protected array $keys = [
        'delegacion' => 'DEL3COD',
        'serie'      => 'RDI1SER',
        'codigo'     => 'RDI1COD',
    ];

    protected bool $generatesCode = true;
    protected string $codeKey = 'codigo';
    protected ?string $delegationKey = 'delegacion';
    protected ?string $seriesKey = 'serie';

    // Sin $foreignKeys: las referencias vacías se guardan como NULL, como en el resto de Calidad.
    protected array $mapping = [
        'delegacion'                => 'DEL3COD',
        'serie'                     => 'RDI1SER',
        'codigo'                    => 'RDI1COD',
        'titulo'                    => 'RDICDES',
        'fecha_inicio'              => 'RDIDINI',   // Periodo revisado
        'fecha_fin'                 => 'RDIDFIN',
        'fecha_reunion'             => 'RDIDFEC',
        'lugar'                     => 'RDICLUG',
        'estado'                    => 'RDICEST',   // B borrador, C celebrada, P aprobada, A anulada
        'aprobada_por_delegacion'   => 'EMA2DEL',   // Empleado
        'aprobada_por_codigo'       => 'EMA2COD',
        'fecha_aprobacion'          => 'RDIDAPR',
        'asistentes_externos'       => 'RDICEXT',
        'eficacia_sistema'          => 'RDICEFI',   // Salidas de 8.9.3 a-d
        'mejoras'                   => 'RDICMEJ',
        'recursos'                  => 'RDICREC',
        'cambios'                   => 'RDICCAM',
        'observaciones'             => 'RDICOBS',
        'fecha_recogida_datos'      => 'RDITDAT',
        'fecha_registro'            => 'RDITREG',
        'usuario_delegacion'        => 'USU2DEL',
        'usuario_codigo'            => 'USU2COD',
    ];

    protected array $searchFields = ['RDICDES', 'RDICLUG', 'RDICOBS'];

    private const DATES = ['fecha_inicio', 'fecha_fin', 'fecha_reunion', 'fecha_aprobacion'];

    /** Textos con su longitud (recortados; vacíos, NULL). */
    private const TEXTS = ['titulo' => 255, 'lugar' => 100, 'asistentes_externos' => null, 'eficacia_sistema' => null,
        'mejoras' => null, 'recursos' => null, 'cambios' => null, 'observaciones' => null];

    /** Solo lectura: los pone la API (se aceptan si no cambian). */
    private const READ_ONLY = ['fecha_registro' => 'RDITREG', 'fecha_recogida_datos' => 'RDITDAT'];

    /** Listas embebidas: parámetro => tabla (para la auditoría de la ficha). */
    private const LIST_TABLES = ['asistentes' => 'CALRDA', 'temas' => 'CALRDX', 'delegaciones' => 'CALRDL'];

    /** Temas: parámetro => columna. */
    private const TOPIC_FIELDS = ['datos' => 'RDXCDAT', 'analisis' => 'RDXCANA'];

    /** Cambios de estado permitidos: estado guardado => destinos. */
    private const TRANSITIONS = [
        'B' => ['C', 'A'],
        'C' => ['P', 'A', 'B'],
        'P' => ['B'],
        'A' => [],
    ];

    /** Sin las tablas (base de datos sin actualizar), 422 en vez de un error de SQL. */
    protected function prepareForDatabase()
    {
        if ($unavailable = parent::prepareForDatabase()) {
            return $unavailable;
        }
        if (! VeolabManagementReview::available()) {
            return response()->json(['message' => 'La base de datos aún no tiene la revisión por la dirección (CALRDI): hay que actualizarla'], 422);
        }

        return null;
    }

    protected function rules(): array
    {
        $rules = [
            'delegacion'                         => 'nullable|string|max:10',
            'serie'                              => 'nullable|string|max:10',
            'codigo'                             => 'nullable|integer|min:1',
            'estado'                             => 'nullable|string|in:'.implode(',', array_keys(VeolabManagementReview::STATE_NAMES)),
            'aprobada_por_delegacion'            => 'nullable|string|max:10',
            'aprobada_por_codigo'                => 'nullable|integer|min:0',
            'usuario_delegacion'                 => 'nullable|string|max:10',
            'usuario_codigo'                     => 'nullable|string|max:15',
            'fecha_registro'                     => 'nullable',   // solo lectura: se acepta si no cambia
            'fecha_recogida_datos'               => 'nullable',   // ídem
            'asistentes'                         => 'nullable|array',
            'asistentes.*.empleado_delegacion'   => 'nullable|string|max:10',
            'asistentes.*.empleado_codigo'       => 'required|integer|min:1',
            'temas'                              => 'nullable|array',
            'temas.*.tema'                       => 'required|string|size:1',
            'temas.*.datos'                      => 'nullable|string',
            'temas.*.analisis'                   => 'nullable|string',
            'delegaciones'                       => 'nullable|array',
            'delegaciones.*.delegacion'          => 'required|string|max:10',
        ];
        foreach (self::TEXTS as $param => $max) {
            $rules[$param] = 'nullable|string'.($max ? "|max:{$max}" : '');
        }
        foreach (self::DATES as $param) {
            $rules[$param] = 'nullable|date';
        }

        return $rules;
    }

    protected function validateRelationships(array $data): void
    {
        VeolabQuality::assertModule();
        if (str_contains((string) ($data['serie'] ?? ''), '#')) {
            throw new BusinessRuleException('La serie no puede llevar el carácter #');
        }
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        $db = DB::connection('dynamic');
        $isNew = ! $keys;
        $before = $isNew ? [] : (array) VeolabManagementReview::review((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo'], true);
        $current = function (string $param) use (&$data, $isNew, $before) {
            return array_key_exists($param, $data) ? $data[$param]
                : ($isNew ? null : ($before[$this->mapping[$param]] ?? null));
        };

        // Solo el responsable de calidad: al crear, quien la registra; al modificar, ?usuario_*
        $user = $isNew
            ? VeolabManagementReview::requestUser($data['usuario_delegacion'] ?? null, isset($data['usuario_codigo']) ? (string) $data['usuario_codigo'] : null)
            : VeolabManagementReview::requestUser(request()->query('usuario_delegacion'), request()->query('usuario_codigo'));
        VeolabManagementReview::assertManager($user);

        // Solo lectura: fechas de registro y de la última recogida de datos, y el usuario que la registra
        foreach (self::READ_ONLY as $param => $column) {
            if (array_key_exists($param, $data)) {
                if ((! $isNew && ! self::sameValue($data[$param], $before[$column] ?? null)) || ($isNew && ! empty($data[$param]))) {
                    throw new BusinessRuleException("El campo '{$param}' lo pone Veolab");
                }
                unset($data[$param]);
            }
        }
        if (! $isNew) {
            foreach (['usuario_delegacion' => 'USU2DEL', 'usuario_codigo' => 'USU2COD'] as $param => $column) {
                if (array_key_exists($param, $data)) {
                    if (! self::sameValue($data[$param], $before[$column] ?? null)) {
                        throw new BusinessRuleException('El usuario que registró la revisión no se cambia');
                    }
                    unset($data[$param]);
                }
            }
        } elseif ($user !== null) {
            $data['usuario_delegacion'] = $user['delegacion'];
            $data['usuario_codigo'] = $user['codigo'];
        } else {
            $data['usuario_delegacion'] = null;
            $data['usuario_codigo'] = null;
        }

        // Textos recortados; vacíos, NULL
        foreach (self::TEXTS as $param => $max) {
            if (array_key_exists($param, $data)) {
                $text = trim((string) ($data[$param] ?? ''));
                $data[$param] = $text === '' ? null : $text;
            }
        }

        // Fechas (solo el día)
        foreach (self::DATES as $param) {
            if (array_key_exists($param, $data)) {
                $data[$param] = empty($data[$param]) ? null : (new \DateTime($data[$param]))->format('Y-m-d 00:00:00');
            }
        }

        // Listas embebidas (normalizadas; se comparan con lo guardado si es de solo lectura)
        $lists = $this->normalizeLists($data, $isNew ? null : $keys);
        foreach (array_keys(self::LIST_TABLES) as $param) {
            unset($data[$param]);
        }

        // Estado: al crear, borrador; después, los cambios permitidos
        $stored = $isNew ? '' : VeolabManagementReview::state((object) $before);
        $state = (string) ($current('estado') ?? '');
        $state = $state === '' ? 'B' : $state;
        if ($isNew || array_key_exists('estado', $data)) {
            $data['estado'] = $state;
        }
        $names = VeolabManagementReview::STATE_NAMES;
        if ($isNew && $state !== 'B') {
            throw new BusinessRuleException('Una revisión nueva empieza en borrador');
        }
        if (! $isNew && $state !== $stored) {
            if (! in_array($state, self::TRANSITIONS[$stored] ?? [], true)) {
                throw new BusinessRuleException(match (true) {
                    $stored === 'A'                  => 'La revisión está anulada y solo se puede consultar',
                    $stored === 'P'                  => 'Una revisión aprobada no se anula; solo se puede reabrir (pasar a borrador)',
                    $stored === 'B' && $state === 'P' => 'Para aprobar la revisión primero hay que marcarla como celebrada',
                    default                          => 'La revisión no puede pasar de '.($names[$stored] ?? $stored).' a '.$names[$state],
                });
            }
        }

        // Reabierta (de aprobada a borrador): la fecha de aprobación se vacía para que la nueva aprobación ponga la
        // suya (quien la aprobó se conserva), como FichaRevisionDireccion.Grabar
        if (! $isNew && $stored === 'P' && $state === 'B') {
            $data['fecha_aprobacion'] = null;
        }

        // Aprobada o anulada (y sin reabrir): solo lectura salvo los documentos
        if (! $isNew && $state === $stored && in_array($stored, VeolabManagementReview::READ_ONLY_STATES, true)) {
            $this->assertUnchanged($data, $lists, $before, $keys, mb_strtolower($names[$stored]));
        }

        // Periodo revisado: al crear, por defecto; siempre con inicio y fin, en orden
        if ($isNew) {
            [$defaultFrom, $defaultTo] = VeolabManagementReview::defaultPeriod();
            $data['fecha_inicio'] = $data['fecha_inicio'] ?? $defaultFrom;
            $data['fecha_fin'] = $data['fecha_fin'] ?? $defaultTo;
        }
        if ($isNew || array_key_exists('fecha_inicio', $data) || array_key_exists('fecha_fin', $data)) {
            if (empty($current('fecha_inicio')) || empty($current('fecha_fin'))) {
                throw new BusinessRuleException('Indique el periodo revisado (fecha de inicio y de fin)');
            }
            if (substr((string) $current('fecha_inicio'), 0, 10) > substr((string) $current('fecha_fin'), 0, 10)) {
                throw new BusinessRuleException('El inicio del periodo revisado es posterior a su fin');
            }
        }
        if ($isNew && empty($data['titulo'])) {
            $data['titulo'] = VeolabManagementReview::defaultTitle((string) $data['fecha_fin']);
        }

        // Quién la aprueba: un empleado; si cambia, de alta
        if (array_key_exists('aprobada_por_codigo', $data) || array_key_exists('aprobada_por_delegacion', $data)) {
            $empDel = (string) $current('aprobada_por_delegacion');
            $empCod = (int) $current('aprobada_por_codigo');
            if ($empCod === 0) {
                $data['aprobada_por_delegacion'] = null;
                $data['aprobada_por_codigo'] = null;
            } else {
                $employee = $db->table('GRHEMP')->where('DEL3COD', $empDel)->where('EMP1COD', $empCod)->first(['EMPBBAJ']);
                if (! $employee) {
                    throw new BusinessRuleException('Quien aprueba la revisión (empleado) no existe');
                }
                $changed = $isNew || (string) ($before['EMA2DEL'] ?? '') !== $empDel || (int) ($before['EMA2COD'] ?? 0) !== $empCod;
                if ($changed && $employee->EMPBBAJ === 'T') {
                    throw new BusinessRuleException('Quien aprueba la revisión (empleado) está de baja');
                }
                $data['aprobada_por_delegacion'] = $empDel;
                $data['aprobada_por_codigo'] = $empCod;
            }
        }

        // Requisitos de celebrada (también para aprobarla) y de aprobada
        if (in_array($state, ['C', 'P'], true)) {
            $touched = $state !== $stored || array_key_exists('fecha_reunion', $data) || array_key_exists('asistentes_externos', $data)
                || array_key_exists('asistentes', $lists) || array_key_exists('temas', $lists);
            if ($touched) {
                $this->assertHeld($current, $lists, $keys);
            }
            if ($state === 'P') {
                if ((int) $current('aprobada_por_codigo') === 0) {
                    throw new BusinessRuleException('Para aprobar la revisión indique quién la aprueba');
                }
                if (empty($current('fecha_aprobacion'))) {
                    $data['fecha_aprobacion'] = (string) $db->selectOne('SELECT CURDATE() AS d')->d.' 00:00:00';
                }
            }
        }

        $data['_listas'] = $lists;
        if ($isNew) {
            $data['fecha_registro'] = (string) $db->selectOne('SELECT NOW() AS n')->n;
        }
        $data['_nueva'] = $isNew;

        return $data;
    }

    /**
     * Celebrada: fecha de la reunión, algún asistente (de la plantilla o
     * externo) y los quince temas con análisis (vale «Sin cambios»).
     */
    private function assertHeld(callable $current, array $lists, array $keys): void
    {
        if (empty($current('fecha_reunion'))) {
            throw new BusinessRuleException('Para marcar la revisión como celebrada indique la fecha de la reunión');
        }
        $own = fn (string $table) => DB::connection('dynamic')->table($table)->where('DEL3COD', (string) $keys['delegacion'])
            ->where('RDI3SER', (string) $keys['serie'])->where('RDI3COD', (int) $keys['codigo']);

        $attendees = array_key_exists('asistentes', $lists) ? count($lists['asistentes']) : $own('CALRDA')->count();
        if ($attendees === 0 && trim((string) $current('asistentes_externos')) === '') {
            throw new BusinessRuleException('Para marcar la revisión como celebrada indique los asistentes (de la plantilla o externos)');
        }

        $analysis = $own('CALRDX')->pluck('RDXCANA', 'RDXCTEM')->map(fn ($t) => trim((string) $t))->all();
        foreach ($lists['temas'] ?? [] as $letter => $fields) {
            if (array_key_exists('RDXCANA', $fields)) {
                $analysis[$letter] = (string) $fields['RDXCANA'];
            }
        }
        $missing = array_filter(str_split(VeolabManagementReview::TOPIC_LETTERS), fn ($l) => ($analysis[$l] ?? '') === '');
        if ($missing) {
            throw new BusinessRuleException('Para marcar la revisión como celebrada todos los temas necesitan su análisis (vale «Sin cambios»); '
                .'faltan: '.implode(', ', $missing));
        }
    }

    /** Aprobada o anulada: lo recibido debe coincidir con lo guardado. */
    private function assertUnchanged(array $data, array $lists, array $before, array $keys, string $stateName): void
    {
        $message = "La revisión está {$stateName} y solo se puede consultar (salvo sus documentos); para cambiarla hay que reabrirla";
        foreach ($data as $param => $value) {
            if (isset($this->keys[$param]) || $param === 'estado' || ! isset($this->mapping[$param])) {
                continue;
            }
            if (! self::sameValue($value, $before[$this->mapping[$param]] ?? null)) {
                throw new BusinessRuleException($message);
            }
        }
        if (! $lists) {
            return;
        }
        $own = fn (string $table) => DB::connection('dynamic')->table($table)->where('DEL3COD', (string) $keys['delegacion'])
            ->where('RDI3SER', (string) $keys['serie'])->where('RDI3COD', (int) $keys['codigo']);
        if (array_key_exists('asistentes', $lists)) {
            $stored = $own('CALRDA')->get(['EMP3DEL', 'EMP3COD'])->map(fn ($e) => $e->EMP3DEL."\x1B".(int) $e->EMP3COD)->sort()->values()->all();
            $given = array_map(fn ($e) => $e[0]."\x1B".$e[1], $lists['asistentes']);
            sort($given);
            if ($stored !== $given) {
                throw new BusinessRuleException($message);
            }
        }
        if (array_key_exists('delegaciones', $lists)) {
            $stored = $own('CALRDL')->pluck('DEL2COD')->map(fn ($d) => (string) $d)->sort()->values()->all();
            $given = $lists['delegaciones'];
            sort($given);
            if ($stored !== $given) {
                throw new BusinessRuleException($message);
            }
        }
        if (array_key_exists('temas', $lists)) {
            $stored = $own('CALRDX')->get()->keyBy('RDXCTEM');
            foreach ($lists['temas'] as $letter => $fields) {
                foreach ($fields as $column => $value) {
                    if (! self::sameValue($value, $stored[$letter]->{$column} ?? null)) {
                        throw new BusinessRuleException($message);
                    }
                }
            }
        }
    }

    /**
     * Comprueba las listas recibidas y las devuelve normalizadas (solo las
     * presentes): asistentes existentes (los que se añaden, de alta) y sin
     * repetir; delegaciones existentes y sin repetir; temas de la lista, sin
     * repetir, con sus textos recortados (vacíos, NULL) y solo los campos
     * indicados: ['a' => ['RDXCDAT' => ..., 'RDXCANA' => ...], ...].
     */
    private function normalizeLists(array $data, ?array $keys): array
    {
        $db = DB::connection('dynamic');
        $lists = [];

        if (array_key_exists('asistentes', $data)) {
            $previous = $keys === null ? [] : $db->table('CALRDA')->where('DEL3COD', (string) $keys['delegacion'])
                ->where('RDI3SER', (string) $keys['serie'])->where('RDI3COD', (int) $keys['codigo'])
                ->get(['EMP3DEL', 'EMP3COD'])->map(fn ($e) => [(string) $e->EMP3DEL, (int) $e->EMP3COD])->all();
            $list = [];
            foreach ($data['asistentes'] ?? [] as $item) {
                $emp = [(string) ($item['empleado_delegacion'] ?? ''), (int) $item['empleado_codigo']];
                $employee = $db->table('GRHEMP')->where('DEL3COD', $emp[0])->where('EMP1COD', $emp[1])->first(['EMPBBAJ']);
                if (! $employee) {
                    throw new BusinessRuleException("El asistente (empleado {$emp[1]}) no existe");
                }
                if (! in_array($emp, $previous, true) && $employee->EMPBBAJ === 'T') {
                    throw new BusinessRuleException("El asistente (empleado {$emp[1]}) está de baja");
                }
                if (in_array($emp, $list, true)) {
                    throw new BusinessRuleException("El asistente (empleado {$emp[1]}) está repetido");
                }
                $list[] = $emp;
            }
            $lists['asistentes'] = $list;
        }

        if (array_key_exists('delegaciones', $data)) {
            $list = [];
            foreach ($data['delegaciones'] ?? [] as $item) {
                $del = trim((string) $item['delegacion']);
                if (! $db->table('ACCDEL')->where('DEL1COD', $del)->exists()) {
                    throw new BusinessRuleException("La delegación {$del} no existe");
                }
                if (in_array($del, $list, true)) {
                    throw new BusinessRuleException("La delegación {$del} está repetida");
                }
                $list[] = $del;
            }
            $lists['delegaciones'] = $list;
        }

        if (array_key_exists('temas', $data)) {
            $list = [];
            foreach ($data['temas'] ?? [] as $item) {
                $letter = strtolower(trim((string) $item['tema']));
                if (! VeolabManagementReview::isTopic($letter)) {
                    throw new BusinessRuleException("El tema '{$letter}' no es de la norma; use una letra de la a a la o (ISO/IEC 17025 8.9.2)");
                }
                if (isset($list[$letter])) {
                    throw new BusinessRuleException("El tema {$letter} está repetido");
                }
                $fields = [];
                foreach (self::TOPIC_FIELDS as $param => $column) {
                    if (array_key_exists($param, $item)) {
                        $text = trim((string) ($item[$param] ?? ''));
                        $fields[$column] = $text === '' ? null : $text;
                    }
                }
                $list[$letter] = $fields;
            }
            $lists['temas'] = $list;
        }

        return $lists;
    }

    /** Valor recibido igual al guardado (vacíos iguales; fechas por su valor). */
    private static function sameValue($given, $stored): bool
    {
        $given = $given === null ? '' : trim((string) $given);
        $stored = $stored === null ? '' : trim((string) $stored);
        if ($given === $stored) {
            return true;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $given) && preg_match('/^\d{4}-\d{2}-\d{2}/', $stored)) {
            return strtotime($given) === strtotime($stored);
        }

        return is_numeric($given) && is_numeric($stored) && (float) $given === (float) $stored;
    }

    /**
     * Tras grabar: reescribe asistentes y delegaciones recibidos, graba los
     * temas indicados (al crear, los quince) y audita el cambio de las listas
     * como la ficha (fila y, con nivel 3, campo con la tabla modificada).
     */
    protected function updateAdditionalData(array $data, array $keys): array
    {
        $db = DB::connection('dynamic');
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $base = ['DEL3COD' => $del, 'RDI3SER' => $ser, 'RDI3COD' => $cod];
        $own = fn ($table) => $db->table($table)->where('DEL3COD', $del)->where('RDI3SER', $ser)->where('RDI3COD', $cod);
        $lists = $data['_listas'] ?? [];
        $isNew = ! empty($data['_nueva']);

        if (array_key_exists('asistentes', $lists)) {
            $own('CALRDA')->delete();
            foreach ($lists['asistentes'] as [$empDel, $empCod]) {
                $db->table('CALRDA')->insert($base + ['EMP3DEL' => $empDel, 'EMP3COD' => $empCod]);
            }
        }
        if (array_key_exists('delegaciones', $lists)) {
            $own('CALRDL')->delete();
            foreach ($lists['delegaciones'] as $delegation) {
                $db->table('CALRDL')->insert($base + ['DEL2COD' => $delegation]);
            }
        }
        if ($isNew) {
            foreach (str_split(VeolabManagementReview::TOPIC_LETTERS) as $letter) {
                $fields = $lists['temas'][$letter] ?? [];
                $db->table('CALRDX')->insert($base + ['RDXCTEM' => $letter,
                    'RDXCDAT' => $fields['RDXCDAT'] ?? null, 'RDXCANA' => $fields['RDXCANA'] ?? null]);
            }
        } elseif (array_key_exists('temas', $lists)) {
            foreach ($lists['temas'] as $letter => $fields) {
                if (! $fields) {
                    continue;
                }
                if ($own('CALRDX')->where('RDXCTEM', $letter)->exists()) {
                    $own('CALRDX')->where('RDXCTEM', $letter)->update($fields);
                } else {
                    $db->table('CALRDX')->insert($base + ['RDXCTEM' => $letter, 'RDXCDAT' => null, 'RDXCANA' => null] + $fields);
                }
            }
        }

        // Auditoría de las listas (en el alta basta la inserción)
        if ($lists && ! $isNew) {
            $row = $this->auditRow($keys);
            if (! array_intersect_key($data, array_diff_key($this->mapping, $this->keys))) {
                VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);   // solo cambian las listas
            }
            if (VeolabAudit::enabled(VeolabAudit::MODIFICACION_CAMPO)) {
                foreach (array_keys($lists) as $param) {
                    VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, self::LIST_TABLES[$param]);
                }
            }
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Listado
    // ------------------------------------------------------------------

    /**
     * Además de los campos (estado, fecha_reunion[gte], fecha_fin[lte]...):
     *  - ?anio=: las del año por la fecha de la revisión: la de la reunión o, si
     *    falta, el fin del periodo (RDI_SQLAnio).
     *  - ?asistente_delegacion=&asistente_codigo=: las que lo tienen entre los asistentes.
     *  - ?con_decisiones_abiertas=T|F: con alguna decisión abierta (o ninguna).
     */
    protected function applyCustomFilters(Request $request, $query): void
    {
        $year = $request->query('anio');
        if (is_string($year) && $year !== '') {
            if (! ctype_digit($year)) {
                throw new BusinessRuleException('El filtro anio no es un número');
            }
            $next = (int) $year + 1;
            $query->whereRaw('COALESCE(CALRDI.RDIDFEC, CALRDI.RDIDFIN) >= ?', ["{$year}-01-01 00:00:00"])
                ->whereRaw('COALESCE(CALRDI.RDIDFEC, CALRDI.RDIDFIN) < ?', ["{$next}-01-01 00:00:00"]);
        }

        $code = $request->query('asistente_codigo');
        if (is_string($code) && $code !== '') {
            if (! ctype_digit($code)) {
                throw new BusinessRuleException('El filtro asistente_codigo no es un número');
            }
            $del = (string) ($request->query('asistente_delegacion') ?? '');
            $query->whereExists(fn ($s) => $s->select(DB::raw(1))->from('CALRDA')
                ->whereColumn('CALRDA.DEL3COD', 'CALRDI.DEL3COD')->whereColumn('CALRDA.RDI3SER', 'CALRDI.RDI1SER')
                ->whereColumn('CALRDA.RDI3COD', 'CALRDI.RDI1COD')
                ->where('CALRDA.EMP3DEL', $del)->where('CALRDA.EMP3COD', (int) $code));
        }

        $open = $request->query('con_decisiones_abiertas');
        if (is_string($open) && $open !== '') {
            $exists = function ($s) {
                $s->select(DB::raw(1))->from('CALIYE')
                    ->join('CALINC', function ($j) {
                        $j->on('CALINC.DEL3COD', '=', 'CALIYE.DEL3COD')->on('CALINC.INC1SER', '=', 'CALIYE.INC3SER')
                            ->on('CALINC.INC1COD', '=', 'CALIYE.INC3COD');
                    })
                    ->where('CALIYE.IYECTAB', VeolabManagementReview::TABLE)
                    ->whereColumn('CALIYE.IYECDEL', 'CALRDI.DEL3COD')->whereColumn('CALIYE.IYECSER', 'CALRDI.RDI1SER')
                    ->whereRaw('CALIYE.IYECCOD = CAST(CALRDI.RDI1COD AS CHAR)')
                    ->where(fn ($q) => $q->whereIn('CALINC.INCCEST', VeolabQuality::OPEN_STATES)->orWhereNull('CALINC.INCCEST')
                        ->orWhere('CALINC.INCCEST', ''));
            };
            strtoupper($open) === 'T' ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }
    }

    /**
     * Datos de solo lectura: código formateado, texto del estado, nombre de
     * quien la aprueba, los asistentes y las delegaciones con su nombre, los
     * quince temas en el orden de la norma (título, si tiene datos
     * automáticos y si ya tiene análisis) y cuántas decisiones tiene y
     * cuántas siguen abiertas.
     */
    protected function appendRelatedData(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $db = DB::connection('dynamic');
        $k = fn ($d, $s, $c) => $d."\x1B".$s."\x1B".(int) $c;
        $byReview = function (string $table) use ($db, $rows) {
            return $db->table($table)
                ->where(function ($q) use ($rows, $table) {
                    foreach ($rows as $row) {
                        $q->orWhere(fn ($w) => $w->where("{$table}.DEL3COD", (string) $row['delegacion'])
                            ->where("{$table}.RDI3SER", (string) $row['serie'])->where("{$table}.RDI3COD", (int) $row['codigo']));
                    }
                });
        };
        $group = fn ($r) => $r->DEL3COD."\x1B".$r->RDI3SER."\x1B".(int) $r->RDI3COD;

        $attendees = $byReview('CALRDA')
            ->leftJoin('GRHEMP', fn ($j) => $j->on('GRHEMP.DEL3COD', '=', 'CALRDA.EMP3DEL')->on('GRHEMP.EMP1COD', '=', 'CALRDA.EMP3COD'))
            ->orderBy('GRHEMP.EMPCNOM')->orderBy('CALRDA.EMP3COD')
            ->get(['CALRDA.DEL3COD', 'CALRDA.RDI3SER', 'CALRDA.RDI3COD', 'CALRDA.EMP3DEL', 'CALRDA.EMP3COD', 'GRHEMP.EMPCNOM'])
            ->groupBy($group);
        $topics = $byReview('CALRDX')->get(['DEL3COD', 'RDI3SER', 'RDI3COD', 'RDXCTEM', 'RDXCDAT', 'RDXCANA'])->groupBy($group);
        $delegations = $byReview('CALRDL')
            ->leftJoin('ACCDEL', 'ACCDEL.DEL1COD', '=', 'CALRDL.DEL2COD')
            ->orderBy('CALRDL.DEL2COD')
            ->get(['CALRDL.DEL3COD', 'CALRDL.RDI3SER', 'CALRDL.RDI3COD', 'CALRDL.DEL2COD', 'ACCDEL.DELCNOM'])->groupBy($group);
        $approvers = $db->table('GRHEMP')
            ->where(function ($q) use ($rows) {
                foreach ($rows as $row) {
                    if ((int) $row['aprobada_por_codigo'] !== 0) {
                        $q->orWhere(fn ($w) => $w->where('DEL3COD', (string) $row['aprobada_por_delegacion'])->where('EMP1COD', (int) $row['aprobada_por_codigo']));
                    }
                }
                $q->orWhereRaw('1 = 0');
            })
            ->get(['DEL3COD', 'EMP1COD', 'EMPCNOM'])->keyBy(fn ($e) => $e->DEL3COD."\x1B".(int) $e->EMP1COD);
        $decisions = VeolabManagementReview::decisions(array_map(fn ($r) => [(string) $r['delegacion'], (string) $r['serie'], (int) $r['codigo']], $rows));

        foreach ($rows as &$row) {
            $key = $k($row['delegacion'], $row['serie'], $row['codigo']);
            $state = (string) ($row['estado'] ?? '') === '' ? 'B' : (string) $row['estado'];
            $own = ($topics[$key] ?? collect())->keyBy(fn ($t) => (string) $t->RDXCTEM);

            $row['codigo_formateado'] = VeolabCodes::format($this->table, (string) $row['codigo'], (string) $row['delegacion'], (string) $row['serie']);
            $row['estado_texto'] = VeolabManagementReview::STATE_NAMES[$state] ?? $state;
            $row['aprobada_por_nombre'] = (int) $row['aprobada_por_codigo'] !== 0
                ? ($approvers[$row['aprobada_por_delegacion']."\x1B".(int) $row['aprobada_por_codigo']]->EMPCNOM ?? null) : null;
            $row['asistentes'] = ($attendees[$key] ?? collect())->map(fn ($e) => [
                'empleado_delegacion' => (string) $e->EMP3DEL,
                'empleado_codigo'     => (int) $e->EMP3COD,
                'empleado_nombre'     => $e->EMPCNOM,
            ])->values()->all();
            $row['temas'] = [];
            foreach (VeolabManagementReview::TOPICS as $letter => $title) {      // en el orden de la norma
                $topic = $own[$letter] ?? null;
                $analysis = $topic ? $topic->RDXCANA : null;
                $row['temas'][] = [
                    'tema'       => $letter,
                    'titulo'     => $title,
                    'automatico' => VeolabManagementReview::isAutomatic($letter) ? 'T' : 'F',
                    'datos'      => $topic ? $topic->RDXCDAT : null,
                    'analisis'   => $analysis,
                    'tratado'    => trim((string) $analysis) !== '' ? 'T' : 'F',
                ];
            }
            $row['temas_sin_analisis'] = count(array_filter($row['temas'], fn ($t) => $t['tratado'] === 'F'));
            $row['delegaciones'] = ($delegations[$key] ?? collect())->map(fn ($d) => [
                'delegacion' => (string) $d->DEL2COD,
                'nombre'     => $d->DELCNOM,
            ])->values()->all();
            $list = $decisions[$key] ?? [];
            $row['decisiones'] = count($list);
            $row['decisiones_abiertas'] = count(array_filter($list, fn ($d) => $d['abierta'] === 'T'));
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Borrado
    // ------------------------------------------------------------------

    /**
     * Solo el responsable de calidad (?usuario_*), solo en borrador y sin
     * decisiones ni documentos: una revisión con decisiones no se elimina, se
     * anula.
     */
    protected function validateBeforeDelete(array $keys): void
    {
        VeolabQuality::assertModule();
        [$del, $ser, $cod] = [(string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']];
        $review = VeolabManagementReview::review($del, $ser, $cod, true);
        if (! $review) {
            return;
        }
        VeolabManagementReview::assertManager(
            VeolabManagementReview::requestUser(request()->query('usuario_delegacion'), request()->query('usuario_codigo')));
        if (VeolabManagementReview::state($review) !== 'B') {
            throw new BusinessRuleException('Solo se puede eliminar una revisión en borrador; si no procede, anúlela');
        }
        if (VeolabManagementReview::decisionCount($del, $ser, $cod) > 0) {
            throw new BusinessRuleException('La revisión tiene decisiones y no se puede eliminar; anúlela');
        }
        if (VeolabManagementReview::hasDocuments($del, $ser, $cod)) {
            throw new BusinessRuleException('La revisión tiene documentos y no se puede eliminar');
        }
    }

    protected function deleteRelatedRecords(array $keys): void
    {
        VeolabManagementReview::deleteReviewData((string) $keys['delegacion'], (string) $keys['serie'], (int) $keys['codigo']);
    }

    // ------------------------------------------------------------------
    // Recoger datos y decisiones
    // ------------------------------------------------------------------

    /** Clave de la query string (delegacion, serie, codigo) o la respuesta 400 si falta. */
    private function queryKeys(Request $request)
    {
        foreach (array_keys($this->keys) as $param) {
            if (! $request->has($param)) {
                return response()->json(['message' => "Falta la clave '{$param}'"], 400);
            }
        }

        return [
            'delegacion' => (string) ($request->query('delegacion') ?? ''),
            'serie'      => (string) ($request->query('serie') ?? ''),
            'codigo'     => (int) $request->query('codigo'),
        ];
    }

    /**
     * POST /revisiones-direccion/recoger-datos?delegacion=&serie=&codigo=
     * [&usuario_delegacion=&usuario_codigo=]. Cuerpo opcional {sobrescribir:
     * T}. Solo en borrador: sustituye los datos del sistema de los temas con
     * datos automáticos (b-o; c, el control de documentos) por el resumen del periodo y pone la fecha de
     * la recogida. Si alguno ya tiene texto y no se pide sobrescribir, 422
     * con la lista (el escritorio lo pregunta).
     */
    public function collectData(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $keys = $this->queryKeys($request);
        if (! is_array($keys)) {
            return $keys;
        }
        $body = json_decode($request->getContent(), true);
        $flag = is_array($body) ? ($body['sobrescribir'] ?? false) : false;
        $overwrite = is_scalar($flag) && in_array(strtoupper(trim((string) $flag)), ['T', 'TRUE', '1'], true);   // true se convierte en '1'
        [$del, $ser, $cod] = [$keys['delegacion'], $keys['serie'], $keys['codigo']];

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            $review = VeolabManagementReview::review($del, $ser, $cod, true);
            if (! $review) {
                $db->rollBack();

                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            $user = VeolabManagementReview::requestUser($request->query('usuario_delegacion'), $request->query('usuario_codigo'));
            VeolabManagementReview::assertManager($user);
            if (VeolabManagementReview::state($review) !== 'B') {
                throw new BusinessRuleException('Los datos solo se recogen con la revisión en borrador');
            }
            if (empty($review->RDIDINI) || empty($review->RDIDFIN)) {
                throw new BusinessRuleException('Indique el periodo revisado (fecha de inicio y de fin) antes de recoger los datos');
            }

            $own = fn () => $db->table('CALRDX')->where('DEL3COD', $del)->where('RDI3SER', $ser)->where('RDI3COD', $cod);
            $stored = $own()->get(['RDXCTEM', 'RDXCDAT'])->keyBy(fn ($t) => (string) $t->RDXCTEM);
            $withData = array_values(array_filter(str_split(VeolabManagementReview::AUTOMATIC_TOPICS),
                fn ($l) => isset($stored[$l]) && trim((string) $stored[$l]->RDXCDAT) !== ''));
            if ($withData && ! $overwrite) {
                $db->rollBack();

                return response()->json([
                    'message' => 'Los temas '.implode(', ', $withData).' ya tienen datos del sistema; repita con {"sobrescribir": "T"} para sustituirlos',
                    'errors'  => ['temas_con_datos' => $withData],
                ], 422);
            }

            $texts = VeolabManagementReview::collect($review, (string) ($user['delegacion'] ?? ''));
            $base = ['DEL3COD' => $del, 'RDI3SER' => $ser, 'RDI3COD' => $cod];
            foreach ($texts as $letter => $text) {
                if (isset($stored[$letter])) {
                    $own()->where('RDXCTEM', $letter)->update(['RDXCDAT' => $text]);
                } else {
                    $db->table('CALRDX')->insert($base + ['RDXCTEM' => $letter, 'RDXCDAT' => $text, 'RDXCANA' => null]);
                }
            }
            $now = (string) $db->selectOne('SELECT NOW() AS n')->n;
            $db->table($this->table)->where('DEL3COD', $del)->where('RDI1SER', $ser)->where('RDI1COD', $cod)->update(['RDITDAT' => $now]);

            $row = $this->auditRow($keys);
            VeolabAudit::record(VeolabAudit::MODIFICACION_FILA, $this->table, $row);
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, $this->table.'RDITDAT',
                VeolabAudit::value($now), VeolabAudit::value($review->RDITDAT));
            VeolabAudit::record(VeolabAudit::MODIFICACION_CAMPO, $this->table, $row, 'CALRDX');

            $db->commit();

            return response()->json([
                'message' => 'Datos recogidos correctamente',
                'data'    => $keys + [
                    'fecha_recogida_datos' => $now,
                    'temas'                => array_map(fn ($letter) => [
                        'tema'   => $letter,
                        'titulo' => VeolabManagementReview::TOPICS[$letter],
                        'datos'  => $texts[$letter],
                    ], array_keys($texts)),
                ],
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 recoger datos {$this->table}", $e, 'Error al recoger los datos de la revisión');
        }
    }

    /**
     * GET /revisiones-direccion/decisiones?delegacion=&serie=&codigo=
     * Decisiones de la revisión (incidencias enlazadas con CALIYE tipo
     * CALRDI): código, título, responsable, fecha prevista de la acción
     * pendiente más próxima y estado. Solo lectura.
     */
    public function decisions(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $keys = $this->queryKeys($request);
        if (! is_array($keys)) {
            return $keys;
        }
        try {
            if (! VeolabManagementReview::review($keys['delegacion'], $keys['serie'], $keys['codigo'])) {
                return response()->json(['message' => 'Registro no encontrado'], 404);
            }
            $list = VeolabManagementReview::decisions([[$keys['delegacion'], $keys['serie'], $keys['codigo']]])
                [$keys['delegacion']."\x1B".$keys['serie']."\x1B".$keys['codigo']] ?? [];

            return response()->json([
                'data' => $list,
                'meta' => [
                    'total'      => count($list),
                    'abiertas'   => count(array_filter($list, fn ($d) => $d['abierta'] === 'T')),
                    'version_bd' => VeolabVersion::current(),
                ],
            ]);
        } catch (\Throwable $e) {
            return ServerError::response("v2 decisiones {$this->table}", $e, 'Error al leer las decisiones de la revisión');
        }
    }
}
