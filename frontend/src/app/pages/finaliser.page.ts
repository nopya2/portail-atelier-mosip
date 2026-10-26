import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../core/auth/auth.service';
import { EsignetService, messageEsignet } from '../core/esignet/esignet.service';
import { formatValue } from '../core/labels';
import { ApiError, EsignetIdentite } from '../core/models';

/**
 * Écran « Finaliser mon compte » : identité vérifiée par eSignet en lecture seule,
 * choix des moyens de contact et acceptation des CGU (étape G).
 */
@Component({
  selector: 'app-finaliser',
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <div class="wrap">
      <h1>Finaliser mon compte</h1>

      @if (chargement()) {
        <div class="spinner"></div>
      } @else if (identite(); as id) {
        <section class="card identite">
          <div class="row between">
            <h2>Votre identité</h2>
            <span class="badge ok">NIP vérifié par eSignet</span>
          </div>
          <p class="muted small">Ces informations proviennent du registre national et ne sont pas modifiables ici.</p>
          <dl class="kv">
            <dt>NIP</dt><dd class="mono">{{ id.nip }}</dd>
            <dt>Nom</dt><dd>{{ id.family_name ?? '—' }}</dd>
            <dt>Prénom(s)</dt><dd>{{ id.given_name ?? id.name ?? '—' }}</dd>
            <dt>Date de naissance</dt><dd>{{ id.birthdate ?? '—' }}</dd>
            <dt>Genre</dt><dd>{{ id.gender ?? '—' }}</dd>
            <dt>Adresse</dt><dd>{{ format(id.address) }}</dd>
          </dl>
        </section>

        <form class="card" [formGroup]="form" (ngSubmit)="creer()" novalidate>
          <h2>Vos moyens de contact</h2>
          <p class="muted small">Au moins un des deux est nécessaire pour recevoir les notifications de vos démarches.</p>

          @if (erreur()) {
            <div class="alert error" role="alert">{{ erreur() }}</div>
          }

          <div class="grid-2">
            <div class="field">
              <label for="email">Email</label>
              <input id="email" type="email" formControlName="email" autocomplete="email" [class.invalid]="invalide('email')" />
              @if (id.email && form.controls.email.value === id.email) {
                <div class="field-hint">Issu du registre</div>
              }
              @if (form.controls.email.getError('server'); as msg) {
                <div class="field-error">{{ msg }}</div>
              }
            </div>
            <div class="field">
              <label for="telephone">Téléphone</label>
              <input id="telephone" type="tel" formControlName="telephone" autocomplete="tel" placeholder="07 12 34 56"
                     [class.invalid]="invalide('telephone')" />
              @if (id.phone_number && form.controls.telephone.value === id.phone_number) {
                <div class="field-hint">Issu du registre</div>
              }
              @if (form.controls.telephone.getError('server'); as msg) {
                <div class="field-error">{{ msg }}</div>
              }
            </div>
          </div>
          @if (form.touched && form.hasError('contact')) {
            <div class="field-error">Renseignez au moins un moyen de contact.</div>
          }

          <label class="cgu">
            <input type="checkbox" formControlName="cgu" />
            J’accepte les <a href="#" (click)="$event.preventDefault()">conditions d’utilisation</a> du portail et le traitement
            de mes données pour le suivi de mes démarches.
          </label>
          @if (form.controls.cgu.touched && form.controls.cgu.invalid) {
            <div class="field-error">Vous devez accepter les conditions d’utilisation.</div>
          }

          <div class="row between actions">
            <span class="muted small">
              @if (expireDans() > 0) { Session valable encore {{ minutes() }} min. } @else { Délai dépassé. }
            </span>
            <button class="btn btn-primary" type="submit" [disabled]="busy() || expireDans() <= 0">
              {{ busy() ? 'Création…' : 'Créer mon compte' }}
            </button>
          </div>
        </form>
      } @else {
        <div class="alert" [class.warning]="!!nonImplemente()" [class.error]="!nonImplemente()" role="alert">
          {{ erreur() }}
          @if (nonImplemente()) {
            <br /><small>Étape {{ nonImplemente() }} de la fiche atelier à implémenter (EsignetService).</small>
          }
        </div>
        <a routerLink="/inscription" class="btn btn-primary">Recommencer</a>
      }
    </div>
  `,
  styles: `
    .wrap { max-width: 720px; margin: 1.5rem auto; display: flex; flex-direction: column; gap: 1rem; }
    .between { justify-content: space-between; }
    .identite h2, form h2 { margin: 0; }
    .cgu { display: flex; gap: 0.5rem; align-items: flex-start; font-weight: 400; margin-top: 0.5rem; }
    .cgu input { margin-top: 0.3rem; }
    .actions { margin-top: 1rem; }
  `,
})
export class FinaliserPage {
  private readonly esignet = inject(EsignetService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  protected readonly format = formatValue;

  protected readonly chargement = signal(true);
  protected readonly busy = signal(false);
  protected readonly identite = signal<EsignetIdentite | null>(null);
  protected readonly erreur = signal<string | null>(null);
  protected readonly nonImplemente = signal<string | null>(null);
  protected readonly expireDans = signal(0);

  protected readonly form = inject(FormBuilder).nonNullable.group(
    {
      email: ['', Validators.email],
      telephone: [''],
      cgu: [false, Validators.requiredTrue],
    },
    { validators: (g) => (g.get('email')?.value || g.get('telephone')?.value ? null : { contact: true }) },
  );

  constructor() {
    const timer = setInterval(() => this.expireDans.update((s) => Math.max(0, s - 1)), 1000);
    inject(DestroyRef).onDestroy(() => clearInterval(timer));
    this.charger();
  }

  protected minutes(): number {
    return Math.ceil(this.expireDans() / 60);
  }

  protected invalide(name: 'email' | 'telephone'): boolean {
    const c = this.form.controls[name];
    return c.invalid && (c.touched || c.dirty);
  }

  private async charger(): Promise<void> {
    const jeton = this.esignet.jeton();
    if (!jeton) {
      this.erreur.set('Aucune identité à finaliser. Commencez par « Créer mon compte avec eSignet ».');
      this.chargement.set(false);
      return;
    }
    try {
      const id = await this.esignet.inscription(jeton);
      this.identite.set(id);
      this.expireDans.set(id.expire_dans);
      // Contacts issus du registre proposés par défaut, modifiables
      this.form.patchValue({ email: id.email ?? '', telephone: id.phone_number ?? '' });
    } catch (e) {
      const m = messageEsignet(e);
      this.erreur.set(m.message);
      this.nonImplemente.set(m.nonImplemente ?? null);
    } finally {
      this.chargement.set(false);
    }
  }

  async creer(): Promise<void> {
    this.form.markAllAsTouched();
    const jeton = this.esignet.jeton();
    if (this.form.invalid || !jeton) return;

    const v = this.form.getRawValue();
    this.busy.set(true);
    this.erreur.set(null);
    try {
      const session = await this.esignet.finaliser(jeton, {
        email: v.email.trim() || null,
        telephone: v.telephone.trim() || null,
        cgu: v.cgu,
      });
      this.esignet.oublierJeton();
      this.auth.ouvrirSession(session);
      await this.router.navigateByUrl(this.esignet.consommerRetour() ?? '/profil?bienvenue=1', { replaceUrl: true });
    } catch (e) {
      const body = e instanceof HttpErrorResponse && e.status === 422 ? (e.error as ApiError) : null;
      for (const [champ, messages] of Object.entries(body?.errors ?? {})) {
        this.form.get(champ)?.setErrors({ server: messages[0] });
      }
      const m = messageEsignet(e);
      this.erreur.set(m.nonImplemente ? `${m.message} (étape ${m.nonImplemente})` : m.message);
    } finally {
      this.busy.set(false);
    }
  }
}
