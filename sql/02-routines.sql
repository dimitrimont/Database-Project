-- The stored function, procedure and triggers.

DROP FUNCTION IF EXISTS PlayerFullName;
DELIMITER //
CREATE FUNCTION PlayerFullName(p_id INT) RETURNS VARCHAR(255) READS SQL DATA
BEGIN
  DECLARE full_name VARCHAR(255);
  SELECT CONCAT(FirstName, ' ', LastName) INTO full_name FROM Player WHERE PlayerID = p_id;
  RETURN full_name;
END//
DELIMITER ;

-- A team's roster in one season (the latest when season_year is NULL): the players whose stint
-- with the team covers that season, not everyone who ever played for it.
DROP PROCEDURE IF EXISTS GetTeamRoster;
DELIMITER //
CREATE PROCEDURE GetTeamRoster(IN team VARCHAR(64), IN season_year INT)
BEGIN
  IF season_year IS NULL THEN
    SELECT YEAR(MAX(StartDate)) INTO season_year FROM Season;
  END IF;
  SELECT p.PlayerID, p.FirstName, p.LastName, pf.Position, pf.JerseyNumber, season_year AS Season
  FROM Player p
  JOIN PlaysFor pf ON p.PlayerID = pf.PlayerID
  WHERE pf.TeamName = team
    AND pf.StartYear <= season_year
    AND (pf.EndYear IS NULL OR pf.EndYear >= season_year)
  ORDER BY pf.JerseyNumber, p.LastName;
END//
DELIMITER ;

-- A stat line must belong to one of the game's two teams, and to a team the player was on that
-- season. CHECK constraints cannot look at other tables, so a trigger does this, on INSERT and
-- on UPDATE. (The counts' "cannot be negative" rule is a CHECK in 01-schema.sql.)
DROP PROCEDURE IF EXISTS CheckStatLine;
DELIMITER //
CREATE PROCEDURE CheckStatLine(IN p_player INT, IN p_game INT, IN p_team VARCHAR(64))
BEGIN
  DECLARE v_home VARCHAR(64);
  DECLARE v_away VARCHAR(64);
  DECLARE v_year INT;
  SELECT HomeTeamName, AwayTeamName, YEAR(SeasonStartDate) INTO v_home, v_away, v_year
  FROM Game WHERE GameID = p_game;
  IF p_team <> v_home AND p_team <> v_away THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The team is not one of the two teams in that game';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM PlaysFor
                 WHERE PlayerID = p_player AND TeamName = p_team
                   AND StartYear <= v_year AND (EndYear IS NULL OR EndYear >= v_year)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The player was not on that team that season';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS bi_playsin_check_team;
DELIMITER //
CREATE TRIGGER bi_playsin_check_team BEFORE INSERT ON PlaysIn
FOR EACH ROW CALL CheckStatLine(NEW.PlayerID, NEW.GameID, NEW.TeamName)//
DELIMITER ;

DROP TRIGGER IF EXISTS bu_playsin_check_team;
DELIMITER //
CREATE TRIGGER bu_playsin_check_team BEFORE UPDATE ON PlaysIn
FOR EACH ROW CALL CheckStatLine(NEW.PlayerID, NEW.GameID, NEW.TeamName)//
DELIMITER ;
