<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A database failure must never print SQL at a visitor, and a hosted-checkout link (Astragate's
 * carries a signed token well over 1,000 characters) must fit the column that stores it.
 */
class PaymentErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_database_error_never_shows_sql_to_a_json_client(): void
    {
        Route::get('/_test/query-failure', function (): never {
            throw new QueryException(
                'mysql',
                'insert into `payments` (`payment_url`) values (?)',
                ['https://checkout.example/secret-token'],
                new \PDOException('SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column')
            );
        });

        $response = $this->getJson('/_test/query-failure');

        $response->assertStatus(500)
            ->assertJson(['success' => false])
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Something went wrong'));

        $body = $response->getContent();
        foreach (['SQLSTATE', 'insert into', 'payments', 'secret-token', 'Data too long'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $body);
        }
    }

    public function test_payment_url_columns_hold_a_long_checkout_link(): void
    {
        foreach (['payments', 'ticket_payments', 'contribution_payments'] as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'payment_url');

            $this->assertNotNull($column, "{$table}.payment_url exists");
            $this->assertStringContainsString('text', strtolower((string) $column['type_name']), "{$table}.payment_url is a text column");
        }
    }
}
