<?php

use Illuminate\Support\Facades\Schedule;

// FX rates refresh once a day (the source publishes daily); the portfolio
// refresh runs after Tadawul and US markets have both closed (UTC).
Schedule::command('mahafeth:fetch-fx-rates')->dailyAt('03:30');
Schedule::command('mahafeth:refresh-portfolios')->dailyAt('04:00');
Schedule::command('queue:prune-failed --hours=168')->weekly();
