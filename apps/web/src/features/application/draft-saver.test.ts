import { describe, expect, it } from "vitest";

import { DraftSaver } from "./draft-saver";

/** A fake server that holds each request until released, recording what it received. */
function fakeServer() {
  const received: string[] = [];
  const pending: Array<() => void> = [];
  const send = (payload: string) =>
    new Promise<string>((resolve) => {
      received.push(payload);
      pending.push(() => resolve(`saved:${payload}`));
    });
  const release = async () => {
    pending.shift()?.();
    await new Promise((r) => setTimeout(r, 0));
  };

  return { received, send, release };
}

describe("DraftSaver", () => {
  it("sends text typed while a save is in flight, in a second request", async () => {
    const server = fakeServer();
    const saver = new DraftSaver(server.send);

    saver.change("abc");
    const first = saver.flush();
    await Promise.resolve();
    saver.change("abcd"); // typed during the request
    const second = saver.flush(); // waits for the first, then sends "abcd"

    await server.release();
    expect(saver.dirty).toBe(true);
    await server.release();

    await expect(first).resolves.toBe("saved:abcd");
    await expect(second).resolves.toBe("saved:abcd");
    expect(server.received).toEqual(["abc", "abcd"]);
    expect(saver.dirty).toBe(false);
  });

  it("never runs two saves at once, so an older snapshot can't land last", async () => {
    const server = fakeServer();
    const saver = new DraftSaver(server.send);

    saver.change("v1");
    void saver.flush();
    saver.change("v2");
    void saver.flush();
    void saver.flush();
    await Promise.resolve();

    expect(server.received).toEqual(["v1"]);
    await server.release();
    await server.release();
    expect(server.received).toEqual(["v1", "v2"]);
    expect(saver.dirty).toBe(false);
  });

  it("keeps edits dirty after a failure and resends them next time", async () => {
    let fail = true;
    const received: string[] = [];
    const saver = new DraftSaver(async (payload: string) => {
      received.push(payload);
      if (fail) throw new Error("422");
      return payload;
    });

    saver.change("bad");
    await expect(saver.flush()).rejects.toThrow("422");
    expect(saver.dirty).toBe(true);

    fail = false;
    saver.change("good");
    await expect(saver.flush()).resolves.toBe("good");
    expect(received).toEqual(["bad", "good"]);
    expect(saver.dirty).toBe(false);
  });

  it("does nothing when there is nothing to save", async () => {
    const server = fakeServer();
    const saver = new DraftSaver(server.send);

    await expect(saver.flush()).resolves.toBeUndefined();
    expect(server.received).toEqual([]);
  });
});
