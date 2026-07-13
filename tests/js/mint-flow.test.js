import { describe, it, expect, vi, beforeEach } from "vitest";
import { tokenizeOne } from "../../assets/src/mint-flow.js";

vi.mock("../../assets/src/wallet.js", () => ({
  executeCalls: vi.fn().mockResolvedValue("0xtx"),
}));
vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0x1" }] } }),
}));

describe("tokenizeOne", () => {
  beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
  });

  it("throws without a collection, before marking minting or fetching anything", async () => {
    await expect(
      tokenizeOne({ restUrl: "/wp-json/medialane/v1", nonce: "abc", postId: 1, collectionContract: "", title: "t", body: "b", account: {}, address: "0xabc" })
    ).rejects.toThrow("No collection configured");
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it("marks minting before minted, and returns the tx hash", async () => {
    const txHash = await tokenizeOne({
      restUrl: "/wp-json/medialane/v1",
      nonce: "abc",
      postId: 1,
      collectionContract: "0xcol",
      title: "t",
      body: "b",
      license: "All Rights Reserved",
      account: {},
      address: "0xabc",
    });

    expect(txHash).toBe("0xtx");
    const urls = global.fetch.mock.calls.map((call) => call[0]);
    expect(urls).toEqual([
      "/wp-json/medialane/v1/posts/1/minting",
      "/wp-json/medialane/v1/posts/1/minted",
    ]);
  });
});
