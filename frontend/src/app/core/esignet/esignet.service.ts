import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiError, EsignetConfiguration, EsignetIdentite, EsignetMode, EsignetRetour, Session } from '../models';

const RETOUR_KEY = 'portail.esignet.retour';   // page où revenir après le parcours
const MODE_KEY = 'portail.esignet.mode';
const JETON_KEY = 'portail.esignet.inscription'; // jeton de l'écran de finalisation
const ERREUR_KEY = 'portail.esignet.erreur';     // erreur du démarrage, affichée par la page de retour

/** Erreurs que le bouton officiel renvoie sur la redirect_uri quand le par_callback échoue */
export const ERREURS_BOUTON = ['request_uri_error', 'request_uri_timeout'];

/**
 * Appels à l'API « création de compte avec eSignet » (/api/auth/esignet/*).
 * Le navigateur ne manipule jamais de jeton eSignet : tout l'OIDC est fait par le backend.
 * Les cookies (liaison du parcours au navigateur) passent automatiquement : l'API est sur la même origine.
 */
@Injectable({ providedIn: 'root' })
export class EsignetService {
  private readonly http = inject(HttpClient);
  private readonly api = `${environment.apiUrl}/auth/esignet`;

  private configurationEnCours?: Promise<EsignetConfiguration>;

  /** Paramètres publics du bouton officiel (authorizeUri, client_id, redirect_uri…), lus une fois. */
  configuration(): Promise<EsignetConfiguration> {
    this.configurationEnCours ??= firstValueFrom(this.http.get<EsignetConfiguration>(`${this.api}/configuration`))
      .catch((e) => {
        this.configurationEnCours = undefined; // nouvel essai au prochain affichage
        throw e;
      });
    return this.configurationEnCours;
  }

  /**
   * Étape A, appelée par le bouton officiel (par_callback du plugin @mosip/sign-in-with-esignet) :
   * le backend génère state, nonce et PKCE, pousse la requête (PAR) et renvoie le request_uri ;
   * le plugin redirige ensuite vers /authorize?client_id=…&request_uri=….
   * Sans PAR côté eSignet (request_uri nul), on suit directement l'authorization_url préparée par le backend.
   */
  async demarrer(mode: EsignetMode, retour?: string): Promise<string> {
    sessionStorage.setItem(MODE_KEY, mode);
    sessionStorage.removeItem(ERREUR_KEY);
    if (retour) sessionStorage.setItem(RETOUR_KEY, retour);
    else sessionStorage.removeItem(RETOUR_KEY);

    let res: { authorization_url: string; request_uri: string | null };
    try {
      res = await firstValueFrom(
        this.http.post<{ authorization_url: string; request_uri: string | null }>(`${this.api}/demarrer`, { mode }),
      );
    } catch (e) {
      // Le plugin redirige alors vers la page de retour avec ?error=request_uri_error : on y affichera le vrai motif
      sessionStorage.setItem(ERREUR_KEY, JSON.stringify(messageEsignet(e)));
      throw e;
    }
    if (res.request_uri) return res.request_uri;

    // La page est quittée : la promesse ne se résout jamais, le plugin n'a plus rien à faire
    window.location.assign(res.authorization_url);
    return new Promise<string>(() => {});
  }

  /** Motif détaillé d'un échec du démarrage (consommé une seule fois). */
  consommerErreurDemarrage(): ReturnType<typeof messageEsignet> | null {
    const brut = sessionStorage.getItem(ERREUR_KEY);
    sessionStorage.removeItem(ERREUR_KEY);
    try {
      return brut ? JSON.parse(brut) : null;
    } catch {
      return null;
    }
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
  nip_invalide: 'Le NIP transmis par le registre n’a pas un format valide. Contactez le support.',
  nip_incoherent: 'Les informations du registre sont incohérentes avec votre NIP ou votre compte. Contactez le support.',
  nip_deja_rattache: 'Ce NIP est déjà associé à un autre compte. Contactez le support.',
  inscription_expiree: 'Le délai pour finaliser votre compte est dépassé. Recommencez.',
  configuration_incomplete: 'Le service eSignet n’est pas encore configuré pour ce portail.',
  esignet_indisponible: 'Le service eSignet est momentanément indisponible. Réessayez dans quelques minutes.',
  request_uri_error: 'Impossible de préparer la connexion à eSignet. Réessayez.',
  request_uri_timeout: 'eSignet met trop de temps à répondre. Réessayez dans un instant.',
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
