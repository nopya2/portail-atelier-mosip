import { Routes } from '@angular/router';
import { adminGuard, authGuard } from './core/auth/guards';

export const routes: Routes = [
  { path: '', title: 'Accueil', loadComponent: () => import('./pages/home.page').then((m) => m.HomePage) },
  { path: 'connexion', title: 'Connexion', loadComponent: () => import('./pages/login.page').then((m) => m.LoginPage) },
  {
    path: 'inscription',
    title: 'Créer un compte',
    loadComponent: () => import('./pages/register.page').then((m) => m.RegisterPage),
  },
  // Création de compte avec eSignet
  {
    path: 'auth/esignet/retour',
    title: 'Vérification de l’identité',
    loadComponent: () => import('./pages/esignet-retour.page').then((m) => m.EsignetRetourPage),
  },
  {
    path: 'inscription/finaliser',
    title: 'Finaliser mon compte',
    loadComponent: () => import('./pages/finaliser.page').then((m) => m.FinaliserPage),
  },
  {
    path: 'services/:slug',
    title: 'Service',
    loadComponent: () => import('./pages/service-detail.page').then((m) => m.ServiceDetailPage),
  },
  {
    path: 'mes-demandes',
    title: 'Mes demandes',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/demandes.page').then((m) => m.DemandesPage),
  },
  {
    path: 'profil',
    title: 'Mon profil',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/profile.page').then((m) => m.ProfilePage),
  },
  {
    path: 'admin/journal',
    title: 'Journal des erreurs',
    canActivate: [authGuard, adminGuard],
    loadComponent: () => import('./pages/admin/logs.page').then((m) => m.LogsPage),
  },
  { path: '**', redirectTo: '' },
];
