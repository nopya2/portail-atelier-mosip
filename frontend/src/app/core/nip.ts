import { AbstractControl, ValidationErrors, ValidatorFn } from '@angular/forms';

/**
 * NIP — Numéro d'Identification Personnel (Gabon) : CC-NNNN-AAAAMMJJ
 * (clé de contrôle alphanumérique, numéro d'ordre, date de naissance).
 * Même règles que App\Support\Nip côté Laravel.
 */
export const NIP_FORMAT = 'CC-NNNN-AAAAMMJJ';
export const NIP_EXAMPLE = '4A-0012-19870425';

const COMPACT = /^([0-9A-Z]{2})([0-9]{4})([0-9]{4})([0-9]{2})([0-9]{2})$/;

/** Forme canonique, ou null si la saisie est invalide. */
export function normalizeNip(value: string | null | undefined): string | null {
  const compact = (value ?? '').toUpperCase().replace(/[\s-]+/g, '');
  const m = COMPACT.exec(compact);
  if (!m) return null;
  const [, cle, ordre, annee, mois, jour] = m;
  const date = new Date(Date.UTC(+annee, +mois - 1, +jour));
  const valide =
    date.getUTCFullYear() === +annee && date.getUTCMonth() === +mois - 1 && date.getUTCDate() === +jour;
  if (!valide || date.getTime() > Date.now()) return null;
  return `${cle}-${ordre}-${annee}${mois}${jour}`;
}

/** Date de naissance (AAAA-MM-JJ) contenue dans le NIP. */
export function nipBirthdate(value: string | null | undefined): string | null {
  const nip = normalizeNip(value);
  return nip ? `${nip.slice(8, 12)}-${nip.slice(12, 14)}-${nip.slice(14, 16)}` : null;
}

/** Mise en forme pendant la saisie : majuscules et tirets insérés automatiquement. */
export function formatNipInput(value: string): string {
  const raw = value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 14);
  const parts = [raw.slice(0, 2), raw.slice(2, 6), raw.slice(6, 14)].filter((p) => p.length);
  return parts.join('-');
}

export const nipValidator: ValidatorFn = (control: AbstractControl): ValidationErrors | null => {
  if (!control.value) return null; // « required » est géré à part
  return normalizeNip(control.value) ? null : { nip: true };
};
