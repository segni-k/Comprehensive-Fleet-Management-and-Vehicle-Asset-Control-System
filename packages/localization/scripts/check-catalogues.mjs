import { readFileSync } from "node:fs";

const files = ["en.ts", "om.ts", "am.ts"];
const keyPattern = /^\s*"([^"]+)":/gm;
const keySets = files.map((file) => {
  const source = readFileSync(
    new URL(`../src/catalogues/${file}`, import.meta.url),
    "utf8",
  );
  return new Set([...source.matchAll(keyPattern)].map((match) => match[1]));
});
const sourceKeys = [...keySets[0]].sort();

for (const [index, keys] of keySets.entries()) {
  const missing = sourceKeys.filter((key) => !keys.has(key));
  const extra = [...keys].filter((key) => !keySets[0].has(key));
  if (missing.length || extra.length) {
    console.error(
      `${files[index]} missing=${missing.join(",")} extra=${extra.join(",")}`,
    );
    process.exitCode = 1;
  }
}

if (!process.exitCode) {
  console.log(`Translation catalogues aligned: ${sourceKeys.length} keys`);
}
