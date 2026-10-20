"""Generate an invented football league whose right answers are known.

8 teams, 4 seasons (2021-2024), 22 players a team, a double round robin (14 games a team a
season). Before each new season a share of the players (--move, default 0.2) moves to a team
they have not played for. Every stat line is generated for the team the player was on that
season, so "touchdowns per team", "who were teammates" and "who was on the roster" are known
without asking the database.

    python scripts/make_league.py                      # sql/03-seed.sql and tests/expected.json
    python scripts/make_league.py --first-sql PATH     # also the same league for the first version's schema

Standard library only. All names are invented.
"""
import argparse, itertools, json, random
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TEAMS = [  # name, city, head coach
    ("Gulls", "Port Elling", "Dana Merritt"), ("Foxes", "Marlow", "Ray Okafor"),
    ("Comets", "Kestrel Bay", "Lena Varga"), ("Pilots", "Dunmore", "Sam Achterberg"),
    ("Herons", "Ashford", "Mo Delacroix"), ("Badgers", "Calder", "Jo Brennick"),
    ("Owls", "Wrenfield", "Pat Quinlan"), ("Pikes", "Halsey", "Kit Moravec"),
]
FIRST = ["Aldo", "Bram", "Cael", "Dov", "Emeka", "Finn", "Gus", "Hale", "Ivo", "Jory", "Kofi",
         "Lior", "Mats", "Nico", "Oren", "Pell", "Quin", "Rafe", "Sol", "Tavi", "Ugo", "Vance",
         "Wes", "Xan", "Yuri", "Zev", "Arlo", "Beau", "Cyd", "Dax", "Eero", "Fritz", "Gil", "Hux",
         "Ike", "Jem", "Knox", "Lars", "Milo", "Nash"]
LAST = ["Abernethy", "Bracewell", "Cadwallader", "Dunleavy", "Eckhart", "Fairweather", "Galloway",
        "Hollins", "Ingram", "Jessop", "Kettering", "Lindqvist", "Marchetti", "Nakamura", "Oduya",
        "Pennington", "Quarles", "Rasmussen", "Szabo", "Tremaine", "Underhill", "Vasquez", "Whitlock",
        "Yarrow", "Zielinski", "Ashdown", "Birkett", "Castellano", "Delancey", "Ellery", "Faulkes",
        "Grimaldi", "Hartigan", "Iverson", "Jablonski", "Kowalczyk", "Lockhart", "Mbeki", "Norwood",
        "Okonkwo", "Prentice", "Rourke", "Sandoval", "Thorne", "Ulrich", "Voss", "Wexley", "Ystad"]
ROSTER = ["QB", "RB", "WR", "WR", "WR", "TE", "OL", "OL", "OL", "OL", "OL",
          "DL", "DL", "DL", "DL", "LB", "LB", "LB", "CB", "CB", "S", "S"]
YEARS = [2021, 2022, 2023, 2024]
STATS = ["Tackles", "RushingYards", "ReceivingYards", "PassingYards", "Interceptions",
         "Deflections", "ForcedFumbles", "FumbleRecoveries", "Touchdowns"]


def pois(rng, lam):
    """A Poisson draw, by counting arrivals (small means only)."""
    k, t = 0, rng.expovariate(1.0)
    while t < lam:
        k += 1
        t += rng.expovariate(1.0)
    return k


def stat_line(rng, pos):
    """One player's game. Stats that do not apply to the position stay NULL."""
    s = dict.fromkeys(STATS)
    if pos == "QB":
        s.update(PassingYards=max(0, int(rng.gauss(225, 60))), RushingYards=int(rng.gauss(12, 15)),
                 Touchdowns=pois(rng, 0.15))
    elif pos == "RB":
        s.update(RushingYards=int(rng.gauss(70, 35)), ReceivingYards=max(0, int(rng.gauss(20, 15))),
                 Touchdowns=pois(rng, 0.55))
    elif pos in ("WR", "TE"):
        s.update(ReceivingYards=max(0, int(rng.gauss(55 if pos == "WR" else 35, 25))),
                 Touchdowns=pois(rng, 0.35 if pos == "WR" else 0.25))
    elif pos in ("DL", "LB", "CB", "S"):
        s.update(Tackles=pois(rng, {"DL": 3, "LB": 6, "CB": 4, "S": 5}[pos]),
                 Interceptions=pois(rng, {"DL": 0.01, "LB": 0.06, "CB": 0.12, "S": 0.1}[pos]),
                 Deflections=pois(rng, 0.6 if pos in ("CB", "S") else 0.2),
                 ForcedFumbles=pois(rng, 0.08), FumbleRecoveries=pois(rng, 0.05), Touchdowns=0)
    return s


def round_robin(names):
    """The circle method: len(names) - 1 rounds, every team once a round."""
    n, fixed, rest = len(names), names[0], names[1:]
    return [[(o[i], o[n - 1 - i]) for i in range(n // 2)]
            for o in ([fixed] + rest[r:] + rest[:r] for r in range(n - 1))]


def game_date(year, week):
    day = 8 + 7 * (week - 1)                      # Thursdays from 8 September
    for month, days in ((9, 30), (10, 31), (11, 30), (12, 31)):
        if day <= days:
            return f"{year}-{month:02d}-{day:02d}"
        day -= days


def build(move=0.2, seed=355):
    rng = random.Random(seed)
    names = [t[0] for t in TEAMS]
    city = {n: c for n, c, _ in TEAMS}
    combos = rng.sample([f"{f} {l}" for f, l in itertools.product(FIRST, LAST)], len(names) * len(ROSTER))
    players, team_of, pos_of, stints = [], {}, {}, []   # stint: [pid, team, pos, start, end, jersey]
    for pid, (t, pos) in enumerate(((t, pos) for t in names for pos in ROSTER), start=1):
        f, l = combos[pid - 1].split(" ")
        players.append((pid, f, l))
        team_of[pid], pos_of[pid] = t, pos
        stints.append([pid, t, pos, YEARS[0], None, rng.randint(1, 99)])
    been = {p: {team_of[p]} for p in team_of}
    on_team, games, lines, gid = {}, [], [], 0           # on_team[(pid, year)] = team
    for yi, year in enumerate(YEARS):
        if yi:
            movers = rng.sample(sorted(team_of), round(move * len(team_of)))
            spots = [team_of[p] for p in movers]        # the vacated places, reassigned so
            rng.shuffle(spots)                          # nobody returns to a former team
            for _ in range(100000):
                bad = [i for i, (p, s) in enumerate(zip(movers, spots)) if s in been[p]]
                if not bad:
                    break
                i, j = rng.choice(bad), rng.randrange(len(spots))
                spots[i], spots[j] = spots[j], spots[i]
            else:
                raise SystemExit("could not place the movers; lower --move")
            for p, s in zip(movers, spots):
                next(st for st in stints if st[0] == p and st[4] is None)[4] = year - 1
                team_of[p] = s
                been[p].add(s)
                stints.append([p, s, pos_of[p], year, None, rng.randint(1, 99)])
        for p in team_of:
            on_team[(p, year)] = team_of[p]
        roster = {t: [p for p in team_of if team_of[p] == t] for t in names}
        week = 0
        for leg in (0, 1):
            for rnd in round_robin(names):
                week += 1
                for a, b in rnd:
                    home, away = (a, b) if leg == 0 else (b, a)
                    gid += 1
                    score = {}
                    for side in (home, away):
                        tds = 0
                        for p in roster[side]:
                            s = stat_line(rng, pos_of[p])
                            tds += s["Touchdowns"] or 0
                            lines.append({"PlayerID": p, "GameID": gid, "TeamName": side, **s})
                        score[side] = 7 * tds + 3 * pois(rng, 1.6)
                    games.append({"GameID": gid, "Date": game_date(year, week), "Location": city[home],
                                  "HomeScore": score[home], "AwayScore": score[away], "HomeTeamName": home,
                                  "AwayTeamName": away, "SeasonStartDate": f"{year}-09-08"})
    return {"players": players, "stints": stints, "games": games, "lines": lines, "on_team": on_team,
            "move": move, "seed": seed}


def expected(L):
    """The right answers, from where each stat line was earned and who was where each season."""
    full = {p: f"{f} {l}" for p, f, l in L["players"]}
    team_td = {t[0]: 0 for t in TEAMS}
    player_td = {}
    for r in L["lines"]:
        team_td[r["TeamName"]] += r["Touchdowns"] or 0
        player_td[r["PlayerID"]] = player_td.get(r["PlayerID"], 0) + (r["Touchdowns"] or 0)
    together = {}
    for (p, y), t in L["on_team"].items():
        together.setdefault((t, y), []).append(p)
    pairs = {(t, a, b) for (t, y), ps in together.items() for a, b in itertools.combinations(sorted(ps), 2)}
    ever = {}
    for p, t, *_ in L["stints"]:
        ever.setdefault(t, set()).add(p)
    never = sorted((t, a, b) for t, ps in ever.items() for a, b in itertools.combinations(sorted(ps), 2)
                   if (t, a, b) not in pairs)
    moved = [p for p, *_ in L["players"] if sum(1 for s in L["stints"] if s[0] == p) > 1]
    story = {}
    if moved:                                       # the mover with the most touchdowns, and two
        star = max(moved, key=lambda p: player_td.get(p, 0))   # pairs: one that shared a season
        by_team = {}                                           # with him, one that did not
        for r in L["lines"]:
            if r["PlayerID"] == star:
                by_team[r["TeamName"]] = by_team.get(r["TeamName"], 0) + (r["Touchdowns"] or 0)
        t, a, b = next(x for x in never if star in x[1:])
        ts, sa, sb = next(x for x in sorted(pairs) if star in x[1:])
        story = {"never_together": {"team": t, "players": [full[a], full[b]]},
                 "together": {"team": ts, "players": [full[sa], full[sb]]},
                 "mover": {"name": full[star],
                           "stints": [[s[1], s[3], s[4]] for s in L["stints"] if s[0] == star],
                           "touchdowns_by_team": by_team, "touchdowns": player_td[star]}}
    return {
        "move": L["move"], "seed": L["seed"], "seasons": YEARS, "teams": [t[0] for t in TEAMS],
        "roster_size": len(ROSTER),
        "team_touchdowns": team_td,
        "player_touchdowns_total": sum(player_td.values()),
        "teammate_pairs": len(pairs),
        "pairs_listed_by_first_version": len(pairs) + len(never),
        **story,
        "last_name_contains_ar": sum(1 for _, _, l in L["players"] if "ar" in l.lower()),
    }


def lit(v):
    return "NULL" if v is None else (str(v) if isinstance(v, int) else "'" + str(v).replace("'", "''") + "'")


def to_sql(L, schema="revised"):
    """INSERTs for the revised schema, or for the first version's (no EndYear, no team on a stat line)."""
    out = ["-- Generated by scripts/make_league.py: an invented league, not real teams or players.",
           f"-- {L['move']:.0%} of players change teams each off-season; seed {L['seed']}.", ""]
    out += [f"INSERT INTO Season VALUES ('{y}-09-08','{y + 1}-01-08');" for y in YEARS]
    out += [f"INSERT INTO Team VALUES ({lit(n)},{lit(h)},{lit(c)});" for n, c, h in TEAMS]
    out += [f"INSERT INTO ParticipatesIn VALUES ('{y}-09-08',{lit(t[0])});" for y in YEARS for t in TEAMS]
    out += [f"INSERT INTO Player VALUES ({p},{lit(f)},{lit(l)});" for p, f, l in L["players"]]
    out += ["INSERT INTO Game VALUES (" + ",".join(lit(g[k]) for k in g) + ");" for g in L["games"]]
    if schema == "revised":
        out += ["INSERT INTO PlaysFor (PlayerID, TeamName, StartYear, EndYear, Position, JerseyNumber) "
                f"VALUES ({p},{lit(t)},{s},{lit(e)},{lit(pos)},{j});" for p, t, pos, s, e, j in L["stints"]]
        cols = ["PlayerID", "GameID", "TeamName"] + STATS
    else:
        out += [f"INSERT INTO PlaysFor VALUES ({p},{lit(t)},{lit(pos)},{s},{j});" for p, t, pos, s, e, j in L["stints"]]
        cols = ["PlayerID", "GameID"] + STATS
    for i in range(0, len(L["lines"]), 500):
        out.append(f"INSERT INTO PlaysIn ({', '.join(cols)}) VALUES\n" + ",\n".join(
            "(" + ",".join(lit(r[c]) for c in cols) + ")" for r in L["lines"][i:i + 500]) + ";")
    return "\n".join(out) + "\n"


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--move", type=float, default=0.2, help="share of players who change teams each off-season")
    ap.add_argument("--seed", type=int, default=355)
    ap.add_argument("--sql", default=ROOT / "sql" / "03-seed.sql")
    ap.add_argument("--expected", default=ROOT / "tests" / "expected.json")
    ap.add_argument("--first-sql", help="also write the league for the first version's schema here")
    ap.add_argument("--league", help="also write the whole league as JSON here")
    a = ap.parse_args()
    L = build(a.move, a.seed)
    Path(a.sql).write_text(to_sql(L))
    Path(a.expected).write_text(json.dumps(expected(L), indent=1) + "\n")
    if a.first_sql:
        Path(a.first_sql).write_text(to_sql(L, "first"))
    if a.league:
        Path(a.league).write_text(json.dumps({**L, "on_team": {f"{p}|{y}": t for (p, y), t in L["on_team"].items()}}))
    print(f"{len(L['players'])} players, {len(L['stints'])} stints, {len(L['games'])} games, "
          f"{len(L['lines'])} stat lines")
