<?php

use Illuminate\Support\Facades\Schedule;

// FX rates refresh once a day (the source publishes daily); the portfolio
// refresh runs after Tadawul and US markets have both closed (UTC).
Schedule::command('mahafeth:purge-demo-accounts')->dailyAt('03:15');
Schedule::command('mahafeth:fetch-fx-rates')->dailyAt('03:30');
Schedule::command('mahafeth:expire-consents')->dailyAt('03:45');
Schedule::command('mahafeth:refresh-portfolios')->dailyAt('04:00');
Schedule::command('mahafeth:refresh-news')->everySixHours();
Schedule::command('mahafeth:refresh-filings')->dailyAt('05:00');
Schedule::command('queue:prune-failed --hours=168')->weekly();

// Sunday opens the Saudi trading week; the digest lands after that
// morning's refresh has produced a fresh snapshot.
Schedule::command('mahafeth:send-weekly-digest')->weeklyOn(0, '05:10');
