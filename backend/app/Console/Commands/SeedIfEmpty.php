<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;

class SeedIfEmpty extends Command
{
    protected $signature = 'app:seed-if-empty';

    protected $description = 'Заполнить базу начальными данными, если она пустая';

    public function handle(): int
    {
        if (Organization::exists()) {
            $this->line('Данные уже есть, пропускаю');

            return self::SUCCESS;
        }
        $this->call('db:seed', ['--force' => true]);

        return self::SUCCESS;
    }
}
