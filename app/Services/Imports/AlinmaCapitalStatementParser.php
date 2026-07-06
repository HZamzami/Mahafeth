<?php

namespace App\Services\Imports;

use App\Services\OpenBanking\AssetCatalog;

/**
 * Parses an Alinma Capital holdings statement exported as CSV. Expected
 * columns (header row required, extra columns ignored): symbol, quantity,
 * avg_cost. Symbols must exist in the asset catalog so metadata and the
 * Shariah classification can be attached.
 */
class AlinmaCapitalStatementParser
{
    private const REQUIRED_COLUMNS = ['symbol', 'quantity', 'avg_cost'];

    public function __construct(private AssetCatalog $assetCatalog) {}

    /**
     * @return array{
     *     rows: list<array{symbol: string, quantity: float, avg_cost: float}>,
     *     errors: list<string>
     * }
     */
    public function parse(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];

        if ($lines === [] || $lines[0] === '') {
            return ['rows' => [], 'errors' => [__('The file is empty.')]];
        }

        $header = array_map(fn (string $column): string => strtolower(trim($column)), str_getcsv(array_shift($lines)));
        $indexes = [];

        foreach (self::REQUIRED_COLUMNS as $column) {
            $index = array_search($column, $header, true);

            if ($index === false) {
                return ['rows' => [], 'errors' => [__('Missing required column: :column', ['column' => $column])]];
            }

            $indexes[$column] = $index;
        }

        $rows = [];
        $errors = [];

        foreach ($lines as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = str_getcsv($line);
            $symbol = strtoupper(trim($fields[$indexes['symbol']] ?? ''));
            $quantity = trim($fields[$indexes['quantity']] ?? '');
            $avgCost = trim($fields[$indexes['avg_cost']] ?? '');

            if ($symbol === '' || ! is_numeric($quantity) || ! is_numeric($avgCost)) {
                $errors[] = __('Line :line is malformed and was skipped.', ['line' => $number + 2]);

                continue;
            }

            if (! $this->assetCatalog->has($symbol)) {
                $errors[] = __('Line :line: unknown symbol :symbol was skipped.', ['line' => $number + 2, 'symbol' => $symbol]);

                continue;
            }

            $rows[] = [
                'symbol' => $symbol,
                'quantity' => (float) $quantity,
                'avg_cost' => (float) $avgCost,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }
}
