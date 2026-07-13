import { connectWallet, executeCalls } from "./wallet.js";
import { uploadJson, createMintIntent } from "./api.js";

async function markMinted(postId, data) {
  await fetch(`${window.medialaneBulkData.restUrl}/posts/${postId}/minted`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": window.medialaneBulkData.nonce },
    body: JSON.stringify(data),
  });
}

async function markError(postId, message) {
  await fetch(`${window.medialaneBulkData.restUrl}/posts/${postId}/error`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": window.medialaneBulkData.nonce },
    body: JSON.stringify({ message }),
  });
}

export async function tokenizeBulk(postIds, onProgress) {
  const data = window.medialaneBulkData;
  if (!data.collectionContract) {
    throw new Error("No collection configured.");
  }
  const { address, account } = await connectWallet();

  for (const postId of postIds) {
    const post = data.posts[postId];
    if (!post) continue;
    onProgress && onProgress(postId, "minting");
    try {
      const body = data.contentScope === "full" ? post.content : post.excerpt;
      const metaRes = await uploadJson({ name: post.title, description: body, image: post.image || undefined, license: "All Rights Reserved" });
      const intentRes = await createMintIntent({
        owner: address,
        collectionId: data.collectionContract,
        recipient: address,
        tokenUri: metaRes.data.url,
        royaltyBps: 0,
      });
      const txHash = await executeCalls(account, intentRes.data.calls);
      await markMinted(postId, { tokenId: "", txHash, contract: data.collectionContract, license: "All Rights Reserved" });
      onProgress && onProgress(postId, "minted");
    } catch (err) {
      await markError(postId, err.message || "Something went wrong");
      onProgress && onProgress(postId, "error", err.message);
    }
  }
}

document.addEventListener("submit", (e) => {
  const form = e.target.closest && e.target.closest("#posts-filter");
  if (!form) return;
  const select = form.querySelector('select[name="action"], select[name="action2"]');
  if (!select || select.value !== "medialane_tokenize") return;
  e.preventDefault();
  const ids = Array.from(form.querySelectorAll('input[name="post[]"]:checked')).map((el) => el.value);
  tokenizeBulk(ids, (id, status) => console.log(`post ${id}: ${status}`)).then(() => location.reload());
});
