<?php
return [
    'key'     => 'mailing',
    'label'   => 'Mailing (newsletters)',
    'module'  => 'mailing',
    'order'   => 50,
    'context' => ['mailing'],
    'entries' => [
        'envoyer-newsletter' => [
            'q'     => "Comment envoyer une newsletter ?",
            'risk'  => 'careful',
            'pages' => ['compose.php'],
            'steps' => [
                "Allez dans **Mailing → Nouvelle newsletter**.",
                "Cochez les **listes** de destinataires : le total sans doublon ni désinscrit s'affiche en direct.",
                "Renseignez l'**objet**, puis composez le message avec des **blocs** (voir la question suivante).",
                "Touchez **M'envoyer un test** pour voir le message dans votre boîte de réception.",
                "Touchez **Envoyer / planifier**. Les messages partent par petits lots d'environ 20 par minute : l'envoi complet peut prendre quelques minutes.",
            ],
            'undo'  => " Une fois les messages partis, il est impossible de les rappeler : vous pouvez seulement annuler ceux qui n'ont pas encore été envoyés (page de la newsletter, **Annuler l'envoi**).",
            'link'  => ['label' => 'Nouvelle newsletter', 'path' => '/mailing/compose.php'],
            'keywords' => 'mail e-mail courriel envoi message liste planifier test',
        ],
        'blocs-newsletter' => [
            'q'     => "Comment composer le contenu avec des blocs ?",
            'risk'  => 'safe',
            'pages' => ['compose.php'],
            'steps' => [
                "Dans l'étape **Contenu**, touchez le **+** pour ajouter un bloc : titre, texte, image, bouton, colonnes, citation, chiffres clés, évènement du planning ou séparateur.",
                "Touchez un bloc pour le régler dans le panneau de droite. Les boutons au-dessus du bloc permettent de le **monter**, le **descendre**, le **dupliquer** ou le **supprimer**.",
                "Pour une **image**, choisissez un fichier sur votre ordinateur : elle est allégée automatiquement. Pensez au texte alternatif.",
                "Le bloc **Évènement** reprend le titre, la date et les horaires de l'évènement choisi dans le planning. Le bouton est facultatif : le planning n'est ouvert qu'aux bénévoles déclarés.",
            ],
            'keywords' => 'bloc image titre texte colonne citation chiffres évènement éditeur contenu',
        ],
        'exclure-destinataire' => [
            'q'     => "Comment exclure quelqu'un d'une newsletter précise ?",
            'risk'  => 'safe',
            'pages' => ['compose.php'],
            'steps' => [
                "Dans l'étape **Destinataires**, ouvrez **Voir précisément qui reçoit ce message**.",
                "Décochez la personne : elle est exclue de **cette newsletter seulement**, et reste dans ses listes. Le total se met à jour tout de suite.",
                "Enregistrez le brouillon ou lancez l'envoi : l'exclusion est conservée avec la newsletter.",
            ],
            'keywords' => 'retirer décocher personne destinataire liste nominative',
        ],
        'listes-diffusion' => [
            'q'     => "D'où viennent les listes de diffusion et comment les modifier ?",
            'risk'  => 'reversible',
            'pages' => ['lists.php'],
            'steps' => [
                "Les listes se remplissent toutes seules : **bénévoles** du planning, **adhérents** à jour de l'année, **donateurs** des 24 derniers mois, **contacts externes** de Prospection.",
                "Pour ajouter quelqu'un qui n'est dans aucune fiche, ouvrez la liste voulue dans **Mailing → Listes de diffusion** et saisissez son adresse.",
                "**Retirer de la liste** ne l'enlève que de cette liste (et jamais de la fiche d'origine) ; **Remettre** annule le retrait.",
            ],
            'keywords' => 'ajouter retirer personne destinataire adhérent donateur bénévole contact',
        ],
        'desinscription' => [
            'q'     => "Quelqu'un ne veut plus recevoir la newsletter, ou veut la recevoir de nouveau",
            'risk'  => 'reversible',
            'pages' => ['lists.php'],
            'steps' => [
                "Chaque message contient un lien **Se désinscrire** : la personne peut s'en servir elle-même, sans rien vous demander.",
                "Si elle vous le demande autrement (mail, téléphone), allez dans **Mailing → Listes de diffusion**, en bas de page, et saisissez son adresse dans **Désinscrire une adresse**.",
                "Pour la réinscrire à sa demande, touchez **Réinscrire** en face de son adresse dans la liste des désinscrits.",
            ],
            'undo'  => " Une personne désinscrite ne reçoit plus rien, quelle que soit la liste : ne la réinscrivez que si elle vous l'a demandé.",
            'keywords' => 'stop opposition rgpd consentement refus désabonner',
        ],
    ],
];
