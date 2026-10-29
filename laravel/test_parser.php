<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$result = app(App\Services\GlParserService::class)->parseFile(base_path('../data_snapshot/extracted/gl_data.json'));
echo json_encode($result['stats'], JSON_PRETTY_PRINT) . PHP_EOL;
exit(($result['stats']['totalRows'] === 12583 && $result['stats']['invalidDates'] === 0) ? 0 : 1);
