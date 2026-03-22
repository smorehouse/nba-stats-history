<?php
/**
 * Shared database connection helper.
 * Returns a PDO instance connected to the SQLite database.
 *
 * On Lambda: downloads the SQLite file from S3 to /tmp on cold start.
 * Locally: uses the local db/nba.sqlite file.
 */
function get_db(): PDO {
    if (getenv('LAMBDA_TASK_ROOT')) {
        $db_path = '/tmp/nba.sqlite';
        if (!file_exists($db_path)) {
            $bucket = getenv('DB_S3_BUCKET');
            $key = getenv('DB_S3_KEY') ?: 'nba.sqlite';
            $cmd = sprintf('aws s3 cp s3://%s/%s %s 2>&1',
                escapeshellarg($bucket),
                escapeshellarg($key),
                escapeshellarg($db_path)
            );
            exec($cmd, $output, $code);
            if ($code !== 0) {
                die('Failed to download database from S3: ' . implode("\n", $output));
            }
        }
    } else {
        $db_path = __DIR__ . '/../db/nba.sqlite';
        if (!file_exists($db_path)) {
            die('Database not found. Run the data loader first: loader/.venv/bin/python loader/load_gamelog.py');
        }
    }

    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}
