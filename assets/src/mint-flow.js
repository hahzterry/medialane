import { signTypedData } from "./wallet.js";
import { uploadJson, createMintIntent, buildSponsoredInvoke, executeSponsoredInvoke } from "./api.js";

async function postJson(restUrl, nonce, path, body) {
  await fetch(`${restUrl}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
    body: JSON.stringify(body),
  });
}

function markMinting(restUrl, nonce, postId) {
  return postJson(restUrl, nonce, `/posts/${postId}/minting`, {});
}

function markMinted(restUrl, nonce, postId, data) {
  return postJson(restUrl, nonce, `/posts/${postId}/minted`, data);
}

function markError(restUrl, nonce, postId, message) {
  return postJson(restUrl, nonce, `/posts/${postId}/error`, { message });
}

// Does not touch the chain or post meta — callers batch these together before executing.
export async function prepareMint({ postId, title, body, image, license, address, collectionContract }) {
  const metaRes = await uploadJson({ name: title, description: body, image: image || undefined, license });
  const intentRes = await createMintIntent({
    owner: address,
    collectionId: collectionContract,
    recipient: address,
    tokenUri: metaRes.data.url,
    royaltyBps: 0,
  });
  return { postId, license, calls: intentRes.data.calls };
}

// One on-chain transaction covers every entry, so they succeed or fail together.
export async function executeMintBatch({ restUrl, nonce, account, address, collectionContract, entries }) {
  if (!collectionContract) {
    throw new Error("No collection configured. Connect a wallet in Medialane Settings first.");
  }
  await Promise.all(entries.map((e) => markMinting(restUrl, nonce, e.postId)));

  const calls = entries.flatMap((e) => e.calls);
  try {
    const buildRes = await buildSponsoredInvoke({ userAddress: address, calls });
    const signature = await signTypedData(account, buildRes.data.typedData);
    const execRes = await executeSponsoredInvoke({ userAddress: address, typedData: buildRes.data.typedData, signature, calls });
    const txHash = execRes.data.transactionHash;

    await Promise.all(entries.map((e) =>
      markMinted(restUrl, nonce, e.postId, { tokenId: "", txHash, contract: collectionContract, license: e.license })
    ));
    return entries.map((e) => ({ postId: e.postId, txHash }));
  } catch (err) {
    const message = err.message || "Something went wrong";
    await Promise.all(entries.map((e) => markError(restUrl, nonce, e.postId, message)));
    return entries.map((e) => ({ postId: e.postId, error: message }));
  }
}
