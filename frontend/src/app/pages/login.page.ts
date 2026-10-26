import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { EsignetButton } from '../core/esignet/esignet-button';

/** Connexion : uniquement avec eSignet (le portail ne gère aucun mot de passe). */
@Component({
  selector: 'app-login',
  imports: [RouterLink, EsignetButton],
  template: `
    <div class="auth card">
      <h1>Connexion</h1>
      <p class="muted">
        Connectez-vous avec votre <strong>identité numérique nationale</strong> : votre NIP et le code reçu
        sur le téléphone enregistré au registre.
      </p>

      @if (expire()) {
        <div class="alert warning">Votre session a expiré, reconnectez-vous.</div>
      }

      <app-esignet-button mode="connexion" libelle="Se connecter avec eSignet" [retour]="retour()" />

      <p class="small center">Pas encore de compte ? <a routerLink="/inscription">Créer un compte</a></p>
    </div>
  `,
  styles: `
    .auth { max-width: 440px; margin: 2rem auto; display: flex; flex-direction: column; gap: 1rem; }
    .auth h1, .auth p { margin: 0; }
    .center { text-align: center; }
  `,
})
export class LoginPage {
  /** paramètres de requête (?retour=…&expire=1) liés via withComponentInputBinding */
  readonly retour = input<string>();
  readonly expire = input<string>();
}
