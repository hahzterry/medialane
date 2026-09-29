import { connectWallet, executeCalls } from "./wallet.js";
import {
  createCollectionIntent, syncCollectionTx, getCollectionsByOwner,
  saveCollectionEntry, saveCategoryMap,
} from "./api.js";

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

// Creates a collection on-chain, waits for the indexer to pick it up, then
// registers it under a label in Settings. Reused for the site's first
// collection (Connect Wallet) and for every additional named collection.
export async function createAndRegisterCollection(address, account, label) {
  const intentRes = await createCollectionIntent({ owner: address, name: label, symbol: "POST" });
  const calls = intentRes.data && intentRes.data.calls;
  const txHash = await executeCalls(account, calls);
  await syncCollectionTx(txHash).catch((err) => console.warn("Medialane: eager tx sync failed", err));

  const contract = await pollForCollection(address);
  if (contract) {
    await saveCollectionEntry({ contract, label });
  }
  return contract;
}

export function collectCategoryMap(rows) {
  const map = {};
  for (const row of rows) {
    if (row.value) map[row.categoryId] = row.value;
  }
  return map;
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
        const contract = await createAndRegisterCollection(address, account, window.medialaneData.siteName || "Medialane Blog");
        if (contract) {
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

  const createCollectionBtn = document.getElementById("medialane-create-collection");
  const newCollectionLabel = document.getElementById("medialane-new-collection-label");
  if (createCollectionBtn && newCollectionLabel) {
    createCollectionBtn.addEventListener("click", async () => {
      const label = newCollectionLabel.value.trim();
      if (!label) {
        alert("Give the new collection a name first.");
        return;
      }
      createCollectionBtn.disabled = true;
      createCollectionBtn.textContent = "Creating…";
      try {
        const { address, account } = await connectWallet();
        await createAndRegisterCollection(address, account, label);
        location.reload();
      } catch (err) {
        createCollectionBtn.disabled = false;
        createCollectionBtn.textContent = "Create Collection";
        alert(err.message || "Failed to create collection.");
      }
    });
  }

  const saveMapBtn = document.getElementById("medialane-save-category-map");
  if (saveMapBtn) {
    saveMapBtn.addEventListener("click", async () => {
      const rows = Array.from(document.querySelectorAll(".medialane-category-collection-select")).map((el) => ({
        categoryId: el.dataset.categoryId,
        value: el.value,
      }));
      saveMapBtn.disabled = true;
      saveMapBtn.textContent = "Saving…";
      try {
        await saveCategoryMap(collectCategoryMap(rows));
        saveMapBtn.textContent = "Saved";
      } catch (err) {
        alert(err.message || "Failed to save the category mapping.");
      } finally {
        saveMapBtn.disabled = false;
        if (saveMapBtn.textContent !== "Saved") saveMapBtn.textContent = "Save Mapping";
      }
    });
  }
});
