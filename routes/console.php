<?php

use App\Support\VeolabLicense;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('veolab:licencia {bd : Nombre de la BD del laboratorio}', function (string $bd) {
    config([
        'database.connections.dynamic' => array_merge(config('database.connections.mysql'), ['database' => $bd]),
    ]);

    $names = [1 => 'Gratuita', 2 => 'Profesional', 3 => 'Empresarial', 4 => 'Empresarial* (Verifactu)'];
    $type = VeolabLicense::type('dynamic', $bd);

    $this->line('Patrones configurados: '.(VeolabLicense::configured() ? 'sí' : 'NO'));
    $this->line('Tipo de licencia: '.($type === null ? 'no determinado' : ($names[$type] ?? "desconocido ({$type})")));
    $this->line('Restricciones Verifactu: '.(VeolabLicense::isVerifactu('dynamic', $bd) ? 'SÍ' : 'no'));
})->purpose('Muestra el tipo de licencia Veolab de un laboratorio');
