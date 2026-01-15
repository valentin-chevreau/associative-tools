# 🧾 Caisse Associative
Application de gestion de caisse pour évènements associatifs

---

## 🎯 Objectif

**Caisse Associative** est une application web conçue pour gérer simplement les ventes lors d’évènements associatifs  
(stands, buvettes, ventes solidaires, etc.).

Elle permet aux bénévoles d’encaisser rapidement tout en garantissant un **suivi fiable des ventes, des paiements et des recettes**.

---

## 📅 Gestion des évènements

- Création d’un **évènement actif** (un seul à la fois)
- Définition d’un **fond de caisse**
- Clôture d’un évènement
- Suppression d’un évènement et de toutes ses ventes associées
- Récapitulatif automatique par évènement :
  - Total CB
  - Total espèces
  - Total chèques
  - Montant gagné
  - Caisse attendue (fond de caisse + espèces)

---

## 🛒 Caisse (encaissement)

### Produits

- Affichage des produits actifs
- Gestion du stock :
  - stock limité
  - stock illimité
- Vue **tuiles** ou **liste**
- Alerte stock faible
- Badge de quantité par produit

---

### 💝 Don libre

- Produit spécial **« Don libre »**
- Saisie libre du montant
- Regroupement automatique des dons sur une seule ligne
- Possibilité de transformer la **monnaie rendue en don**

---

## 🧺 Panier

- Ajout / retrait de produits
- Modification des quantités
- Suppression d’une ligne
- Total du panier mis en évidence
- Vider le panier
- Mise en attente d’une vente
- Rappel d’une vente mise en attente
- **Mode rapide** pour accélérer l’encaissement

---

## 💳 Paiements

### Moyens de paiement

- Espèces
- Carte bancaire (CB)
- Chèque

### Logique de paiement

- La saisie d’un montant en **espèces** enregistre automatiquement un paiement
- Les espèces peuvent être **complétées par CB ou chèque**
- Un paiement **CB ou chèque seul** valide automatiquement la vente
- Un paiement **espèces** nécessite :
  - soit une validation manuelle via le bouton *Valider*
  - soit un complément CB / chèque
- La monnaie rendue peut être conservée comme **don**

---

### Bloc « Paiements enregistrés »

- Affichage clair des paiements par type
- Total payé
- Reste à payer
- Mise à jour en temps réel

---

## ✅ Validation de la vente

- Protection contre les doubles clics
- Confirmation requise pour les montants élevés
- Enregistrement complet :
  - vente
  - lignes de produits
  - paiements
- Réinitialisation automatique de la caisse après validation

---

## 🕘 Historique des ventes

### Historique détaillé

- Une ligne par produit vendu
- Regroupement logique par vente
- Paiements affichés de manière lisible
- Tableau responsive
- Filtres disponibles :
  - évènement
  - bénévole
  - type de paiement
  - période (dates)
- Export CSV

---

### Actions administrateur

- Suppression d’une **ligne de vente**
- Suppression d’une **vente complète**
- Annulation de la **dernière vente** (avec remise à jour du stock)

---

## 🧾 Reçu / Ticket

- Génération d’un **reçu imprimable**
- Format A4, style « ticket »
- Compatible impression et PDF
- Générable après coup depuis l’historique
- Version stable, indépendante de l’interface de caisse

---

## 👤 Bénévoles

- Association facultative d’un bénévole à une vente
- Filtrage de l’historique par bénévole
- Valeur par défaut : *Global / non renseigné*

---

## 🔐 Mode administrateur

- Activation via une interface dédiée
- Accès réservé aux actions sensibles :
  - gestion des évènements
  - suppression de ventes
  - suppression de lignes
- Séparation claire entre usage bénévole et administration

---

## 🧱 Architecture technique

### Frontend

- HTML
- CSS (externalisé)
- JavaScript vanilla (externalisé)

### Backend

- PHP
- Base de données relationnelle (PDO)

### Stockage local

- `localStorage` pour la mise en attente des ventes
- Aucun encaissement validé sans enregistrement serveur

---

## 🧠 Philosophie

- Simplicité d’usage
- Pensé pour des bénévoles non techniques
- Résistant aux erreurs humaines
- Lecture et maintenance faciles
- Évolutif sans dépendances lourdes

---

## 📌 État du projet

- Fonctionnel
- Utilisable en conditions réelles
- Amélioré de manière itérative
- Historisé et sauvegardé via Git