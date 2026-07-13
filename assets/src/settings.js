import { connectWallet, executeCalls } from "./wallet.js";
import { createCollectionIntent, syncCollectionTx } from "./api.js";

async function saveCollectionContract(contract) {
  await fetch(`${window.medialaneData.restUrl}/settings/collection`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": window.medialaneData.nonce },
    body: JSON.stringify({ contract }),
  });
}

export async function pollForCollection(owner, attempts = 10) {
  for (let i = 0; i < attempts; i++) {
    const res = await fetch(`${window.medialaneData.restUrl}/collections?owner=${owner}`, {
      headers: { "X-WP-Nonce": window.medialaneData.nonce },
    });
    const body = await res.json().catch(() => ({}));
    const list = (body.data && body.data.items) || body.data || [];
    if (Array.isArray(list) && list.length > 0) {
      return list[0].contract || list[0].address;
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
        await syncCollectionTx(txHash).catch(() => {});

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
