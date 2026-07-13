import { connectWallet } from "./wallet.js";
import { tokenizeOne, markError } from "./mint-flow.js";

export async function tokenizeBulk(postIds, onProgress) {
  const data = window.medialaneData;
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
      await tokenizeOne({
        restUrl: data.restUrl,
        nonce: data.nonce,
        postId,
        collectionContract: data.collectionContract,
        title: post.title,
        body,
        image: post.image,
        license: "All Rights Reserved",
        account,
        address,
      });
      onProgress && onProgress(postId, "minted");
    } catch (err) {
      await markError(data.restUrl, data.nonce, postId, err.message || "Something went wrong");
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
