<?php

namespace Tests\Unit;

use App\Models\Expense;
use App\Support\BusinessClock;
use Tests\TestCase;

class SerializesDatesInAppTimezoneTest extends TestCase
{
    public function test_expense_date_json_is_midnight_in_app_timezone(): void
    {
        $expense = new Expense([
            'expense_date' => '2026-09-12',
        ]);

        $this->assertSame('America/Guayaquil', (string) config('app.timezone'));
        $this->assertSame(BusinessClock::TIMEZONE, (string) config('app.timezone'));
        $this->assertSame('2026-09-12 00:00:00', $expense->toArray()['expense_date']);
        $this->assertStringNotContainsString('05:00:00', (string) $expense->toJson());
    }
}
