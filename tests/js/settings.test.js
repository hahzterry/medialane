import { describe, it, expect, vi, beforeEach } from "vitest";
import { pollForCollection } from "../../assets/src/settings.js";

describe("pollForCollection", () => {
  beforeEach(() => {
    global.window = { medialaneData: { restUrl: "/wp-json/medialane/v1", nonce: "abc" } };
  });

  it("returns the first collection contract once the list is non-empty", async () => {
    global.fetch = vi
      .fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [] }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ contract: "0xcol" }] }) });

    const result = await pollForCollection("0xowner", 2);
    expect(result).toBe("0xcol");
  }, 10000);

  it("keeps polling past a transient error instead of aborting", async () => {
    global.fetch = vi
      .fn()
      .mockResolvedValueOnce({ ok: false, status: 502, json: async () => ({ error: "upstream down" }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ contract: "0xcol" }] }) });

    const result = await pollForCollection("0xowner", 2);
    expect(result).toBe("0xcol");
  }, 10000);
});
