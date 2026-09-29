import { connectWallet } from "./wallet.js";
import { prepareMint, executeMintBatch } from "./mint-flow.js";

export async function tokenizePost(postId) {
  const data = window.medialaneData;

  const licenseSelect = document.getElementById("medialane-license");
  const licenseCustom = document.getElementById("medialane-license-custom");
  const license = licenseSelect.value === "Custom" ? licenseCustom.value : licenseSelect.value;

  const { address, account } = await connectWallet();
  const body = data.contentScope === "full" ? data.postContent : data.postExcerpt;

  const entry = await prepareMint({
    postId, title: data.postTitle, body, image: data.featuredImageUrl, license, address,
    collectionContract: data.collectionContract, authorEmail: data.authorEmail,
  });
  const [result] = await executeMintBatch({
    restUrl: data.restUrl, nonce: data.nonce, account, address,
    collectionContract: data.collectionContract, entries: [entry],
  });
  if (result.error) {
    throw new Error(result.error);
  }
  return result.txHash;
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
