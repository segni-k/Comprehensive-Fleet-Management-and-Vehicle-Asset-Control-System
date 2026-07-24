import { existsSync, readFileSync } from "node:fs";

const required = [
  "README.md",
  "CONTRIBUTING.md",
  "SECURITY.md",
  "docs/development/local-setup.md",
  "docs/development/repository-structure.md",
  "docs/development/coding-standards.md",
  "docs/development/testing-guide.md",
  "docs/development/environment-variables.md",
  "docs/development/api-contract-workflow.md",
  "docs/development/localization-guide.md",
  "docs/development/mobile-nativewind-guide.md",
  "docs/operations/health-and-readiness.md",
  "docs/operations/logging-and-correlation.md",
  "docs/operations/queue-and-scheduler.md",
  "docs/operations/troubleshooting.md",
  "docs/architecture/milestone-1-implementation-notes.md",
  "docs/ui-ux/mobile-design-system.md",
];
const missing = required.filter((file) => !existsSync(file));
if (missing.length)
  throw new Error(`Missing documentation: ${missing.join(", ")}`);

for (const file of required.filter((value) => value.endsWith(".md"))) {
  const source = readFileSync(file, "utf8");
  if ((source.match(/```/g) ?? []).length % 2 !== 0)
    throw new Error(`Unbalanced Markdown fence: ${file}`);
}
console.log(`Required documentation present: ${required.length} files`);
