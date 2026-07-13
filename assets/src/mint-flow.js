import { executeCalls } from "./wallet.js";
import { uploadJson, createMintIntent } from "./api.js";

async function postJson(restUrl, nonce, path, body) {
  await fetch(`${restUrl}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
    body: JSON.stringify(body),
  });
}

export function markMinting(restUrl, nonce, postId) {
  return postJson(restUrl, nonce, `/posts/${postId}/minting`, {});
}

export function markMinted(restUrl, nonce, postId, data) {
  return postJson(restUrl, nonce, `/posts/${postId}/minted`, data);
}

export function markError(restUrl, nonce, postId, message) {
  return postJson(restUrl, nonce, `/posts/${postId}/error`, { message });
}

/**
 * Uploads metadata, creates a mint intent, and executes it with the connected
 * wallet — the single mint sequence shared by the per-post metabox and the
 * Posts-list bulk action. Callers own connecting the wallet and reporting the
 * outcome (mark_error) on failure; this only marks "minting" and "minted".
 */
export async function tokenizeOne({ restUrl, nonce, postId, collectionContract, title, body, image, license, account, address }) {
  if (!collectionContract) {
    throw new Error("No collection configured. Connect a wallet in Medialane Settings first.");
  }

  await markMinting(restUrl, nonce, postId);

  const metaRes = await uploadJson({ name: title, description: body, image: image || undefined, license });
  const tokenUri = metaRes.data.url;

  const intentRes = await createMintIntent({
    owner: address,
    collectionId: collectionContract,
    recipient: address,
    tokenUri,
    royaltyBps: 0,
  });
  const txHash = await executeCalls(account, intentRes.data.calls);

  // tokenId is intentionally not resolved client-side: mip-erc721 assigns it
  // on-chain during execution and medialane-starknet's own mint flow doesn't
  // read it back either — the tx hash is the authoritative reference; the
  // indexer backfills the token row asynchronously.
  await markMinted(restUrl, nonce, postId, { tokenId: "", txHash, contract: collectionContract, license });

  return txHash;
}
