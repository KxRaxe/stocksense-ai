# Load tests

[k6](https://k6.io) scripts that measure how the production-like stack behaves under load: how fast pages answer when many people use them at once, how long report downloads take, and how long a forecast takes. They are the evidence for the performance section of the thesis (ISO/IEC 25010 performance efficiency); the figures from a real run are in [docs/testing.md](../../docs/testing.md).

| Script | What it does | Held to |
|---|---|---|
| `pages.js` | Up to 25 people at once, each opening the pages of a working day (dashboard, recommendations, products, inventory, sales, forecasts, accuracy, reports, notifications) with a pause between them | under 1% failed; 95 of 100 page loads under 1 s (dashboard and reports 1.2 s) |
| `exports.js` | A steady six downloads a minute of Excel and PDF reports, within the app's own limit of ten a minute for each person | 95 of 100 Excel files under 6 s, PDFs under 12 s |
| `forecast-run.js` | Starts a weekly and a monthly forecast and times each until it finishes, while three people keep browsing | each run under 5 minutes; pages stay under 2 s meanwhile |

Each script signs in once, in `setup()`, and gives the session to every virtual user: signing in is rate limited to five tries a minute for each address, which is itself under test elsewhere.

## Running them

Bring up the production-like stack and seed it (see [e2e/README.md](../../e2e/README.md)), then run k6 in Docker from the repository root:

```bash
docker run --rm -i -v "$PWD/tests/load:/scripts" \
  -e BASE_URL=http://host.docker.internal:8090 \
  grafana/k6 run /scripts/pages.js

# keep the numbers as JSON
docker run --rm -i -v "$PWD/tests/load:/scripts" -e BASE_URL=http://host.docker.internal:8090 \
  grafana/k6 run --summary-export=/scripts/results-pages.json /scripts/pages.js
```

`BASE_URL`, `EMAIL` and `PASSWORD` can be set; the defaults are the end-to-end stack and the demo Owner. A failed threshold makes k6 exit with an error, so the scripts can also gate a pipeline.

## Reading the results

Results depend on the machine. Run on a laptop with Docker Desktop, the database and the PHP, Redis and ML containers all share the same cores as k6 and the browser, so the figures are a pessimistic floor, not what dedicated hardware would give. Compare runs on the same machine, and note which one it was.
