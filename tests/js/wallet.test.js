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
}));

const { connectWallet, executeCalls, signTypedData } = await import("../../assets/src/wallet.js");

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

describe("executeCalls", () => {
  it("throws when calls array is empty", async () => {
    const account = { execute: vi.fn() };
    await expect(executeCalls(account, [])).rejects.toThrow("No calls to execute.");
  });

  it("executes calls and waits for confirmation", async () => {
    const waitForTransaction = vi.fn().mockResolvedValue(undefined);
    const account = {
      execute: vi.fn().mockResolvedValue({ transaction_hash: "0x123" }),
      channel: { provider: { waitForTransaction } },
    };
    const txHash = await executeCalls(account, [{ contractAddress: "0x1", entrypoint: "mint", calldata: [] }]);
    expect(txHash).toBe("0x123");
    expect(waitForTransaction).toHaveBeenCalledWith("0x123");
  });
});
