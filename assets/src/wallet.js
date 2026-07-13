import { connect } from "get-starknet-core";
import { RpcProvider } from "starknet";

export async function connectWallet() {
  const swo = await connect({ modalMode: "alwaysAsk" });
  if (!swo) {
    throw new Error("No Starknet wallet found. Install Ready or Braavos.");
  }
  await swo.enable();
  const address = swo.selectedAddress || swo.account?.address;
  if (!address) {
    throw new Error("Wallet did not return an address.");
  }
  return { address, account: swo.account };
}

export async function executeCalls(account, calls) {
  if (!calls || calls.length === 0) {
    throw new Error("No calls to execute.");
  }
  const { transaction_hash } = await account.execute(calls);
  const provider = account.channel?.provider ?? new RpcProvider();
  await provider.waitForTransaction(transaction_hash);
  return transaction_hash;
}
