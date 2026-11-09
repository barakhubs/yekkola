# Load tests (k6)

Four scripts for the busiest paths in the API, run against **staging** (never production). Each one fails if the
p95 targets from `project-context.md` are missed: reads under 300 ms and writes under 800 ms. It also fails if more
than 1% of requests error.

| Script         | What it does                                                                                  |
| -------------- | --------------------------------------------------------------------------------------------- |
| `catalogue.js` | Anonymous visitors: home rails, listing, search, course page, reviews, professor page         |
| `playback.js`  | Enrolled students: playback token, then a stream heartbeat                                    |
| `progress.js`  | Progress sync: single saves every 15 s (web), 20-lesson batches (mobile coming back online)   |
| `checkout.js`  | Paid checkout on the fake gateway: quote, order, mobile-money payment, waiting-screen polling |

## Prepare

1. Staging needs the demo catalogue: at least one listed free course with video or audio lessons, and one listed
   paid course.
2. On the staging API, create the test students and save the output on your machine as `fixture.json`:

   ```sh
   php artisan loadtest:prepare --students=200 > fixture.json
   ```

   The command makes students `+243 990 000 001…` with one-day tokens and enrols them in the free course. Running it
   again reuses the same students and gives them new tokens. It refuses to run in production.

## Run

```sh
k6 run -e API_URL=https://api-staging.example -e WEB_SERVER_KEY=… catalogue.js
k6 run -e API_URL=https://api-staging.example -e VUS=100 -e DURATION=10m playback.js
k6 run -e API_URL=https://api-staging.example progress.js
k6 run -e API_URL=https://api-staging.example -e VUS=20 checkout.js
```

Options:

| Variable         | Default          | Meaning                                                    |
| ---------------- | ---------------- | ---------------------------------------------------------- |
| `API_URL`        | `localhost:8000` | API origin (the scripts add `/api/v1`)                     |
| `FIXTURE`        | `./fixture.json` | Output of `loadtest:prepare`                               |
| `VUS`            | 50               | Virtual users at the plateau (1-minute ramp up)            |
| `DURATION`       | 5m               | Plateau length                                             |
| `WEB_SERVER_KEY` | none             | Staging's `WEB_SERVER_KEY`, for `catalogue.js` (see below) |

## Rate limits

The scripts stay inside the API's own limits:

- **Anonymous visitors.** They are limited per IP. Pass staging's `WEB_SERVER_KEY` so each virtual user counts as
  its own visitor, the same way pages rendered by our web server reach the API. Without the key, the whole run
  shares one IP and is stopped at 1,200 catalogue requests a minute.
- **Students.** Each student has 600 requests a minute overall, 60 playback and 30 progress calls a minute, and 10
  payment starts an hour. Prepare at least as many students as `VUS` (one web stream per account).
- **Checkout.** Each student buys the paid course once. Later iterations get `422` (already owned) and wait; the
  scripts treat `422` as expected. For a full checkout load, prepare new students (`--students` higher) or reset
  staging.
- **SMS.** Test students use `+243 990 1…` numbers. Keep staging on the log SMS driver while load testing, so no
  receipt reaches a real phone.
