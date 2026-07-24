import { catalogues, translate } from "@oromia/localization";

describe("localization foundation", () => {
  it("loads every supported catalogue with matching keys", () => {
    const source = Object.keys(catalogues.en).sort();
    expect(Object.keys(catalogues.om).sort()).toEqual(source);
    expect(Object.keys(catalogues.am).sort()).toEqual(source);
    expect(translate("en", "state.offline")).toBe("Offline");
  });
});
