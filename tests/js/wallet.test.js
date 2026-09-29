import { describe, it, expect, vi, beforeEach } from "vitest";

const getAvailableWallets = vi.fn();
const walletAccountConnect = vi.fn();

vi.mock("get-starknet-core", () => ({
  getStarknet: () => ({ getAvailableWallets }),
}));
vi.mock("starknet", () => ({
  RpcProvider: class {},
  WalletAccount: { connect: (...args) => walletAccountConnect(...args) },
  stark: { signatureToHexArray: (sig) => sig },
  ec: { starkCurve: {
    utils: { randomPrivateKey: () => new Uint8Array([1, 2, 3, 4]) },
    getStarkKey: (priv) => "0xpub" + Array.from(priv).join(""),
  } },
  Account: class {
    constructor(_provider, address, _privateKey) { this.address = address; }
    async signMessage(typedData) { return ["0xsig1", "0xsig2"]; }
  },
}));
vi.mock("@medialane/sdk/starknet", () => ({
  computeAccountAddress: (pubkey) => `0xaddr-for-${pubkey}`,
}));

const {
  connectWallet, executeCalls, signTypedData, waitForConfirmation,
  generateInterimKeypair, signDeploymentWithInterimKey,
} = await import("../../assets/src/wallet.js");

describe("connectWallet", () => {
  beforeEach(() => {
    getAvailableWallets.mockReset();
    walletAccountConnect.mockReset();
  });

  it("throws when no wallet extension is available", async () => {
    getAvailableWallets.mockResolvedValue([]);
    await expect(connectWallet()).rejects.toThrow("No Starknet wallet found. Install Ready or Braavos.");
    expect(walletAccountConnect).not.toHaveBeenCalled();
  });

  it("connects the first available wallet via WalletAccount.connect", async () => {
    const wallet = { id: "argentX", name: "Ready" };
    getAvailableWallets.mockResolvedValue([wallet]);
    walletAccountConnect.mockResolvedValue({ address: "0xabc" });

    const result = await connectWallet();

    expect(walletAccountConnect).toHaveBeenCalledWith(expect.anything(), wallet);
    expect(result).toEqual({ address: "0xabc", account: { address: "0xabc" } });
  });

  it("throws when the connected account has no address", async () => {
    getAvailableWallets.mockResolvedValue([{ id: "argentX" }]);
    walletAccountConnect.mockResolvedValue({ address: undefined });
    await expect(connectWallet()).rejects.toThrow("Wallet did not return an address.");
  });
});

describe("signTypedData", () => {
  it("signs the typed data and normalizes the signature to a hex array", async () => {
    const signMessage = vi.fn().mockResolvedValue(["0x1", "0x2"]);
    const account = { signMessage };
    const typedData = { domain: {}, message: {} };

    const signature = await signTypedData(account, typedData);

    expect(signMessage).toHaveBeenCalledWith(typedData);
    expect(signature).toEqual(["0x1", "0x2"]);
  });
});

describe("generateInterimKeypair", () => {
  it("derives a public key and address from a fresh random private key", () => {
    const keypair = generateInterimKeypair();
    expect(keypair.privateKey).toBeTruthy();
    expect(keypair.publicKey).toBe("0xpub1234");
    expect(keypair.address).toBe("0xaddr-for-0xpub1234");
  });
});

describe("signDeploymentWithInterimKey", () => {
  it("signs the deployment typed data with a throwaway Account built from the interim key", async () => {
    const signature = await signDeploymentWithInterimKey("0xpriv", "0xaddr", { domain: {}, message: {} });
    expect(signature).toEqual(["0xsig1", "0xsig2"]);
  });
});

describe("waitForConfirmation", () => {
  it("returns the receipt when the transaction succeeds", async () => {
    const receipt = { isReverted: () => false };
    const provider = { waitForTransaction: vi.fn().mockResolvedValue(receipt) };
    await expect(waitForConfirmation("0x123", provider)).resolves.toBe(receipt);
  });

  it("throws when the transaction reverted, even though it has a tx hash", async () => {
    const receipt = { isReverted: () => true, value: { revert_reason: "insufficient balance" } };
    const provider = { waitForTransaction: vi.fn().mockResolvedValue(receipt) };
    await expect(waitForConfirmation("0x123", provider)).rejects.toThrow("insufficient balance");
  });
});

describe("executeCalls", () => {
  it("throws when calls array is empty", async () => {
    const account = { execute: vi.fn() };
    await expect(executeCalls(account, [])).rejects.toThrow("No calls to execute.");
  });

  it("executes calls and waits for confirmation", async () => {
    const waitForTransaction = vi.fn().mockResolvedValue({ isReverted: () => false });
    const account = {
      execute: vi.fn().mockResolvedValue({ transaction_hash: "0x123" }),
      channel: { provider: { waitForTransaction } },
    };
    const txHash = await executeCalls(account, [{ contractAddress: "0x1", entrypoint: "mint", calldata: [] }]);
    expect(txHash).toBe("0x123");
    expect(waitForTransaction).toHaveBeenCalledWith("0x123");
  });

  it("throws when the transaction reverts, instead of returning a hash", async () => {
    const waitForTransaction = vi.fn().mockResolvedValue({ isReverted: () => true, value: { revert_reason: "execution failed" } });
    const account = {
      execute: vi.fn().mockResolvedValue({ transaction_hash: "0x123" }),
      channel: { provider: { waitForTransaction } },
    };
    await expect(executeCalls(account, [{ contractAddress: "0x1", entrypoint: "mint", calldata: [] }]))
      .rejects.toThrow("execution failed");
  });
});
