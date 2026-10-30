/**
 * Saves a draft one request at a time (PRD-02 FR-02 autosave). Every edit bumps a version; a save sends the latest
 * payload and only counts as "saved" for the version it sent, so text typed while a request is in flight is sent
 * by the next one — never dropped, and never overtaken by an older snapshot.
 */
export class DraftSaver<TPayload, TResult> {
  private version = 0;
  private savedVersion = 0;
  private latest: TPayload | undefined;
  private inFlight: Promise<TResult> | null = null;
  private lastResult: TResult | undefined;

  constructor(private readonly send: (payload: TPayload) => Promise<TResult>) {}

  /** Records an edit (the full payload as it is now). */
  change(payload: TPayload): void {
    this.version += 1;
    this.latest = payload;
  }

  /** Edits not yet confirmed by the server. */
  get dirty(): boolean {
    return this.version !== this.savedVersion;
  }

  get saving(): boolean {
    return this.inFlight !== null;
  }

  /**
   * Waits for any save in progress, then sends what is still unsaved, until nothing is. Resolves with the last
   * server result (undefined if nothing was ever saved); rejects with the first failure, leaving the edits dirty.
   */
  async flush(): Promise<TResult | undefined> {
    while (this.inFlight) {
      await this.inFlight.catch(() => undefined);
    }
    while (this.dirty) {
      const version = this.version;
      const request = this.send(this.latest as TPayload);
      this.inFlight = request;
      try {
        this.lastResult = await request;
        this.savedVersion = Math.max(this.savedVersion, version);
      } finally {
        this.inFlight = null;
      }
    }

    return this.lastResult;
  }
}
