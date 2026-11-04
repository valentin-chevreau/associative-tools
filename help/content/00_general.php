<?php
return [
    'key'     => 'general',
    'label'   => 'Pour bien démarrer',
    'module'  => null,
    'order'   => 10,
    'context' => [],
    'entries' => [
        'utiliser-aide' => [
            'q'     => "Comment fonctionne cette aide ?",
            'risk'  => 'safe',
            'steps' => [
                "Sur chaque page, le bouton rond **?** en bas à droite ouvre l'aide de la page où vous êtes.",
                "Tapez quelques mots dans la barre de recherche (par exemple « monnaie » ou « étiquette ») : la recherche porte sur toutes les questions auxquelles vous avez accès.",
                "Chaque fiche indique si votre action est **sans risque**, **modifiable après coup** ou **irréversible**, et ce qu'il faut faire si vous vous êtes trompé.",
            ],
            'keywords' => 'aide support question bouton point d\'interrogation',
        ],
        'telephone-menu' => [
            'q'     => "Je suis sur téléphone : où est le menu ?",
            'risk'  => 'safe',
            'steps' => [
                "Touchez les **trois traits** en haut à gauche de l'écran : le menu s'ouvre par la gauche.",
                "Touchez une rubrique pour y aller, le menu se referme tout seul.",
            ],
            'keywords' => 'mobile smartphone hamburger navigation',
        ],
        'erreur-que-faire' => [
            'q'     => "J'ai fait une erreur et je ne sais pas comment la corriger",
            'risk'  => 'safe',
            'intro' => "Rien de grave : presque tout se rattrape.",
            'steps' => [
                "**Ne recommencez pas dans l'autre sens pour compenser** : une correction à l'aveugle peut compliquer la suite.",
                "Notez ce qui s'est passé : l'heure, le montant ou le produit concerné, et ce que vous vouliez faire.",
                "Prévenez **Valentin, le trésorier** avec ces informations : il pourra corriger proprement.",
            ],
            'keywords' => 'boulette erreur annuler faute problème bug',
        ],
    ],
];
