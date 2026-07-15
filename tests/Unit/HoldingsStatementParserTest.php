<?php

namespace Tests\Unit;

use App\Services\Imports\HoldingsStatementParser;
use App\Services\OpenBanking\AssetCatalog;
use Tests\TestCase;

class HoldingsStatementParserTest extends TestCase
{
    private HoldingsStatementParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new HoldingsStatementParser(new AssetCatalog);
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

    public function test_average_cost_is_optional_and_defaults_to_zero(): void
    {
        $result = $this->parser->parse("symbol,quantity\n2222.SR,800");

        $this->assertSame([], $result['errors']);
        $this->assertSame(['symbol' => '2222.SR', 'quantity' => 800.0, 'avg_cost' => 0.0], $result['rows'][0]);
    }

    public function test_an_empty_file_reports_an_error(): void
    {
        $result = $this->parser->parse('');

        $this->assertSame([], $result['rows']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_a_utf8_bom_is_stripped_so_the_header_still_matches(): void
    {
        $result = $this->parser->parse("\xEF\xBB\xBFsymbol,quantity,avg_cost\n2222.SR,800,8.10");

        $this->assertSame([], $result['errors']);
        $this->assertSame('2222.SR', $result['rows'][0]['symbol']);
    }

    public function test_semicolon_delimited_files_are_supported(): void
    {
        $result = $this->parser->parse("symbol;quantity;avg_cost\n2222.SR;800;8,10");

        $this->assertSame([], $result['errors']);
        $this->assertSame(800.0, $result['rows'][0]['quantity']);
        $this->assertSame(8.10, $result['rows'][0]['avg_cost']);
    }

    public function test_header_aliases_are_recognised(): void
    {
        $result = $this->parser->parse("Ticker,Qty,Avg Cost\n2222.SR,800,8.10");

        $this->assertSame([], $result['errors']);
        $this->assertSame('2222.SR', $result['rows'][0]['symbol']);
        $this->assertSame(800.0, $result['rows'][0]['quantity']);
    }

    public function test_bare_saudi_codes_resolve_to_their_sr_symbol(): void
    {
        $result = $this->parser->parse("symbol,quantity,avg_cost\n2222,800,8.10");

        $this->assertSame('2222.SR', $result['rows'][0]['symbol']);
    }

    public function test_thousands_separators_and_currency_symbols_are_tolerated(): void
    {
        $result = $this->parser->parse("symbol,quantity,avg_cost\n2222.SR,\"1,200\",\"8.10 SAR\"");

        $this->assertSame(1200.0, $result['rows'][0]['quantity']);
        $this->assertSame(8.10, $result['rows'][0]['avg_cost']);
    }

    public function test_eastern_arabic_digits_are_parsed(): void
    {
        $result = $this->parser->parse("symbol,quantity,avg_cost\n2222.SR,٨٠٠,٨٫١٠");

        $this->assertSame(800.0, $result['rows'][0]['quantity']);
        $this->assertSame(8.10, $result['rows'][0]['avg_cost']);
    }

    public function test_a_headerless_file_is_read_positionally(): void
    {
        $result = $this->parser->parse("2222.SR,800,8.10\n7010.SR,500,10.40");

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('2222.SR', $result['rows'][0]['symbol']);
    }
}
