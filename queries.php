<?php
// Every page's query, by name. build_query() takes the request's parameters and returns the
// title, the SQL and its parameters; it never touches the database.

const TABLES = ["Player", "Team", "PlaysFor", "Game", "PlaysIn", "ParticipatesIn", "Season"];

function build_query(array $get): array
{
    $q = $get['q'] ?? '';
    switch ($q) {
        case "table":
            $lookup = [];
            foreach (TABLES as $allowed) {
                $lookup[strtolower($allowed)] = $allowed;
            }
            $key = strtolower($get['t'] ?? '');
            if (!isset($lookup[$key])) {
                throw new InvalidArgumentException("Unknown table.");
            }
            $table = $lookup[$key];
            return ["Table: $table", "SELECT * FROM `$table`", []];

        case "players_teams":
            // Every stint, with its years: a player who changed teams appears once per team.
            return ["Players and their Teams", "
                SELECT p.PlayerID, p.FirstName, p.LastName,
                       pf.TeamName, pf.Position, pf.JerseyNumber,
                       pf.StartYear, IFNULL(pf.EndYear, 'now') AS Until
                FROM Player p
                JOIN PlaysFor pf ON p.PlayerID = pf.PlayerID
                ORDER BY p.LastName, p.FirstName, pf.StartYear
            ", []];

        case "player_game_stats":
            return ["Player Stats with Game Info", "
                SELECT p.FirstName, p.LastName, pi.TeamName AS Team, g.Date,
                       g.HomeTeamName, g.AwayTeamName, pi.Touchdowns, pi.RushingYards
                FROM Player p
                JOIN PlaysIn pi ON p.PlayerID = pi.PlayerID
                JOIN Game g ON pi.GameID = g.GameID
                ORDER BY g.Date, g.GameID, p.LastName
            ", []];

        case "teammates":
            // Two players are teammates if their stints with the same team overlap by a season.
            return ["Teammates (Self-Join)", "
                SELECT a.TeamName,
                       CONCAT(p1.FirstName, ' ', p1.LastName) AS Player1,
                       CONCAT(p2.FirstName, ' ', p2.LastName) AS Player2,
                       GREATEST(a.StartYear, b.StartYear) AS FromYear,
                       IFNULL(CASE WHEN a.EndYear IS NULL THEN b.EndYear
                                   WHEN b.EndYear IS NULL THEN a.EndYear
                                   ELSE LEAST(a.EndYear, b.EndYear) END, 'now') AS Until
                FROM PlaysFor a
                JOIN PlaysFor b ON a.TeamName = b.TeamName
                               AND a.PlayerID < b.PlayerID
                               AND a.StartYear <= IFNULL(b.EndYear, 9999)
                               AND b.StartYear <= IFNULL(a.EndYear, 9999)
                JOIN Player p1 ON a.PlayerID = p1.PlayerID
                JOIN Player p2 ON b.PlayerID = p2.PlayerID
                ORDER BY a.TeamName, FromYear, p1.LastName, p2.LastName
            ", []];

        case "td_totals":
            return ["Touchdowns Per Player", "
                SELECT p.FirstName, p.LastName,
                       IFNULL(SUM(pi.Touchdowns), 0) AS TotalTD
                FROM Player p
                JOIN PlaysIn pi ON p.PlayerID = pi.PlayerID
                GROUP BY p.PlayerID
                ORDER BY TotalTD DESC, p.LastName
            ", []];

        case "interceptions":
            return ["Players with >= 3 Interceptions", "
                SELECT p.FirstName, p.LastName,
                       SUM(pi.Interceptions) AS TotalInts
                FROM Player p
                JOIN PlaysIn pi ON p.PlayerID = pi.PlayerID
                GROUP BY p.PlayerID
                HAVING TotalInts >= 3
                ORDER BY TotalInts DESC, p.LastName
            ", []];

        case "search":
            $name = trim($get['name'] ?? '');
            if ($name === '') {
                throw new InvalidArgumentException("Enter part of a last name.");
            }
            // % and _ are matched as themselves, not as wildcards.
            return ["Search Results", "
                SELECT FirstName, LastName
                FROM Player
                WHERE LastName LIKE :name
                ORDER BY LastName, FirstName
            ", [':name' => '%' . addcslashes($name, '%_\\') . '%']];

        case "season_teams":
            return ["Teams in Latest Season", "
                SELECT TeamName
                FROM ParticipatesIn
                WHERE StartDate = (SELECT MAX(StartDate) FROM Season)
                ORDER BY TeamName
            ", []];

        case "team_touchdowns":
            // Each touchdown counts for the team the player scored it for.
            return ["Team Touchdown Totals", "
                SELECT t.TeamName, IFNULL(SUM(pi.Touchdowns), 0) AS TDs
                FROM Team t
                LEFT JOIN PlaysIn pi ON pi.TeamName = t.TeamName
                GROUP BY t.TeamName
                ORDER BY TDs DESC, t.TeamName
            ", []];

        case "function":
            return ["Stored Function Result", "
                SELECT PlayerID, PlayerFullName(PlayerID) AS FullName
                FROM Player
            ", []];

        case "procedure":
            $team = trim($get['team'] ?? '');
            if ($team === '') {
                throw new InvalidArgumentException("Choose a team.");
            }
            $season = trim($get['season'] ?? '');
            if ($season !== '' && !ctype_digit($season)) {
                throw new InvalidArgumentException("A season is a year, such as 2024.");
            }
            $label = $season === '' ? "latest season" : $season;
            return ["Team Roster for $team, $label", "CALL GetTeamRoster(:team, :season)",
                    [':team' => $team, ':season' => $season === '' ? null : (int) $season]];
    }
    throw new InvalidArgumentException("Unknown query.");
}
