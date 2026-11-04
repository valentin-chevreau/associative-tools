<?php
return [
    'key'     => 'etiquettes',
    'label'   => 'Étiquettes et catégories',
    'module'  => 'logistique',
    'order'   => 40,
    'context' => ['logistique'],
    'entries' => [
        'etiquette-rapide' => [
            'q'     => "Comment imprimer l'étiquette d'une catégorie ?",
            'risk'  => 'safe',
            'pages' => ['categories/view.php'],
            'steps' => [
                "Allez dans **Convois → Catégories**.",
                "Sur la catégorie voulue, touchez **Étiquette ×4** (une icône d'imprimante sur téléphone en mode liste).",
                "Le PDF de 4 étiquettes s'ouvre dans un nouvel onglet : imprimez-le depuis la visionneuse.",
            ],
            'keywords' => 'imprimer pdf quatre étiquettes',
        ],
        'planche-personnalisee' => [
            'q'     => "Comment préparer une planche avec plusieurs catégories et plus d'étiquettes ?",
            'risk'  => 'safe',
            'pages' => ['labels/labels.php'],
            'steps' => [
                "Allez dans **Étiquettes** (menu Convois).",
                "Tapez le nom d'une sous-catégorie, touchez-la pour l'ajouter, puis réglez la **quantité** avec **−** et **+**. Répétez pour chaque catégorie.",
                "Touchez **Générer PDF** : le PDF s'ouvre dans un nouvel onglet. Les pages sont ajoutées automatiquement selon le nombre d'étiquettes (4 par page).",
            ],
            'link'  => ['label' => 'Ouvrir les étiquettes', 'path' => '/logistique/labels/labels.php'],
            'keywords' => 'planche pages quantité plusieurs',
        ],
        'traduction-manquante' => [
            'q'     => "« Traduction UA manquante » : que faire ?",
            'risk'  => 'safe',
            'pages' => ['labels/labels.php', 'categories/view.php'],
            'steps' => [
                "L'étiquette est bilingue français / ukrainien : sans libellé ukrainien, elle ne peut pas être éditée.",
                "Un **admin** peut le compléter dans **Convois → Catégories → Modifier** (champ **Libellé UA**).",
            ],
            'keywords' => 'ukrainien libellé UA erreur',
        ],
        'etiquette-medicale' => [
            'q'     => "Pourquoi certaines étiquettes ont un repère rouge ?",
            'risk'  => 'safe',
            'pages' => ['labels/labels.php', 'categories/view.php'],
            'steps' => [
                "Les étiquettes des catégories **médicales** reçoivent automatiquement un repère rouge (un cercle avec une croix) en haut à droite.",
                "Il n'est donc plus nécessaire de dessiner une croix au marqueur.",
                "Si une étiquette médicale n'a pas son repère, un **admin** doit cocher **Médical** sur la catégorie (ou sur sa catégorie racine) dans **Convois → Catégories → Modifier**.",
            ],
            'keywords' => 'croix rouge médical marqueur',
        ],
        'bien-imprimer' => [
            'q'     => "Comment imprimer les étiquettes proprement ?",
            'risk'  => 'safe',
            'pages' => ['labels/labels.php', 'categories/view.php'],
            'steps' => [
                "Dans la visionneuse PDF, lancez l'impression en **A4 paysage**.",
                "Choisissez l'échelle **100 % / taille réelle** (pas « Ajuster à la page »).",
                "Découpez les quatre étiquettes de la feuille.",
            ],
            'keywords' => 'imprimante format échelle découper',
        ],
        'affichage-categories' => [
            'q'     => "Comment changer l'affichage de la liste des catégories ?",
            'risk'  => 'safe',
            'pages' => ['categories/view.php'],
            'steps' => [
                "En haut à droite, à côté du titre, choisissez **Dalles** (cartes) ou **Liste** (une ligne par catégorie). Votre choix est mémorisé sur cet appareil.",
                "Sur téléphone, les catégories sont repliées : touchez la **flèche** devant un nom pour déplier ses sous-catégories. Une recherche déplie tout ce qui correspond.",
            ],
            'keywords' => 'tuiles vue replier déplier',
        ],
    ],
];
