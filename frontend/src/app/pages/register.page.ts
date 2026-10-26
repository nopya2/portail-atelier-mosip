import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { EsignetButton } from '../core/esignet/esignet-button';

/**
 * Création de compte : uniquement avec eSignet (identité vérifiée par le registre national).
 * Le parcours continue sur /auth/esignet/retour puis /inscription/finaliser.
 */
@Component({
  selector: 'app-register',
  imports: [RouterLink, EsignetButton],
  template: `
    <div class="auth card">
      <h1>Créer un compte</h1>
      <p class="muted">
        La création de compte se fait avec votre <strong>identité numérique nationale</strong> via eSignet.
        Votre NIP et votre état civil sont repris du registre : vous n’avez rien à ressaisir.
      </p>

      <ol class="etapes">
        <li><strong>Authentifiez-vous sur eSignet</strong> avec votre NIP et le code reçu sur le téléphone enregistré au registre.</li>
        <li><strong>Acceptez le partage</strong> des informations nécessaires (NIP, nom, date de naissance…).</li>
        <li><strong>Finalisez votre compte</strong> : vérifiez votre identité, choisissez vos moyens de contact.</li>
      </ol>

      <app-esignet-button mode="creation" libelle="Créer mon compte avec eSignet" />

      <p class="small center">Déjà inscrit ? <a routerLink="/connexion">Se connecter</a></p>
    </div>
  `,
  styles: `
    .auth { max-width: 520px; margin: 2rem auto; display: flex; flex-direction: column; gap: 1rem; }
    .auth h1, .auth p { margin: 0; }
    .etapes { margin: 0; padding-left: 1.25rem; display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.95rem; }
    .center { text-align: center; }
  `,
})
export class RegisterPage {}
