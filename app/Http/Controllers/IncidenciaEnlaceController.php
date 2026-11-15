<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Support\ServerError;
use App\Support\VeolabAuditChecklists;
use App\Support\VeolabQuality;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * «A qué afecta» una incidencia de calidad (CALIYE, desde Veolab 3), como
 * la pestaña de FichaIncidencia: registros enlazados por tabla y clave en
 * texto (enlace_delegacion, enlace_serie, enlace_codigo).
 *
 *  - Tablas: LABOPE e LABINF (delegación, serie, código), SINCLI, SINPRO,
 *    GRHEMP, LABTEC, ALMPRD, LABCDC (delegación y código), CALINC (otra
 *    incidencia), CALEAP (ensayo de aptitud), CALAUI (auditoría) y CALRDI (revisión por la dirección), estas tres con delegación, serie y código; ALMSEL es la serie o lote de un producto: delegación del
 *    producto, producto en enlace_serie y la serie o lote en enlace_codigo.
 *    La serie oculta '-' de un producto sin series se enlaza como el
 *    producto (CAL_EnlaceInventario).
 *  - El registro debe existir; no se repite ni se enlaza la incidencia
 *    consigo misma. Solo alta y baja (la ficha no edita un enlace).
 *  - La lectura añade tabla_descripcion, enlace_codigo_formateado,
 *    enlace_descripcion y existe (los registros borrados después quedan
 *    enlazados, como en Veolab, con la descripción vacía).
 *  - POST /incidencias/enlaces/informes enlaza los informes que contienen
 *    las operaciones ya enlazadas (InformesAfectados).
 *  - Los enlaces con una auditoría, una revisión por la dirección, un
 *    objetivo o un ensayo de aptitud (por ellos encuentran esas fichas sus
 *    hallazgos, decisiones y mejoras) solo los quita el responsable de
 *    calidad (QuitarEnlaces): con ?usuario_delegacion=&usuario_codigo= en el
 *    DELETE se comprueba; sin usuario, la API actúa como responsable de
 *    calidad. Al quitar el de una auditoría, las preguntas de su lista de
 *    verificación que tenían la incidencia como hallazgo se quedan sin él.
 */
class IncidenciaEnlaceController extends IncidenciaChildController
{
    protected string $table = 'CALIYE';
    protected string $auditField = 'CALIYE';

    protected array $keys = [
        'incidencia_delegacion' => 'DEL3COD',
        'incidencia_serie'      => 'INC3SER',
        'incidencia_codigo'     => 'INC3COD',
        'codigo'                => 'IYE1COD',
    ];

    protected array $mapping = [
        'incidencia_delegacion' => 'DEL3COD',
        'incidencia_serie'      => 'INC3SER',
        'incidencia_codigo'     => 'INC3COD',
        'codigo'                => 'IYE1COD',
        'tabla'                 => 'IYECTAB',
        'enlace_delegacion'     => 'IYECDEL',
        'enlace_serie'          => 'IYECSER',
        'enlace_codigo'         => 'IYECCOD',
    ];

    protected function fieldRules(): array
    {
        return [
            'codigo'            => 'nullable|integer|min:1',
            'tabla'             => (request()->isMethod('post') ? 'required' : 'sometimes').'|string|in:'.implode(',', array_keys(VeolabQuality::LINK_TABLES)),
            'enlace_delegacion' => 'nullable|string|max:10',
            'enlace_serie'      => 'nullable|string|max:30',
            'enlace_codigo'     => (request()->isMethod('post') ? 'required' : 'sometimes').'|string|max:50',
        ];
    }

    protected function validateAdditionalCriteria(array $data, array $keys = []): array
    {
        if ($keys) {
            throw new BusinessRuleException('Un enlace no se modifica: se quita y se añade otro');
        }
        $data = parent::validateAdditionalCriteria($data, $keys);
        $key = self::incidentKey($data);

        [$table, $del, $ser, $cod] = VeolabQuality::normalizeLink((string) $data['tabla'], (string) ($data['enlace_delegacion'] ?? ''),
            (string) ($data['enlace_serie'] ?? ''), trim((string) $data['enlace_codigo']));
        $this->checkLink($key, $table, $del, $ser, $cod);

        $data['tabla'] = $table;
        $data['enlace_delegacion'] = $del;
        $data['enlace_serie'] = $ser;
        $data['enlace_codigo'] = $cod;
        if (empty($data['codigo'])) {
            $data['codigo'] = $this->nextLine($key, 'IYE1COD');
        }

        return $data;
    }

    /** Enlaces que Veolab crea desde la ficha del registro enlazado (EnlaceProtegido). */
    private const SOURCE_LINKS = ['CALAUI', 'CALRDI', 'CALOBJ', 'CALEAP'];

    /** QuitarEnlaces: los enlaces de SOURCE_LINKS, solo el responsable de calidad; el de una auditoría limpia el hallazgo de sus preguntas. */
    protected function validateBeforeDelete(array $keys): void
    {
        parent::validateBeforeDelete($keys);

        $key = self::incidentKey($keys);
        $db = DB::connection('dynamic');
        $link = $db->table('CALIYE')->where('DEL3COD', $key[0])->where('INC3SER', $key[1])->where('INC3COD', $key[2])
            ->where('IYE1COD', (int) ($keys['codigo'] ?? 0))->first(['IYECTAB', 'IYECDEL', 'IYECSER', 'IYECCOD']);
        if (! $link || ! in_array((string) $link->IYECTAB, self::SOURCE_LINKS, true)) {
            return;
        }
        $user = VeolabQuality::requestUser(request()->query('usuario_delegacion'), request()->query('usuario_codigo'));
        if ($user !== null && ! $user['calidad']) {
            throw new BusinessRuleException('Los enlaces con una auditoría, una revisión por la dirección, un objetivo o un ensayo de aptitud solo los puede quitar el responsable de calidad');
        }
        // CALAUP es de la delegación de la auditoría y su hallazgo, una incidencia de esa misma delegación
        if ((string) $link->IYECTAB === 'CALAUI' && (string) $link->IYECDEL === $key[0] && ctype_digit((string) $link->IYECCOD)
            && VeolabAuditChecklists::available()) {
            $db->table(VeolabAuditChecklists::LINES)->where('DEL3COD', $key[0])->where('AUI3SER', (string) $link->IYECSER)
                ->where('AUI3COD', (int) $link->IYECCOD)->where('INC2SER', $key[1])->where('INC2COD', $key[2])
                ->update(['INC2SER' => null, 'INC2COD' => null]);
        }
    }

    /** AnadirEnlace: existe, no es la propia incidencia y no estaba ya. */
    private function checkLink(array $key, string $table, string $del, string $ser, string $cod): void
    {
        if ($table === 'CALINC' && $del === $key[0] && $ser === $key[1] && $cod === (string) $key[2]) {
            throw new BusinessRuleException('Una incidencia no se enlaza consigo misma');
        }
        if ($cod === '' || ! VeolabQuality::linkExists($table, $del, $ser, $cod)) {
            throw new BusinessRuleException('El registro enlazado ('.VeolabQuality::LINK_TABLES[$table].') no existe');
        }
        $repeated = DB::connection('dynamic')->table('CALIYE')
            ->where('DEL3COD', $key[0])->where('INC3SER', $key[1])->where('INC3COD', $key[2])
            ->where('IYECTAB', $table)->where('IYECDEL', $del)->where('IYECSER', $ser)->where('IYECCOD', $cod)->exists();
        if ($repeated) {
            throw new BusinessRuleException('El registro ya está enlazado a la incidencia');
        }
    }

    /** Texto del tipo, código formateado, descripción y si el registro sigue existiendo. */
    protected function appendRelatedData(array $rows): array
    {
        foreach ($rows as &$row) {
            [$table, $del, $ser, $cod] = [(string) $row['tabla'], (string) $row['enlace_delegacion'], (string) $row['enlace_serie'],
                (string) $row['enlace_codigo']];
            $exists = VeolabQuality::linkExists($table, $del, $ser, $cod);
            $row['tabla_descripcion'] = VeolabQuality::LINK_TABLES[$table] ?? $table;
            $row['enlace_codigo_formateado'] = isset(VeolabQuality::LINK_TABLES[$table]) ? VeolabQuality::linkCode($table, $del, $ser, $cod) : $cod;
            $row['enlace_descripcion'] = $exists ? VeolabQuality::linkDescription($table, $del, $ser, $cod) : '';
            $row['existe'] = $exists ? 'T' : 'F';
        }
        unset($row);

        return $rows;
    }

    /**
     * POST /incidencias/enlaces/informes. Cuerpo {incidencia_delegacion,
     * incidencia_serie, incidencia_codigo}: enlaza los informes (LABIYO) que
     * contienen las operaciones enlazadas a la incidencia, los afectados por
     * el trabajo no conforme (ISO 17025, 7.10.1 c). Devuelve los añadidos.
     */
    public function linkReports(Request $request)
    {
        if ($unavailable = $this->prepareForDatabase()) {
            return $unavailable;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $validator = Validator::make($body, [
            'incidencia_delegacion' => 'nullable|string|max:10',
            'incidencia_serie'      => 'nullable|string|max:10',
            'incidencia_codigo'     => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }
        $key = self::incidentKey($body);

        $db = DB::connection('dynamic');
        try {
            $db->beginTransaction();

            VeolabQuality::assertModule();
            $this->lockIncident($key);

            $operations = $db->table('CALIYE')->where('DEL3COD', $key[0])->where('INC3SER', $key[1])->where('INC3COD', $key[2])
                ->where('IYECTAB', 'LABOPE')->get(['IYECDEL', 'IYECSER', 'IYECCOD'])
                ->filter(fn ($o) => ctype_digit((string) $o->IYECCOD));
            if ($operations->isEmpty()) {
                throw new BusinessRuleException('La incidencia no tiene operaciones enlazadas');
            }
            $reports = $db->table('LABIYO')
                ->where(function ($q) use ($operations) {
                    foreach ($operations as $o) {
                        $q->orWhere(fn ($w) => $w->where('OPE3DEL', (string) $o->IYECDEL)->where('OPE3SER', (string) $o->IYECSER)
                            ->where('OPE3COD', (int) $o->IYECCOD));
                    }
                })
                ->distinct()->orderBy('INF3DEL')->orderBy('INF3SER')->orderBy('INF3COD')
                ->get(['INF3DEL', 'INF3SER', 'INF3COD']);

            $added = [];
            $line = $this->nextLine($key, 'IYE1COD');
            foreach ($reports as $report) {
                $link = ['LABINF', (string) $report->INF3DEL, (string) $report->INF3SER, (string) $report->INF3COD];
                $exists = $db->table('CALIYE')->where('DEL3COD', $key[0])->where('INC3SER', $key[1])->where('INC3COD', $key[2])
                    ->where('IYECTAB', $link[0])->where('IYECDEL', $link[1])->where('IYECSER', $link[2])->where('IYECCOD', $link[3])->exists();
                if ($exists) {
                    continue;
                }
                $db->table('CALIYE')->insert([
                    'DEL3COD' => $key[0], 'INC3SER' => $key[1], 'INC3COD' => $key[2], 'IYE1COD' => $line,
                    'IYECTAB' => $link[0], 'IYECDEL' => $link[1], 'IYECSER' => $link[2], 'IYECCOD' => $link[3],
                ]);
                $added[] = ['codigo' => $line, 'tabla' => $link[0], 'enlace_delegacion' => $link[1], 'enlace_serie' => $link[2],
                    'enlace_codigo' => $link[3]];
                $line++;
            }
            if ($added) {
                VeolabQuality::auditIncident($key[0], $key[1], $key[2], $this->auditField);
            }

            $db->commit();

            return response()->json([
                'message' => $added ? 'Informes enlazados correctamente' : 'Los informes de las operaciones enlazadas ya estaban enlazados',
                'data'    => $added,
            ]);
        } catch (BusinessRuleException $e) {
            $db->rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $db->rollBack();

            return ServerError::response("v2 informes {$this->table}", $e, 'Error al enlazar los informes');
        }
    }
}
