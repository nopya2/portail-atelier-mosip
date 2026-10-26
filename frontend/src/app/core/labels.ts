/** Libellés des informations de profil reprises dans les demandes. */
export const INFO_LABELS: Record<string, string> = {
  nip: 'NIP',
  name: 'Nom complet',
  given_name: 'Prénom',
  family_name: 'Nom',
  birthdate: 'Date de naissance',
  gender: 'Genre',
  email: 'Email',
  phone_number: 'Téléphone',
  address: 'Adresse',
};

export function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'object') {
    return Object.values(value as Record<string, unknown>)
      .filter((v) => v)
      .join(', ');
  }
  return String(value);
}
