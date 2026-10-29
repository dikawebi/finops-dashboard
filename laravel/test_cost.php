<?php
require __DIR__.'/vendor/autoload.php'; $app=require __DIR__.'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=app(App\Services\CostMatrixService::class)->build('2026-10'); foreach($r['rows'] as $x) if($x['code']==='5000054') {var_dump($x);break;}
