# Evaluation

The first version and the revised one, run on the same invented league and scored against the
league's known answers.

`compare.py` takes the first version's pages from the tag `first-version` and serves them on a
database built from the project's schema diagram (`first_version_schema.sql`; the repository never
had the DDL). The revised pages run on `sql/`. Both get the same league from
`scripts/make_league.py`, and every page is fetched over HTTP, as a visitor would see it. The
known answers come from the generator, which knows which team each player was on each season and
which team every stat line was earned for.

```bash
python evaluation/compare.py                                    # 20% of players move each off-season
python evaluation/compare.py --moves 0 0.05 0.1 0.15 0.2 0.25 0.3 0.35 0.4 --json results/sweep.json
```

Needs Docker and git. It starts its own MySQL 8.4 and PHP 8.3 containers and removes them after.

## Results

Each row is a league of 8 teams, 4 seasons and 22 players a team in which that share of players
changes teams before each new season. The leagues differ from row to row, so the known totals do
too.

| Players changing teams | Team touchdowns, first version | Known | Mean error per team | Teammate pairs, first version | Known | Roster sizes, first version (known 22) | Revised matches every known answer |
|---|---|---|---|---|---|---|---|
| 0% | 863 | 863 | 0.0% | 1,848 | 1,848 | 22–22 | yes |
| 5% | 1,008 | 849 | 18.4% | 2,478 | 2,404 | 24–27 | yes |
| 10% | 1,069 | 850 | 24.7% | 3,211 | 2,925 | 27–34 | yes |
| 15% | 1,254 | 916 | 38.1% | 3,929 | 3,378 | 28–36 | yes |
| **20%** | **1,266** | **840** | **50.3%** | **4,832** | **3,836** | **31–40** | yes |
| 25% | 1,665 | 905 | 86.9% | 5,815 | 4,281 | 34–44 | yes |
| 30% | 1,641 | 925 | 80.3% | 6,904 | 4,704 | 35–48 | yes |
| 35% | 1,823 | 912 | 103.9% | 8,033 | 5,086 | 42–49 | yes |
| 40% | 1,785 | 825 | 118.4% | 9,184 | 5,402 | 39–52 | yes |

The first version is exact when nobody changes teams, which is what its ERD assumes (one team per
player), and wrong as soon as anyone does, which its schema allows (`PlaysFor` is keyed on player
*and* team). The reason is one join: `PlaysIn` was joined to `PlaysFor` on `PlayerID` alone, so
every stat line was repeated once for each team the player had ever played for.

In every league the first version's "Teams in the latest season" page stopped with a PHP fatal
error (it names a column, `SeasonStart`, that the schema calls `StartDate`), and its search
matched all 176 players for `%`. `results/` has every number.
