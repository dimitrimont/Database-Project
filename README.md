CSC355 Football Database
========================

Overview
--------
A simple PHP + MySQL frontend for exploring a football dataset. It exposes links to view raw tables, run predefined queries, execute a stored function (player full name), and execute a stored procedure (team roster).

Project URLs
------------
- Deployed: `https://ada.cis.uncw.edu/~username/` (loads `index.html` in `public_html`)

Files
-----
- `index.html` – menu of links to queries and search.
- `run_query.php` – executes queries based on `q`/`t`/`name`/`team` parameters.
- `search_players.html` – simple last-name search form.
- `db_connect.php` – PDO connection settings (update credentials for your environment).

Setup
-----
1) Database: import/create the `CSC355FA25Football` schema with data (Player, PlaysFor, Game, PlaysIn, ParticipatesIn, Season).  
2) Upload files to `public_html` (or run via local PHP server) and load `index.html`.

Stored Routine & Procedure
--------------------------
```sql
DROP FUNCTION IF EXISTS PlayerFullName;
DELIMITER //
CREATE FUNCTION PlayerFullName(p_id INT) RETURNS VARCHAR(255) DETERMINISTIC
BEGIN
  DECLARE full_name VARCHAR(255);
  SELECT CONCAT(FirstName, ' ', LastName) INTO full_name FROM Player WHERE PlayerID = p_id;
  RETURN full_name;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS GetTeamRoster;
DELIMITER //
CREATE PROCEDURE GetTeamRoster(IN team VARCHAR(255))
BEGIN
  SELECT p.PlayerID, p.FirstName, p.LastName, pf.Position, pf.JerseyNumber
  FROM Player p
  JOIN PlaysFor pf ON p.PlayerID = pf.PlayerID
  WHERE pf.TeamName = team
  ORDER BY pf.JerseyNumber;
END//
DELIMITER ;
```
The procedure accepts a `team` argument; the menu links default to `team=49ers`. 

Trigger
-------
(protects against negative stats in PlaysIn):
```sql
DROP TRIGGER IF EXISTS bi_playsin_no_negative_stats;
DELIMITER //
CREATE TRIGGER bi_playsin_no_negative_stats
BEFORE INSERT ON PlaysIn
FOR EACH ROW
BEGIN
  IF NEW.Touchdowns < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Touchdowns cannot be negative';
  END IF;
  IF NEW.Interceptions < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Interceptions cannot be negative';
  END IF;
END//
DELIMITER ;
```
Demonstrate by attempting an insert with negative values (fails) vs. positive values (succeeds with a new GameID/PlayerID pair).

Query Map (covers required patterns)
------------------------------------
- Two-table join: `players_teams` (Player ↔ PlaysFor)
- Three-table join: `player_game_stats` (Player ↔ PlaysIn ↔ Game)
- Self-join: `teammates` (PlaysFor joined to itself)
- Aggregate: `td_totals` (SUM touchdowns per player)
- Aggregate with GROUP BY/HAVING: `interceptions` (players with >= 3 interceptions)
- LIKE search: `search` (last-name search)
- Subquery: `season_teams` (teams in latest season)
- Stored function: `function` (PlayerFullName)
- Stored procedure: `procedure` (GetTeamRoster)
- Additional aggregate: `team_touchdowns` (team totals)

Usage
-----
- Main menu: `index.html`
- Stored procedure link: `run_query.php?q=procedure&team=49ers` (change team as needed)
- Search: `search_players.html` or `run_query.php?q=search&name=Mahomes`

