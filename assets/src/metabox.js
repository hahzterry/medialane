import { connectWallet } from "./wallet.js";
import { tokenizeOne, markError } from "./mint-flow.js";

export async function tokenizePost(postId) {
  const data = window.medialaneData;

  const licenseSelect = document.getElementById("medialane-license");
  const licenseCustom = document.getElementById("medialane-license-custom");
  const license = licenseSelect.value === "Custom" ? licenseCustom.value : licenseSelect.value;

  const { address, account } = await connectWallet();
  const body = data.contentScope === "full" ? data.postContent : data.postExcerpt;

  return tokenizeOne({
    restUrl: data.restUrl,
    nonce: data.nonce,
    postId,
    collectionContract: data.collectionContract,
    title: data.postTitle,
    body,
    image: data.featuredImageUrl,
    license,
    account,
    address,
  });
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
      const data = window.medialaneData;
      await markError(data.restUrl, data.nonce, postId, err.message || "Something went wrong");
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
