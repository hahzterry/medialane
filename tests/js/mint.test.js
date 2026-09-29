import { describe, it, expect, vi, beforeEach } from "vitest";
import { tokenizePost } from "../../assets/src/metabox.js";

vi.mock("../../assets/src/wallet.js", () => ({
  connectWallet: vi.fn().mockResolvedValue({ address: "0xabc", account: {} }),
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
  waitForConfirmation: vi.fn().mockResolvedValue({ isReverted: () => false }),
  mintedTokenIdsFromReceipt: vi.fn().mockReturnValue(["1"]),
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
        authorEmail: "author@example.com",
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
