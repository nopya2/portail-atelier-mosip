<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Catalogue de services de démonstration. Chaque service indique les
     * informations du profil de l'usager reprises dans la demande.
     */
    public function run(): void
    {
        $services = [
            [
                'slug' => 'extrait-acte-naissance',
                'titre' => 'Extrait d\'acte de naissance',
                'categorie' => 'État civil',
                'administration' => 'Mairie de Libreville',
                'description' => 'Obtenir un extrait d\'acte de naissance. Nom, date de naissance et genre sont repris de votre profil.',
                'icone' => 'baby',
                'delai_jours' => 3,
                'infos_requises' => ['name', 'birthdate', 'gender'],
                'champs' => [
                    ['name' => 'lieu_naissance', 'label' => 'Lieu de naissance', 'type' => 'text', 'required' => true],
                    ['name' => 'nombre_copies', 'label' => 'Nombre de copies', 'type' => 'number', 'required' => true],
                ],
            ],
            [
                'slug' => 'casier-judiciaire',
                'titre' => 'Bulletin n°3 du casier judiciaire',
                'categorie' => 'Justice',
                'administration' => 'Ministère de la Justice',
                'description' => 'Demande en ligne du bulletin n°3 du casier judiciaire, délivré à la personne concernée.',
                'icone' => 'scale',
                'delai_jours' => 5,
                'infos_requises' => ['name', 'birthdate', 'address'],
                'champs' => [
                    ['name' => 'motif', 'label' => 'Motif de la demande', 'type' => 'select', 'required' => true, 'options' => ['Emploi', 'Concours', 'Autre']],
                ],
            ],
            [
                'slug' => 'certificat-residence',
                'titre' => 'Certificat de résidence',
                'categorie' => 'Administration territoriale',
                'administration' => 'Préfecture',
                'description' => 'Attestation de domicile établie à partir de l\'adresse de votre profil.',
                'icone' => 'home',
                'delai_jours' => 2,
                'infos_requises' => ['name', 'address', 'phone_number'],
                'champs' => [
                    ['name' => 'depuis', 'label' => 'Résident depuis le', 'type' => 'date', 'required' => true],
                ],
            ],
            [
                'slug' => 'inscription-cnss',
                'titre' => 'Immatriculation à la sécurité sociale',
                'categorie' => 'Protection sociale',
                'administration' => 'CNAMGS',
                'description' => 'Immatriculation d\'un assuré. Le NIP sert d\'identifiant pivot entre les administrations.',
                'icone' => 'shield',
                'delai_jours' => 10,
                'infos_requises' => ['name', 'birthdate', 'gender', 'email', 'phone_number'],
                'champs' => [
                    ['name' => 'employeur', 'label' => 'Employeur', 'type' => 'text', 'required' => false],
                ],
            ],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(['slug' => $service['slug']], $service);
        }

        // Aucun compte n'est créé ici : création de compte et connexion passent uniquement par eSignet.
        // Les administrateurs sont désignés par leur NIP (PORTAIL_ADMIN_NIPS) ; leur compte est créé
        // à leur première connexion eSignet, comme pour tout usager.
    }
}
