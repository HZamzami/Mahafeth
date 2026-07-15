<?php

namespace App\Services\Imports;

use App\Services\OpenBanking\AssetCatalog;

/**
 * Parses a brokerage holdings statement exported as CSV, from any broker —
 * Saudi capital-markets apps don't share a single format, so the parser is
 * deliberately forgiving. It handles a UTF-8 BOM, comma/semicolon/tab
 * delimiters, English or Arabic header names (and their common aliases),
 * headerless files, Arabic-Indic digits, thousands/decimal separators, and
 * currency symbols. Bare four-digit Saudi codes are matched to their .SR
 * symbol. Only symbol and quantity are required; average cost defaults to 0
 * so a positions-only statement still imports. Symbols must exist in the
 * asset catalog so metadata and the Shariah classification can be attached.
 */
class HoldingsStatementParser
{
    /**
     * Header names mapped to the canonical column, all lower-cased. The
     * first alias that appears in the header wins.
     *
     * @var array<string, list<string>>
     */
    private const COLUMN_ALIASES = [
        'symbol' => ['symbol', 'ticker', 'ticker symbol', 'code', 'stock code', 'stock', 'instrument', 'security', 'security code', 'isin', 'رمز', 'الرمز', 'رمز السهم', 'الرمز التعريفي'],
        'quantity' => ['quantity', 'qty', 'shares', 'no of shares', 'no. of shares', 'number of shares', 'units', 'holding', 'holdings', 'owned', 'الكمية', 'كمية', 'عدد الأسهم', 'الأسهم', 'عدد'],
        'avg_cost' => ['avg_cost', 'avg cost', 'average cost', 'avg. cost', 'avg price', 'average price', 'avg_price', 'unit cost', 'cost', 'cost price', 'purchase price', 'book cost', 'متوسط التكلفة', 'التكلفة', 'سعر التكلفة', 'متوسط السعر', 'سعر الشراء'],
    ];

    /**
     * @var array<string, string>
     */
    private const EASTERN_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function __construct(private AssetCatalog $assetCatalog) {}

    /**
     * @return array{
     *     rows: list<array{symbol: string, quantity: float, avg_cost: float}>,
     *     errors: list<string>
     * }
     */
    public function parse(string $contents): array
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $this->stripBom($contents)) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return ['rows' => [], 'errors' => [__('The file is empty.')]];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $firstRow = $this->fields($lines[0], $delimiter);
        $indexes = $this->resolveHeader($firstRow);

        if ($indexes !== null) {
            // Recognised header row — the rest of the file is data.
            $dataLines = array_slice($lines, 1);
        } else {
            // No recognisable header: fall back to symbol, quantity, avg_cost
            // by position. If the first row's quantity is not a number it is a
            // header we could not name, so skip it; otherwise it is data.
            if (count($firstRow) < 2) {
                return ['rows' => [], 'errors' => [__('Missing required column: :column', ['column' => 'symbol, quantity'])]];
            }

            $indexes = ['symbol' => 0, 'quantity' => 1] + (count($firstRow) >= 3 ? ['avg_cost' => 2] : []);
            $dataLines = $this->toNumber((string) ($firstRow[1] ?? '')) === null ? array_slice($lines, 1) : $lines;
        }

        $rows = [];
        $errors = [];

        foreach ($dataLines as $offset => $line) {
            $fields = $this->fields($line, $delimiter);
            $lineNumber = $offset + 2;

            $symbol = $this->normalizeSymbol((string) ($fields[$indexes['symbol']] ?? ''));
            $quantity = $this->toNumber((string) ($fields[$indexes['quantity']] ?? ''));

            if ($symbol === '' || $quantity === null) {
                $errors[] = __('Line :line is malformed and was skipped.', ['line' => $lineNumber]);

                continue;
            }

            if (! $this->assetCatalog->has($symbol)) {
                $errors[] = __('Line :line: unknown symbol :symbol was skipped.', ['line' => $lineNumber, 'symbol' => $symbol]);

                continue;
            }

            $avgCost = isset($indexes['avg_cost'])
                ? ($this->toNumber((string) ($fields[$indexes['avg_cost']] ?? '')) ?? 0.0)
                : 0.0;

            $rows[] = [
                'symbol' => $symbol,
                'quantity' => $quantity,
                'avg_cost' => $avgCost,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Map header cells to canonical column indexes, or null when neither the
     * symbol nor quantity column can be recognised.
     *
     * @param  list<string>  $cells
     * @return array{symbol: int, quantity: int, avg_cost?: int}|null
     */
    private function resolveHeader(array $cells): ?array
    {
        $header = array_map(fn (string $cell): string => strtolower(trim(str_replace(['"', "'"], '', $cell))), $cells);
        $indexes = [];

        foreach (self::COLUMN_ALIASES as $canonical => $aliases) {
            foreach ($header as $index => $cell) {
                if (in_array($cell, $aliases, true)) {
                    $indexes[$canonical] = $index;

                    break;
                }
            }
        }

        return isset($indexes['symbol'], $indexes['quantity']) ? $indexes : null;
    }

    /**
     * @return list<string>
     */
    private function fields(string $line, string $delimiter): array
    {
        // Escape args passed explicitly to avoid PHP 8.4's deprecation of the
        // default escape mechanism.
        return str_getcsv($line, $delimiter, '"', '\\');
    }

    private function detectDelimiter(string $headerLine): string
    {
        $counts = [
            ',' => substr_count($headerLine, ','),
            ';' => substr_count($headerLine, ';'),
            "\t" => substr_count($headerLine, "\t"),
        ];
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    private function normalizeSymbol(string $raw): string
    {
        $symbol = strtoupper(preg_replace('/\s+/', '', strtr(trim($raw), self::EASTERN_DIGITS)) ?? '');

        if ($symbol === '') {
            return '';
        }

        // A bare four-digit Tadawul code (e.g. 2222) resolves to its .SR symbol.
        if (! $this->assetCatalog->has($symbol) && preg_match('/^\d{4}$/', $symbol) && $this->assetCatalog->has($symbol.'.SR')) {
            return $symbol.'.SR';
        }

        return $symbol;
    }

    /**
     * Parse a numeric field tolerantly: Eastern digits, Arabic separators,
     * currency symbols, and thousands/decimal punctuation all normalise to a
     * float. Returns null when nothing numeric remains.
     */
    private function toNumber(string $raw): ?float
    {
        $value = strtr(trim($raw), self::EASTERN_DIGITS);
        $value = strtr($value, ['٫' => '.', '٬' => ',', '،' => ',']);
        $value = preg_replace('/[^0-9.,\-]/u', '', $value) ?? '';

        if ($value === '' || $value === '-') {
            return null;
        }

        $hasDot = str_contains($value, '.');
        $hasComma = str_contains($value, ',');

        if ($hasDot && $hasComma) {
            $value = str_replace(',', '', $value); // comma is the thousands separator
        } elseif ($hasComma) {
            $parts = explode(',', $value);
            $value = count($parts) === 2 && strlen($parts[1]) > 0 && strlen($parts[1]) <= 2
                ? $parts[0].'.'.$parts[1] // decimal comma (e.g. 8,10)
                : str_replace(',', '', $value); // thousands (e.g. 1,200)
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function stripBom(string $contents): string
    {
        foreach (["\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($contents, $bom)) {
                return substr($contents, strlen($bom));
            }
        }

        return $contents;
    }
}
