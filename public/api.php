<?php
require_once __DIR__ . '/rankings.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cat_labels = [
    'fg_impact' => 'fg_pct', 'ft_impact' => 'ft_pct',
    'fg3m' => 'fg3m', 'pts' => 'pts',
    'reb' => 'reb', 'ast' => 'ast',
    'stl' => 'stl', 'blk' => 'blk',
];

$z_labels = [
    'fg_impact' => 'z_fg', 'ft_impact' => 'z_ft',
    'fg3m' => 'z_fg3m', 'pts' => 'z_pts',
    'reb' => 'z_reb', 'ast' => 'z_ast',
    'stl' => 'z_stl', 'blk' => 'z_blk',
];

$avg_getters = [
    'fg_impact' => fn($p) => $p['fg_pct'],
    'ft_impact' => fn($p) => $p['ft_pct'],
    'fg3m' => fn($p) => $p['fg3m'],
    'pts' => fn($p) => $p['pts'],
    'reb' => fn($p) => $p['reb'],
    'ast' => fn($p) => $p['ast'],
    'stl' => fn($p) => $p['stl'],
    'blk' => fn($p) => $p['blk'],
];

$result = [];
foreach ($players as $p) {
    $row = [
        'rank' => $p['rank'],
        'player_id' => $p['player_id'],
        'player_name' => $p['player_name'],
        'gp' => $p['gp'],
        'min' => $p['min'],
    ];

    foreach ($cat_labels as $key => $label) {
        if (in_array($key, $punt)) continue;
        $row[$label] = $avg_getters[$key]($p);
    }

    foreach ($z_labels as $key => $label) {
        if (in_array($key, $punt)) continue;
        $row[$label] = $p['z_' . $key];
    }

    $row['z_total'] = $p['z_total'];

    if ($use_salary) {
        $row['salary'] = $p['salary'];
    }

    $result[] = $row;
}

echo json_encode($result, JSON_PRETTY_PRINT);
