# Formulaires d'un type de diplôme : canevas, bibliothèque et variantes

Conception du 21/10/2026. Remplace l'étape 3 « Formulaires » décrite au §6.3 de `2026-10-20-creation-diplome-design.md` et la ligne « Formulaires de paramètres » des arbitrages (§10) de ce document.

## 1. Intention

La page `/diplomes/{id}/versions/{versionId}/formulaires` (et l'étape 3 de l'assistant) est jugée trop figée. Elle propose quatre emplacements fixes (formation › organisation, formation › présentation, parcours › organisation, parcours › présentation), chacun avec une liste déroulante de types d'ELP de famille « paramètre ». On ne peut ni masquer une entrée, ni en ajouter une, ni adapter un formulaire existant au besoin d'un diplôme, et les fiches des éléments de la maquette n'y figurent pas.

Principe directeur, rappelé par l'utilisateur : **tout doit être modulable**. Un type de diplôme, même inexistant aujourd'hui, doit pouvoir se créer uniquement par configuration. Aucun code ni gabarit ne teste le nom ou la clé d'un diplôme.

Ce qu'on veut à la place :

- un **canevas** de la fiche telle que le responsable de formation la voit, niveau par niveau, où chaque entrée reçoit son formulaire, peut être masquée, et où l'on peut ajouter des entrées « au cas où » ;
- un **nombre libre de formulaires**, rangés dans une bibliothèque commune ;
- la possibilité de partir d'un formulaire et de l'**adapter** au diplôme (variante) ;
- un **aperçu** qui montre la vraie page de la formation, modifiée en conséquence.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**

- Le canevas couvre trois fiches : formation, parcours, éléments (un niveau par maillon de la chaîne de structure, le niveau feuille décliné par nature d'élément).
- Pour chaque entrée de la fiche formation et de la fiche parcours : choisir le formulaire, masquer, réordonner, renommer ; ajouter ou retirer une entrée propre au diplôme.
- Pour chaque niveau d'élément (et chaque nature au niveau feuille) : choisir le formulaire.
- Bibliothèque de formulaires commune à tous les diplômes, sans limite de nombre, avec des formulaires « socle » et des **variantes** qui héritent d'un formulaire et ne stockent que leurs écarts.
- Une variante **suit** son formulaire de départ : elle récupère automatiquement ce qui change dans celui-ci (décision de l'utilisateur).
- Une variante est un formulaire de la **bibliothèque commune**, réutilisable par d'autres diplômes (décision de l'utilisateur).
- Bouton « Aperçu » par fiche, qui ouvre la vraie page formation rendue avec la configuration de la version.
- La barre latérale de l'éditeur de formation et celle de l'éditeur de parcours sont construites à partir du canevas de la version, plus à partir de listes codées en dur.

**Non-objectifs**

- Refaire le constructeur de champs (`FieldDef`, types de champ) : il est réutilisé, à ceci près que ses réglages de présentation deviennent propres à chaque formulaire (§6.4).
- Rendre configurables les écrans intégrés (BCC, configuration de la structure, maquette, ramification) : ils restent des écrans dédiés, seulement placés dans le canevas.
- Traiter les droits fins (qui peut modifier quoi dans une fiche) : la page reste réservée à l'admin.

**Anti-objectifs** (ce qui ferait échouer le chantier même si « ça marche »)

- Une condition, un gabarit ou une constante qui connaît un diplôme, une entrée ou un formulaire par sa clé (`if key == 'presentation'`…).
- Une variante qui devient une copie figée : si un champ ajouté au socle n'apparaît pas dans la variante, c'est raté.
- Un aperçu qui est un rendu séparé de la vraie page et qui finit par diverger d'elle.
- Une saisie de responsable perdue parce qu'un admin a masqué une entrée ou changé de formulaire.

## 3. Vocabulaire

| Terme | Sens |
|---|---|
| Fiche | Ce que le responsable remplit pour un objet : la formation, un parcours, ou un élément de la maquette (année, semestre, UE, EC…). |
| Canevas | Configuration, portée par la version du type, de ce que contient chaque fiche. |
| Entrée | Une ligne de la barre latérale d'une fiche formation ou parcours (ex. « 1 \| Organisation et localisation »). Elle affiche soit un formulaire, soit un écran intégré. |
| Écran intégré | Entrée dont le contenu est un écran dédié codé dans l'application : BCC, configuration de la structure, maquette, ramification. On ne lui choisit pas de formulaire. |
| Formulaire | Liste de champs rangés en onglets, avec séparateurs et règles de validation d'onglet. Dans le prototype, c'est un `NodeType` : ses capacités activent des `FieldDef`. |
| Socle | Formulaire qui ne dérive d'aucun autre. Les types `param_*` et les types de structure actuels deviennent des socles. |
| Variante | Formulaire qui dérive d'un autre (socle ou variante) et ne stocke que ses écarts. |
| Bibliothèque | Ensemble des formulaires (socles et variantes), commun à tous les diplômes. |

## 4. Ce qui existe aujourd'hui

- `src/Form/Diplome/FormsType.php` : quatre emplacements fixes (`FormsType::SLOTS`), stockés dans la configuration de version sous `forms` (`{"formation.organisation": "<clé de NodeType>", …}`).
- `src/Diplome/ParamTypeResolver.php` : retrouve le `NodeType` d'un emplacement, à défaut la convention `param_<section>_<portée>`.
- `src/Controller/FormationController.php` : `PARAM_SECTIONS` (organisation, présentation, structure) et `PARCOURS_PARAM_SECTIONS` (organisation, présentation) en dur ; routes `formation_param` / `parcours_param` par clé de section ; la section « organisation » enregistre en plus le nom, le domaine et la composante sur l'entité `Formation`.
- `templates/formation/editor.html.twig` (l.140-163) et `templates/formation/parcours_editor.html.twig` (l.85-100) : barre latérale numérotée en dur (1 Organisation, 2 Présentation, 3 BCC ou Ramification, 4 Structure).
- `src/Maquette/Completion.php` : statut et anomalies par section (`formationParamStatus`, `formationParamIssues`, équivalents parcours), qui alimentent les pastilles et la progression.
- Les données saisies sont stockées par section : `Formation::getParametre($section)` et `TreeNode::getParametre($section)` pour un parcours, valeurs indexées par clé de champ.
- Champs : un `FieldDef` est **global** (libellé, onglet, position, obligatoire, aide). Un `NodeType` n'en choisit que l'activation (`capabilities`, clé de champ ⇒ booléen) ; `FieldDefRepository::activeOrderedFor()` donne les champs actifs d'un type dans l'ordre global. Un champ `locked` ne s'édite ni ne se retire depuis le constructeur (nom de formation, responsables…).
- Éléments : le panneau d'un nœud s'appuie sur `TreeNode::effectiveCapabilities()` (capacités du type + surcharges du nœud), utilisé aussi par `QuickGrid`, `NodeClipboard` et `Completion`.
- Le rendu des champs est déjà générique (`src/FieldType/`, `widgetTemplate()` / `viewTemplate()`), préalable du §6.6 de la conception précédente.

## 5. Décisions de l'utilisateur

1. La page Formulaires devient un **canevas** de la fiche actuelle, avec ses entrées. Pour chaque entrée : choisir son formulaire, la masquer ; on peut en ajouter une.
2. Il manquait des **niveaux** : le canevas montre la fiche formation, la fiche parcours et les fiches des éléments (validé sur maquette, voir §6.1).
3. **Nombre libre** de formulaires.
4. On peut partir d'un formulaire et l'**adapter** au besoin du diplôme.
5. **Aperçu** : pointer vers la vraie page, modifiée en conséquence.
6. Une variante **suit son socle** : elle ne garde que ses écarts et récupère les nouveautés du socle.
7. Les variantes vont dans la **bibliothèque commune** : une variante peut être réutilisée par d'autres diplômes.
8. Une **formation sans parcours** utilise aussi la fiche parcours du canevas (entrées, variantes, masquage) ; plus de champs en dur dans « Configuration de la structure » (§6.7).

Les maquettes validées sont dans `.superpowers/brainstorm/63540-1792570480/content/` (`canevas.html`, `adapter.html`, non versionnées).

## 6. Comportement attendu

### 6.1 Onglet « Formulaires » : le canevas

Même composant pour l'étape « Formulaires » de l'assistant et pour l'onglet d'édition d'une version. L'étape **passe après « Structure »** (elle dépend de la chaîne de niveaux, des natures et de l'autorisation des parcours, qui y sont réglées) ; l'onglet d'édition suit le même ordre. Trois blocs, dans cet ordre :

**Fiche formation.** Liste ordonnée des entrées, numérotées comme dans la barre latérale. Pour chaque entrée :

- poignée de déplacement (l'ordre est celui de la barre latérale) ;
- libellé de l'entrée, modifiable (par défaut le libellé du formulaire) ;
- pour une entrée à formulaire : liste déroulante des formulaires de la bibliothèque de portée « formation », variantes comprises (affichées sous leur formulaire de départ) ; bouton **Adapter**, qui crée toujours une nouvelle variante du formulaire choisi et la sélectionne ; bouton **Modifier**, qui ouvre le formulaire choisi (socle ou variante) avec la confirmation d'impact du §6.2. Une variante n'a pas de diplôme propriétaire ;
- pour un écran intégré : la mention « Écran intégré », sans choix de formulaire ;
- bouton **Masquer** / **Afficher**. Une entrée masquée reste dans la liste, grisée et barrée, sans numéro ;
- bouton **Retirer** pour toute entrée à formulaire (les écrans intégrés se masquent seulement, quand c'est permis). Badge « ajoutée » sur une entrée absente du canevas par défaut.

En bas : **＋ Ajouter une entrée** (libellé, puis choix d'un formulaire existant ou création d'un formulaire vide).

**Fiche parcours.** Même présentation. Le bloc est toujours affiché : il s'applique aussi au parcours unique d'une formation sans parcours (§6.7).

**Fiches des éléments.** Une ligne par niveau de la chaîne (onglet Structure), dans l'ordre de la chaîne ; le niveau feuille a une ligne par nature d'élément déclarée par la version. Chaque ligne : le formulaire choisi (socles et variantes de la portée « élément » compatibles avec ce type de nœud), **Adapter** / **Modifier la variante**. Pas de masquage ni d'ajout à ce niveau : une fiche d'élément n'a pas de barre latérale d'entrées.

Un bouton **Aperçu ↗** en tête de chaque bloc (voir §6.5).

**Champs verrouillés.** L'enregistrement du canevas est refusé si un champ `locked` d'une fiche (nom de la formation, domaine, composante, responsables, nom du parcours…) ne figure dans aucune entrée affichée de cette fiche. Le message nomme le champ manquant.

**Si la structure change ensuite.** Le canevas est relu face à la structure à chaque affichage : un niveau ou une nature ajouté prend par défaut le formulaire du type de nœud lui-même ; un niveau ou une nature retiré disparaît du canevas au prochain enregistrement.

**Écrans intégrés et masquage.**

| Écran intégré | Fiche | Masquable |
|---|---|---|
| Blocs de compétences | formation (sans parcours), parcours | oui |
| Ramification | formation (avec parcours) | non |
| Configuration de la structure | formation | non |
| Positionnement & ramification | parcours | non |

Les entrées « Ramification » et « Blocs de compétences » de la fiche formation dépendent du mode avec ou sans parcours de la formation, comme aujourd'hui : le canevas les déclare toutes deux, l'éditeur n'affiche que celle qui s'applique.

**Canevas par défaut.** Le canevas d'une nouvelle version (et celui appliqué à une formation sans type, ou à une version sans canevas) reproduit exactement la fiche actuelle : formation = Organisation et localisation, Présentation, BCC / Ramification, Configuration de la structure ; parcours = Organisation et localisation, Présentation, BCC, Positionnement & ramification ; éléments = le type de nœud de chaque niveau tel quel.

Ce canevas par défaut est **une donnée, pas une constante** : chaque formulaire socle porte sa portée et, s'il fait partie du canevas par défaut, sa position par défaut ; chaque écran intégré déclare la sienne. L'admin peut donc changer le canevas par défaut depuis la bibliothèque. Les fixtures posent les valeurs qui reproduisent la fiche actuelle.

### 6.2 Bibliothèque de formulaires

- Gérée dans l'administration, page « Types d'élément pédagogique » (`/node-types`), qui affiche les variantes sous leur formulaire de départ, avec le nombre de diplômes qui utilisent chaque formulaire. La colonne de droite du canevas en donne une vue en lecture.
- Un formulaire a une **portée** : formation, parcours ou élément. La portée remplace la convention de suffixe `*_formation` / `*_parcours` pour filtrer les listes. Un formulaire d'élément est rattaché au type de nœud qu'il habille (`ue`, `ec`…) : seuls les formulaires de ce type sont proposés pour un niveau.
- Créer une variante : depuis le canevas (« Adapter ») ou depuis la bibliothèque. Nom proposé : « <formulaire de départ> · <sigle du diplôme> », modifiable. Depuis le canevas, la variante créée est aussitôt choisie pour l'entrée. La variante est enregistrée dans la bibliothèque dès son propre « Enregistrer », indépendamment du canevas, y compris dans l'assistant ; une variante restée inutilisée (assistant abandonné) se supprime normalement.
- Modifier un formulaire : la page liste les diplômes et versions qui l'utilisent, **directement ou à travers une de ses variantes**, et exige la **confirmation d'impact** s'il y en a, comme pour une version utilisée (§« Modifier une version » de `docs/diplomes.md`). Une modification s'applique tout de suite à toutes les formations concernées.
- Supprimer un formulaire n'est possible que s'il n'est utilisé par aucun canevas et n'a pas de variante ; le refus est appliqué côté serveur.

### 6.3 Écran « Adapter » (variante)

Maquette `adapter.html`. En tête : nom de la variante, badge « variante », formulaire de départ, diplômes qui l'utilisent, boutons **Aperçu ↗** et **Enregistrer**.

Par onglet, la liste des champs résolus (formulaire de départ + écarts), chacun avec :

- son origine : « hérité », « libellé changé », « masqué », « rendu obligatoire » / « rendu facultatif », « déplacé », « ajouté » ;
- un interrupteur **Affiché** et un interrupteur **Obligatoire** ;
- le libellé, modifiable (le libellé du départ reste indiqué en dessous) ;
- la poignée de déplacement.

**＋ Ajouter un champ** (un `FieldDef` existant du catalogue, ou un nouveau champ) et **＋ Séparateur**. Ajouter un onglet est possible, et un champ hérité peut être déplacé dans un autre onglet.

Pour une **fiche d'élément**, l'écran reprend les réglages de capacité de l'éditeur de type actuel au lieu du seul interrupteur « Affiché » : activé par défaut ou non, et réservé à l'admin (`lockedCapabilities`) ou non.

Un bandeau signale, à titre d'information, les champs apparus dans le formulaire de départ depuis la dernière modification de la variante.

### 6.4 Résolution d'une variante

Une variante stocke uniquement ses écarts par rapport à son formulaire de départ :

- champs masqués ;
- libellé, aide et caractère obligatoire surchargés, par champ ;
- ordre (position de ses champs dans un onglet) ;
- champs et séparateurs ajoutés ;
- règles de validation d'onglet ajoutées.

La liste de champs d'une variante = résolution récursive du formulaire de départ, puis application des écarts. Conséquences :

- un champ ajouté au départ apparaît dans la variante, visible, à la place qu'il a dans le départ ;
- un champ retiré du départ disparaît de la variante ; un écart qui le visait est ignoré, sans erreur ;
- un champ `locked` (§4) ne peut pas être masqué ni rendu facultatif dans une variante ;
- une variante d'une variante est permise, sur plusieurs niveaux ; un cycle est refusé.

**Réglages par formulaire, socles compris.** Aujourd'hui le libellé, l'aide, le caractère obligatoire, l'onglet et la position d'un champ sont globaux sur `FieldDef`, et le constructeur de `/node-types` les modifie pour tous les types à la fois. Ils deviennent des réglages **de chaque formulaire** (socle ou variante). `FieldDef` garde la définition du champ : clé, type, options, référentiel, bornes, et les valeurs par défaut de libellé, aide et obligatoire utilisées quand un formulaire n'en fixe pas. Renommer « Objectif » dans « Présentation (formation) » ne touche donc plus « Présentation (parcours) », seulement ses variantes qui n'ont pas leur propre libellé. Le constructeur de `/node-types` écrit ces réglages sur le formulaire ; seuls la création d'un champ et la modification de son type ou de ses options restent globales. Les règles de validation d'onglet et la complétude utilisent les valeurs résolues.

**Fiches d'élément.** Masquer un champ dans une variante d'élément revient à désactiver la capacité correspondante, avec les mêmes effets qu'aujourd'hui pour un type sans cette capacité (pas d'ECTS comptés, pas de contrôle MCCC, etc.). Le responsable peut la réactiver sur un nœud seulement si la variante ne la réserve pas à l'admin, comme aujourd'hui avec `capabilityOverrides`.

### 6.5 Aperçu

- Le bouton ouvre, dans un nouvel onglet, **la vraie page** de l'éditeur de formation (fiche formation), de l'éditeur de parcours (fiche parcours) ou du panneau d'un nœud (fiche élément), rendue avec le canevas en cours d'édition : entrées masquées absentes, entrées ajoutées présentes, variantes appliquées.
- Il porte sur une formation de cette version si elle en a une ; sinon sur une formation fictive construite en mémoire à partir des bornes de la version (comme l'aperçu de l'onglet Structure), jamais enregistrée.
- L'aperçu est en lecture seule : aucune saisie, aucun enregistrement.
- Dans l'assistant, l'aperçu prend le canevas du brouillon, non encore enregistré.
- Depuis l'écran « Adapter », l'aperçu montre la fiche avec la variante en cours, même non enregistrée.

### 6.6 Côté formation

- La barre latérale de l'éditeur de formation et de l'éditeur de parcours est construite à partir du canevas de la version de la formation : entrées non masquées, dans l'ordre, numérotées à la suite.
- Chaque entrée à formulaire s'ouvre par une route générique (même principe que `formation_param` / `parcours_param`, paramétrée par la clé de l'entrée) qui rend les champs résolus du formulaire choisi. Plus de liste de sections en dur dans le contrôleur.
- Les données d'une entrée sont stockées sous la clé de l'entrée (`getParametre(<clé d'entrée>)`). Les entrées existantes gardent leur clé (`organisation`, `presentation`) : aucune donnée existante ne change de place.
- Les champs d'entité de la section organisation (nom, domaine, composante de la formation) sont des champs `locked` qui écrivent sur l'entité ; ils doivent continuer à fonctionner quel que soit le formulaire choisi, sans test sur la clé d'entrée.
- **Entrée masquée** : absente de la barre latérale, de la progression, des pastilles, de la page Vérifier, de la page « Voir » et de l'export enrichi. Ses données sont conservées : la réafficher les fait revenir.
- **Changement de formulaire** d'une entrée : les valeurs déjà saisies sont conservées par clé de champ ; un champ absent du nouveau formulaire n'est plus affiché ni exporté, sa valeur reste stockée.
- L'export JSON brut (`MaquetteExporter::raw()`) garde son contrat : il renvoie `parametres` tels que stockés, sans filtrage.
- **Fiches des éléments** : le formulaire d'un nœud est celui que le canevas désigne pour son niveau et, au niveau feuille, pour sa nature (`natureElement`, nature par défaut sinon). Les surcharges de capacités par nœud (`capabilityOverrides`) et `lockedCapabilities` continuent de s'appliquer par-dessus. Le panneau, la grille rapide, le presse-papiers, la complétude et l'export utilisent tous cette résolution.

### 6.7 Formation sans parcours

Décision de l'utilisateur : la fiche parcours du canevas s'applique aussi à une formation sans parcours.

- Aujourd'hui, les informations de niveau parcours d'une formation sans parcours sont codées en dur dans « Configuration de la structure » (`templates/formation/param/structure.html.twig` : intitulé `intituleMono`, ECTS total, composante, régimes d'inscription, modalités d'alternance, langue, poursuite d'études, débouchés…) et enregistrées par `formation_settings` dans `parametres.structure`. Ces champs en sont retirés : l'écran ne garde que les réglages de structure (avec ou sans parcours, unité et durée du calendrier, dérogations).
- Dans l'éditeur d'une formation sans parcours, la barre latérale affiche, après les entrées de la fiche formation, un groupe « Parcours » avec les **entrées à formulaire** de la fiche parcours (non masquées, dans l'ordre du canevas). Les écrans intégrés de la fiche parcours n'y figurent pas : les BCC sont déjà l'entrée « Blocs de compétences » de la fiche formation, et « Positionnement & ramification » n'a pas de sens sans parcours.
- Les données de ces entrées sont rangées sur la formation, sous la clé de l'entrée dans un espace propre au parcours unique (par exemple `parametres.parcours.<clé d'entrée>`), avec les mêmes clés de champ que sur un nœud parcours.
- Les champs qui écrivent aujourd'hui sur l'entité (`ectsTotal`, `composante`…) continuent d'écrire au bon endroit dans les deux modes, sans test sur la clé d'entrée.
- **Passage avec ↔ sans parcours** (`reshapeParcoursLevel`) : vers « avec parcours », les données de parcours de la formation sont recopiées sur le parcours créé ; vers « sans parcours » (un seul parcours restant), celles du parcours remontent sur la formation. Rien n'est perdu.
- Complétude, page Vérifier, page « Voir » et exports lisent ces données au même endroit que l'éditeur.
- Le canevas affiche le bloc « Fiche parcours » même si la version n'autorise pas les parcours, avec la mention « s'applique au parcours unique ».

### 6.8 Versions et assistant

- Le canevas fait partie de la configuration de version (il remplace la clé `forms`) : « Nouvelle version », « Partir d'un diplôme existant » et « Dupliquer » le copient. Les formulaires, eux, ne sont pas copiés : le canevas copié désigne les mêmes formulaires de la bibliothèque.
- L'onglet Formulaires obéit aux règles d'édition d'une version : liste des formations concernées et confirmation d'impact si la version est utilisée.
- Le récapitulatif de l'assistant (et la comparaison avec le type de départ) décrit le canevas : entrées, formulaires choisis, entrées masquées ou ajoutées.
- Lecture de l'ancien format : une configuration qui contient encore `forms` (quatre emplacements) est lue comme un canevas par défaut dont les quatre entrées correspondantes portent ces formulaires.

## 7. Contraintes

- Aucun code, gabarit ou condition ne teste la clé d'un diplôme, d'une entrée, d'un formulaire ou d'un écran intégré précis ailleurs que dans la déclaration de cet écran intégré.
- Les écrans intégrés sont déclarés de façon extensible (même principe que les étapes d'assistant ou les gabarits de règles : une classe découverte automatiquement) ; en ajouter un ne modifie ni le canevas ni les barres latérales.
- Page et actions réservées à l'admin côté serveur, avec jeton CSRF (préfixe de route `diplome_` pour le canevas).
- Pas de migrations dans ce prototype : après changement de schéma, `make db-reset` (efface les données locales). Les fixtures doivent recréer les formulaires socles et un canevas par défaut pour chaque type de démonstration.
- Tests dans le conteneur : `docker compose exec -T app php bin/phpunit`. Une fonctionnalité n'est terminée qu'avec un test qui l'exerce comme un appelant (requête HTTP pour un contrôleur).
- Conventions Symfony du dépôt (`AGENTS.md`) : attributs, autowiring, contrôleurs minces, Form pour les formulaires serveur.
- `docs/diplomes.md` est mis à jour (étape 3, vocabulaire, « Où est quoi »).

## 8. Choix faits par l'assistant (à vérifier en lisant)

- Les fiches des éléments font partie du canevas, avec un formulaire par nature au niveau feuille. Cela revient sur la décision Q23 (« fiche EC identique partout ») au nom du principe « tout modulable ».
- Les écrans intégrés se placent et, pour BCC, se masquent, mais ne se remplacent pas. Structure, Positionnement & ramification et Ramification ne se masquent pas.
- Les entrées d'une fiche formation ou parcours se renomment, se masquent et se retirent ; les fiches d'élément n'ont ni masquage ni ajout d'entrée.
- L'étape « Formulaires » de l'assistant passe après « Structure ».
- Le canevas par défaut est porté par les formulaires et les écrans intégrés (portée, position par défaut), modifiable par l'admin.
- Libellé, aide, obligatoire, onglet et position d'un champ deviennent des réglages de chaque formulaire, socles compris.
- Une variante n'a pas de diplôme propriétaire ; « Adapter » crée toujours une nouvelle variante ; une variante s'enregistre dans la bibliothèque indépendamment du canevas.
- Un canevas qui laisse un champ verrouillé sans entrée affichée est refusé.
- Masquer un champ dans une variante d'élément désactive la capacité, avec ses effets actuels.
- L'export JSON brut n'est pas filtré.
- Un champ ajouté au formulaire de départ apparaît visible dans la variante, sans validation ; le bandeau n'est qu'informatif.
- Variantes de variantes permises.
- Champs `locked` impossibles à masquer ou rendre facultatifs dans une variante.
- Une entrée masquée ou un formulaire changé ne supprime jamais de données.
- L'aperçu utilise une formation réelle de la version si elle existe, sinon une formation fictive en mémoire.
- La bibliothèque reste gérée sur `/node-types` ; modifier un formulaire partagé exige la confirmation d'impact.
- Ordre de réalisation proposé : (1) canevas formation / parcours, bibliothèque et variantes, barres latérales génériques, formation sans parcours (§6.7), aperçu ; (2) fiches des éléments par niveau et par nature.
- Formation sans parcours : groupe « Parcours » dans la barre latérale, sans les écrans intégrés de la fiche parcours ; données rangées sur la formation et recopiées lors du passage avec ↔ sans parcours.

## 9. Laissé au constructeur

- La représentation en base des variantes (par exemple un lien `parent` et un champ JSON d'écarts sur `NodeType`, ou une entité « formulaire » distincte du type de nœud) et la façon d'empêcher qu'une variante apparaisse comme type d'ELP choisissable dans la chaîne ou les menus d'ajout.
- Le format JSON du canevas dans la configuration de version et ses objets de lecture (dans `src/Diplome/Spec/`, comme les autres parties de la configuration).
- Le mécanisme exact de l'aperçu (paramètre de requête, session, rendu en mémoire) tant qu'il réutilise les vraies pages.
- La forme de l'interaction de glisser-déposer (Stimulus, comme le reste du prototype).
- La clé technique d'une entrée ajoutée (générée depuis le libellé, unique dans la fiche).

## 10. Hors périmètre

- Droits par entrée (une entrée visible mais non modifiable par le responsable, etc.).
- Historique des modifications d'un formulaire de la bibliothèque.
- Variantes figées (copie indépendante) : non retenues.
