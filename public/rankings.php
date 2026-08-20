<?php
require_once __DIR__ . '/db.php';

$db = get_db();

// Defaults
$date_mode = $_GET['date_mode'] ?? 'full_season';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['to']   ?? date('Y-m-d');
$min_games = (int)($_GET['min_games'] ?? 10);
$use_min_games = isset($_GET['apply']) ? isset($_GET['use_min_games']) : false;
$calc_mode = $_GET['calc_mode'] ?? 'strict';

// Salary settings
$use_salary = isset($_GET['apply']) ? isset($_GET['use_salary']) : false;
$salary_teams = (int)($_GET['salary_teams'] ?? 30);
$salary_roster = (int)($_GET['salary_roster'] ?? 12);
$salary_cap = (int)($_GET['salary_cap'] ?? 84000000);
$salary_pct_max = (int)($_GET['salary_pct_max'] ?? 40);
$salary_floor = (int)($_GET['salary_floor'] ?? 600000);
$salary_round = (int)($_GET['salary_round'] ?? 100000);
$salary_ranked = isset($_GET['apply']) ? isset($_GET['salary_ranked']) : false;

// Punt categories
$all_categories = ['fg_impact', 'ft_impact', 'fg3m', 'pts', 'reb', 'ast', 'stl', 'blk'];
$punt = isset($_GET['punt']) && is_array($_GET['punt'])
    ? array_intersect($_GET['punt'], $all_categories)
    : [];

// Fetch per-player aggregates in the date range
$where_date = $date_mode === 'selected_dates'
    ? 'AND g.game_date >= :date_from AND g.game_date <= :date_to'
    : '';
$stmt = $db->prepare("
    SELECT
        p.player_id,
        p.player_name,
        COUNT(*) AS gp,
        SUM(g.min) AS total_min,
        SUM(g.pts) AS total_pts,
        SUM(g.reb) AS total_reb,
        SUM(g.ast) AS total_ast,
        SUM(g.stl) AS total_stl,
        SUM(g.blk) AS total_blk,
        SUM(g.fg3m) AS total_fg3m,
        SUM(g.fgm) AS total_fgm,
        SUM(g.fga) AS total_fga,
        SUM(g.ftm) AS total_ftm,
        SUM(g.fta) AS total_fta
    FROM game_logs g
    JOIN players p ON p.player_id = g.player_id
    WHERE 1=1 $where_date
    GROUP BY g.player_id
");
$params = [];
if ($date_mode === 'selected_dates') {
    $params[':date_from'] = $date_from;
    $params[':date_to']   = $date_to;
}
$stmt->execute($params);
$raw = $stmt->fetchAll();

// Filter by minimum games if toggled
$players = [];
foreach ($raw as $r) {
    if ($use_min_games && $r['gp'] < $min_games) continue;
    $gp = $r['gp'];
    $players[] = [
        'player_id'   => $r['player_id'],
        'player_name' => $r['player_name'],
        'gp'          => $gp,
        'min'         => round($r['total_min'] / $gp, 1),
        'pts'         => round($r['total_pts'] / $gp, 1),
        'reb'         => round($r['total_reb'] / $gp, 1),
        'ast'         => round($r['total_ast'] / $gp, 1),
        'stl'         => round($r['total_stl'] / $gp, 1),
        'blk'         => round($r['total_blk'] / $gp, 1),
        'fg3m'        => round($r['total_fg3m'] / $gp, 1),
        // Keep totals for impact calc
        'total_fgm'   => (int)$r['total_fgm'],
        'total_fga'   => (int)$r['total_fga'],
        'total_ftm'   => (int)$r['total_ftm'],
        'total_fta'   => (int)$r['total_fta'],
    ];
}

// Compute league averages for FG% and FT% (across all players in set)
$league_fgm = array_sum(array_column($players, 'total_fgm'));
$league_fga = array_sum(array_column($players, 'total_fga'));
$league_ftm = array_sum(array_column($players, 'total_ftm'));
$league_fta = array_sum(array_column($players, 'total_fta'));
$league_fg_pct = $league_fga > 0 ? $league_fgm / $league_fga : 0;
$league_ft_pct = $league_fta > 0 ? $league_ftm / $league_fta : 0;

// Add impact values and per-game display percentages
foreach ($players as &$p) {
    $p['fg_impact'] = $p['total_fgm'] - ($p['total_fga'] * $league_fg_pct);
    $p['ft_impact'] = $p['total_ftm'] - ($p['total_fta'] * $league_ft_pct);
    $p['fg_pct'] = $p['total_fga'] > 0 ? round($p['total_fgm'] / $p['total_fga'] * 100, 1) : 0;
    $p['ft_pct'] = $p['total_fta'] > 0 ? round($p['total_ftm'] / $p['total_fta'] * 100, 1) : 0;
}
unset($p);

// Z-score calculation -- exclude punted categories
$categories = array_values(array_diff($all_categories, $punt));

function format_salary(int $value): string {
    if ($value >= 1000000) {
        $m = $value / 1000000;
        return '$' . ($m == (int)$m ? number_format($m, 0) : number_format($m, 1)) . 'M';
    } elseif ($value >= 1000) {
        $k = $value / 1000;
        return '$' . ($k == (int)$k ? number_format($k, 0) : number_format($k, 1)) . 'K';
    }
    return '$' . number_format($value);
}

function calc_mean_std(array $values): array {
    $n = count($values);
    if ($n === 0) return ['mean' => 0, 'std' => 1];
    $mean = array_sum($values) / $n;
    $variance = 0;
    foreach ($values as $v) {
        $variance += ($v - $mean) ** 2;
    }
    $std = $n > 1 ? sqrt($variance / ($n - 1)) : 1;
    if ($std == 0) $std = 1;
    return ['mean' => $mean, 'std' => $std];
}

function calc_median_iqr(array $values): array {
    sort($values);
    $n = count($values);
    if ($n === 0) return ['median' => 0, 'iqr' => 1];
    $median = $values[intdiv($n, 2)];
    $q1 = $values[intdiv($n, 4)];
    $q3 = $values[intdiv(3 * $n, 4)];
    $iqr = $q3 - $q1;
    if ($iqr == 0) $iqr = 1;
    return ['median' => $median, 'iqr' => $iqr];
}

$stats = [];
foreach ($categories as $cat) {
    $col = array_column($players, $cat);
    $stats[$cat] = calc_mean_std($col);
    $stats[$cat] += calc_median_iqr($col);
    $stats[$cat]['min'] = min($col);
    $stats[$cat]['max'] = max($col);
    $sorted = $col;
    sort($sorted);
    $stats[$cat]['p60'] = $sorted[(int)(count($sorted) * 0.60)];
}

foreach ($players as &$p) {
    $total_z = 0;
    foreach ($categories as $cat) {
        $s = $stats[$cat];
        if ($calc_mode === 'minmax') {
            $range = $s['max'] - $s['min'];
            $z = $range > 0 ? ($p[$cat] - $s['min']) / $range * 2 - 1 : 0;
        } elseif ($calc_mode === 'minmax_median') {
            $center = $s['p60'];
            $above = $s['max'] - $center;
            $below = $center - $s['min'];
            $scale = max($above, $below);
            $z = $scale > 0 ? ($p[$cat] - $center) / $scale : 0;
        } elseif ($calc_mode === 'combined') {
            $z = ($p[$cat] - $s['median']) / $s['iqr'];
            $z = max(-3, min(3, $z));
        } else {
            $z = ($p[$cat] - $s['mean']) / $s['std'];
        }
        $p['z_' . $cat] = round($z, 2);
        $total_z += $z;
    }
    $p['z_total'] = round($total_z, 2);
}
unset($p);

// Sort by total z-score descending
usort($players, fn($a, $b) => $b['z_total'] <=> $a['z_total']);

// Assign rank
$rank = 1;
foreach ($players as &$p) {
    $p['rank'] = $rank++;
}
unset($p);

// Salary calculation (power curve distribution)
if ($use_salary) {
    $pool_size = $salary_teams * $salary_roster;
    $max_salary = $salary_cap * ($salary_pct_max / 100);
    $min_salary = $salary_floor;
    $log_k = 9;
    $log_denom = log(1 + $log_k);

    if ($salary_ranked) {
        foreach ($players as &$p) {
            if ($pool_size <= 1) {
                $p['salary'] = $max_salary;
            } elseif ($p['rank'] <= $pool_size) {
                $pct = ($pool_size - $p['rank']) / ($pool_size - 1);
                $scaled = log(1 + $pct * $log_k) / $log_denom;
                $p['salary'] = $min_salary + ($max_salary - $min_salary) * $scaled;
            } else {
                $p['salary'] = $min_salary;
            }
            $p['salary'] = $salary_round > 0 ? (int)(round($p['salary'] / $salary_round) * $salary_round) : (int)round($p['salary']);
        }
        unset($p);
    } else {
        $pool_players = array_slice($players, 0, $pool_size);
        $z_max = $pool_players[0]['z_total'];
        $z_min = end($pool_players)['z_total'];
        $z_range = $z_max - $z_min;

        foreach ($players as &$p) {
            if ($z_range <= 0) {
                $p['salary'] = $max_salary;
            } elseif ($p['rank'] <= $pool_size) {
                $pct = max(0, ($p['z_total'] - $z_min) / $z_range);
                $scaled = log(1 + $pct * $log_k) / $log_denom;
                $p['salary'] = $min_salary + ($max_salary - $min_salary) * $scaled;
            } else {
                $p['salary'] = $min_salary;
            }
            $p['salary'] = $salary_round > 0 ? (int)(round($p['salary'] / $salary_round) * $salary_round) : (int)round($p['salary']);
        }
        unset($p);
    }
}
