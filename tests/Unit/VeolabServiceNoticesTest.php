<?php

namespace Tests\Unit;

use App\Support\VeolabServiceNotices;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VeolabServiceNoticesTest extends TestCase
{
    public function test_interpreta_la_respuesta_de_getnotice(): void
    {
        $body = "7\t2\t2026-11-17 08:00\t2026-11-19 08:00\t\tIncidencia\tL\u{00ED}nea 1\\nL\u{00ED}nea 2\\tcon tab y \\\\ barra\n"
            ."8\t1\t2026-11-17 09:00\t2026-11-18 23:00\t2026-11-18 22:00\tReinicio\tTexto\r\n"
            ."basura sin campos\n";

        $notices = VeolabServiceNotices::parse($body);

        $this->assertCount(2, $notices);
        $this->assertSame(7, $notices[0]['codigo']);
        $this->assertSame('urgente', $notices[0]['gravedad_descripcion']);
        $this->assertNull($notices[0]['corte']);
        $this->assertSame("L\u{00ED}nea 1\nL\u{00ED}nea 2\tcon tab y \\ barra", $notices[0]['texto']);
        $this->assertSame('programado', $notices[1]['gravedad_descripcion']);
        $this->assertSame('2026-11-18 22:00:00', $notices[1]['corte']);
        $this->assertSame('Texto', $notices[1]['texto']);
    }

    public function test_respuesta_vacia_sin_avisos(): void
    {
        $this->assertSame([], VeolabServiceNotices::parse(''));
    }

    public function test_consulta_spuch_y_guarda_en_cache(): void
    {
        config(['veolab.service_notices.url' => 'https://avisos.test/getnotice.php', 'veolab.service_notices.server' => 'veolab.es']);
        Http::fake(['avisos.test/*' => Http::response("3\t0\t2026-11-17 08:00\t2026-11-20 08:00\t\tHola\tTexto", 200)]);

        $first = VeolabServiceNotices::fetch('LIC1', 'bd1');
        $second = VeolabServiceNotices::fetch('LIC1', 'bd1');

        $this->assertSame('Hola', $first[0]['titulo']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['licencia'] === 'LIC1' && $request['servidor'] === 'veolab.es' && $request['version'] === 'api');
    }

    public function test_sin_respuesta_devuelve_null(): void
    {
        config(['veolab.service_notices.url' => 'https://avisos.test/getnotice.php']);
        Http::fake(['avisos.test/*' => Http::response('', 500)]);

        $this->assertNull(VeolabServiceNotices::fetch('LIC2', 'bd2'));
    }
}
