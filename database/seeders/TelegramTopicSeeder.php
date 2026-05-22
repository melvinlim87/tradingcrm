<?php

namespace Database\Seeders;

use App\Models\TelegramTopic;
use Illuminate\Database\Seeder;

class TelegramTopicSeeder extends Seeder
{
    /**
     * Seed the default topic mapping (matches existing Python config).
     * mt5_account_id is left null — bind to real accounts via UI/seed later.
     */
    public function run(): void
    {
        $topics = [
            ['name' => 'account_1',         'thread_id' => 123, 'description' => 'Per-account alerts (#1)'],
            ['name' => 'account_2',         'thread_id' => 124, 'description' => 'Per-account alerts (#2)'],
            ['name' => 'account_3',         'thread_id' => 125, 'description' => 'Per-account alerts (#3)'],
            ['name' => 'account_4',         'thread_id' => 126, 'description' => 'Per-account alerts (#4)'],
            ['name' => 'account_5',         'thread_id' => 127, 'description' => 'Per-account alerts (#5)'],
            ['name' => 'overall_reporting', 'thread_id' => 128, 'description' => 'Portfolio-wide reports'],
            ['name' => 'risk_management',   'thread_id' => 129, 'description' => 'Risk / drawdown alerts'],
            ['name' => 'daily_report',      'thread_id' => 130, 'description' => 'Daily summary'],
            ['name' => 'weekly_report',     'thread_id' => 131, 'description' => 'Weekly summary'],
            ['name' => 'monthly_report',    'thread_id' => 132, 'description' => 'Monthly summary'],
        ];

        foreach ($topics as $topic) {
            TelegramTopic::updateOrCreate(
                ['name' => $topic['name']],
                $topic + ['enabled' => true]
            );
        }
    }
}
