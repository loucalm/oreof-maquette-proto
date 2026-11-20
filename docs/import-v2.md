# Importer des données d'ORéOF v2

La commande `app:import-v2` reprend des formations réelles d'ORéOF v2 dans le prototype. Elle **lit** la base v2
(uniquement des `SELECT`, rien n'y est jamais écrit) et range les données dans le modèle du prototype.

Trois façons de s'en servir, de la plus simple à la plus complète :

| Vous avez…                                   | Faites                                          |
|----------------------------------------------|-------------------------------------------------|
| rien (ni base, ni dump)                      | [rejouer l'exemple versionné](#1-sans-base-v2--rejouer-lexemple) |
| la pile `oreof-stack` qui tourne en local    | [lire sa base](#2-avec-la-pile-oreof-stack-locale) |
| seulement un dump SQL de la base v2          | [charger le dump](#3-avec-un-dump-sql)           |

Les formations de démonstration (fixtures) restent là dans tous les cas : l'import **ajoute** des formations.

## 1. Sans base v2 : rejouer l'exemple

`data/import-v2/exemple.json` est un extrait **anonymisé** de trois formations v2 (une licence sans parcours,
un BUT à deux parcours, un master à trois parcours) et des référentiels liés.

```bash
make import-v2-exemple
```

(équivaut à `docker compose exec app php bin/console app:import-v2 --from=data/import-v2/exemple.json`)

## 2. Avec la pile oreof-stack locale

La base v2 (`oreof-db`) n'est visible que sur le réseau Docker de la pile. On y relie le conteneur du proto :

```bash
make v2-stack
```

Puis, dans `.env.local` (jamais dans `.env`, il contient un mot de passe) :

```dotenv
OREOF_V2_DATABASE_URL=mysql://oreof:<mot de passe de la pile>@oreof-db:3306/oreof_v2
```

Le mot de passe est celui de `MYSQL_PASSWORD` dans le `docker-compose.yml` de la pile. Si le réseau de votre pile
ne s'appelle pas `oreof-stack_default` (`docker network ls`), précisez-le : `OREOF_V2_NETWORK=… make v2-stack`.

## 3. Avec un dump SQL

Une base MariaDB jetable charge le dump au premier démarrage (quelques minutes pour ~200 Mo) :

```bash
make v2-dump DUMP=/chemin/vers/oreof_v2.sql
```

Puis, dans `.env.local` :

```dotenv
OREOF_V2_DATABASE_URL=mysql://root:proto@v2-db:3306/oreof_v2
```

Pour repartir d'un autre dump : `docker compose -f compose.yaml -f compose.v2-dump.yaml down -v`, puis relancer.

## Choisir et importer

```bash
make import-v2 ARGS="--list"            # toutes les formations v2 (id, type, nom, parcours, campagne)
make import-v2 ARGS="--list=BUT"        # filtrées par type (L, LP, M, BUT…)
make import-v2 ARGS="--formation=33 --formation=333"
make import-v2                          # sans formation : seulement les référentiels liés
```

La base peut aussi être donnée à la volée : `--source=mysql://…` (prioritaire sur `OREOF_V2_DATABASE_URL`).

Réimporter une formation **remplace** l'import précédent de la même formation v2 (ses fiches matière et son
historique avec). Les formations créées à la main dans le prototype ne sont jamais touchées.

## Partager un exemple

Pour que d'autres puissent rejouer un import sans accès à la base :

```bash
make import-v2 ARGS="--formation=33 --formation=333 --formation=11 --save=data/import-v2/exemple.json"
```

`--save` n'importe rien : il écrit l'extrait JSON. À l'enregistrement, les noms des personnes deviennent
« Responsable 1 », « Responsable 2 »…, les coordonnées (secrétariat, contacts) sont retirées et les e-mails et
téléphones des textes libres masqués. Relisez tout de même le fichier avant de le versionner.

## Ce qui est repris

| ORéOF v2                                                     | Prototype                                                   |
|--------------------------------------------------------------|-------------------------------------------------------------|
| type de diplôme (`libelle_court` : L, LP, M, BUT)            | type de même clé ; les autres types sont ignorés (signalé)  |
| formation (mention, sigle, niveaux, RNCP, objectifs…)        | fiche formation (Organisation, Présentation)                |
| « Parcours par défaut » seul                                 | formation sans parcours, fiche du parcours par défaut       |
| parcours (lieu, régimes, stage, débouchés, codes ROME…)      | parcours (nœud + fiche), ramification (`parcours_parent_id`) |
| semestres numérotés                                          | années et semestres, à compter du 1er semestre du type (LP : S5 = année 1) ; un parcours qui commence plus tard garde ses numéros (S3 → « Année 2, Semestre 3 ») |
| semestre / UE « raccrochés » (mutualisés)                    | contenu de la source recopié                                |
| UE, EC (libellé, code Apogée, ECTS, heures, type, nature)    | nœuds UE / EC                                               |
| UE ou EC à choix (enfants, même sans semestre ou UE dans v2) | bloc de choix (« UE à choix » / « EC à choix » sans libellé) |
| textes riches (HTML de l'éditeur v2)                         | texte simple (paragraphes, listes « - », entités décodées)  |
| fiches matière des EC                                        | fiches portées par la formation importée                    |
| MCCC d'un EC (`type_mccc` + lignes `mccc`)                   | champ MCCC de l'EC : type (cc → CC, ct → CT, cc_ct → CC + CT, cci → CCI), épreuves de 1re session (type d'épreuve, %, durée), options (quitus, assiduité) |
| MCCC d'une fiche matière                                     | MCCC par défaut de la fiche (l'EC sans MCCC propre les reprend) |
| seconde session (rattrapage)                                 | gardée dans la valeur (`rattrapage`), pas encore affichée par le prototype |
| MCCC du BUT (modalités TD/TP, CM, portfolio, stage… en %)    | type CC, une épreuve par modalité non nulle                 |
| domaines, mentions, composantes, villes, types d'UE/EC…      | valeurs ajoutées aux référentiels (rien n'est retiré)       |

Pas encore repris : mutualisations entre formations, compétences / BCC, workflows et validations, historique,
commentaires, utilisateurs et droits.

MCCC : dans le prototype, seul le type CCI affiche sa liste d'épreuves. Pour voir (et modifier) les épreuves importées
des types CC, CT et CC + CT, activer « Collection d'épreuves » sur ces types dans Administration › Types de MCCC.
Les données v2 sont nettoyées à l'import : lignes BUT réservées aux BUT, lignes répétées d'une campagne à l'autre
retirées, et de deux générations d'une même épreuve (plus de 100 % à elles deux) seule la plus récente est gardée.

Une formation v2 peut sortir des bornes de son type de diplôme dans le prototype : elle est importée telle quelle
et les écarts apparaissent dans « Vérifier la saisie ». Les anomalies de v2 repérées (semestres en double dans un
parcours…) sont signalées dans le compte rendu de la commande.

## Code

- `src/Import/V2/V2Reader.php` : lecture de la base v2 → extrait (tableau) ; seul endroit qui connaît les tables v2.
- `src/Import/V2/V2ExtractFile.php` : extrait ↔ JSON, anonymisation.
- `src/Import/V2/V2Importer.php` : extrait → entités et maquettes du prototype.
- `src/Command/ImportV2Command.php` : la commande.
- `tests/Import/V2ImportTest.php` : mini base v2 en SQLite, import, remplacement, anonymisation, commande.
