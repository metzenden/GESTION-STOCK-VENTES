import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';

export function useWarehouses() {
  return useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.GET('/warehouses')).data?.data ?? [],
  });
}

export function useWarehouseNames(): (id: string) => string {
  const { data } = useWarehouses();
  const names = new Map((data ?? []).map((w) => [w.id, w.name]));
  return (id: string) => names.get(id) ?? '—';
}

export const documentStatusLabels: Record<
  string,
  { label: string; tone: 'neutral' | 'success' | 'warning' | 'danger' }
> = {
  draft: { label: 'Brouillon', tone: 'neutral' },
  pending_approval: { label: 'En attente d’approbation', tone: 'warning' },
  posted: { label: 'Comptabilisé', tone: 'success' },
  rejected: { label: 'Rejeté', tone: 'danger' },
  cancelled: { label: 'Annulé', tone: 'neutral' },
  counting: { label: 'Comptage', tone: 'warning' },
  review: { label: 'Revue', tone: 'warning' },
  validated: { label: 'Validé', tone: 'success' },
  confirmed: { label: 'Confirmé', tone: 'warning' },
  in_transit: { label: 'En transit', tone: 'warning' },
  received: { label: 'Réceptionné', tone: 'success' },
  partially_received: { label: 'Partiellement reçu', tone: 'warning' },
  closed: { label: 'Clôturé', tone: 'neutral' },
  preparing: { label: 'En préparation', tone: 'neutral' },
  shipped: { label: 'Expédié', tone: 'warning' },
  arrived: { label: 'Arrivé', tone: 'warning' },
  unloading: { label: 'Déchargement', tone: 'warning' },
  open: { label: 'Ouvert', tone: 'warning' },
  partially_paid: { label: 'Partiellement réglé', tone: 'warning' },
  paid: { label: 'Réglé', tone: 'success' },
  uploaded: { label: 'Téléversée', tone: 'neutral' },
  in_review: { label: 'En revue', tone: 'warning' },
  computed: { label: 'Calculé', tone: 'warning' },
  final: { label: 'Définitif', tone: 'success' },
  superseded: { label: 'Remplacé', tone: 'neutral' },
  active: { label: 'Actif', tone: 'success' },
  retired: { label: 'Retiré', tone: 'neutral' },
  inactive: { label: 'Inactif', tone: 'neutral' },
  sent: { label: 'Envoyé', tone: 'warning' },
  accepted: { label: 'Accepté', tone: 'success' },
  converted: { label: 'Converti', tone: 'success' },
  expired: { label: 'Expiré', tone: 'neutral' },
  preparing_delivery: { label: 'En préparation', tone: 'warning' },
  ready: { label: 'Prête', tone: 'warning' },
  in_delivery: { label: 'En livraison', tone: 'warning' },
  delivered: { label: 'Livrée', tone: 'success' },
  failed: { label: 'Échec', tone: 'danger' },
  partially_delivered: { label: 'Partiellement livrée', tone: 'warning' },
  issued: { label: 'Émis', tone: 'success' },
  unpaid: { label: 'Non payée', tone: 'danger' },
  inspected: { label: 'Contrôlé', tone: 'warning' },
  reversed: { label: 'Contre-passé', tone: 'neutral' },
};

export const movementLabels: Record<string, string> = {
  receipt: 'Réception',
  sale_delivery: 'Livraison client',
  return_in: 'Retour client',
  transfer_in: 'Transfert entrant',
  transfer_out: 'Transfert sortant',
  adjustment_in: 'Ajustement +',
  adjustment_out: 'Ajustement −',
  loss: 'Perte',
  opening: 'Stock d’ouverture',
  reversal: 'Contre-écriture',
};
