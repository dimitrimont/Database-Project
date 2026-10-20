"""Run the first version and the revised one on the same invented league, and score both.

The first version is taken from the git tag `first-version`, on a database built from the
project's own schema diagram (first_version_schema.sql: the repository never had the DDL). The
revised version is this working tree on sql/. Each gets the same league from
scripts/make_league.py, and every page is fetched over HTTP and compared with the league's known
answers. Needs Docker and git.

    python evaluation/compare.py                       # the league in sql/03-seed.sql (20% move)
    python evaluation/compare.py --moves 0 0.1 0.2 0.3 --json results/sweep.json
"""
import argparse, json, secrets, subprocess, sys, tempfile, time, urllib.error, urllib.request
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts"))
import make_league  # noqa: E402

NET, DB, PHP = "football-eval", "football-eval-db", "football-eval-php"
FIRST_PORT, REVISED_PORT = 18081, 18082
TEST_CONNECT = """<?php
// The first version's db_connect.php, pointed at the evaluation database.
try {
    $dbc = new PDO('mysql:host=%s;dbname=first', 'root', getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('DB Connection Failed: ' . $e->getMessage());
}
"""


def sh(*cmd, stdin=None, check=True):
    return subprocess.run(cmd, input=stdin, capture_output=True, text=True, check=check).stdout


class Table(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.rows, self.cell = [], None
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        if tag == "tr":
            self.rows.append([])
        elif tag in ("td", "th"):
            self.cell = ""

    def handle_endtag(self, tag):
        if tag in ("td", "th") and self.cell is not None:
            self.rows[-1].append(self.cell.strip())
            self.cell = None

    def handle_data(self, d):
        if self.cell is not None:
            self.cell += d


def page(port, query):
    try:
        with urllib.request.urlopen(f"http://localhost:{port}/run_query.php?{query}", timeout=120) as r:
            status, body = r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        status, body = e.code, e.read().decode()
    rows = Table(body).rows
    return status, body, ([dict(zip(rows[0], r)) for r in rows[1:]] if rows else [])


def score(port, exp):
    """What one version's pages say, beside the known answers."""
    _, _, rows = page(port, "q=team_touchdowns")
    team = {r["TeamName"]: int(r["TDs"] or 0) for r in rows}
    _, _, mates = page(port, "q=teammates")
    rosters = {t: len(page(port, f"q=procedure&team={t}")[2]) for t in exp["teams"]}
    st, body, latest = page(port, "q=season_teams")
    return {
        "team_touchdowns": team,
        "touchdowns_total": sum(team.values()),
        "mean_team_error_pct": round(100 * sum(abs(team.get(t, 0) - n) / n
                                               for t, n in exp["team_touchdowns"].items()) / len(team), 1),
        "teammate_pairs": len(mates),
        "roster_sizes_latest": rosters,
        "season_teams": {"status": st, "rows": len(latest), "error": "Fatal error" in body},
        "search_percent_matches": len(page(port, "q=search&name=%25")[2]),
        "team_table": page(port, "q=table&t=Team")[0] == 200 and "Invalid" not in page(port, "q=table&t=Team")[1],
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--moves", type=float, nargs="+", default=[0.2])
    ap.add_argument("--seed", type=int, default=355)
    ap.add_argument("--json", help="write every result here")
    a = ap.parse_args()
    pw = secrets.token_hex(12)
    work = Path(tempfile.mkdtemp(prefix="football-eval-"))
    first = work / "first"
    first.mkdir()
    for f in ("index.html", "search_players.html", "run_query.php"):
        (first / f).write_text(sh("git", "-C", str(ROOT), "show", f"first-version:{f}"))
    (first / "db_connect.php").write_text(TEST_CONNECT % DB)

    sh("docker", "network", "create", NET, check=False)
    sh("docker", "build", "-q", "-t", PHP, str(ROOT / "docker"))
    sh("docker", "run", "-d", "--name", DB, "--network", NET, "-e", f"MYSQL_ROOT_PASSWORD={pw}", "mysql:8.4")
    mysql = ["docker", "exec", "-i", DB, "mysql", "-uroot", f"-p{pw}"]
    try:
        for _ in range(90):
            if subprocess.run(mysql[:4] + ["mysql", "-h127.0.0.1", "-uroot", f"-p{pw}", "-e", "SELECT 1"],
                              capture_output=True).returncode == 0:
                break
            time.sleep(2)
        common = ["docker", "run", "-d", "--network", NET, "-e", f"DB_PASSWORD={pw}", "-w", "/app"]
        sh(*common, "--name", "football-eval-first", "-p", f"{FIRST_PORT}:8000", "-v", f"{first}:/app:ro",
           PHP, "php", "-S", "0.0.0.0:8000")
        sh(*common, "--name", "football-eval-revised", "-p", f"{REVISED_PORT}:8000", "-v", f"{ROOT}:/app:ro",
           "-e", f"DB_HOST={DB}", "-e", "DB_NAME=revised", "-e", "DB_USER=root", PHP, "php", "-S", "0.0.0.0:8000")
        results = []
        for move in a.moves:
            L = make_league.build(move, a.seed)
            exp = make_league.expected(L)
            sh(*mysql, "-e", "DROP DATABASE IF EXISTS first; DROP DATABASE IF EXISTS revised; "
                             "CREATE DATABASE first; CREATE DATABASE revised;")
            sh(*mysql, "first", stdin=(ROOT / "evaluation" / "first_version_schema.sql").read_text())
            sh(*mysql, "first", stdin=make_league.to_sql(L, "first"))
            sh(*mysql, "revised", stdin=(ROOT / "sql" / "01-schema.sql").read_text())
            sh(*mysql, "revised", stdin=(ROOT / "sql" / "02-routines.sql").read_text())
            sh(*mysql, "revised", stdin=make_league.to_sql(L))
            known = {"team_touchdowns": exp["team_touchdowns"], "touchdowns_total": exp["player_touchdowns_total"],
                     "teammate_pairs": exp["teammate_pairs"], "roster_size": exp["roster_size"],
                     "teams": len(exp["teams"])}
            results.append({"move": move, "known": known,
                            "first": score(FIRST_PORT, exp), "revised": score(REVISED_PORT, exp)})
            r = results[-1]
            print(f"move {move:.0%}: touchdowns {r['first']['touchdowns_total']} / {r['revised']['touchdowns_total']}"
                  f" (known {known['touchdowns_total']}); teammates {r['first']['teammate_pairs']} /"
                  f" {r['revised']['teammate_pairs']} (known {known['teammate_pairs']})", file=sys.stderr)
    finally:
        for c in ("football-eval-first", "football-eval-revised", DB):
            sh("docker", "rm", "-f", c, check=False)
        sh("docker", "network", "rm", NET, check=False)
    if a.json:
        Path(a.json).write_text(json.dumps(results, indent=1) + "\n")
    print(json.dumps(results, indent=1))


if __name__ == "__main__":
    main()
