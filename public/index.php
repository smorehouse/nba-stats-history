<?php
require_once __DIR__ . '/db.php';

$db = get_db();

// Defaults
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['to'] ?? date('Y-m-d');
$min_games = (int)($_GET['min_games'] ?? 5);
$use_min_games = isset($_GET['apply']) ? isset($_GET['use_min_games']) : true;

// Fetch per-player aggregates in the date range
$stmt = $db->prepare('
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
    WHERE g.game_date >= :date_from
      AND g.game_date <= :date_to
    GROUP BY g.player_id
');
$stmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
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

// Z-score calculation
// Categories: pts, reb, ast, stl, blk, fg3m, fg_impact, ft_impact
$categories = ['pts', 'reb', 'ast', 'stl', 'blk', 'fg3m', 'fg_impact', 'ft_impact'];

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

$stats = [];
foreach ($categories as $cat) {
    $stats[$cat] = calc_mean_std(array_column($players, $cat));
}

foreach ($players as &$p) {
    $total_z = 0;
    foreach ($categories as $cat) {
        $z = ($p[$cat] - $stats[$cat]['mean']) / $stats[$cat]['std'];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NBA Stats History &mdash; Z-Score Rankings</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; color: #333; padding: 1.5rem; }
        h1 { margin-bottom: 0.25rem; }
        .subtitle { color: #666; margin-bottom: 1.5rem; }
        .controls { background: white; border-radius: 8px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; }
        .controls label { font-weight: 600; font-size: 0.9rem; }
        .controls input[type="date"], .controls input[type="number"] { padding: 0.4rem; font-size: 0.9rem; border: 1px solid #ccc; border-radius: 4px; }
        .controls input[type="number"] { width: 60px; }
        .controls button { padding: 0.5rem 1.25rem; font-size: 0.9rem; background: #1d428a; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .controls button:hover { background: #163570; }
        .min-games-group { display: flex; align-items: center; gap: 0.5rem; }
        .player-count { color: #666; font-size: 0.9rem; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); font-size: 0.85rem; }
        th, td { padding: 0.4rem 0.6rem; text-align: center; white-space: nowrap; }
        th { background: #1d428a; color: white; font-size: 0.75rem; text-transform: uppercase; position: sticky; top: 0; }
        th.section-start { border-left: 2px solid rgba(255,255,255,0.3); }
        td.section-start { border-left: 2px solid #e0e0e0; }
        tr:nth-child(even) { background: #f9f9f9; }
        tr:hover { background: #e8edf5; }
        td.player-name { text-align: left; font-weight: 500; }
        td.player-name a { color: #1d428a; text-decoration: none; }
        td.player-name a:hover { text-decoration: underline; }
        .z-pos { color: #2e7d32; }
        .z-neg { color: #c62828; }
        .z-total { font-weight: 700; font-size: 0.95rem; }
        .rank-col { color: #888; font-weight: 600; }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: #163570; }
        th.sortable::after { content: ' \2195'; opacity: 0.4; font-size: 0.7rem; }
        th.sort-asc::after { content: ' \2191'; opacity: 1; }
        th.sort-desc::after { content: ' \2193'; opacity: 1; }
    </style>
</head>
<body>
    <h1>NBA Z-Score Rankings</h1>
    <p class="subtitle">2025-26 Season &mdash; 8-Category</p>

    <form class="controls" method="get" action="/">
        <input type="hidden" name="apply" value="1">
        <div>
            <label for="from">From</label><br>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        <div>
            <label for="to">To</label><br>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <div class="min-games-group">
            <input type="checkbox" id="use_min_games" name="use_min_games" value="1" <?= $use_min_games ? 'checked' : '' ?>>
            <label for="use_min_games">Min games</label>
            <input type="number" name="min_games" value="<?= $min_games ?>" min="1" max="82">
        </div>
        <button type="submit">Update</button>
    </form>

    <p class="player-count"><?= count($players) ?> players</p>

    <?php if (!empty($players)): ?>
    <div style="overflow-x: auto;">
    <table>
        <thead>
            <tr>
                <th class="sortable" data-col="0" data-type="num">#</th>
                <th class="sortable" data-col="1" data-type="str">Player</th>
                <th class="sortable" data-col="2" data-type="num">GP</th>
                <th class="sortable" data-col="3" data-type="num">MIN</th>
                <th class="sortable section-start" data-col="4" data-type="num">PTS</th>
                <th class="sortable" data-col="5" data-type="num">REB</th>
                <th class="sortable" data-col="6" data-type="num">AST</th>
                <th class="sortable" data-col="7" data-type="num">STL</th>
                <th class="sortable" data-col="8" data-type="num">BLK</th>
                <th class="sortable" data-col="9" data-type="num">3PM</th>
                <th class="sortable" data-col="10" data-type="num">FG%</th>
                <th class="sortable" data-col="11" data-type="num">FT%</th>
                <th class="sortable section-start" data-col="12" data-type="num">zPTS</th>
                <th class="sortable" data-col="13" data-type="num">zREB</th>
                <th class="sortable" data-col="14" data-type="num">zAST</th>
                <th class="sortable" data-col="15" data-type="num">zSTL</th>
                <th class="sortable" data-col="16" data-type="num">zBLK</th>
                <th class="sortable" data-col="17" data-type="num">z3PM</th>
                <th class="sortable" data-col="18" data-type="num">zFG</th>
                <th class="sortable" data-col="19" data-type="num">zFT</th>
                <th class="sortable section-start" data-col="20" data-type="num">Z-Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($players as $p): ?>
            <tr>
                <td class="rank-col"><?= $p['rank'] ?></td>
                <td class="player-name">
                    <a href="/player.php?id=<?= $p['player_id'] ?>&from=<?= urlencode($date_from) ?>&to=<?= urlencode($date_to) ?>">
                        <?= htmlspecialchars($p['player_name']) ?>
                    </a>
                </td>
                <td><?= $p['gp'] ?></td>
                <td><?= $p['min'] ?></td>
                <td class="section-start"><?= $p['pts'] ?></td>
                <td><?= $p['reb'] ?></td>
                <td><?= $p['ast'] ?></td>
                <td><?= $p['stl'] ?></td>
                <td><?= $p['blk'] ?></td>
                <td><?= $p['fg3m'] ?></td>
                <td><?= $p['fg_pct'] ?>%</td>
                <td><?= $p['ft_pct'] ?>%</td>
                <?php foreach (['pts','reb','ast','stl','blk','fg3m','fg_impact','ft_impact'] as $i => $cat): ?>
                    <td class="<?= $i === 0 ? 'section-start ' : '' ?><?= $p['z_'.$cat] >= 0 ? 'z-pos' : 'z-neg' ?>">
                        <?= $p['z_'.$cat] >= 0 ? '+' : '' ?><?= number_format($p['z_'.$cat], 2) ?>
                    </td>
                <?php endforeach; ?>
                <td class="section-start z-total <?= $p['z_total'] >= 0 ? 'z-pos' : 'z-neg' ?>">
                    <?= $p['z_total'] >= 0 ? '+' : '' ?><?= number_format($p['z_total'], 2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
        <p style="color: #888; font-style: italic;">No data for this date range.</p>
    <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const table = document.querySelector('table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const headers = table.querySelectorAll('th.sortable');
    let currentCol = null;
    let ascending = false;

    headers.forEach(function(th) {
        th.addEventListener('click', function() {
            const col = parseInt(th.dataset.col);
            const type = th.dataset.type;

            if (currentCol === col) {
                ascending = !ascending;
            } else {
                ascending = type === 'str'; // strings default asc, numbers default desc
                currentCol = col;
            }

            headers.forEach(function(h) { h.classList.remove('sort-asc', 'sort-desc'); });
            th.classList.add(ascending ? 'sort-asc' : 'sort-desc');

            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort(function(a, b) {
                let aVal = a.cells[col].textContent.trim();
                let bVal = b.cells[col].textContent.trim();

                if (type === 'num') {
                    aVal = parseFloat(aVal.replace('%', '').replace('+', '')) || 0;
                    bVal = parseFloat(bVal.replace('%', '').replace('+', '')) || 0;
                    return ascending ? aVal - bVal : bVal - aVal;
                } else {
                    return ascending
                        ? aVal.localeCompare(bVal)
                        : bVal.localeCompare(aVal);
                }
            });

            rows.forEach(function(row) { tbody.appendChild(row); });
        });
    });
});
</script>
</body>
</html>
