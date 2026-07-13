import { describe, it, expect, vi, beforeEach } from "vitest";
import { tokenizeBulk } from "../../assets/src/bulk-action.js";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn().mockResolvedValue({ address: "0xabc", account: {} }),
  executeCalls: vi.fn().mockResolvedValue("0xtx"),
}));
vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0x1" }] } }),
}));

describe("tokenizeBulk", () => {
  beforeEach(() => {
    global.window = {
      medialaneBulkData: {
        collectionContract: "0xcol",
        contentScope: "excerpt",
        restUrl: "/wp-json/medialane/v1",
        nonce: "abc",
        posts: { 1: { title: "A", excerpt: "a", content: "aa", image: "" }, 2: { title: "B", excerpt: "b", content: "bb", image: "" } },
      },
    };
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
  });

  it("mints each post sequentially and reports progress", async () => {
    const progress = [];
    await tokenizeBulk(["1", "2"], (id, status) => progress.push(`${id}:${status}`));
    expect(progress).toEqual(["1:minting", "1:minted", "2:minting", "2:minted"]);
  });
});
