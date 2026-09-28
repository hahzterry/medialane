import { connectWallet } from "./wallet.js";
import { prepareMint, executeMintBatch } from "./mint-flow.js";

const MAX_BATCH_SIZE = 25;

function chunk(array, size) {
  const out = [];
  for (let i = 0; i < array.length; i += size) out.push(array.slice(i, i + size));
  return out;
}

export async function tokenizeBulk(postIds, onProgress) {
  const data = window.medialaneData;
  if (!data.collectionContract) {
    throw new Error("No collection configured.");
  }
  const { address, account } = await connectWallet();

  const entries = [];
  for (const postId of postIds) {
    const post = data.posts[postId];
    if (!post) continue;
    onProgress && onProgress(postId, "preparing");
    const body = data.contentScope === "full" ? post.content : post.excerpt;
    entries.push(await prepareMint({
      postId, title: post.title, body, image: post.image, license: "All Rights Reserved", address,
      collectionContract: data.collectionContract,
    }));
  }

  for (const group of chunk(entries, MAX_BATCH_SIZE)) {
    group.forEach((e) => onProgress && onProgress(e.postId, "minting"));
    const results = await executeMintBatch({
      restUrl: data.restUrl, nonce: data.nonce, account, address,
      collectionContract: data.collectionContract, entries: group,
    });
    results.forEach((r) => onProgress && onProgress(r.postId, r.error ? "error" : "minted", r.error));
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
