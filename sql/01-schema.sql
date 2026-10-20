-- The football league database (MySQL 8.0.16 or later, which enforces CHECK constraints).
-- The tables in docs/ProjectFinalSchema.webp, with two additions so the queries can tell when a
-- player was on a team:
--   PlaysFor.EndYear   the last season of a stint (NULL while it lasts). StartYear joins the key,
--                      so a player can return to a team they left.
--   PlaysIn.TeamName   the team a player was playing for in that game.
-- Seasons are named by the year they start: the 2024 season starts 2024-09-08.

CREATE TABLE Season (
  StartDate DATE PRIMARY KEY,
  EndDate   DATE NOT NULL,
  CHECK (EndDate > StartDate)
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
  FirstName VARCHAR(64) NOT NULL,
  LastName  VARCHAR(64) NOT NULL
);

CREATE TABLE Game (
  GameID          INT PRIMARY KEY,
  Date            DATE NOT NULL,
  Location        VARCHAR(64),
  HomeScore       INT,
  AwayScore       INT,
  HomeTeamName    VARCHAR(64) NOT NULL,
  AwayTeamName    VARCHAR(64) NOT NULL,
  SeasonStartDate DATE NOT NULL,
  FOREIGN KEY (HomeTeamName) REFERENCES Team(TeamName),
  FOREIGN KEY (AwayTeamName) REFERENCES Team(TeamName),
  FOREIGN KEY (SeasonStartDate) REFERENCES Season(StartDate),
  CHECK (HomeTeamName <> AwayTeamName),
  CHECK (HomeScore >= 0 AND AwayScore >= 0)
);

CREATE TABLE PlaysFor (
  PlayerID     INT,
  TeamName     VARCHAR(64),
  StartYear    INT NOT NULL,
  EndYear      INT NULL,
  Position     VARCHAR(8),
  JerseyNumber INT,
  PRIMARY KEY (PlayerID, TeamName, StartYear),
  FOREIGN KEY (PlayerID) REFERENCES Player(PlayerID),
  FOREIGN KEY (TeamName) REFERENCES Team(TeamName),
  CHECK (EndYear IS NULL OR EndYear >= StartYear),
  CHECK (JerseyNumber BETWEEN 0 AND 99)
);

-- Counts cannot be negative, on INSERT or UPDATE. Yards can: a run stopped behind the line of
-- scrimmage loses yards. Stats that do not apply to a position stay NULL.
CREATE TABLE PlaysIn (
  PlayerID         INT,
  GameID           INT,
  TeamName         VARCHAR(64) NOT NULL,
  Tackles          INT NULL CHECK (Tackles >= 0),
  RushingYards     INT NULL,
  ReceivingYards   INT NULL,
  PassingYards     INT NULL,
  Interceptions    INT NULL CHECK (Interceptions >= 0),
  Deflections      INT NULL CHECK (Deflections >= 0),
  ForcedFumbles    INT NULL CHECK (ForcedFumbles >= 0),
  FumbleRecoveries INT NULL CHECK (FumbleRecoveries >= 0),
  Touchdowns       INT NULL CHECK (Touchdowns >= 0),
  PRIMARY KEY (PlayerID, GameID),
  FOREIGN KEY (PlayerID) REFERENCES Player(PlayerID),
  FOREIGN KEY (GameID) REFERENCES Game(GameID),
  FOREIGN KEY (TeamName) REFERENCES Team(TeamName)
);
