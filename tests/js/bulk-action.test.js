import { describe, it, expect, vi, beforeEach } from "vitest";
import { tokenizeBulk } from "../../assets/src/bulk-action.js";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn().mockResolvedValue({ address: "0xabc", account: {} }),
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
}));
vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0x1" }] } }),
  buildSponsoredInvoke: vi.fn().mockResolvedValue({ data: { typedData: { message: { calls: [] } } } }),
  executeSponsoredInvoke: vi.fn().mockResolvedValue({ data: { transactionHash: "0xtx" } }),
}));

describe("tokenizeBulk", () => {
  beforeEach(() => {
    global.window = {
      medialaneData: {
        collectionContract: "0xcol",
        contentScope: "excerpt",
        restUrl: "/wp-json/medialane/v1",
        nonce: "abc",
        posts: { 1: { title: "A", excerpt: "a", content: "aa", image: "" }, 2: { title: "B", excerpt: "b", content: "bb", image: "" } },
      },
    };
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
  });

  it("prepares every post, then mints the whole batch in one sponsored transaction", async () => {
    const progress = [];
    await tokenizeBulk(["1", "2"], (id, status) => progress.push(`${id}:${status}`));
    expect(progress).toEqual([
      "1:preparing", "2:preparing",
      "1:minting", "2:minting",
      "1:minted", "2:minted",
    ]);
  });

  it("splits more than 25 posts into multiple batches", async () => {
    const postIds = Array.from({ length: 30 }, (_, i) => String(i + 1));
    global.window.medialaneData.posts = Object.fromEntries(
      postIds.map((id) => [id, { title: "t", excerpt: "e", content: "c", image: "" }])
    );

    const { executeSponsoredInvoke } = await import("../../assets/src/api.js");
    executeSponsoredInvoke.mockClear();

    await tokenizeBulk(postIds, () => {});

    expect(executeSponsoredInvoke).toHaveBeenCalledTimes(2);
  });
});
