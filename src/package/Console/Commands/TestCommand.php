<?php

namespace PragmaRX\TestsWatcher\Package\Console\Commands;

class TestCommand extends BaseCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'tddd:work';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Continuously run tests';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function fire()
    {
        $this->getLaravel()->make('tddd.tester')->run($this);
    }

    /**
     * Handle command.
     */
    public function handle()
    {
        $this->fire();
    }
}
