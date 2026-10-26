import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../core/auth/auth.service';
import { EsignetService, messageErreurRetour, messageEsignet } from '../core/esignet/esignet.service';

/**
 * redirect_uri du client OIDC : eSignet renvoie ici ?code=…&state=… (ou ?error=…).
 * La page relaie ces paramètres au backend (étapes B à F) puis oriente l'usager :
 * connecté, ou vers l'écran de finalisation (première connexion).
 */
@Component({
  selector: 'app-esignet-retour',
  imports: [RouterLink],
  template: `
    <div class="box card">
      @if (!erreur()) {
        <div class="spinner"></div>
        <h1>Vérification de votre identité…</h1>
        <p class="muted small">Le portail vérifie la réponse d’eSignet. Ne fermez pas cette page.</p>
      } @else {
        <h1>{{ nonImplemente() ? 'Intégration en cours' : 'Connexion impossible' }}</h1>
        <div class="alert" [class.warning]="nonImplemente()" [class.error]="!nonImplemente()" role="alert">
          {{ erreur() }}
          @if (nonImplemente()) {
            <br /><small>Étape {{ nonImplemente() }} de la fiche atelier à implémenter (EsignetService).</small>
          }
          @if (reference()) {
            <br /><small class="muted">Réf. {{ reference() }}</small>
          }
        </div>
        <div class="row center">
          <a routerLink="/inscription" class="btn btn-primary">Recommencer</a>
          <a routerLink="/" class="btn btn-secondary">Retour à l’accueil</a>
        </div>
      }
    </div>
  `,
  styles: `
    .box { max-width: 520px; margin: 2rem auto; display: flex; flex-direction: column; gap: 0.75rem; align-items: center; text-align: center; }
    .center { justify-content: center; }
    .alert { text-align: left; width: 100%; }
  `,
})
export class EsignetRetourPage {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly esignet = inject(EsignetService);

  protected readonly erreur = signal<string | null>(null);
  protected readonly nonImplemente = signal<string | null>(null);
  protected readonly reference = signal<string | null>(null);

  constructor() {
    this.traiter();
  }

  private async traiter(): Promise<void> {
    const q = this.route.snapshot.queryParamMap;
    const state = q.get('state');
    const error = q.get('error');

    // Les paramètres ne doivent pas rester dans l'historique du navigateur
    history.replaceState(null, '', '/auth/esignet/retour');

    if (!state) {
      this.erreur.set(error ? messageErreurRetour(error, q.get('error_description')) : 'Réponse d’eSignet incomplète.');
      return;
    }

    try {
      // Le backend contrôle aussi « error » (et clôture la transaction) : on lui transmet tout
      const resultat = await this.esignet.retour({
        state,
        code: q.get('code'),
        error,
        error_description: q.get('error_description'),
      });

      switch (resultat.statut) {
        case 'connecte':
          this.auth.ouvrirSession(resultat);
          await this.router.navigateByUrl(this.esignet.consommerRetour() ?? '/', { replaceUrl: true });
          break;
        case 'finalisation':
          this.esignet.memoriserJeton(resultat.jeton);
          await this.router.navigateByUrl('/inscription/finaliser', { replaceUrl: true });
          break;
      }
    } catch (e) {
      const m = messageEsignet(e);
      this.erreur.set(m.message);
      this.nonImplemente.set(m.nonImplemente ?? null);
      this.reference.set(m.reference ?? null);
    }
  }
}
