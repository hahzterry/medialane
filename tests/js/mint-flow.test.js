import { describe, it, expect, vi } from "vitest";
import { prepareMint, executeMintBatch } from "../../assets/src/mint-flow.js";

vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] } }),
  buildSponsoredInvoke: vi.fn().mockResolvedValue({ data: { typedData: { message: { calls: [] } } } }),
  executeSponsoredInvoke: vi.fn().mockResolvedValue({ data: { transactionHash: "0xtx" } }),
}));
vi.mock("../../assets/src/wallet.js", () => ({
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
}));

describe("prepareMint", () => {
  it("uploads metadata and returns the mint calls without touching the chain", async () => {
    const result = await prepareMint({
      postId: 42, title: "A Post", body: "Body", image: "", license: "CC BY-SA", address: "0xowner",
      collectionContract: "0xcol",
    });
    expect(result.postId).toBe(42);
    expect(result.calls).toHaveLength(1);
  });
});

describe("executeMintBatch", () => {
  it("throws without a collection, before marking anything", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    await expect(
      executeMintBatch({ restUrl: "/wp-json/medialane/v1", nonce: "abc", account: {}, address: "0xowner", collectionContract: "", entries: [] })
    ).rejects.toThrow("No collection configured");
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it("signs and executes once for the whole batch, then marks every post minted with the shared tx hash", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    const entries = [
      { postId: 1, license: "CC BY-SA", calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] },
      { postId: 2, license: "CC BY-SA", calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] },
    ];
    const results = await executeMintBatch({
      restUrl: "/wp-json/medialane/v1", nonce: "abc", account: {}, address: "0xowner",
      collectionContract: "0xcol", entries,
    });
    expect(results).toEqual([
      { postId: 1, txHash: "0xtx" },
      { postId: 2, txHash: "0xtx" },
    ]);
  });
});
