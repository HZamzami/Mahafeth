<?php

namespace Tests\Unit;

use App\Services\Imports\AlinmaCapitalStatementParser;
use App\Services\OpenBanking\AssetCatalog;
use Tests\TestCase;

class AlinmaCapitalStatementParserTest extends TestCase
{
    private AlinmaCapitalStatementParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new AlinmaCapitalStatementParser(new AssetCatalog);
    }

    public function test_a_well_formed_statement_parses_all_rows(): void
    {
        $result = $this->parser->parse(
            (string) file_get_contents(base_path('tests/fixtures/alinma-capital-holdings.csv')),
        );

        $this->assertSame([], $result['errors']);
        $this->assertCount(3, $result['rows']);
        $this->assertSame(['symbol' => '2222.SR', 'quantity' => 800.0, 'avg_cost' => 8.10], $result['rows'][0]);
    }

    public function test_malformed_rows_are_skipped_with_an_error(): void
    {
        $result = $this->parser->parse("symbol,quantity,avg_cost\n2222.SR,not-a-number,8.10\n7010.SR,500,10.40");

        $this->assertCount(1, $result['rows']);
        $this->assertSame('7010.SR', $result['rows'][0]['symbol']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_unknown_symbols_are_skipped_with_an_error(): void
    {
        $result = $this->parser->parse("symbol,quantity,avg_cost\nZZZZ.SR,100,5.00\n2222.SR,800,8.10");

        $this->assertCount(1, $result['rows']);
        $this->assertStringContainsString('ZZZZ.SR', $result['errors'][0]);
    }

    public function test_a_missing_required_column_fails_the_whole_file(): void
    {
        $result = $this->parser->parse("symbol,quantity\n2222.SR,800");

        $this->assertSame([], $result['rows']);
        $this->assertStringContainsString('avg_cost', $result['errors'][0]);
    }

    public function test_an_empty_file_reports_an_error(): void
    {
        $result = $this->parser->parse('');

        $this->assertSame([], $result['rows']);
        $this->assertNotEmpty($result['errors']);
    }
}
