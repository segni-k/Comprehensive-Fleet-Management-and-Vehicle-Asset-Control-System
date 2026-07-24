import { readFileSync } from "node:fs";
import YAML from "yaml";

const document = YAML.parse(readFileSync("docs/api/openapi.yaml", "utf8"));
if (document.openapi !== "3.1.0") throw new Error("OpenAPI must be 3.1.0");

const operationIds = [];
const methods = new Set(["get", "post", "put", "patch", "delete"]);
for (const [path, item] of Object.entries(document.paths)) {
  for (const [method, operation] of Object.entries(item)) {
    if (!methods.has(method)) continue;
    if (!operation.operationId)
      throw new Error(`${method.toUpperCase()} ${path} has no operationId`);
    operationIds.push(operation.operationId);
  }
}
const duplicates = operationIds.filter(
  (id, index) => operationIds.indexOf(id) !== index,
);
if (duplicates.length)
  throw new Error(
    `Duplicate operation IDs: ${[...new Set(duplicates)].join(", ")}`,
  );

function resolveReference(reference) {
  let current = document;
  for (const raw of reference.slice(2).split("/")) {
    const key = raw.replaceAll("~1", "/").replaceAll("~0", "~");
    if (!(key in current))
      throw new Error(`Unresolved local reference: ${reference}`);
    current = current[key];
  }
}
function visit(value) {
  if (Array.isArray(value)) value.forEach(visit);
  else if (value && typeof value === "object") {
    if (typeof value.$ref === "string" && value.$ref.startsWith("#/"))
      resolveReference(value.$ref);
    Object.values(value).forEach(visit);
  }
}
visit(document);
console.log(
  `OpenAPI structure valid: ${Object.keys(document.paths).length} paths, ${operationIds.length} unique operations`,
);
