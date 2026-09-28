import { describe, it, expect, vi, beforeEach } from "vitest";
import { createMintIntent, getToken, buildSponsoredInvoke, executeSponsoredInvoke } from "../../assets/src/api.js";

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

  it("buildSponsoredInvoke posts to /paymaster/invoke/build", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { typedData: {} } }) });
    await buildSponsoredInvoke({ userAddress: "0xabc", calls: [] });
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/paymaster/invoke/build"),
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("executeSponsoredInvoke posts to /paymaster/invoke/execute", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { transactionHash: "0x1" } }) });
    const body = await executeSponsoredInvoke({ userAddress: "0xabc", typedData: {}, signature: ["0x1"], calls: [] });
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining("/paymaster/invoke/execute"),
      expect.objectContaining({ method: "POST" }),
    );
    expect(body.data.transactionHash).toBe("0x1");
  });
});
