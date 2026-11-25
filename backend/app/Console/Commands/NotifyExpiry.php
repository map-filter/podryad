<?php

namespace App\Console\Commands;

use App\Services\ExpiryNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class NotifyExpiry extends Command
{
    protected $signature = 'permits:notify {--date= : Дата проверки, по умолчанию сегодня}';

    protected $description = 'Проверить сроки документов и отправить уведомления в Telegram и на почту';

    public function handle(ExpiryNotifier $notifier): int
    {
        $on = $this->option('date') ? CarbonImmutable::parse($this->option('date'))->startOfDay() : null;
        $res = $notifier->run($on);
        $this->info(sprintf('Организаций: %d, документов в уведомлениях: %d, сообщений: %d', $res['organizations'], $res['items'], $res['messages']));

        return self::SUCCESS;
    }
}
