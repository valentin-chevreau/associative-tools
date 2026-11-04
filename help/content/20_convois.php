<?php
return [
    'key'     => 'convois',
    'label'   => 'Convois',
    'module'  => 'logistique',
    'order'   => 30,
    'context' => ['logistique'],
    'entries' => [
        'ajouter-des-cartons' => [
            'q'     => "Comment ajouter des cartons à un convoi ?",
            'risk'  => 'reversible',
            'pages' => ['convoys/view.php'],
            'steps' => [
                "Ouvrez le convoi, onglet **Cartons**.",
                "Sur la ligne de la catégorie, touchez **+** pour ajouter 1 carton (ou **+5 / +10 / +20** sur ordinateur), ou tapez directement le nombre voulu.",
                "Le total du convoi se met à jour tout de suite : il n'y a pas de bouton « Enregistrer » à toucher.",
            ],
            'undo' => " Touchez **−** pour retirer un carton, ou corrigez le nombre. Rien n'est définitif.",
            'keywords' => 'quantité compter stock carton chargement',
        ],
        'categorie-absente' => [
            'q'     => "La catégorie de mon carton n'est pas dans la liste",
            'risk'  => 'safe',
            'pages' => ['convoys/view.php'],
            'steps' => [
                "Touchez **+ Ajouter catégorie** en haut de la page, ou **Ajouter…** sur une ligne, puis cherchez par mot-clé.",
                "Si elle n'existe pas du tout, un **admin** doit la créer dans **Convois → Catégories** (avec son libellé en ukrainien pour les étiquettes).",
            ],
            'keywords' => 'nouvelle catégorie manquante créer',
        ],
        'rechercher-dans-un-convoi' => [
            'q'     => "Comment retrouver une catégorie dans un convoi ?",
            'risk'  => 'safe',
            'pages' => ['convoys/view.php'],
            'steps' => [
                "Tapez quelques lettres dans **Rechercher…** au-dessus du tableau : seules les lignes correspondantes restent affichées.",
            ],
        ],
        'imprimer-etiquette-convoi' => [
            'q'     => "Comment imprimer l'étiquette d'une catégorie depuis un convoi ?",
            'risk'  => 'safe',
            'pages' => ['convoys/view.php'],
            'steps' => [
                "Sur la ligne de la catégorie, touchez l'icône d'**imprimante** à droite.",
                "Le PDF s'ouvre dans un nouvel onglet : une page avec **4 étiquettes**.",
                "Imprimez-le depuis la visionneuse du navigateur (format A4 paysage, échelle 100 %).",
            ],
            'undo' => " Rien n'est modifié par l'impression : vous pouvez en refaire autant que nécessaire.",
            'keywords' => 'étiquette pdf imprimer carton',
        ],
        'pas-d-icone-etiquette' => [
            'q'     => "L'icône d'imprimante n'apparaît pas sur une ligne",
            'risk'  => 'safe',
            'pages' => ['convoys/view.php'],
            'steps' => [
                "L'icône n'est proposée que pour les catégories qui ont un **libellé en ukrainien**, puisque l'étiquette est bilingue.",
                "Demandez à un admin de compléter le **libellé UA** de la catégorie dans **Convois → Catégories → Modifier**.",
            ],
        ],
    ],
];
