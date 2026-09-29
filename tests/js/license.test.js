import { describe, it, expect } from "vitest";
import { buildLicenseAttributes } from "../../assets/src/license.js";

describe("buildLicenseAttributes", () => {
  it("expands the CC BY-SA preset into the platform's canonical trait set", () => {
    expect(buildLicenseAttributes("CC BY-SA", "Allowed")).toEqual([
      { trait_type: "License", value: "CC BY-SA" },
      { trait_type: "Commercial Use", value: "Yes" },
      { trait_type: "Derivatives", value: "Share-Alike" },
      { trait_type: "Attribution", value: "Required" },
      { trait_type: "Territory", value: "Worldwide" },
      { trait_type: "AI Policy", value: "Allowed" },
    ]);
  });

  it("expands All Rights Reserved to no commercial use, no derivatives", () => {
    expect(buildLicenseAttributes("All Rights Reserved", "Not Allowed")).toEqual([
      { trait_type: "License", value: "All Rights Reserved" },
      { trait_type: "Commercial Use", value: "No" },
      { trait_type: "Derivatives", value: "Not Allowed" },
      { trait_type: "Attribution", value: "Required" },
      { trait_type: "Territory", value: "Worldwide" },
      { trait_type: "AI Policy", value: "Not Allowed" },
    ]);
  });

  it("expands CC0 to no attribution required", () => {
    const attrs = buildLicenseAttributes("CC0", "Allowed");
    expect(attrs).toContainEqual({ trait_type: "Attribution", value: "Not Required" });
  });

  it("does not fabricate Commercial Use/Derivatives/Attribution for a Custom license", () => {
    const attrs = buildLicenseAttributes("Custom", "Allowed");
    expect(attrs).toEqual([
      { trait_type: "License", value: "Custom" },
      { trait_type: "Territory", value: "Worldwide" },
      { trait_type: "AI Policy", value: "Allowed" },
    ]);
  });
});
