import { getStarknet } from "get-starknet-core";
import { RpcProvider, WalletAccount } from "starknet";

export async function connectWallet() {
  const wallets = await getStarknet().getAvailableWallets();
  if (!wallets || wallets.length === 0) {
    throw new Error("No Starknet wallet found. Install Ready or Braavos.");
  }
  // get-starknet-core v4's StarknetWindowObject only exposes a raw request()
  // method, not an .account with .execute() the way older versions did —
  // WalletAccount.connect() is starknet.js's own bridge for exactly this,
  // and requests account access from the wallet as part of connecting.
  const account = await WalletAccount.connect(new RpcProvider(), wallets[0]);
  if (!account?.address) {
    throw new Error("Wallet did not return an address.");
  }
  return { address: account.address, account };
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
