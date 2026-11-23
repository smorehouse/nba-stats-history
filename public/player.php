<?php
require_once __DIR__ . '/db.php';

$db = get_db();

$player_id = (int)($_GET['id'] ?? 0);
$date_from = $_GET['from'] ?? null;
$date_to = $_GET['to'] ?? null;
$full_season = !$date_from && !$date_to;
$has_game_score = has_column($db, 'game_logs', 'game_score');

// Get player info
$stmt = $db->prepare('SELECT player_name FROM players WHERE player_id = :id');
$stmt->execute([':id' => $player_id]);
$player = $stmt->fetch();

if (!$player) {
    die('Player not found.');
}

// Get game logs, optionally filtered by date range
if ($full_season) {
    $stmt = $db->prepare('
        SELECT * FROM game_logs
        WHERE player_id = :id
        ORDER BY game_date DESC
    ');
    $stmt->execute([':id' => $player_id]);
} else {
    $stmt = $db->prepare('
        SELECT * FROM game_logs
        WHERE player_id = :id
          AND game_date >= :date_from
          AND game_date <= :date_to
        ORDER BY game_date DESC
    ');
    $stmt->execute([
        ':id' => $player_id,
        ':date_from' => $date_from,
        ':date_to' => $date_to,
    ]);
}
$games = $stmt->fetchAll();

// Compute averages
$totals = ['pts' => 0, 'reb' => 0, 'ast' => 0, 'stl' => 0, 'blk' => 0, 'tov' => 0,
           'fgm' => 0, 'fga' => 0, 'fg3m' => 0, 'fg3a' => 0, 'ftm' => 0, 'fta' => 0,
           'min' => 0, 'plus_minus' => 0];
$count = count($games);

foreach ($games as $g) {
    foreach ($totals as $key => &$val) {
        $val += (int)$g[$key];
    }
    unset($val);
}

$avgs = [];
if ($count > 0) {
    foreach ($totals as $key => $val) {
        $avgs[$key] = round($val / $count, 1);
    }
    $avgs['fg_pct'] = $totals['fga'] > 0 ? round($totals['fgm'] / $totals['fga'] * 100, 1) : 0;
    $avgs['fg3_pct'] = $totals['fg3a'] > 0 ? round($totals['fg3m'] / $totals['fg3a'] * 100, 1) : 0;
    $avgs['ft_pct'] = $totals['fta'] > 0 ? round($totals['ftm'] / $totals['fta'] * 100, 1) : 0;
    if ($has_game_score) {
        $avgs['game_score'] = round(array_sum(array_column($games, 'game_score')) / $count, 1);
    }
}

// Back link: restore the rankings filters passed along from the rankings page.
// Re-encoding via parse_str/http_build_query keeps it a plain query string on "/".
if (isset($_GET['back']) && is_string($_GET['back'])) {
    parse_str($_GET['back'], $back_query);
    // Match the form's encoding: punt[]=x rather than punt[0]=x
    $back_params = preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($back_query));
} else {
    $back_params = $full_season ? '' : http_build_query(['date_mode' => 'selected_dates', 'from' => $date_from, 'to' => $date_to]);
}

// Game score chart: x-axis spans the selected range, or the season so far for full season
$chart = null;
if ($has_game_score && $count > 0) {
    if ($full_season) {
        $range = $db->query('SELECT MIN(game_date) AS lo, MAX(game_date) AS hi FROM game_logs')->fetch();
        [$chart_from, $chart_to] = [$range['lo'], $range['hi']];
    } else {
        [$chart_from, $chart_to] = [$date_from, $date_to];
    }
    $chart = [
        'from'  => $chart_from,
        'to'    => $chart_to,
        'avg'   => $avgs['game_score'],
        'repl'  => 10, // replacement level for a 30-team x 12-man league
        'games' => array_map(fn($g) => [
            'id'      => $g['game_id'],
            'date'    => $g['game_date'],
            'matchup' => $g['matchup'],
            'wl'      => $g['wl'],
            'gs'      => round((float)$g['game_score'], 1),
        ], array_reverse($games)),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($player['player_name']) ?> &mdash; NBA Stats History</title>
    <script>
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
            --accent: #1d428a;
            --accent-hover: #163570;
            --accent-text: #fff;
            --z-pos: #2e7d32;
            --z-neg: #c62828;
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
                --accent: #5b8bd4;
                --accent-hover: #7aa3e0;
                --accent-text: #fff;
                --z-pos: #66bb6a;
                --z-neg: #ef5350;
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
            --accent: #5b8bd4;
            --accent-hover: #7aa3e0;
            --accent-text: #fff;
            --z-pos: #66bb6a;
            --z-neg: #ef5350;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); padding: 2rem; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.25rem; }
        h1 { margin-bottom: 0; }
        #theme-toggle { background: none; border: 1px solid var(--border); border-radius: 4px; cursor: pointer; font-size: 1.2rem; padding: 0.2rem 0.5rem; line-height: 1; color: var(--text); }
        #theme-toggle:hover { background: var(--hover); }
        .subtitle { color: var(--text-muted); margin-bottom: 1.5rem; }
        .averages { background: var(--surface); border-radius: 8px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; gap: 2rem; flex-wrap: wrap; box-shadow: 0 1px 3px var(--shadow); }
        .avg-stat { text-align: center; }
        .avg-stat .value { font-size: 1.5rem; font-weight: 700; color: var(--accent); }
        .avg-stat .label { font-size: 0.8rem; color: var(--text-faint); text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px var(--shadow); }
        th, td { padding: 0.5rem 0.75rem; text-align: center; }
        th { background: var(--accent); color: var(--accent-text); font-size: 0.85rem; text-transform: uppercase; }
        tr:nth-child(even) { background: var(--surface-alt); }
        tr:hover { background: var(--hover); }
        td { font-size: 0.9rem; }
        .win { color: var(--z-pos); font-weight: 600; }
        .loss { color: var(--z-neg); font-weight: 600; }
        .empty { color: var(--text-faint); font-style: italic; margin-top: 1rem; }
        .site-footer { margin: 24px 0 8px; text-align: center; font-size: 13px; color: var(--text-faint); }
        .chart-card { position: relative; background: var(--surface); border-radius: 8px; padding: 0.75rem 1rem 0.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px var(--shadow); }
        .chart-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.25rem; }
        .chart-title { font-size: 0.9rem; font-weight: 600; color: var(--text); }
        .chart-toggle { background: none; border: 1px solid var(--border); border-radius: 4px; color: var(--text-muted); cursor: pointer; font-size: 1rem; line-height: 1; padding: 0.15rem 0.45rem; }
        .chart-toggle:hover { background: var(--hover); color: var(--text); }
        .chart-show { background: none; border: 1px solid var(--border); border-radius: 4px; color: var(--text-muted); cursor: pointer; font-size: 0.85rem; padding: 0.3rem 0.7rem; margin-bottom: 1rem; }
        .chart-show:hover { background: var(--hover); color: var(--text); }
        #gs-chart svg { display: block; width: 100%; }
        .gs-grid { stroke: var(--border); stroke-width: 1; }
        .gs-axis-text { fill: var(--text-muted); font-size: 11px; }
        .gs-ref { stroke: var(--text-faint); stroke-width: 1; }
        .gs-ref-avg { stroke: var(--text-muted); }
        .gs-ref-text { fill: var(--text-muted); font-size: 11px; }
        .gs-line { fill: none; stroke: var(--accent); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
        .gs-dot { fill: var(--accent); stroke: var(--surface); stroke-width: 2; pointer-events: none; }
        .gs-dot.active { r: 6; }
        .gs-crosshair { stroke: var(--text-faint); stroke-width: 1; pointer-events: none; }
        .chart-tip { position: absolute; pointer-events: none; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; box-shadow: 0 2px 6px var(--shadow); padding: 0.35rem 0.55rem; font-size: 0.8rem; white-space: nowrap; color: var(--text-muted); }
        .chart-tip strong { color: var(--text); font-size: 0.95rem; }
        tr.gs-hl { background: var(--hover); }
        .site-footer a { color: var(--text-muted); }
    </style>
</head>
<body>
    <p><a href="/<?= $back_params !== '' ? '?' . htmlspecialchars($back_params) : '' ?>">&larr; Back to rankings</a></p>
    <div class="header">
        <h1><?= htmlspecialchars($player['player_name']) ?></h1>
        <button id="theme-toggle" title="Toggle dark mode"></button>
    </div>
    <p class="subtitle"><?= $full_season ? 'Full Season' : "$date_from to $date_to" ?> (<?= $count ?> games)</p>

    <?php if ($count > 0): ?>
        <div class="averages">
            <?php if ($has_game_score): ?>
            <div class="avg-stat"><div class="value"><?= number_format($avgs['game_score'], 1) ?></div><div class="label">GmSc</div></div>
            <?php endif; ?>
            <div class="avg-stat"><div class="value"><?= $avgs['pts'] ?></div><div class="label">PPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['reb'] ?></div><div class="label">RPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['ast'] ?></div><div class="label">APG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['stl'] ?></div><div class="label">SPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['blk'] ?></div><div class="label">BPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['fg_pct'] ?>%</div><div class="label">FG%</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['fg3_pct'] ?>%</div><div class="label">3P%</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['ft_pct'] ?>%</div><div class="label">FT%</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['min'] ?></div><div class="label">MPG</div></div>
            <div class="avg-stat"><div class="value"><?= $avgs['plus_minus'] > 0 ? '+' : '' ?><?= $avgs['plus_minus'] ?></div><div class="label">+/-</div></div>
        </div>

        <?php if ($chart): ?>
        <div class="chart-card" id="gs-chart-card">
            <div class="chart-head">
                <h2 class="chart-title">Game score by game</h2>
                <button type="button" class="chart-toggle" id="gs-chart-hide" title="Hide chart" aria-label="Hide chart">&times;</button>
            </div>
            <div id="gs-chart"></div>
            <div class="chart-tip" id="gs-tip" hidden></div>
        </div>
        <button type="button" class="chart-show" id="gs-chart-show" hidden>Show game score chart</button>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Date</th><th>Matchup</th><th>W/L</th>
                    <?php if ($has_game_score): ?><th title="Game score">GmSc</th><?php endif; ?>
                    <th>MIN</th>
                    <th>PTS</th><th>REB</th><th>AST</th><th>STL</th><th>BLK</th><th>TOV</th>
                    <th>FG</th><th>3PT</th><th>FT</th><th>+/-</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($games as $g): ?>
                <tr data-game-id="<?= htmlspecialchars($g['game_id']) ?>">
                    <td><?= $g['game_date'] ?></td>
                    <td><?= htmlspecialchars($g['matchup']) ?></td>
                    <td class="<?= $g['wl'] === 'W' ? 'win' : 'loss' ?>"><?= $g['wl'] ?></td>
                    <?php if ($has_game_score): ?><td><strong><?= number_format($g['game_score'], 1) ?></strong></td><?php endif; ?>
                    <td><?= $g['min'] ?></td>
                    <td><strong><?= $g['pts'] ?></strong></td>
                    <td><?= $g['reb'] ?></td>
                    <td><?= $g['ast'] ?></td>
                    <td><?= $g['stl'] ?></td>
                    <td><?= $g['blk'] ?></td>
                    <td><?= $g['tov'] ?></td>
                    <td><?= $g['fgm'] ?>/<?= $g['fga'] ?></td>
                    <td><?= $g['fg3m'] ?>/<?= $g['fg3a'] ?></td>
                    <td><?= $g['ftm'] ?>/<?= $g['fta'] ?></td>
                    <td><?= $g['plus_minus'] > 0 ? '+' : '' ?><?= $g['plus_minus'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="empty">No games found in this date range.</p>
    <?php endif; ?>
<?php if ($chart): ?>
<script>
(function() {
    var DATA = <?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var NS = 'http://www.w3.org/2000/svg';
    var DAY = 86400000;
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var card = document.getElementById('gs-chart-card');
    var host = document.getElementById('gs-chart');
    var tip = document.getElementById('gs-tip');
    var showBtn = document.getElementById('gs-chart-show');
    var rows = {};
    document.querySelectorAll('tr[data-game-id]').forEach(function(tr) { rows[tr.dataset.gameId] = tr; });

    function parseDate(s) { var p = s.split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]); }
    function fmtDate(t) { var d = new Date(t); return MONTHS[d.getUTCMonth()] + ' ' + d.getUTCDate(); }
    function el(name, attrs, parent) {
        var e = document.createElementNS(NS, name);
        for (var k in attrs) e.setAttribute(k, attrs[k]);
        if (parent) parent.appendChild(e);
        return e;
    }
    function niceStep(range, target) {
        var raw = range / target, mag = Math.pow(10, Math.floor(Math.log10(raw)));
        var steps = [1, 2, 2.5, 5, 10];
        for (var i = 0; i < steps.length; i++) if (steps[i] * mag >= raw) return steps[i] * mag;
        return 10 * mag;
    }

    var games = DATA.games.map(function(g) { return Object.assign({ t: parseDate(g.date) }, g); });
    var x0 = parseDate(DATA.from), x1 = parseDate(DATA.to);
    if (x1 <= x0) { x0 -= DAY; x1 += DAY; }
    var vals = games.map(function(g) { return g.gs; }).concat([DATA.avg, DATA.repl]);
    var yStep = niceStep(Math.max(Math.max.apply(null, vals) - Math.min(0, Math.min.apply(null, vals)), 10), 5);
    var y0 = Math.min(0, Math.floor(Math.min.apply(null, vals) / yStep) * yStep);
    var y1 = Math.ceil(Math.max.apply(null, vals) / yStep) * yStep;
    if (y1 === Math.max.apply(null, vals)) y1 += yStep;

    var state = { dots: [], xs: [], crosshair: null, active: -1, m: null, H: 0 };

    function render() {
        host.textContent = '';
        var W = host.clientWidth, H = 240;
        var m = { l: 34, r: 58, t: 10, b: 26 };
        var pw = W - m.l - m.r, ph = H - m.t - m.b;
        if (pw <= 50) return;
        var sx = function(t) { return m.l + (t - x0) / (x1 - x0) * pw; };
        var sy = function(v) { return m.t + (y1 - v) / (y1 - y0) * ph; };
        var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img',
            'aria-label': 'Game score by game, ' + fmtDate(x0) + ' to ' + fmtDate(x1) }, host);

        // Y gridlines + labels
        for (var v = y0; v <= y1 + 1e-9; v += yStep) {
            el('line', { x1: m.l, x2: m.l + pw, y1: sy(v), y2: sy(v), class: 'gs-grid' }, svg);
            var yl = el('text', { x: m.l - 6, y: sy(v) + 4, 'text-anchor': 'end', class: 'gs-axis-text' }, svg);
            yl.textContent = Math.round(v * 10) / 10;
        }
        // X ticks: about one label per 90px, on whole-day steps
        var spanDays = (x1 - x0) / DAY;
        var maxTicks = Math.max(2, Math.floor(pw / 90));
        var stepDays = [1, 2, 3, 7, 14, 28, 56].find(function(s) { return spanDays / s <= maxTicks; }) || 56;
        for (var t = x0; t <= x1 + 1; t += stepDays * DAY) {
            var xl = el('text', { x: sx(t), y: H - 6, 'text-anchor': 'middle', class: 'gs-axis-text' }, svg);
            xl.textContent = fmtDate(t);
        }
        // Reference lines: replacement level and the player's average, labeled at the right
        [[DATA.repl, 'gs-ref', 'repl ' + DATA.repl], [DATA.avg, 'gs-ref gs-ref-avg', 'avg ' + DATA.avg.toFixed(1)]].forEach(function(r, i) {
            el('line', { x1: m.l, x2: m.l + pw, y1: sy(r[0]), y2: sy(r[0]), class: r[1] }, svg);
            var dy = Math.abs(sy(DATA.avg) - sy(DATA.repl)) < 12 ? (i === 0 ? 8 : -4) : 4;
            var lbl = el('text', { x: m.l + pw + 6, y: sy(r[0]) + dy, class: 'gs-ref-text' }, svg);
            lbl.textContent = r[2];
        });
        // Line + dots
        el('path', { d: games.map(function(g, i) { return (i ? 'L' : 'M') + sx(g.t) + ',' + sy(g.gs); }).join(''), class: 'gs-line' }, svg);
        state.crosshair = el('line', { y1: m.t, y2: m.t + ph, class: 'gs-crosshair', visibility: 'hidden' }, svg);
        state.dots = games.map(function(g) { return el('circle', { cx: sx(g.t), cy: sy(g.gs), r: 4, class: 'gs-dot' }, svg); });
        state.xs = games.map(function(g) { return sx(g.t); });
        state.ys = games.map(function(g) { return sy(g.gs); });
        state.m = m; state.W = W;

        // Hover layer: the whole plot area snaps to the nearest game by date
        var hit = el('rect', { x: m.l, y: 0, width: pw, height: H, fill: 'transparent' }, svg);
        hit.addEventListener('pointermove', function(e) {
            var px = e.clientX - svg.getBoundingClientRect().left, best = 0;
            for (var i = 1; i < state.xs.length; i++) if (Math.abs(state.xs[i] - px) < Math.abs(state.xs[best] - px)) best = i;
            activate(best);
        });
        hit.addEventListener('pointerleave', function() { activate(-1); });
        if (state.active >= 0) activate(state.active);
    }

    function activate(i) {
        if (state.active >= 0) {
            if (state.dots[state.active]) state.dots[state.active].classList.remove('active');
            var prev = rows[games[state.active].id]; if (prev) prev.classList.remove('gs-hl');
        }
        state.active = i;
        if (i < 0) { state.crosshair && state.crosshair.setAttribute('visibility', 'hidden'); tip.hidden = true; return; }
        var g = games[i];
        state.dots[i].classList.add('active');
        state.dots[i].parentNode.appendChild(state.dots[i]); // draw on top
        state.crosshair.setAttribute('x1', state.xs[i]); state.crosshair.setAttribute('x2', state.xs[i]);
        state.crosshair.setAttribute('visibility', 'visible');
        var row = rows[g.id]; if (row) row.classList.add('gs-hl');
        tip.textContent = '';
        var strong = document.createElement('strong'); strong.textContent = g.gs.toFixed(1) + ' GmSc';
        tip.appendChild(strong);
        tip.appendChild(document.createElement('br'));
        tip.appendChild(document.createTextNode(fmtDate(g.t) + ' · ' + g.matchup + (g.wl ? ' · ' + g.wl : '')));
        tip.hidden = false;
        var left = host.offsetLeft + state.xs[i] + 12, top = host.offsetTop + state.ys[i] - tip.offsetHeight / 2;
        if (left + tip.offsetWidth > card.clientWidth - 4) left = host.offsetLeft + state.xs[i] - tip.offsetWidth - 12;
        tip.style.left = left + 'px';
        tip.style.top = Math.max(4, top) + 'px';
    }

    // Table rows highlight their dot too
    games.forEach(function(g, i) {
        var row = rows[g.id]; if (!row) return;
        row.addEventListener('mouseenter', function() { if (!card.hidden) activate(i); });
        row.addEventListener('mouseleave', function() { activate(-1); });
    });

    // Hide / show, remembered per browser
    function setHidden(h) {
        card.hidden = h; showBtn.hidden = !h;
        try { localStorage.setItem('gsChartHidden', h ? '1' : '0'); } catch (e) {}
        if (!h) render();
    }
    document.getElementById('gs-chart-hide').addEventListener('click', function() { activate(-1); setHidden(true); });
    showBtn.addEventListener('click', function() { setHidden(false); });
    var startHidden = false;
    try { startHidden = localStorage.getItem('gsChartHidden') === '1'; } catch (e) {}
    card.hidden = startHidden; showBtn.hidden = !startHidden;
    if (!startHidden) render();

    var pending = false;
    window.addEventListener('resize', function() {
        if (pending || card.hidden) return;
        pending = true;
        requestAnimationFrame(function() { pending = false; render(); });
    });
})();
</script>
<?php endif; ?>
<footer class="site-footer">
    <a href="https://github.com/smorehouse/nba-stats-history">Source on GitHub</a>
</footer>

<script>
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
</script>
</body>
</html>
