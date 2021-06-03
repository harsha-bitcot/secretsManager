<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\SecretsController;

class ClearSecrets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'secrets:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'clear AWS secrets manager data from cache';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(SecretsController $secretsController)
    {
        $secrets = $secretsController->clearSecrets();
        if ($secrets){
            $this->error('Something went wrong!');
        }else {
            $this->info('The command was successful!');
        }
        return 0;
    }
}
