# Spike — Which Laravel Cloud region serves the DRC best?

**Goal:** choose between `eu-west-2` (London) and `eu-central-1` (Frankfurt) — the closest Laravel Cloud regions — using real measurements from the DRC, not guesses.

## Method A — RIPE Atlas (no travel needed)

1. Create a RIPE Atlas account; get credits (hosting a probe or requesting from RIPE NCC).
2. Select probes with country code `CD` (DRC), spread across operators (Vodacom, Orange, Airtel, Africell) and cities (west, east, south — e.g. Kinshasa, Lubumbashi, Goma, Kisangani, Mbuji-Mayi) where available.
3. Targets: an AWS endpoint in each region (e.g. a small EC2/Lambda URL or the public regional endpoints `ec2.eu-west-2.amazonaws.com`, `ec2.eu-central-1.amazonaws.com`).
4. Run `ping` and `traceroute` measurements, 4 times a day for 3 days (peak and off-peak).

## Method B — Testers in the DRC

Ask 5–10 testers on different operators and cities to open a simple page that times `fetch` requests (TTFB) to both regions 20 times and submits the results.

## Decide

- Primary metric: median and p90 RTT/TTFB per region, across cities and operators.
- Pick the region with the lower p90; if within 10%, pick the one with better Laravel Cloud feature availability.
- Note: Mux media and Cloudflare-fronted assets are served from CDNs, so this choice mainly affects API calls.

## Results

_Not run yet._

| City / operator | London median / p90 | Frankfurt median / p90 |
|---|---|---|
| | | |
