<?php

namespace Tests\Unit\Support;

use App\Support\PaymentLog;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PaymentLogChannelTest extends TestCase
{
    public function test_falls_back_to_default_logger_when_payments_channel_fails(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('payments')
            ->andThrow(new \InvalidArgumentException('Invalid log level.'));

        Log::shouldReceive('info')
            ->once()
            ->with('poll.completed', \Mockery::on(function (array $context): bool {
                return ($context['event'] ?? null) === 'poll.completed'
                    && ($context['processed'] ?? null) === 0;
            }));

        PaymentLog::info('poll.completed', ['processed' => 0]);
    }

    public function test_writes_to_payments_channel_when_healthy(): void
    {
        $logger = \Mockery::mock();
        $logger->shouldReceive('info')
            ->once()
            ->with('poll.completed', \Mockery::on(function (array $context): bool {
                return ($context['event'] ?? null) === 'poll.completed';
            }));

        Log::shouldReceive('channel')
            ->once()
            ->with('payments')
            ->andReturn($logger);

        PaymentLog::info('poll.completed', ['processed' => 0]);
    }
}
