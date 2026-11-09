import { Badge } from '@/components/ui/Badge';
import { documentStatusLabels } from '@/modules/stock/hooks';

export function StatusBadge({ status }: { status: string }) {
  const s = documentStatusLabels[status] ?? { label: status, tone: 'neutral' as const };
  return <Badge tone={s.tone}>{s.label}</Badge>;
}
