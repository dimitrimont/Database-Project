-- The first version's database, rebuilt from docs/ProjectFinalSchema.webp (the repository never
-- had the DDL), with the function, procedure and trigger exactly as the first README gave them.
CREATE TABLE Season (
  StartDate DATE PRIMARY KEY,
  EndDate   DATE
);
CREATE TABLE Team (
  TeamName  VARCHAR(64) PRIMARY KEY,
  HeadCoach VARCHAR(64),
  City      VARCHAR(64)
);
CREATE TABLE ParticipatesIn (
  StartDate DATE,
  TeamName  VARCHAR(64),
  PRIMARY KEY (StartDate, TeamName),
  FOREIGN KEY (StartDate) REFERENCES Season(StartDate),
  FOREIGN KEY (TeamName) REFERENCES Team(TeamName)
);
CREATE TABLE Player (
  PlayerID  INT PRIMARY KEY,
  FirstName VARCHAR(64),
  LastName  VARCHAR(64)
);
CREATE TABLE Game (
  GameID          INT PRIMARY KEY,
  Date            DATE,
  Location        VARCHAR(64),
  HomeScore       INT,
  AwayScore       INT,
  HomeTeamName    VARCHAR(64),
  AwayTeamName    VARCHAR(64),
  SeasonStartDate DATE,
  FOREIGN KEY (HomeTeamName) REFERENCES Team(TeamName),
  FOREIGN KEY (AwayTeamName) REFERENCES Team(TeamName),
  FOREIGN KEY (SeasonStartDate) REFERENCES Season(StartDate)
);
CREATE TABLE PlaysFor (
  PlayerID     INT,
  TeamName     VARCHAR(64),
  Position     VARCHAR(8),
  StartYear    INT,
  JerseyNumber INT,
  PRIMARY KEY (PlayerID, TeamName),
  FOREIGN KEY (PlayerID) REFERENCES Player(PlayerID),
  FOREIGN KEY (TeamName) REFERENCES Team(TeamName)
);
CREATE TABLE PlaysIn (
  PlayerID         INT,
  GameID           INT,
  Tackles          INT NULL,
  RushingYards     INT NULL,
  ReceivingYards   INT NULL,
  PassingYards     INT NULL,
  Interceptions    INT NULL,
  Deflections      INT NULL,
  ForcedFumbles    INT NULL,
  FumbleRecoveries INT NULL,
  Touchdowns       INT NULL,
  PRIMARY KEY (PlayerID, GameID),
  FOREIGN KEY (PlayerID) REFERENCES Player(PlayerID),
  FOREIGN KEY (GameID) REFERENCES Game(GameID)
);

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
