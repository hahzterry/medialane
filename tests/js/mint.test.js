import { describe, it, expect, vi, beforeEach } from "vitest";
import { tokenizePost } from "../../assets/src/metabox.js";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn().mockResolvedValue({ address: "0xabc", account: {} }),
  executeCalls: vi.fn().mockResolvedValue("0xtx"),
}));
vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0x1" }] } }),
}));

describe("tokenizePost", () => {
  beforeEach(() => {
    global.window = {
      medialaneData: {
        collectionContract: "0xcol",
        contentScope: "excerpt",
        postTitle: "Hello",
        postExcerpt: "World",
        postContent: "Full body",
        featuredImageUrl: "",
        restUrl: "/wp-json/medialane/v1",
        nonce: "abc",
      },
    };
    global.document = {
      getElementById: (id) => {
        if (id === "medialane-license") return { value: "All Rights Reserved" };
        if (id === "medialane-license-custom") return { value: "" };
        return null;
      },
    };
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
  });

  it("throws when no collection is configured", async () => {
    global.window.medialaneData.collectionContract = "";
    await expect(tokenizePost(1)).rejects.toThrow("No collection configured");
  });

  it("uploads metadata, mints, and marks the post minted", async () => {
    const txHash = await tokenizePost(1);
    expect(txHash).toBe("0xtx");
    expect(global.fetch).toHaveBeenCalledWith(
      "/wp-json/medialane/v1/posts/1/minted",
      expect.objectContaining({ method: "POST" })
    );
  });
});
