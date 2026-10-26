export const environment = {
  /** API Laravel : relative, servie par le proxy (ng serve) ou nginx (Docker). */
  apiUrl: '/api',
  appVersion: '1.0.0',
  /** Envoi des erreurs au journal centralisé du backend. */
  remoteLogging: true,
  /** Niveau minimal remonté au backend. */
  remoteLogLevel: 'warning' as 'debug' | 'info' | 'warning' | 'error',
};
