import type { Locale } from "@oromia/localization";
import Link from "next/link";
import type { OrganizationTreeNode } from "@/organization/types";

export function OrganizationTree({
  nodes,
  locale,
}: {
  readonly nodes: readonly OrganizationTreeNode[];
  readonly locale: Locale;
}) {
  if (nodes.length === 0) return null;

  return (
    <ul className="organization-tree">
      {nodes.map((node) => (
        <li key={node.id}>
          <Link href={`/organizations/${node.id}`}>
            <strong>{node.name[locale]}</strong>
            <span className="status-badge">{node.status}</span>
          </Link>
          <OrganizationTree nodes={node.children} locale={locale} />
        </li>
      ))}
    </ul>
  );
}
