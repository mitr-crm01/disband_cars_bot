<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $test = DB::connection('mysql_dbmihold')
            ->table('b_uts_crm_deal')
            ->where('VALUE_ID', 1253122)
            ->value('UF_CRM_1695214894');

        Log::info($test);

    }
}
