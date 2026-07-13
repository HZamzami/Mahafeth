<?php
$m = app(App\Services\Markets\YahooMarketMovers::class)->fetch();
if ($m === null) { echo "NULL\n"; exit; }
foreach ($m as $key => $list) {
    echo $key.': '.count($list)." — ".implode(', ', array_map(fn ($q) => $q['symbol'].' '.round($q['changePercent'] ?? 0, 1).'%', array_slice($list, 0, 3)))."\n";
}
