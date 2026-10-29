<?php
require __DIR__.'/vendor/autoload.php'; $app=require __DIR__.'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(App\Models\CostAccount::where('account_code','5000054')->get() as $a) var_dump($a->account_code);
