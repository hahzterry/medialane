import { getStarknet } from "get-starknet-core";
import { RpcProvider, WalletAccount, stark, ec, Account } from "starknet";
import { computeAccountAddress } from "@medialane/sdk/starknet";

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

export async function signTypedData(account, typedData) {
  const signature = await account.signMessage(typedData);
  return stark.signatureToHexArray(signature);
}

// Used once, to authorize a newly provisioned author wallet's deployment.
// Never persisted: the caller uses it immediately and lets it go out of scope.
export function generateInterimKeypair() {
  const privateKeyBytes = ec.starkCurve.utils.randomPrivateKey();
  const privateKey = "0x" + Array.from(privateKeyBytes).map((b) => b.toString(16).padStart(2, "0")).join("");
  const publicKey = ec.starkCurve.getStarkKey(privateKeyBytes);
  const address = computeAccountAddress(publicKey);
  return { privateKey, publicKey, address };
}

export async function signDeploymentWithInterimKey(privateKey, address, typedData) {
  const account = new Account(new RpcProvider(), address, privateKey);
  const signature = await account.signMessage(typedData);
  return stark.signatureToHexArray(signature);
}

// A transaction hash alone proves nothing was rejected before broadcast, not
// that it landed on chain, and not that it succeeded once it did. Only a
// confirmed, non-reverted receipt from the chain itself is authoritative.
export async function waitForConfirmation(txHash, provider = new RpcProvider()) {
  const receipt = await provider.waitForTransaction(txHash);
  if (receipt.isReverted && receipt.isReverted()) {
    throw new Error(receipt.value?.revert_reason || "Transaction reverted");
  }
  return receipt;
}

export async function executeCalls(account, calls) {
  if (!calls || calls.length === 0) {
    throw new Error("No calls to execute.");
  }
  const { transaction_hash } = await account.execute(calls);
  await waitForConfirmation(transaction_hash, account.channel?.provider);
  return transaction_hash;
}
