<?php
require_once __DIR__ . '/db.php';

$db = get_db();

// Defaults
$date_mode = $_GET['date_mode'] ?? 'full_season';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['to']   ?? date('Y-m-d');
$min_games = (int)($_GET['min_games'] ?? 5);
$use_min_games = isset($_GET['apply']) ? isset($_GET['use_min_games']) : false;

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

// Z-score calculation — exclude punted categories
$categories = array_values(array_diff($all_categories, $punt));

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
    <script>
        // Apply theme before render to prevent flash
        (function() {
            var saved = localStorage.getItem('theme');
            if (saved) document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
    <style>
        :root {
            --bg: #f5f5f5;
            --text: #333;
            --text-muted: #666;
            --text-faint: #888;
            --surface: #fff;
            --surface-alt: #f9f9f9;
            --hover: #e8edf5;
            --border: #e0e0e0;
            --shadow: rgba(0,0,0,0.1);
            --input-border: #ccc;
            --accent: #1d428a;
            --accent-hover: #163570;
            --accent-text: #fff;
            --z-pos: #2e7d32;
            --z-neg: #c62828;
            --punt-bg: #f0f2f5;
            --th-separator: rgba(255,255,255,0.3);
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --bg: #1a1a2e;
                --text: #e0e0e0;
                --text-muted: #aaa;
                --text-faint: #888;
                --surface: #16213e;
                --surface-alt: #1a2744;
                --hover: #1f2f50;
                --border: #2a3a5c;
                --shadow: rgba(0,0,0,0.3);
                --input-border: #3a4a6c;
                --accent: #5b8bd4;
                --accent-hover: #7aa3e0;
                --accent-text: #fff;
                --z-pos: #66bb6a;
                --z-neg: #ef5350;
                --punt-bg: #1a2744;
                --th-separator: rgba(255,255,255,0.15);
            }
        }
        :root[data-theme="dark"] {
            --bg: #1a1a2e;
            --text: #e0e0e0;
            --text-muted: #aaa;
            --text-faint: #888;
            --surface: #16213e;
            --surface-alt: #1a2744;
            --hover: #1f2f50;
            --border: #2a3a5c;
            --shadow: rgba(0,0,0,0.3);
            --input-border: #3a4a6c;
            --accent: #5b8bd4;
            --accent-hover: #7aa3e0;
            --accent-text: #fff;
            --z-pos: #66bb6a;
            --z-neg: #ef5350;
            --punt-bg: #1a2744;
            --th-separator: rgba(255,255,255,0.15);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); padding: 1.5rem; }
        h1 { margin-bottom: 0.25rem; }
        #theme-toggle { background: none; border: 1px solid var(--border); border-radius: 4px; cursor: pointer; font-size: 1.2rem; padding: 0.2rem 0.5rem; line-height: 1; color: var(--text); }
        #theme-toggle:hover { background: var(--hover); }
        .subtitle { color: var(--text-muted); margin-bottom: 1.5rem; }
        .controls { background: var(--surface); border-radius: 8px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px var(--shadow); display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; }
        .controls label { font-weight: 600; font-size: 0.9rem; }
        .controls input[type="date"], .controls input[type="number"] { padding: 0.4rem; font-size: 0.9rem; border: 1px solid var(--input-border); border-radius: 4px; background: var(--surface); color: var(--text); }
        .controls input[type="number"] { width: 60px; }
        .controls button { padding: 0.5rem 1.25rem; font-size: 0.9rem; background: var(--accent); color: var(--accent-text); border: none; border-radius: 4px; cursor: pointer; }
        .controls button:hover { background: var(--accent-hover); }
        .date-mode-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .date-mode-group > label { font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer; }
        .date-inputs { display: none; align-items: center; gap: 0.5rem; margin-top: 0.25rem; flex-wrap: wrap; }
        .date-inputs.open { display: flex; }
        .min-games-group { display: flex; align-items: center; gap: 0.5rem; }
        .punt-toggle { display: flex; align-items: center; gap: 0.5rem; }
        .punt-panel { display: none; gap: 0.75rem; flex-wrap: wrap; padding: 0.75rem 0 0; width: 100%; }
        .punt-panel.open { display: flex; }
        .punt-panel label { font-weight: 400; font-size: 0.85rem; display: flex; align-items: center; gap: 0.3rem; cursor: pointer; }
        .player-count { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px var(--shadow); font-size: 0.85rem; }
        th, td { padding: 0.4rem 0.6rem; text-align: center; white-space: nowrap; }
        th { background: var(--accent); color: var(--accent-text); font-size: 0.75rem; text-transform: uppercase; position: sticky; top: 0; }
        th.section-start { border-left: 2px solid var(--th-separator); }
        td.section-start { border-left: 2px solid var(--border); }
        tr:nth-child(even) { background: var(--surface-alt); }
        tr:hover { background: var(--hover); }
        td.player-name { text-align: left; font-weight: 500; }
        td.player-name a { color: var(--accent); text-decoration: none; }
        td.player-name a:hover { text-decoration: underline; }
        .z-pos { color: var(--z-pos); }
        .z-neg { color: var(--z-neg); }
        .z-total { font-weight: 700; font-size: 0.95rem; }
        .rank-col { color: var(--text-faint); font-weight: 600; }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: var(--accent-hover); }
        th.sortable::after { content: ' \2195'; opacity: 0.4; font-size: 0.7rem; }
        th.sort-asc::after { content: ' \2191'; opacity: 1; }
        th.sort-desc::after { content: ' \2193'; opacity: 1; }
    </style>
</head>
<body>
    <h1>NBA Z-Score Rankings</h1>
    <p class="subtitle">2025-26 Season &mdash; <?= count($categories) ?>-Category<?= !empty($punt) ? ' (punting ' . count($punt) . ')' : '' ?></p>

    <form class="controls" method="get" action="/">
        <input type="hidden" name="apply" value="1">
        <input type="hidden" id="date_mode_input" name="date_mode" value="<?= htmlspecialchars($date_mode) ?>">
        <div class="date-mode-group">
            <label><input type="checkbox" id="mode_full" <?= $date_mode !== 'selected_dates' ? 'checked' : '' ?>> Full Season</label>
            <label><input type="checkbox" id="mode_dates" <?= $date_mode === 'selected_dates' ? 'checked' : '' ?>> Selected Dates</label>
            <div class="date-inputs<?= $date_mode === 'selected_dates' ? ' open' : '' ?>" id="date_inputs">
                <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>">
                <span>to</span>
                <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>">
            </div>
        </div>
        <div class="min-games-group">
            <input type="checkbox" id="use_min_games" name="use_min_games" value="1" <?= $use_min_games ? 'checked' : '' ?>>
            <label for="use_min_games">Min games</label>
            <input type="number" name="min_games" value="<?= $min_games ?>" min="1" max="82">
        </div>
        <div class="punt-toggle">
            <input type="checkbox" id="punt_toggle" <?= !empty($punt) ? 'checked' : '' ?>>
            <label for="punt_toggle">Punt</label>
        </div>
        <button type="submit">Update</button>
        <button type="button" id="theme-toggle" title="Toggle dark mode" style="margin-left: auto;"></button>
        <div class="punt-panel <?= !empty($punt) ? 'open' : '' ?>">
            <?php
            $punt_labels = [
                'fg_impact' => 'FG%', 'ft_impact' => 'FT%', 'fg3m' => '3PM', 'pts' => 'PTS',
                'reb' => 'REB', 'ast' => 'AST', 'stl' => 'STL', 'blk' => 'BLK',
            ];
            foreach ($punt_labels as $val => $label): ?>
                <label>
                    <input type="checkbox" name="punt[]" value="<?= $val ?>" <?= in_array($val, $punt) ? 'checked' : '' ?>>
                    <?= $label ?>
                </label>
            <?php endforeach; ?>
        </div>
    </form>

    <p class="player-count"><?= count($players) ?> players</p>

    <?php if (!empty($players)): ?>
    <div style="overflow-x: auto;">
    <table>
        <?php
        // Column definitions for avg and z-score sections
        $avg_cols = [
            'fg_impact' => 'FG%', 'ft_impact' => 'FT%', 'fg3m' => '3PM', 'pts' => 'PTS',
            'reb' => 'REB', 'ast' => 'AST', 'stl' => 'STL', 'blk' => 'BLK',
        ];
        $z_cols = [
            'fg_impact' => 'zFG', 'ft_impact' => 'zFT', 'fg3m' => 'z3PM', 'pts' => 'zPTS',
            'reb' => 'zREB', 'ast' => 'zAST', 'stl' => 'zSTL', 'blk' => 'zBLK',
        ];
        $col = 0;
        ?>
        <thead>
            <tr>
                <th class="sortable" data-col="<?= $col++ ?>" data-type="num">#</th>
                <th class="sortable" data-col="<?= $col++ ?>" data-type="str">Player</th>
                <th class="sortable" data-col="<?= $col++ ?>" data-type="num">GP</th>
                <th class="sortable" data-col="<?= $col++ ?>" data-type="num">MIN</th>
                <?php $first_avg = true; foreach ($avg_cols as $key => $label):
                    if (in_array($key, $punt)) continue; ?>
                    <th class="sortable<?= $first_avg ? ' section-start' : '' ?>" data-col="<?= $col++ ?>" data-type="num"><?= $label ?></th>
                <?php $first_avg = false; endforeach; ?>
                <?php $first_z = true; foreach ($z_cols as $key => $label):
                    if (in_array($key, $punt)) continue; ?>
                    <th class="sortable<?= $first_z ? ' section-start' : '' ?>" data-col="<?= $col++ ?>" data-type="num"><?= $label ?></th>
                <?php $first_z = false; endforeach; ?>
                <th class="sortable section-start" data-col="<?= $col++ ?>" data-type="num">Z-Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Map category keys to their display value getters
            $avg_display = [
                'fg_impact' => fn($p) => $p['fg_pct'] . '%',
                'ft_impact' => fn($p) => $p['ft_pct'] . '%',
                'fg3m' => fn($p) => $p['fg3m'],
                'pts' => fn($p) => $p['pts'],
                'reb' => fn($p) => $p['reb'],
                'ast' => fn($p) => $p['ast'],
                'stl' => fn($p) => $p['stl'],
                'blk' => fn($p) => $p['blk'],
            ];
            foreach ($players as $p): ?>
            <tr>
                <td class="rank-col"><?= $p['rank'] ?></td>
                <td class="player-name">
                    <a href="/player.php?id=<?= $p['player_id'] ?>&from=<?= urlencode($date_from) ?>&to=<?= urlencode($date_to) ?>">
                        <?= htmlspecialchars($p['player_name']) ?>
                    </a>
                </td>
                <td><?= $p['gp'] ?></td>
                <td><?= $p['min'] ?></td>
                <?php $first_avg = true; foreach ($avg_display as $key => $getter):
                    if (in_array($key, $punt)) continue; ?>
                    <td<?= $first_avg ? ' class="section-start"' : '' ?>><?= $getter($p) ?></td>
                <?php $first_avg = false; endforeach; ?>
                <?php $first_z = true; foreach ($categories as $cat):  ?>
                    <td class="<?= $first_z ? 'section-start ' : '' ?><?= $p['z_'.$cat] >= 0 ? 'z-pos' : 'z-neg' ?>">
                        <?= $p['z_'.$cat] >= 0 ? '+' : '' ?><?= number_format($p['z_'.$cat], 2) ?>
                    </td>
                <?php $first_z = false; endforeach; ?>
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
    // Theme toggle
    var html = document.documentElement;
    var toggle = document.getElementById('theme-toggle');
    function isDark() {
        return html.getAttribute('data-theme') === 'dark' ||
            (!html.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }
    function updateIcon() { toggle.textContent = isDark() ? '\u2600\uFE0F' : '\uD83C\uDF19'; }
    updateIcon();
    toggle.addEventListener('click', function() {
        var next = isDark() ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        updateIcon();
    });

    // Date mode toggle
    var modeFull      = document.getElementById('mode_full');
    var modeDates     = document.getElementById('mode_dates');
    var dateInputs    = document.getElementById('date_inputs');
    var dateModeInput = document.getElementById('date_mode_input');
    function setMode(mode) {
        modeFull.checked  = (mode === 'full_season');
        modeDates.checked = (mode === 'selected_dates');
        dateInputs.classList.toggle('open', mode === 'selected_dates');
        dateModeInput.value = mode;
    }
    modeFull.addEventListener('change',  function() { setMode(this.checked ? 'full_season' : 'selected_dates'); });
    modeDates.addEventListener('change', function() { setMode(this.checked ? 'selected_dates' : 'full_season'); });

    // Punt panel toggle
    var puntToggle = document.getElementById('punt_toggle');
    var puntPanel = document.querySelector('.punt-panel');
    if (puntToggle && puntPanel) {
        puntToggle.addEventListener('change', function() {
            puntPanel.classList.toggle('open', this.checked);
            if (!this.checked) {
                puntPanel.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });
            }
        });
    }

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
