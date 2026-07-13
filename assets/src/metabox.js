import { connectWallet, executeCalls } from "./wallet.js";
import { uploadJson, createMintIntent } from "./api.js";

async function markMinted(postId, data) {
  await fetch(`${window.medialaneData.restUrl}/posts/${postId}/minted`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": window.medialaneData.nonce },
    body: JSON.stringify(data),
  });
}

async function markError(postId, message) {
  await fetch(`${window.medialaneData.restUrl}/posts/${postId}/error`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": window.medialaneData.nonce },
    body: JSON.stringify({ message }),
  });
}

export async function tokenizePost(postId) {
  const data = window.medialaneData;
  if (!data.collectionContract) {
    throw new Error("No collection configured. Connect a wallet in Medialane Settings first.");
  }

  const licenseSelect = document.getElementById("medialane-license");
  const licenseCustom = document.getElementById("medialane-license-custom");
  const license = licenseSelect.value === "Custom" ? licenseCustom.value : licenseSelect.value;

  const { address, account } = await connectWallet();

  const body = data.contentScope === "full" ? data.postContent : data.postExcerpt;
  const metaRes = await uploadJson({
    name: data.postTitle,
    description: body,
    image: data.featuredImageUrl || undefined,
    license,
  });
  const tokenUri = metaRes.data.url;

  const intentRes = await createMintIntent({
    owner: address,
    collectionId: data.collectionContract,
    recipient: address,
    tokenUri,
    royaltyBps: 0,
  });
  const calls = intentRes.data.calls;
  const txHash = await executeCalls(account, calls);

  // tokenId is intentionally not resolved client-side: mip-erc721 assigns it
  // on-chain during execution and medialane-starknet's own mint flow doesn't
  // read it back either — the tx hash is the authoritative reference the
  // metabox links to; the indexer backfills the token row asynchronously.
  await markMinted(postId, {
    tokenId: "",
    txHash,
    contract: data.collectionContract,
    license,
  });

  return txHash;
}

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("medialane-tokenize-btn");
  if (!btn) return;
  btn.addEventListener("click", async () => {
    const postId = document.getElementById("medialane-metabox").dataset.postId;
    btn.disabled = true;
    btn.textContent = "Tokenizing…";
    try {
      await tokenizePost(postId);
      location.reload();
    } catch (err) {
      await markError(postId, err.message || "Something went wrong");
      btn.disabled = false;
      btn.textContent = "Tokenize Post";
      alert(err.message || "Something went wrong");
    }
  });

  const licenseSelect = document.getElementById("medialane-license");
  const licenseCustom = document.getElementById("medialane-license-custom");
  if (licenseSelect) {
    licenseSelect.addEventListener("change", () => {
      licenseCustom.style.display = licenseSelect.value === "Custom" ? "block" : "none";
    });
  }
});
