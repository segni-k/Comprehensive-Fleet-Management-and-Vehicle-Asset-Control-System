import { readFileSync, writeFileSync } from "node:fs";
import { parse } from "yaml";

const schemaPath = new URL("../../../docs/api/openapi.yaml", import.meta.url);
const outputPath = new URL("../src/generated.ts", import.meta.url);
const document = parse(readFileSync(schemaPath, "utf8"));
const methods = ["get", "post", "put", "patch", "delete"];
const operations = [];

for (const [path, pathItem] of Object.entries(document.paths ?? {})) {
  for (const method of methods) {
    const operation = pathItem?.[method];
    if (!operation?.operationId) continue;
    operations.push({
      id: operation.operationId,
      method: method.toUpperCase(),
      path,
    });
  }
}

operations.sort((left, right) => left.id.localeCompare(right.id));
const lines = [
  "/* This file is generated from docs/api/openapi.yaml. Do not edit manually. */",
  "",
  "export interface ApiOperationMap {",
  ...operations.map(
    ({ id, method, path }) =>
      `  ${JSON.stringify(id)}: { method: ${JSON.stringify(method)}; path: ${JSON.stringify(path)} };`,
  ),
  "}",
  "",
  "export type ApiOperationId = keyof ApiOperationMap;",
  "export type ApiOperation<K extends ApiOperationId> = ApiOperationMap[K];",
  "",
];

writeFileSync(outputPath, `${lines.join("\n")}\n`);
console.log(`Generated ${operations.length} operation contracts`);
