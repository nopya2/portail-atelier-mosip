<!doctype html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5">
    <h2 style="color: #0b6b3a">Votre compte a été créé</h2>
    <p>Bienvenue sur le portail des services.</p>
    <table cellpadding="6" style="border-collapse: collapse">
        <tr><td>NIP</td><td><strong>{{ $user->nip }}</strong></td></tr>
        <tr><td>Identifiant de connexion</td><td>{{ $user->email }}</td></tr>
        <tr><td>Date</td><td>{{ $user->created_at->format('d/m/Y H:i') }}</td></tr>
    </table>
    <p>Pensez à compléter votre profil (nom, prénom, adresse) pour pouvoir déposer vos demandes.</p>
    <p style="font-size: 12px; color: #6b7280">
        Message envoyé par le portail des services — environnement de démonstration.
    </p>
</body>
</html>
