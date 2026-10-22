# Onglet Structure d'un diplôme : arbre éditable

Conception du 22/10/2026. Remplace la présentation de l'onglet / étape « Structure » (§6.4 de `2026-10-20-creation-diplome-design.md`) et revient sur les **éléments imposés** (A6, A18 et la ligne « Élément imposé » des gabarits de règle). Les autres arbitrages du §10 restent valables.

## 1. Intention

L'onglet Structure actuel (`/diplomes/{id}/versions/{v}/structure`) est jugé « mal fait, compliqué et non explicite » :
- une ligne « Durée (nombre de nœuds du premier niveau) » abstraite ;
- un tableau de niveaux dont chaque ligne aligne quatre bornes identiques (libre / au moins / au plus / entre / exactement) sans dire ce qu'elles signifient pour un humain ;
- une notion « élément imposé » à part, avec position, nature, surcharge de borne, verrous ;
- les natures d'élément saisies dans un champ texte séparé par des virgules ;
- rien ne dit quels niveaux la formation pourra modifier.

L'utilisateur veut qu'un admin **lise la structure comme on la décrit à voix haute** : « 3 années de 2 semestres, chaque semestre a 3 à 6 UE, chaque UE 1 à 4 EC ; l'année et le semestre sont fixes, les UE et les EC sont libres ». Il veut pouvoir définir explicitement :
1. les nœuds utilisés, leur hiérarchie et leur nombre d'enfants ;
2. la durée, en choisissant un nœud temporel et un nombre ;
3. quels nœuds sont modifiables et lesquels sont verrouillés ;
4. les types d'éléments du dernier niveau.

Finalité, rappelée par l'utilisateur avec deux exports réels (`MCCC-2026-2027-BUT-MMI-WEB.xlsx`, `MCCC-2026-2027-L-DROIT-AP.xlsx`) : ces exports Excel doivent à terme sortir **automatiquement** de la structure et des fiches, avec les bons coefficients par type d'EC. La structure doit donc porter tout ce dont ces exports ont besoin.

## 2. Ce que montrent les deux exports

| | Licence Droit AP (L3) | BUT MMI |
|---|---|---|
| Découpage | une feuille par année (S5 + S6) | une feuille par semestre |
| Place de l'EC | sous **une seule UE** (UE 5.1 › EC 1, EC 2) | au **semestre**, avec un **coefficient vers plusieurs UE** (BC1…BC5) |
| ECTS | saisis sur l'EC ; l'UE fait la somme ; le semestre = 30 | portés par l'UE (compétence) |
| Type d'EC | colonne « Type d'enseignement » : Disciplinaire, Méthodologie, Langue vivante, Pré-professionnalisation, Compétences transversales, Stage, TER | Ressource, SAÉ, Stage, Portfolio — le type range les lignes et fixe les colonnes MCCC |
| Compétences | mentions (« 1A, 2A ») sans coefficient | coefficients chiffrés par UE ; ratio SAÉ ≈ 0,5 calculé en bas |
| Stage | un EC « Stage » dans une UE « Pratique professionnelle » | une ligne EC « STAGE » au S2 |

Conséquence : le stage n'est nulle part un nœud spécial. C'est un **EC d'un type donné**, avec sa fiche.

## 3. Décisions prises avec l'utilisateur (22/10/2026)

- **D1 — Présentation en arbre** (direction A parmi trois), version **épurée** (v3) : l'arbre ne montre que le statut, le nom et le nombre ; les réglages d'un niveau s'ouvrent dans un panneau à droite quand on le clique. Maquette : `.superpowers/brainstorm/4930-1792662766/content/structure-arbre-v3.html` (non versionnée).
- **D2 — EC est toujours le dernier niveau.** Il ferme l'arbre, ne se choisit pas et ne se supprime pas. Ce qui varie entre diplômes, ce sont ses **types**.
- **D3 — Plus d'« éléments imposés ».** L'utilisateur doute de leur utilité ; un stage ou un projet tutoré est « une EC avec une fiche matière où on configure ce qu'il faut ». Une obligation (« un stage au S6 ») devient une **règle sur les types d'EC**.
- **D4 — Une seule fiche EC, options activées par diplôme.** Le formulaire EC prévoit toutes les options ; un diplôme active ou désactive celles dont il a besoin. Pas de champ propre à un type (« période de stage ») : une précision va dans un champ texte libre commun (« Informations complémentaires »).
- **D5 — Durée** = un niveau temporel de la chaîne choisi dans une liste + une borne (« le diplôme dure exactement 6 semestres »).
- **D6 — Statut modifiable / verrouillé par niveau**, visible dans l'arbre (cadenas / crayon).
- **D7 — Exports automatiques** (§1) = critère de réussite de la refonte des diplômes ; l'export lui-même n'est pas construit ici (§4).

## 4. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Un onglet Structure (édition de version et étape de l'assistant, mêmes classes, A11) en forme d'arbre épuré + panneau de réglages.
- Couvrir par configuration au moins Licence, LP, Master, M2E (sans semestre) et BUT.
- Le modèle de structure dit, pour chaque niveau, où se saisissent les ECTS et comment les EC s'y rattachent, pour que l'export puisse être déduit plus tard.
- Les règles d'obligation sur les types d'EC remplacent les éléments imposés, y compris dans le contrôle des formations et les dérogations.

**Non-objectifs**
- Construire l'export Excel ou l'API (chantier à part, voir §7 du document du 20/10).
- Rendre la maquette d'une formation capable du rattachement multiple (EC au semestre coefficienté vers plusieurs UE). Ici, le **réglage** est déclaré, enregistré et dessiné dans l'arbre ; le comportement dans l'éditeur de formation reste le chantier 5.
- Changer l'onglet Formulaires (canevas, variantes) ou l'onglet MCCC, sauf les liens qui y mènent.
- Les règles de validation (compensation…), hors périmètre (Q20).

**Anti-objectifs** (raté même si ça « marche »)
- Un réglage, un gabarit ou une condition qui connaît « BUT », « Licence », « stage » ou une clé de type par son nom (principe tout modulable).
- Un arbre qui réaffiche toutes les bornes de tous les niveaux en permanence : on revient au problème de départ.
- Un admin qui doit comprendre « nœud », « borne », « parent » pour lire la structure : l'arbre parle en phrases courtes (« UE 3 à 6 », « × 2 », « 30 ECTS »).
- Une formation existante qui perd des données parce que les éléments imposés disparaissent (voir §6.6).

## 5. L'écran

### 5.1 Disposition

```
┌───────────────────────────── Structure ─────────────────────────────┐
│ Dure [exactement 6] [semestres ▾]          │  UE                    │
│                                             │  Nombre par semestre   │
│ 🔒 Année        × 3                          │   [entre 3 et 6]       │
│   🔒 Semestre   × 2 · 30 ECTS  ⏱             │  Dans la formation     │
│     ✏️ UE       3 à 6            ◀ sélection │   [modifiable ▾]       │
│       ✏️ EC     1 à 4 · 7 types              │  Les EC…               │
│     + niveau                                │   [sont rangés dedans▾]│
│                                             │  ECTS                  │
│ Règles                                      │   [somme des EC ▾]     │
│ • au moins 1 EC Stage en S6                 │  Plus de réglages ▸    │
│ + règle                                     │                        │
│ ▸ Aperçu de la maquette minimale            │                        │
└─────────────────────────────────────────────┴────────────────────────┘
```

### 5.2 L'arbre (colonne de gauche)

- **Ligne de durée** en tête : « Dure [borne] [niveau temporel ▾] ». La liste ne propose que les niveaux temporels de la chaîne (`NodeType::isTemporel()`). Le niveau choisi porte la marque ⏱ dans l'arbre.
- **Une ligne par niveau**, indentée sous son parent : icône de statut (🔒 verrouillé / ✏️ modifiable), nom du type de nœud, résumé court :
  - nombre : « × 3 » (exactement), « 3 à 6 », « au moins 2 », « au plus 4 », rien si libre ;
  - ECTS seulement s'il y a une contrainte : « 30 ECTS » (somme attendue) ou « ≤ 10 ECTS » ;
  - pour EC : « N types ».
  - pour un niveau dont les EC sont coefficientés (§5.3) : l'EC est dessiné **à côté** de ce niveau, sous son parent, avec la mention « coef. vers les UE ».
- **EC** est toujours la dernière ligne, d'une couleur distincte, sans action « supprimer ».
- **« + niveau »** insère un niveau entre deux niveaux existants (liste des types de nœud non encore utilisés, EC exclu). Supprimer un niveau se fait depuis son panneau. Le niveau Parcours suit le réglage « Parcours autorisés » (existant), placé dans « Plus de réglages » du premier niveau.
- **Règles** : liste courte en phrases sous l'arbre, « + règle » ouvre le catalogue de gabarits (existant).
- **Aperçu de la maquette minimale** (existant) : replié par défaut sous les règles.

### 5.3 Le panneau d'un niveau (colonne de droite)

Visible quand un niveau est sélectionné ; au chargement, aucun niveau n'est sélectionné et le panneau affiche une aide d'une ligne. Réglages, dans cet ordre, en phrases :

| Réglage | Valeurs | Modèle |
|---|---|---|
| Nombre par <parent> | libre / au moins / au plus / entre / exactement | `LevelSpec::count` (existant) |
| Dans la formation | modifiable / verrouillé | nouveau, §6.2 |
| Les EC… (seulement le niveau juste au-dessus d'EC) | sont rangés dedans / lui apportent un coefficient | nouveau, §6.3 |
| ECTS | libres / saisis ici (avec borne par nœud) / somme des enfants (avec valeur attendue) | `ects`, `ectsSum` (existants), présentés en un seul choix |
| Plus de réglages ▸ | numérotation (départ), parcours autorisés (premier niveau) | `startAt`, `parcoursAllowed` (existants) |

Panneau du niveau **EC** : nombre par parent, statut, ECTS, et la **liste des types d'EC** en étiquettes (ajout, renommage, suppression). Chaque étiquette porte deux liens : « fiche » (vers l'onglet Formulaires, ligne du type au niveau feuille, où l'on choisit socle ou variante — mécanique existante) et « MCCC » (vers l'onglet MCCC, jeu du type). Supprimer un type utilisé par une règle, un jeu MCCC ou une formation est refusé avec la raison, comme ailleurs.

Les modifications du panneau se reflètent aussitôt dans le résumé de la ligne. Un seul bouton « Enregistrer » pour l'onglet (comportement actuel).

## 6. Modèle

### 6.1 Durée

`StructureSpec::duration` devient `{ levelKey, bound }` : le niveau temporel qui mesure la durée et la borne sur son **nombre total par cursus** (A15 inchangé : périodes du parcours feuille + celles de ses parents). Aujourd'hui la durée compte les nœuds du premier niveau ; avec un niveau choisi plus bas (semestres sous des années), le contrôle compte les descendants de ce type dans le cursus, pas seulement les enfants directs.

`levelKey` doit être un niveau temporel de la chaîne ; si l'admin retire ce niveau de la chaîne, la durée est effacée et l'arbre le signale.

**Lien avec le nombre du premier niveau.** Le premier niveau n'a pas de parent : son nombre n'est pas saisi, il **découle de la durée**.
- Si le niveau de durée est le premier niveau, son nombre est la borne de durée (comportement actuel).
- Sinon, chaque niveau situé entre le premier niveau et le niveau de durée doit avoir un nombre « exactement » ; le panneau l'impose. Le nombre du premier niveau = durée ÷ produit de ces nombres (ex. 6 semestres ÷ 2 par année = 3 années ; « 6 ou 8 semestres » donne « 3 ou 4 années »).
- Une borne de durée qui ne se divise pas exactement (ex. 5 semestres avec 2 par année) est refusée à l'enregistrement de la version, avec un message en clair.
- L'arbre affiche le nombre déduit du premier niveau en lecture seule (« × 3 »).
- L'instanciation génère la durée minimale, comme aujourd'hui. Le contrôle compte les nœuds du niveau de durée par cursus (A15), descendants indirects compris.

### 6.2 Statut modifiable / verrouillé

Nouveau booléen `LevelSpec::locked`.
- **Verrouillé** : dans une formation, le nombre et l'ordre des nœuds de ce niveau sont fixés par la maquette générée à la création. Le responsable ne peut ni ajouter, ni supprimer, ni dupliquer, ni déplacer un nœud de ce niveau. Il saisit librement leur contenu (intitulé, heures, ECTS si saisis à ce niveau…).
- **Modifiable** : ajout, suppression, duplication, déplacement autorisés dans les bornes du nombre.
- **Un niveau verrouillé a forcément un nombre « exactement N ».** Dans le panneau, passer un niveau en « verrouillé » réduit le choix du nombre à « exactement » (valeur proposée : le minimum actuel). Un intervalle verrouillé n'aurait pas de sens : la formation resterait bloquée au minimum généré.
- Contrôle : le garde compare, pour chaque parent, la suite ordonnée des identifiants (`nid`) des nœuds du niveau verrouillé avant et après la modification. Toute différence (ajout, suppression, suppression puis recréation, déplacement) est une violation. Pas de copie de la maquette générée ni de marqueur par nœud.
- Le contrôle passe par le même chemin que les bornes : action refusée, dérogation possible (§10 « Dérogations »).
- Comme les bornes, le statut est lu en direct depuis la version : il s'applique aussitôt aux formations existantes, sans les modifier. Une formation déjà différente reste telle quelle (seule une aggravation est refusée).
- EC est modifiable par défaut ; verrouiller EC est permis.

### 6.3 Rattachement des EC

Nouveau réglage, `LevelSpec::ecLink`, porté **uniquement par le niveau juste au-dessus d'EC** : `contains` (défaut : les EC sont des enfants de ce niveau) ou `coefficient` (les EC sont enfants du niveau encore au-dessus et apportent un coefficient à chaque nœud de ce niveau ; BUT : UE en `coefficient`, EC sous le semestre). Une chaîne réduite à « premier niveau › EC » ne propose pas `coefficient`. Quand il l'est, le niveau qui porte les ECTS est ce niveau-là (réglage ECTS de l'EC forcé à « libres »).

Ce chantier enregistre et dessine le réglage. Le chantier 5 (rattachement multiple) l'exploite dans la maquette des formations. En attendant, une version en `coefficient` génère des formations comme aujourd'hui (EC sous ce niveau) et l'arbre affiche une note « effet dans les formations : à venir ».

### 6.4 Types d'EC

Les « natures d'élément » (`StructureSpec::natures`, attribut de nœud `natureElement`, A5) sont renommées **types d'EC** dans l'interface. Chaque type a une clé (stable, dérivée du libellé à la création, figée dès qu'il est utilisé) et un libellé. Le modèle passe d'une liste de clés à une liste `{ key, label }`. `fromArray` accepte encore l'ancienne forme (liste de clés, libellé = clé) pour les versions et brouillons déjà enregistrés. Rien d'autre ne change : le type continue de choisir la fiche (canevas) et le jeu MCCC.

### 6.5 Règles d'obligation

Le gabarit `required_element` devient **« Au moins N EC d'un type »** :
- paramètres : type d'EC, nombre minimal (défaut 1), portée (dans chaque nœud d'un niveau choisi / dans le premier / le dernier / le nœud numéro n d'un niveau temporel) ;
- « numéro n » = le **numéro affiché**, après `startAt` et compté sur le cursus (A15) : en LP, « S6 » est n = 6. « Premier » et « dernier » se lisent aussi par cursus ;
- plus de paramètre interne `imposed` (A9 devient sans objet pour ce gabarit).
- Exemples : « au moins 1 EC Stage dans le dernier semestre » (Licence, LP), « au moins 1 EC Stage dans les semestres 2, 4, 6 » (trois règles ou une portée multiple, au choix du constructeur).

Ces règles restent bloquantes et dérogeables comme aujourd'hui.

### 6.6 Suppression des éléments imposés

Disparaissent : `StructureSpec::imposed`, `ImposedElement`, `ImposedHostLocator`, `ImposedPropagator`, la surcharge de borne par position (`countOverride`), le marquage `_imposed` à l'instanciation, la clé de dérogation `imposed:`, et l'UI correspondante.

Reprise des données :
- les fixtures sont réécrites : la LP perd son UE imposée « Projet tutoré et stage » au profit d'EC des types « Projet tutoré » et « Stage » et de deux règles d'obligation ; le BUT déclare le type « Stage » et une règle ;
- les nœuds déjà marqués `_imposed` dans les formations existantes restent des nœuds ordinaires (on retire seulement le marqueur) ;
- pas de migrations dans le prototype (A14/A19) : après le changement de schéma, `make db-reset` est nécessaire et ne se lance qu'avec l'accord de l'utilisateur.

### 6.7 EC, dernier niveau obligatoire

Une version valide a une chaîne qui se termine par le type de nœud EC. La chaîne est créée avec EC déjà présent ; EC ne peut pas être retiré ni déplacé. Le type de nœud qui joue ce rôle est désigné par un réglage du type de nœud (« niveau élément »), pas par sa clé `ec`. Un seul type de nœud, toutes familles confondues, porte ce réglage ; une variante de type de nœud ne peut pas le porter. « + niveau » propose les types de nœud structurels (famille `Structural`, variantes exclues) qui ne sont ni déjà dans la chaîne ni le niveau élément ; les niveaux non temporels intermédiaires (Bloc de formation du M2E) restent possibles.

## 7. Contraintes

- Conventions du dépôt (`AGENTS.md`), CSRF et rôle admin sur les actions (A17), tests `WebTestCase` / `KernelTestCase`.
- Les mêmes classes servent à l'assistant et à l'édition (A11).
- Le rendu reste générique : aucun gabarit Twig ne teste une clé de type de nœud.
- Écran utilisable en largeur réduite : sous ~900 px, le panneau passe sous l'arbre.

## 8. Laissé au constructeur

- Technique du panneau (Stimulus, Turbo Frame…) et de la sélection d'un niveau.
- Forme exacte des sélecteurs de borne dans le panneau.
- Portée multiple ou règles multiples pour « semestres 2, 4, 6 ».
- Découpage des commits et ordre : on peut livrer l'écran (§5) avant la suppression des imposés (§6.6).

## 9. Choix faits par le rédacteur (à vérifier)

- **Sens de « verrouillé »** (§6.2) : nombre et ordre fixés, contenu saisissable. Proposé à l'utilisateur, non contredit.
- **Verrouillé ⇒ « exactement N »** (§6.2), et contrôle par comparaison des `nid` avant / après.
- **Durée en total par cursus** sur le niveau choisi (§6.1), pas « par parent » ; nombre du premier niveau déduit de la durée.
- **Rattachement par coefficient** limité au niveau juste au-dessus d'EC (§6.3).
- **Numéro n d'une règle** = numéro affiché (LP : S6 = 6) (§6.5).
- **« Variante » = la fiche d'un type d'EC**, choisie dans l'onglet Formulaires (socle ou variante, mécanique du 21/10). Le terme employé par l'utilisateur dans sa demande n'a pas été confirmé.
- **EC à choix** (« Droit constitutionnel OU Libertés fondamentales ») : décidé par l'utilisateur (22/10) — le nombre de choix d'un EC à choix est libre et se décide dans l'éditeur de formation, pas dans l'onglet Structure. Aucun réglage ici ; l'attribut existant `nature` (obligatoire / à choix) suffit.
- **Réglage de rattachement enregistré mais sans effet** dans les formations avant le chantier 5 (§6.3).
- **Aperçu de la maquette minimale** conservé, replié.
- **Champ « Informations complémentaires »** de la fiche EC : à ajouter au socle EC s'il n'existe pas ; hors de l'onglet Structure.
