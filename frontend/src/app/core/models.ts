export interface User {
  id: number;
  nip: string;
  name: string | null;
  given_name?: string | null;
  family_name?: string | null;
  email?: string | null;
  phone_number?: string | null;
  email_verified_at?: string | null;
  phone_verified_at?: string | null;
  /** « declare » : inscription locale ; « esignet » : NIP vérifié par le registre */
  nip_source?: 'declare' | 'esignet';
  nip_verifie_at?: string | null;
  acr?: string | null;
  birthdate?: string | null;
  gender?: string | null;
  address?: Record<string, string> | null;
  picture?: string | null;
  is_admin: boolean;
  last_login_at?: string | null;
}

export interface ChampService {
  name: string;
  label: string;
  type: 'text' | 'number' | 'date' | 'select';
  required?: boolean;
  options?: string[];
}

export interface Service {
  id: number;
  slug: string;
  titre: string;
  categorie: string;
  administration: string;
  description: string;
  icone: string;
  delai_jours: number;
  infos_requises: string[];
  champs: ChampService[] | null;
}

export interface Demande {
  id: number;
  reference: string;
  statut: string;
  nip: string;
  identite: Record<string, unknown>;
  donnees: Record<string, unknown>;
  created_at: string;
  service: Pick<Service, 'id' | 'slug' | 'titre' | 'administration'>;
}

export interface AppLog {
  id: number;
  source: 'backend' | 'frontend';
  level: string;
  level_value?: number;
  message: string;
  exception_class?: string | null;
  file?: string | null;
  line?: number | null;
  trace?: string | null;
  context?: Record<string, unknown> | null;
  url?: string | null;
  method?: string | null;
  status_code?: number | null;
  ip?: string | null;
  user_agent?: string | null;
  user?: { id: number; name: string; nip: string } | null;
  request_id?: string | null;
  session_id?: string | null;
  created_at: string;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Session {
  token: string;
  expires_in: number;
  user: User;
}

/** Création de compte et connexion : uniquement avec eSignet */
export type EsignetMode = 'creation' | 'connexion';

/** Paramètres publics du bouton « Se connecter avec eSignet » (GET /api/auth/esignet/configuration) */
export interface EsignetConfiguration {
  authorize_uri: string;
  client_id: string;
  redirect_uri: string;
  scope: string;
  ui_locales: string;
}

export type EsignetRetour = ({ statut: 'connecte' } & Session) | { statut: 'finalisation'; jeton: string };

/** Identité vérifiée affichée (lecture seule) sur l'écran de finalisation */
export interface EsignetIdentite {
  nip: string;
  name: string | null;
  given_name: string | null;
  family_name: string | null;
  birthdate: string | null;
  gender: string | null;
  email: string | null;
  phone_number: string | null;
  address: Record<string, string> | null;
  expire_dans: number;
}

export interface ApiError {
  etape?: string;
  error?: string;
  message?: string;
  errors?: Record<string, string[]>;
  request_id?: string;
}
