import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiError, EsignetIdentite, EsignetMode, EsignetRetour, Session } from '../models';

const RETOUR_KEY = 'portail.esignet.retour';   // page où revenir après le parcours
const MODE_KEY = 'portail.esignet.mode';
const JETON_KEY = 'portail.esignet.inscription'; // jeton de l'écran de finalisation

/**
 * Appels à l'API « création de compte avec eSignet » (/api/auth/esignet/*).
 * Le navigateur ne manipule jamais de jeton eSignet : tout l'OIDC est fait par le backend.
 * Les cookies (liaison du parcours au navigateur) passent automatiquement : l'API est sur la même origine.
 */
@Injectable({ providedIn: 'root' })
export class EsignetService {
  private readonly http = inject(HttpClient);
  private readonly api = `${environment.apiUrl}/auth/esignet`;

  /** Étape A : demande l'URL eSignet au backend puis y redirige le navigateur. */
  async demarrer(mode: EsignetMode, retour?: string): Promise<void> {
    sessionStorage.setItem(MODE_KEY, mode);
    if (retour) sessionStorage.setItem(RETOUR_KEY, retour);
    else sessionStorage.removeItem(RETOUR_KEY);

    const res = await firstValueFrom(this.http.post<{ authorization_url: string }>(`${this.api}/demarrer`, { mode }));
    window.location.assign(res.authorization_url);
  }

  /** Étapes B à F : transmet au backend ce qu'eSignet a renvoyé dans l'URL. */
  retour(params: { state: string; code: string | null; error: string | null; error_description: string | null }) {
    return firstValueFrom(this.http.post<EsignetRetour>(`${this.api}/retour`, params));
  }

  inscription(jeton: string) {
    return firstValueFrom(this.http.get<EsignetIdentite>(`${this.api}/inscription/${jeton}`));
  }

  finaliser(jeton: string, contact: { email: string | null; telephone: string | null; cgu: boolean }) {
    return firstValueFrom(this.http.post<Session>(`${this.api}/inscription/${jeton}`, contact));
  }

  // ---------------------------------------------------------------- état du parcours (sessionStorage)

  mode(): EsignetMode | null {
    return sessionStorage.getItem(MODE_KEY) as EsignetMode | null;
  }

  consommerRetour(): string | null {
    const url = sessionStorage.getItem(RETOUR_KEY);
    sessionStorage.removeItem(RETOUR_KEY);
    sessionStorage.removeItem(MODE_KEY);
    return url;
  }

  memoriserJeton(jeton: string): void {
    sessionStorage.setItem(JETON_KEY, jeton);
  }

  jeton(): string | null {
    return sessionStorage.getItem(JETON_KEY);
  }

  oublierJeton(): void {
    sessionStorage.removeItem(JETON_KEY);
  }
}

/** Messages affichés à l'usager pour les codes d'erreur du parcours (voir EsignetException côté Laravel). */
const MESSAGES: Record<string, string> = {
  access_denied: 'Vous avez annulé la connexion ou refusé le partage de vos informations.',
  consentement_refuse: 'Le partage de vos informations est nécessaire pour créer votre compte.',
  transaction_invalide: 'Cette demande de connexion n’est plus valable. Recommencez depuis le portail.',
  transaction_expiree: 'Le délai est dépassé. Recommencez la création de compte.',
  invalid_grant: 'Le délai de connexion est dépassé. Recommencez.',
  invalid_assertion: 'Erreur de configuration du portail auprès d’eSignet. Réessayez plus tard.',
  jeton_invalide: 'La réponse d’eSignet n’a pas pu être vérifiée. Recommencez.',
  nonce_invalide: 'La réponse d’eSignet n’a pas pu être vérifiée. Recommencez.',
  acr_insuffisant: 'Ce mode d’authentification ne suffit pas pour créer un compte. Utilisez le code reçu par SMS ou la biométrie.',
  claim_manquant: 'Une information nécessaire n’a pas été partagée. Recommencez en acceptant le partage.',
  nip_incoherent: 'Le NIP vérifié ne correspond pas à celui de votre compte. Contactez le support.',
  nip_deja_rattache: 'Ce NIP est déjà associé à un autre compte. Contactez le support.',
  inscription_expiree: 'Le délai pour finaliser votre compte est dépassé. Recommencez.',
  configuration_incomplete: 'Le service eSignet n’est pas encore configuré pour ce portail.',
};

/** Message lisible pour une erreur du parcours eSignet. */
export function messageEsignet(error: unknown): { message: string; nonImplemente?: string; reference?: string } {
  if (error instanceof HttpErrorResponse) {
    const body = error.error as ApiError | null;
    const reference = body?.request_id?.slice(0, 8);
    if (error.status === 501 && body?.error === 'non_implemente') {
      return { message: 'L’intégration eSignet est en cours de réalisation sur ce portail.', nonImplemente: body.etape ?? '?', reference };
    }
    if (error.status === 0) return { message: 'Le serveur est injoignable. Réessayez dans un instant.' };
    const firstValidation = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
    return {
      message: (body?.error && MESSAGES[body.error]) || firstValidation || body?.message || 'Une erreur est survenue.',
      reference,
    };
  }
  return { message: error instanceof Error ? error.message : 'Une erreur est survenue.' };
}

/** Libellés des codes d'erreur renvoyés par eSignet dans l'URL de retour (?error=…). */
export function messageErreurRetour(code: string, description: string | null): string {
  return MESSAGES[code] ?? description ?? `eSignet a renvoyé une erreur (${code}).`;
}
