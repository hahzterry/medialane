import { describe, it, expect, vi, beforeEach } from "vitest";
import { tokenizeBulk, buildStatusPanel } from "../../assets/src/bulk-action.js";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn().mockResolvedValue({ address: "0xabc", account: {} }),
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
  waitForConfirmation: vi.fn().mockResolvedValue({ isReverted: () => false }),
  generateInterimKeypair: vi.fn().mockReturnValue({ privateKey: "0xpriv", publicKey: "0xpub", address: "0xinterim" }),
  signDeploymentWithInterimKey: vi.fn().mockResolvedValue(["0xdsig"]),
}));
vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0x1" }] } }),
  buildSponsoredInvoke: vi.fn().mockResolvedValue({ data: { typedData: { message: { calls: [] } } } }),
  executeSponsoredInvoke: vi.fn().mockResolvedValue({ data: { transactionHash: "0xtx" } }),
  buildSponsoredDeploy: vi.fn().mockResolvedValue({ data: { typedData: {}, deployment: {}, calls: [] } }),
  provisionRecipientWallet: vi.fn().mockResolvedValue({ data: { walletAddress: "0xauthorwallet" } }),
}));

describe("tokenizeBulk", () => {
  beforeEach(() => {
    global.window = {
      medialaneData: {
        collectionContract: "0xcol",
        contentScope: "excerpt",
        restUrl: "/wp-json/medialane/v1",
        nonce: "abc",
        posts: {
          1: { title: "A", excerpt: "a", content: "aa", image: "", authorEmail: "a@example.com" },
          2: { title: "B", excerpt: "b", content: "bb", image: "", authorEmail: "b@example.com" },
        },
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
      postIds.map((id) => [id, { title: "t", excerpt: "e", content: "c", image: "", authorEmail: `author${id}@example.com` }])
    );

    const { executeSponsoredInvoke } = await import("../../assets/src/api.js");
    executeSponsoredInvoke.mockClear();

    await tokenizeBulk(postIds, () => {});

    expect(executeSponsoredInvoke).toHaveBeenCalledTimes(2);
  });
});

describe("buildStatusPanel", () => {
  it("lists every post and updates its row as progress comes in", () => {
    const data = { posts: { 1: { title: "A" }, 2: { title: "B" } } };
    const panel = buildStatusPanel(["1", "2"], data);

    const rows = panel.element.querySelectorAll("li");
    expect(rows[0].textContent).toBe("A — Waiting…");
    expect(rows[1].textContent).toBe("B — Waiting…");

    panel.update("1", "minting");
    expect(rows[0].textContent).toBe("A — Signing & minting…");

    panel.update("2", "error", "Something went wrong");
    expect(rows[1].textContent).toBe("B — Failed: Something went wrong");
  });
});
