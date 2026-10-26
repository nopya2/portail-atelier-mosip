import { formatNipInput, nipBirthdate, normalizeNip } from './nip';

describe('NIP', () => {
  it('normalise les saisies valides', () => {
    expect(normalizeNip('4A-0012-19870425')).toBe('4A-0012-19870425');
    expect(normalizeNip(' 4a 0012 19870425 ')).toBe('4A-0012-19870425');
    expect(normalizeNip('4A001219870425')).toBe('4A-0012-19870425');
  });

  it('rejette les saisies invalides', () => {
    for (const v of ['', '4A-012-19870425', '4AB-0012-19870425', '4A-0012-19870231', '4A-0012-29990101', '4#-0012-19870425']) {
      expect(normalizeNip(v)).toBeNull();
    }
  });

  it('met en forme pendant la saisie', () => {
    expect(formatNipInput('4a')).toBe('4A');
    expect(formatNipInput('4a00')).toBe('4A-00');
    expect(formatNipInput('4a0012198704251234')).toBe('4A-0012-19870425');
  });

  it('extrait la date de naissance', () => {
    expect(nipBirthdate('4A-0012-19870425')).toBe('1987-04-25');
  });
});
