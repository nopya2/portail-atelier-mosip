import { Component, inject, input, signal } from '@angular/core';
import { EsignetMode } from '../models';
import { EsignetService, messageEsignet } from './esignet.service';

/**
 * Bouton « … avec eSignet » : démarre le parcours (étape A) puis redirige vers eSignet.
 * Usage : <app-esignet-button mode="creation" libelle="Créer mon compte avec eSignet" />
 */
@Component({
  selector: 'app-esignet-button',
  template: `
    <button type="button" class="btn btn-esignet" [disabled]="busy()" (click)="demarrer()">
      <img src="sceau.png" alt="" width="22" height="22" />
      {{ busy() ? 'Redirection vers eSignet…' : libelle() }}
    </button>
    @if (erreur(); as e) {
      <div class="alert" [class.warning]="!!e.nonImplemente" [class.error]="!e.nonImplemente" role="alert">
        {{ e.message }}
        @if (e.nonImplemente) {
          <br /><small class="muted">Étape {{ e.nonImplemente }} de la fiche atelier à implémenter (EsignetService).</small>
        }
        @if (e.reference) {
          <br /><small class="muted">Réf. {{ e.reference }}</small>
        }
      </div>
    }
  `,
  styles: `
    :host { display: block; }
    .btn-esignet {
      width: 100%; padding: 0.75rem 1rem; font-size: 1rem;
      background: #1d2f6f; color: #fff; border: 0;
    }
    .btn-esignet:hover:not(:disabled) { background: #14225a; }
    .btn-esignet img { border-radius: 4px; background: #fff; }
    .alert { margin: 0.75rem 0 0; text-align: left; }
  `,
})
export class EsignetButton {
  private readonly esignet = inject(EsignetService);

  readonly mode = input.required<EsignetMode>();
  readonly libelle = input('Continuer avec eSignet');
  /** Page où revenir à la fin du parcours */
  readonly retour = input<string>();

  protected readonly busy = signal(false);
  protected readonly erreur = signal<ReturnType<typeof messageEsignet> | null>(null);

  async demarrer(): Promise<void> {
    this.busy.set(true);
    this.erreur.set(null);
    try {
      await this.esignet.demarrer(this.mode(), this.retour());
    } catch (e) {
      this.erreur.set(messageEsignet(e));
      this.busy.set(false);
    }
  }
}
