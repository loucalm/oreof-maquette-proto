# Prototype — maquette ORéOF par nœuds

Bac à sable pour tester un modèle de maquette **entièrement souple** : une formation
n'est plus une hiérarchie figée (`Parcours › Année › Semestre › UE › EC`) mais un
**arbre de nœuds typés**, où le responsable définit lui‑même via l'interface les
types, leurs capacités et les imbrications autorisées.

> Séparé du dépôt `oreof-stack` / `oreofv2`. Jetable (SQLite).

## Démarrer

```bash
make up          # http://localhost:8830  (Symfony local server dans Docker)
make db-reset    # recrée la base SQLite + fixtures de démo
```

Autres : `make sh`, `make console ARGS="…"`, `make cc`, `make tw-watch`, `make logs`.

## Ce qui est dans le prototype

| Brique | Où |
|---|---|
| **Métamodèle** `NodeType` (clé, famille, `allowedChildKeys`, `capabilities`, `ectsTarget`) | `src/Entity/NodeType.php` + `/node-types` (CRUD) |
| **Arbre** `Node` (liste d'adjacence : `parent` + `position`, `attributes` JSON, `capabilityOverrides`) | `src/Entity/Node.php` |
| **Catalogue d'attributs** normalisés (ects, ueType, nature, competencies, ficheMatiere, hours, mccc) groupés par domaine | `src/Maquette/AttributeCatalog.php` |
| **Calculs** : statut de complétude 🔴/🟠/🟢 + agrégats heures / ECTS remontés | `src/Maquette/MaquetteBuilder.php` |
| **Éditeur** : arbre drag&drop (SortableJS) + panneau d'attributs par onglet, gated par capacités | `templates/formation/editor.html.twig`, `templates/node/panel.html.twig` |
| **Templates de structure** : base réutilisable (BUT, Licence, Master à parcours), instanciée puis modifiable ; « enregistrer la structure courante comme template » | `src/Entity/StructureTemplate.php` + `src/Maquette/TemplateApplier.php` + `/templates` |
| **Opérations nœud** : ajouter (typé, filtré), déplacer / reparenter, dupliquer, supprimer, « Paramètre du nœud » (capacités par nœud), mutualiser | `src/Controller/NodeController.php` |

## Pas (encore) dans le prototype

- Résolution d'héritage d'attributs le long de l'arbre (politique par diplôme)
- Moteur de règles de validation composable
- « Raccrocher » un nœud mutualisé d'une autre formation (le flag `mutualized` existe, l'écran de récupération non)
- Relations inter‑parcours (ramification), workflow, exports

## Stack

Symfony 8.1 / PHP 8.5 · Doctrine + SQLite · Twig + Stimulus + Turbo · AssetMapper.
**Tailwind v4 est chargé au runtime via CDN** (`@tailwindcss/browser`, dans `base.html.twig`) —
le binaire standalone du bundle ne scannait pas les templates dans ce conteneur ; pour un
prototype le runtime suffit et supprime tout build CSS. Styles maison dans `assets/styles/app.css`.
Conteneur : image `dannebicque/oreof-symfony-app` + `php8.5-sqlite3` (`docker/Dockerfile`).
