<?php
require 'db_connect.php';

$q = $_GET['q'] ?? '';
$name = $_GET['name'] ?? '';
$table = $_GET['t'] ?? '';
$team = $_GET['team'] ?? '';
$allowedTables = ["Player", "PlaysFor", "Game", "PlaysIn", "ParticipatesIn", "Season"];

$desc = '';
$sql = '';
$params = [];

switch ($q) {
    case "table":
        $lookup = [];
        foreach ($allowedTables as $allowed) {
            $lookup[strtolower($allowed)] = $allowed;
        }
        $key = strtolower($table);
        if (!isset($lookup[$key])) {
            die("Invalid table.");
        }
        $safeTable = $lookup[$key];
        $desc = "Table: " . $safeTable;
        $sql = "SELECT * FROM `$safeTable`";
        break;

    case "players_teams":
        $desc = "Players Joined with Teams";
        $sql = "
            SELECT p.PlayerID, p.FirstName, p.LastName,
                   pf.TeamName, pf.Position, pf.JerseyNumber
            FROM Player p
            JOIN PlaysFor pf ON p.PlayerID = pf.PlayerID
        ";
        break;

    case "player_game_stats":
        $desc = "Player Stats with Game Info";
        $sql = "
            SELECT p.FirstName, p.LastName, g.Date, g.HomeTeamName, g.AwayTeamName,
                   pi.Touchdowns, pi.RushingYards
            FROM Player p
            JOIN PlaysIn pi ON p.PlayerID = pi.PlayerID
            JOIN Game g ON pi.GameID = g.GameID
        ";
        break;

    case "teammates":
        $desc = "Teammates (Self-Join)";
        $sql = "
            SELECT pf1.TeamName,
                   p1.LastName AS Player1, p2.LastName AS Player2
            FROM PlaysFor pf1
            JOIN PlaysFor pf2 ON pf1.TeamName = pf2.TeamName
                             AND pf1.PlayerID < pf2.PlayerID
            JOIN Player p1 ON pf1.PlayerID = p1.PlayerID
            JOIN Player p2 ON pf2.PlayerID = p2.PlayerID
        ";
        break;

    case "td_totals":
        $desc = "Touchdowns Per Player";
        $sql = "
            SELECT p.FirstName, p.LastName,
                   SUM(pi.Touchdowns) AS TotalTD
            FROM Player p
            JOIN PlaysIn pi ON p.PlayerID = pi.PlayerID
            GROUP BY p.PlayerID
        ";
        break;

    case "interceptions":
        $desc = "Players with >= 3 Interceptions";
        $sql = "
            SELECT p.FirstName, p.LastName,
                   SUM(pi.Interceptions) AS TotalInts
            FROM Player p
            JOIN PlaysIn pi ON p.PlayerID = pi.PlayerID
            GROUP BY p.PlayerID
            HAVING TotalInts >= 3
        ";
        break;

    case "search":
        $desc = "Search Results";
        $sql = "
            SELECT FirstName, LastName
            FROM Player
            WHERE LastName LIKE :name
        ";
        $params = [':name' => '%' . $name . '%'];
        break;

    case "season_teams":
        $desc = "Teams in Latest Season";
        $sql = "
            SELECT TeamName 
            FROM ParticipatesIn
            WHERE SeasonStart = (SELECT MAX(SeasonStart) FROM Season)
        ";
        break;

    case "team_touchdowns":
        $desc = "Team Touchdown Totals";
        $sql = "
            SELECT pf.TeamName,
                   SUM(pi.Touchdowns) AS TDs
            FROM PlaysFor pf
            JOIN PlaysIn pi ON pf.PlayerID = pi.PlayerID
            GROUP BY pf.TeamName
        ";
        break;

    case "function":
        $desc = "Stored Function Result";
        $sql = "
            SELECT PlayerID, PlayerFullName(PlayerID) AS FullName
            FROM Player
        ";
        break;

    case "procedure":
        $team = trim($team);
        if ($team === '') {
            $team = '49ers'; // fallback to a team that exists in the sample data
        }
        $desc = "Team Roster for {$team}";
        $sql = "CALL GetTeamRoster(:team)";
        $params = [':team' => $team];
        break;

    default:
        die("Invalid query.");
}

if ($sql === '') {
    die("Invalid query.");
}

if (!empty($params)) {
    $stmt = $dbc->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = $dbc->query($sql);
}

$columns = [];
for ($i = 0; $i < $stmt->columnCount(); $i++) {
    $meta = $stmt->getColumnMeta($i);
    $columns[] = $meta['name'] ?? "col{$i}";
}
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($desc); ?></title>
</head>
<body>
<h1><?php echo htmlspecialchars($desc); ?></h1>
<nav>
    <a href="index.html">Back to Menu</a> |
    <a href="search_players.html">Search Player</a>
</nav>

<table border="1" cellpadding="4" cellspacing="0">
    <tr>
        <?php foreach ($columns as $column): ?>
            <th><?php echo htmlspecialchars($column); ?></th>
        <?php endforeach; ?>
    </tr>

    <?php foreach ($rows as $row): ?>
        <tr>
            <?php foreach ($columns as $column): ?>
                <td><?php echo htmlspecialchars((string)($row[$column] ?? '')); ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
