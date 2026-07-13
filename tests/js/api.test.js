import { describe, it, expect, vi, beforeEach } from "vitest";
import { createMintIntent, getToken } from "../../assets/src/api.js";

describe("api client", () => {
  beforeEach(() => {
    global.window = { medialaneData: { restUrl: "/wp-json/medialane/v1", nonce: "abc" } };
  });

  it("posts mint intent params and returns parsed body", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: { calls: [{ contractAddress: "0x1" }] } }),
    });
    const result = await createMintIntent({ owner: "0x1", collectionId: "0x2", recipient: "0x1", tokenUri: "ipfs://x", royaltyBps: 0 });
    expect(result.data.calls).toHaveLength(1);
    expect(global.fetch).toHaveBeenCalledWith(
      "/wp-json/medialane/v1/intents/mint",
      expect.objectContaining({ method: "POST" })
    );
  });

  it("getToken returns null instead of throwing on 404", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: false, status: 404, json: async () => ({ error: "not found" }) });
    const result = await getToken("0x1", "1");
    expect(result).toBeNull();
  });
});
