<?php
// Known-answer tests:  docker compose run --rm tests
//
// The answers are in tests/expected.json, written by scripts/make_league.py from where it put
// every player and every stat line, not from the database. The pages are fetched over HTTP, as a
// visitor would; the rules the database enforces are tried directly, inside a transaction that is
// rolled back.

$expected = json_decode(file_get_contents(__DIR__ . '/expected.json'), true);
$web = rtrim(getenv('WEB_URL') ?: 'http://localhost:8080', '/');
$dbc = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME')),
    getenv('DB_USER'), getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "\n      $detail") . "\n";
}

/** A page as a visitor gets it: [HTTP status, body, rows keyed by column]. */
function page(array $get): array
{
    global $web;
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 60]]);
    $body = file_get_contents("$web/run_query.php?" . http_build_query($get), false, $ctx);
    preg_match('{^HTTP/\S+ (\d+)}', $http_response_header[0] ?? '', $m);
    $rows = [];
    $doc = new DOMDocument();
    if (@$doc->loadHTML($body) && ($table = $doc->getElementsByTagName('table')->item(0))) {
        $header = null;
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $c) {
                if ($c instanceof DOMElement) {
                    $cells[] = trim($c->textContent);
                }
            }
            if ($header === null) {
                $header = $cells;
            } else {
                $rows[] = array_combine($header, $cells);
            }
        }
    }
    return [(int) ($m[1] ?? 0), $body, $rows];
}

/**
 * Runs the statements in order and returns MySQL's message if it refuses the last one, or null
 * if it accepts it. The ones before it set the scene and must succeed. Nothing is kept.
 */
function refusal(string ...$sqls): ?string
{
    global $dbc;
    $last = array_pop($sqls);
    $dbc->beginTransaction();
    try {
        foreach ($sqls as $sql) {
            $dbc->exec($sql);
        }
        try {
            $dbc->exec($last);
            return null;
        } catch (PDOException $e) {
            return $e->errorInfo[2] ?? $e->getMessage();
        }
    } finally {
        $dbc->rollBack();
    }
}

// ---- Totals that depend on knowing when a player was on a team --------------------------------

[, , $rows] = page(['q' => 'team_touchdowns']);
$got = array_map('intval', array_column($rows, 'TDs', 'TeamName'));
$want = $expected['team_touchdowns'];
$diff = array_filter(array_keys($want), fn($t) => (int) ($got[$t] ?? -1) !== $want[$t]);
check("Team touchdowns: each touchdown counts for the team it was scored for",
      count($got) === count($want) && !$diff,
      "wrong for " . implode(', ', array_map(fn($t) => "$t: " . ($got[$t] ?? 'missing') . " not {$want[$t]}", $diff)));

[, , $rows] = page(['q' => 'td_totals']);
$players = array_sum(array_map('intval', array_column($rows, 'TotalTD')));
check("The teams' touchdowns add up to the players' ({$expected['player_touchdowns_total']})",
      array_sum($got) === $expected['player_touchdowns_total'] && $players === $expected['player_touchdowns_total'],
      "teams " . array_sum($got) . ", players $players");

[, , $rows] = page(['q' => 'teammates']);
$pair = fn($r) => $r['TeamName'] . ': ' . implode(' & ', [$r['Player1'], $r['Player2']]);
$listed = array_map($pair, $rows);
$asPair = fn($p) => $p['team'] . ': ' . implode(' & ', $p['players']);
check("Teammates: {$expected['teammate_pairs']} pairs who shared at least one season",
      count($rows) === $expected['teammate_pairs'], count($rows) . " listed");
$never = $asPair($expected['never_together']);
$together = $asPair($expected['together']);
check("Teammates: a pair who shared a season is listed, and a pair on the team in different seasons is not",
      in_array($together, $listed, true) && !in_array($never, $listed, true),
      (in_array($together, $listed, true) ? "" : "$together is missing. ") . (in_array($never, $listed, true) ? "$never is listed." : ""));

$sizes = [];
foreach ($expected['teams'] as $team) {
    foreach ($expected['seasons'] as $season) {
        [, , $rows] = page(['q' => 'procedure', 'team' => $team, 'season' => $season]);
        if (count($rows) !== $expected['roster_size']) {
            $sizes[] = "$team $season: " . count($rows);
        }
    }
}
check("Rosters: every team has {$expected['roster_size']} players in every season", !$sizes, implode(', ', $sizes));

$mover = $expected['mover'];
$misplaced = [];
foreach ($mover['stints'] as [$team, $from, $until]) {
    foreach ($expected['seasons'] as $season) {
        [, , $rows] = page(['q' => 'procedure', 'team' => $team, 'season' => $season]);
        $on = in_array($mover['name'], array_map(fn($r) => "{$r['FirstName']} {$r['LastName']}", $rows), true);
        $should = $season >= $from && ($until === null || $season <= $until);
        if ($on !== $should) {
            $misplaced[] = "$team $season";
        }
    }
}
check("Rosters: {$mover['name']} is on each team only in his own seasons there", !$misplaced, implode(', ', $misplaced));

[, , $rows] = page(['q' => 'procedure', 'team' => $expected['teams'][0]]);
check("Rosters: with no season given, the latest season's roster", count($rows) === $expected['roster_size']
      && ($rows[0]['Season'] ?? '') === (string) end($expected['seasons']));

// ---- Pages that failed or were missing ----------------------------------------------------------

[$status, , $rows] = page(['q' => 'season_teams']);
check("Teams in the latest season: all " . count($expected['teams']), $status === 200 && count($rows) === count($expected['teams']),
      "status $status, " . count($rows) . " rows");

[$status, , $rows] = page(['q' => 'table', 't' => 'Team']);
check("The Team table can be viewed", $status === 200 && count($rows) === count($expected['teams']));

[, , $percent] = page(['q' => 'search', 'name' => '%']);
[, , $underscore] = page(['q' => 'search', 'name' => '_']);
[, , $ar] = page(['q' => 'search', 'name' => 'ar']);
check("Search: % and _ are matched as characters, not wildcards", count($percent) === 0 && count($underscore) === 0,
      count($percent) . " and " . count($underscore) . " matches");
check("Search: 'ar' finds the {$expected['last_name_contains_ar']} last names containing it",
      count($ar) === $expected['last_name_contains_ar'], count($ar) . " found");

// ---- What the database refuses ------------------------------------------------------------------

$line = $dbc->query("SELECT pi.PlayerID, pi.GameID, pi.TeamName, g.HomeTeamName, g.AwayTeamName
                     FROM PlaysIn pi JOIN Game g ON g.GameID = pi.GameID
                     WHERE pi.Tackles IS NOT NULL LIMIT 1")->fetch();
$where = "PlayerID = {$line['PlayerID']} AND GameID = {$line['GameID']}";
$other = $line['TeamName'] === $line['HomeTeamName'] ? $line['AwayTeamName'] : $line['HomeTeamName'];
$third = $dbc->query("SELECT TeamName FROM Team WHERE TeamName NOT IN ('{$line['HomeTeamName']}', '{$line['AwayTeamName']}') LIMIT 1")->fetchColumn();

$insert = fn($tackles) => "INSERT INTO PlaysIn (PlayerID, GameID, TeamName, Tackles)
                            VALUES ({$line['PlayerID']}, {$line['GameID']}, '{$line['TeamName']}', $tackles)";
$delete = "DELETE FROM PlaysIn WHERE $where";
$checkFails = fn(?string $m) => $m !== null && str_contains($m, 'Check constraint');
check("Negative counts are refused on INSERT (and a valid line is accepted)",
      $checkFails(refusal($delete, $insert(-4))) && refusal($delete, $insert(4)) === null);
check("Negative counts are refused on UPDATE, which the first version's trigger did not cover",
      $checkFails(refusal("UPDATE PlaysIn SET Touchdowns = -3 WHERE $where"))
      && $checkFails(refusal("UPDATE PlaysIn SET Tackles = -4 WHERE $where")));
check("Negative yards are allowed: a run can lose yards",
      refusal("UPDATE PlaysIn SET RushingYards = -4 WHERE $where") === null);
check("A stat line must belong to one of the game's two teams",
      str_contains((string) refusal("UPDATE PlaysIn SET TeamName = '$third' WHERE $where"), 'not one of the two teams'));
check("A stat line must belong to a team the player was on that season",
      str_contains((string) refusal("UPDATE PlaysIn SET TeamName = '$other' WHERE $where"), 'not on that team'));
$game = fn($home, $away, $score) => "INSERT INTO Game VALUES (100000, '2024-12-30', 'Marlow', $score, 3, '$home', '$away', '2024-09-08')";
check("A team cannot play itself, and scores cannot be negative (and a valid game is accepted)",
      $checkFails(refusal($game('Foxes', 'Foxes', 7))) && $checkFails(refusal($game('Foxes', 'Gulls', -7)))
      && refusal($game('Foxes', 'Gulls', 7)) === null);
$first = $mover['stints'][0][0];
$id = $dbc->query("SELECT PlayerID FROM Player WHERE CONCAT(FirstName, ' ', LastName) = " . $dbc->quote($mover['name']))->fetchColumn();
check("A player can return to a team he left",
      refusal("INSERT INTO PlaysFor (PlayerID, TeamName, StartYear) VALUES ($id, '$first', 2025)") === null);

// ---- What a visitor sees when something goes wrong -----------------------------------------------

$leaks = fn($body) => preg_match('{Stack trace|/app/|SQLSTATE|PDO}', $body) === 1;
[$s1, $b1] = page(['q' => 'no_such_query']);
[$s2, $b2] = page(['q' => 'table', 't' => 'mysql.user']);
[$s3, $b3] = page(['q' => 'procedure']);
check("Bad requests get a 400 and a plain message",
      $s1 === 400 && $s2 === 400 && $s3 === 400 && !$leaks($b1 . $b2 . $b3) && str_contains($b3, 'Choose a team'),
      "statuses $s1, $s2, $s3");

$env = ['DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'),
        'DB_PASSWORD' => 'not-the-password'];
$proc = proc_open([PHP_BINARY, '-r', '$_GET = ["q" => "td_totals"]; include "run_query.php";'],
                  [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
$out = stream_get_contents($pipes[1]);
stream_get_contents($pipes[2]);              // the server's log
proc_close($proc);
check("A failed connection tells the visitor nothing about the server",
      trim($out) === 'The database is not available right now.', "printed: " . substr($out, 0, 120));

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
