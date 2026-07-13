function base() {
  return (window.medialaneData && window.medialaneData.restUrl) || "/wp-json/medialane/v1";
}

function nonce() {
  return (window.medialaneData && window.medialaneData.nonce) || "";
}

async function request(path, opts = {}) {
  const res = await fetch(`${base()}${path}`, {
    ...opts,
    headers: {
      "X-WP-Nonce": nonce(),
      ...(opts.body && !(opts.body instanceof FormData) ? { "Content-Type": "application/json" } : {}),
      ...(opts.headers || {}),
    },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(body.message || body.error || `Medialane request failed (${res.status})`);
  }
  return body;
}

export function uploadJson(payload) {
  return request("/metadata/upload", { method: "POST", body: JSON.stringify(payload) });
}

export function uploadFile(file) {
  const form = new FormData();
  form.append("file", file, file.name);
  return request("/metadata/upload-file", { method: "POST", body: form });
}

export function createCollectionIntent(params) {
  return request("/intents/create-collection", { method: "POST", body: JSON.stringify(params) });
}

export function createMintIntent(params) {
  return request("/intents/mint", { method: "POST", body: JSON.stringify(params) });
}

export function syncCollectionTx(txHash) {
  return request("/collections/sync-tx", { method: "POST", body: JSON.stringify({ txHash }) });
}

export async function getToken(contract, tokenId) {
  try {
    return await request(`/tokens/${contract}/${tokenId}`, { method: "GET" });
  } catch {
    return null;
  }
}
