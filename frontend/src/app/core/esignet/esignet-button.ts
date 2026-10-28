import { Component, ElementRef, afterNextRender, inject, input, signal, viewChild } from '@angular/core';
import { init } from '@mosip/sign-in-with-esignet';
import { EsignetMode } from '../models';
import { EsignetService, messageEsignet } from './esignet.service';

/**
 * Bouton officiel « … avec eSignet » (plugin MOSIP @mosip/sign-in-with-esignet),
 * intégré comme dans mosip/esignet-mock-services/mock-relying-party-ui.
 *
 * Le plugin ne reçoit aucun secret : au clic, son par_callback appelle le backend (étape A),
 * qui génère state, nonce et PKCE, pousse la requête (PAR) et renvoie le request_uri ;
 * le plugin redirige alors vers eSignet (/authorize?client_id=…&request_uri=…).
 *
 * Usage : <app-esignet-button mode="creation" libelle="Créer mon compte avec eSignet" />
 */
@Component({
  selector: 'app-esignet-button',
  template: `
    <div #cible class="cible" [class.busy]="busy()" (click)="busy.set(true)" [attr.aria-busy]="busy()"></div>
    @if (chargement()) {
      <div class="placeholder" aria-hidden="true"></div>
    }
    @if (busy()) {
      <p class="small muted center">Redirection vers eSignet…</p>
    }
    @if (erreur(); as e) {
      <div class="alert error" role="alert">
        {{ e.message }}
        @if (e.reference) {
          <br /><small class="muted">Réf. {{ e.reference }}</small>
        }
      </div>
    }
  `,
  styles: `
    :host { display: block; }
    /* le plugin crée un <button> natif : on neutralise le style par défaut du navigateur */
    .cible ::ng-deep > button { display: block; width: 100%; padding: 0; border: 0; background: none; cursor: pointer; }
    .cible.busy { pointer-events: none; opacity: 0.6; }
    .placeholder { height: 48px; border-radius: 8px; background: var(--surface-2, #eef0f4); }
    .center { text-align: center; margin: 0.5rem 0 0; }
    .alert { margin: 0.75rem 0 0; text-align: left; }
  `,
})
export class EsignetButton {
  private readonly esignet = inject(EsignetService);
  private readonly cible = viewChild.required<ElementRef<HTMLElement>>('cible');

  readonly mode = input.required<EsignetMode>();
  readonly libelle = input('Se connecter avec eSignet');
  /** Page où revenir à la fin du parcours */
  readonly retour = input<string>();

  protected readonly chargement = signal(true);
  protected readonly busy = signal(false);
  protected readonly erreur = signal<ReturnType<typeof messageEsignet> | null>(null);

  constructor() {
    afterNextRender(() => this.afficher());
  }

  private async afficher(): Promise<void> {
    try {
      const conf = await this.esignet.configuration();
      await init({
        oidcConfig: {
          authorizeUri: conf.authorize_uri,
          redirect_uri: conf.redirect_uri,
          client_id: conf.client_id,
          scope: conf.scope,
          ui_locales: conf.ui_locales,
          par_callback: () => this.esignet.demarrer(this.mode(), this.retour()),
          par_callback_timeout: 15000,
        },
        buttonConfig: {
          labelText: this.libelle(),
          shape: 'soft_edges',
          theme: 'outline',
          width: '100%',
        },
        signInElement: this.cible().nativeElement,
        style: {},
      });
    } catch (e) {
      this.erreur.set(messageEsignet(e));
    } finally {
      this.chargement.set(false);
    }
  }
}
