import { connectWallet, executeCalls } from "./wallet.js";
import { createCollectionIntent, syncCollectionTx, saveCollectionContract, getCollectionsByOwner } from "./api.js";

export async function pollForCollection(owner, attempts = 10) {
  for (let i = 0; i < attempts; i++) {
    try {
      const body = await getCollectionsByOwner(owner);
      const list = (body.data && body.data.items) || body.data || [];
      if (Array.isArray(list) && list.length > 0) {
        return list[0].contract || list[0].address;
      }
    } catch {
      // Not indexed yet, or a transient error — keep polling until attempts run out.
    }
    await new Promise((r) => setTimeout(r, 3000));
  }
  return null;
}

document.addEventListener("DOMContentLoaded", () => {
  const button = document.getElementById("medialane-connect-wallet");
  if (!button) return;
  button.addEventListener("click", async () => {
    button.disabled = true;
    button.textContent = "Connecting…";
    try {
      const { address, account } = await connectWallet();
      document.getElementById("medialane_wallet_address").value = address;
      document.getElementById("medialane-wallet-status").textContent = address;

      if (!window.medialaneData.collectionContract) {
        button.textContent = "Creating collection…";
        const intentRes = await createCollectionIntent({
          owner: address,
          name: window.medialaneData.siteName || "Medialane Blog",
          symbol: "POST",
        });
        const calls = intentRes.data && intentRes.data.calls;
        const txHash = await executeCalls(account, calls);
        // Best-effort: pollForCollection below still succeeds via the indexer's own
        // polling if this eager sync fails, just slower — but a silently-swallowed
        // failure here once hid a real broken-route bug for a while, so log it.
        await syncCollectionTx(txHash).catch((err) => console.warn("Medialane: eager tx sync failed", err));

        button.textContent = "Confirming collection…";
        const contract = await pollForCollection(address);
        if (contract) {
          await saveCollectionContract(contract);
          window.medialaneData.collectionContract = contract;
        }
      }
      button.textContent = "Connected";
    } catch (err) {
      button.disabled = false;
      button.textContent = "Connect Wallet";
      alert(err.message || "Failed to connect wallet.");
    }
  });
});
