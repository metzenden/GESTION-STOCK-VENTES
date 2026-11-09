/** Affichage des dates (fuseau de Dakar, format français). */
const dateTime = new Intl.DateTimeFormat('fr-FR', {
  dateStyle: 'short',
  timeStyle: 'short',
  timeZone: 'Africa/Dakar',
});

export function formatDateTime(iso: string | null | undefined): string {
  return iso === null || iso === undefined ? '—' : dateTime.format(new Date(iso));
}
