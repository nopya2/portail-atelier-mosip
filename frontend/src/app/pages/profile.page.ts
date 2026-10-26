import { Component, computed, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { apiErrorMessage } from '../core/api.service';
import { AuthService } from '../core/auth/auth.service';

@Component({
  selector: 'app-profile',
  imports: [ReactiveFormsModule],
  template: `
    <div class="page-header">
      <div>
        <h1>Mon profil</h1>
        <p class="muted">Ces informations sont reprises dans vos demandes.</p>
      </div>
    </div>

    <section class="card statut-identite">
      <span class="badge ok">NIP vérifié</span>
      <span class="small muted">Identité confirmée par eSignet : nom, prénom, genre et date de naissance proviennent du registre national.</span>
    </section>

    @if (bienvenue() && incomplet()) {
      <div class="alert success">
        <strong>Votre compte a été créé.</strong> Complétez votre adresse pour pouvoir déposer toutes vos demandes.
      </div>
    }

    <div class="card profile">
      <div class="grid-2">
        <div class="field">
          <label for="nip">NIP</label>
          <input id="nip" [value]="auth.user()?.nip" disabled class="mono" />
          <div class="field-hint">Identifiant de votre compte, non modifiable.</div>
        </div>
        <div class="field">
          <label for="bd">Date de naissance</label>
          <input id="bd" type="date" [value]="auth.user()?.birthdate ?? ''" disabled />
          <div class="field-hint">Issue de votre NIP.</div>
        </div>
      </div>

      @if (message()) {
        <div class="alert success">{{ message() }}</div>
      }
      @if (error()) {
        <div class="alert error">{{ error() }}</div>
      }

      <form [formGroup]="form" (ngSubmit)="save()">
        <div class="grid-2">
          <div class="field"><label for="gn">Prénom *</label><input id="gn" formControlName="given_name" autocomplete="given-name" /></div>
          <div class="field"><label for="fn">Nom *</label><input id="fn" formControlName="family_name" autocomplete="family-name" /></div>
          <div class="field">
            <label for="em">Email @if (auth.user()?.email_verified_at) { <span class="badge ok">vérifié</span> }</label>
            <input id="em" type="email" formControlName="email" autocomplete="email" />
          </div>
          <div class="field">
            <label for="ph">Téléphone @if (auth.user()?.phone_verified_at) { <span class="badge ok">vérifié</span> }</label>
            <input id="ph" type="tel" formControlName="phone_number" autocomplete="tel" placeholder="07 12 34 56" />
          </div>
          <div class="field">
            <label for="ge">Genre</label>
            <select id="ge" formControlName="gender"><option value="">—</option><option>Femme</option><option>Homme</option></select>
          </div>
        </div>

        <fieldset formGroupName="address">
          <legend>Adresse</legend>
          <div class="grid-2">
            <div class="field"><label for="st">Rue / quartier</label><input id="st" formControlName="street_address" /></div>
            <div class="field"><label for="lo">Ville</label><input id="lo" formControlName="locality" /></div>
            <div class="field"><label for="re">Province</label><input id="re" formControlName="region" /></div>
            <div class="field"><label for="pc">Boîte postale</label><input id="pc" formControlName="postal_code" /></div>
          </div>
        </fieldset>

        <button class="btn btn-primary" type="submit" [disabled]="busy() || form.invalid">Enregistrer</button>
      </form>
    </div>
  `,
  styles: `
    .profile { max-width: 760px; }
    .statut-identite { max-width: 760px; display: flex; flex-wrap: wrap; gap: 0.5rem 0.75rem; align-items: center; margin-bottom: 1rem; }
    fieldset { border: 1px solid var(--border); border-radius: 8px; margin: 0 0 1rem; padding: 0.75rem 1rem 0; }
    legend { font-weight: 600; padding: 0 0.25rem; }
  `,
})
export class ProfilePage {
  protected readonly auth = inject(AuthService);
  protected readonly busy = signal(false);
  protected readonly message = signal<string | null>(null);
  protected readonly error = signal<string | null>(null);

  /** ?bienvenue=1 : arrivée juste après la création du compte */
  readonly bienvenue = input<string>();
  protected readonly incomplet = computed(() => !this.auth.user()?.address);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    given_name: ['', Validators.required],
    family_name: ['', Validators.required],
    email: ['', Validators.email],
    phone_number: [''],
    gender: [''],
    address: inject(FormBuilder).nonNullable.group({
      street_address: [''],
      locality: [''],
      region: [''],
      postal_code: [''],
      country: ['Gabon'],
    }),
  });

  constructor() {
    const u = this.auth.user();
    if (u) {
      this.form.patchValue({
        given_name: u.given_name ?? '',
        family_name: u.family_name ?? '',
        email: u.email ?? '',
        phone_number: u.phone_number ?? '',
        gender: u.gender ?? '',
        address: { ...u.address },
      });
      // Le contact vérifié à l'inscription n'est pas modifiable
      if (u.email_verified_at) this.form.controls.email.disable();
      if (u.phone_verified_at) this.form.controls.phone_number.disable();
      // Identité issue du registre (eSignet) : jamais modifiable ici
      this.form.controls.given_name.disable();
      this.form.controls.family_name.disable();
      this.form.controls.gender.disable();
    }
  }

  async save(): Promise<void> {
    this.busy.set(true);
    this.message.set(null);
    this.error.set(null);
    const v = this.form.value; // sans les champs désactivés (contact vérifié)
    try {
      await this.auth.updateProfile({
        ...v,
        ...('email' in v ? { email: v.email || null } : {}),
        ...('phone_number' in v ? { phone_number: v.phone_number || null } : {}),
        gender: v.gender || null,
      });
      this.message.set('Profil mis à jour.');
    } catch (e) {
      this.error.set(apiErrorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }
}
