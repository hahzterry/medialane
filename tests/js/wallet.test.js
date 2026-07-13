import { describe, it, expect, vi } from "vitest";
import { executeCalls } from "../../assets/src/wallet.js";

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
