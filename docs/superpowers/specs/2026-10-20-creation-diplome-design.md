# Refonte de la création des diplômes — document de conception

Date : 20/10/2026. Dépôt : `oreof-maquette-proto` (prototype de maquette ORéOF, Symfony 8.1, Doctrine + SQLite, Twig + Stimulus).

Ce document couvre l'ensemble de la refonte et détaille le **chantier 1** (page Diplômes, assistant, structure). Les chantiers 2 à 5 sont cadrés mais feront chacun l'objet d'un document à part.

> **État (21/10/2026)** : le chantier 1 est implémenté et fusionné dans `master`. Le fonctionnement réel est décrit dans `docs/diplomes.md`. Les arbitrages pris pendant la réalisation, qui précisent ou corrigent ce document, sont rassemblés au **§10** ; en cas d'écart, le §10 fait foi.

## 1. Intention

Aujourd'hui, dans le prototype, un diplôme n'est qu'un libellé tiré d'un référentiel (`referentiel('diplomes')`), relié à un seul `StructureTemplate` (`StructureTemplate::diplome`, unique). Ce modèle est copié une fois dans la formation à sa création (`FormationController::create`, `TemplateApplier`). Le diplôme ne porte ni ses règles de structure, ni ses règles de MCCC, ni le format de ses blocs de compétences. Pour la Licence, il a fallu décrire à la main 3 années × 2 semestres, alors qu'ils sont structurellement identiques.

Dans ORéOF v2, ces différences sont codées en dur : quatre gestionnaires (`UniversityHandler`, `ButHandler`, `M2EHandler`, `DuHandler`/`DaeuHandler`) choisis par le libellé court du diplôme, et des tests `libelleCourt === 'BUT'` dans une quarantaine de fichiers.

**L'intention : un type de diplôme devient un modèle complet, entièrement défini par configuration dans l'interface.** N'importe quel type — ceux d'ORéOF v2 (Licence, LP, Master, MEEF, M2E, BUT, DEUST, DAEU, diplôme d'ingénieur, CPI, CMI, DNO, DFG, DFA, DE) comme ceux qui n'existent pas encore — doit pouvoir être créé sans écrire de code.

La finalité de l'application (hors de ce projet) est de produire les tableaux Excel de MCCC et d'alimenter l'API de diffusion (site URCA, puis Parcoursup, MonMaster… via les Excel de la gestion de l'offre). Le modèle doit donc contenir de quoi les produire, mais ce projet ne les construit pas.

## 2. Principe directeur : tout est modulable

Aucun comportement n'est propre à un diplôme nommé. Chaque spécificité observée devient un **réglage générique** que n'importe quel type peut activer. Exemples :

| Spécificité observée | Réglage générique |
|---|---|
| BUT : ressources et SAÉ | le type déclare ses **natures d'élément** (EC, ressource, SAÉ, stage…) |
| BUT : une ressource compte dans plusieurs UE avec un coefficient | option **rattachement multiple** : un élément peut compter dans plusieurs UE, avec un coefficient dans chacune ; les ECTS sont portés par l'UE |
| BUT : ratio ressources / SAÉ ; Licence : règles sur les types d'UE | **règles** entre natures ou attributs, sur le moteur `RuleEvaluator` |
| BUT : stage obligatoire | **éléments imposés** dans la structure |
| M2E : organisé par année et par blocs, sans semestres | la **chaîne de niveaux** est libre (on peut omettre le semestre) |
| BUT : Compétence › Niveau › AC ; M2E : Bloc › Objectif › Attendu | BCC à **N niveaux nommés** |
| LP en 1 ou 2 ans, Licence « oui-si » en 4 ans | **durée variable** dans des bornes, choisie par la formation |

Pas d'étape, d'écran ni de classe « BUT » ou « Licence ».

## 3. Décisions déjà prises

Validées par l'utilisateur et par un expert métier URCA (réponses du 20/10/2026) :

- **Droits.** Seuls SES/admin créent et modifient un type de diplôme, et initialisent une formation d'un type.
- **Versions.** Un type ne se modifie que pour corriger une erreur de saisie. Un changement de règle s'applique à une année universitaire donnée, pour garder l'historique. → une **version du type par année universitaire**.
- **Règlement des études.** Il se traduit au niveau du type. Les spécificités locales des composantes (règlements intérieurs) ne sont pas intégrées.
- **Variantes.** LP 1 an / 2 ans, Licence oui-si, Licence Accès Santé = même type ; seul le nombre d'années change.
- **MEEF** est conservé pour l'historique, il disparaît au profit du M2E.
- **Pas de diplômes intermédiaires** (DEUG, DUT, maîtrise) dans la maquette.
- **Structure.** Bornes par niveau (minimum, maximum, exactement), plus des éléments imposés précis (ex. stage), plus des règles (ex. ratio). Une UE ou un EC à choix compte pour 1 dans les bornes, quel que soit le nombre de choix ; des règles peuvent porter sur ses enfants (ex. tous les enfants ont les mêmes ECTS).
- **Hors bornes.** Bloqué par défaut ; une demande de dérogation est possible, toujours validée par SES/admin, et historisée.
- **MCCC.** L, LP, M ont les quatre types CT, CC + CT, CC, CCI, avec des règles différentes (Licence stricte : CCI à exactement 3 épreuves, CT à 50 %… ; LP et M plus souples). Seconde chance a priori commune. Modalités BUT (ressources / SAÉ) figées à l'échelle de l'URCA. Stage, projet, mémoire = élément classique avec MCCC. Quitus, contrôle d'assiduité, « sans note » = options activables partout.
- **BCC.** Niveaux confirmés : L / LP / M = Bloc › Compétence ; BUT = Compétence › Niveau › Apprentissage critique ; M2E = Bloc › Objectif › Attendu. Le contenu est saisi dans chaque formation (pré-remplissage possible). Les éléments sont reliés au **dernier niveau** (L, LP, BUT ; M2E à vérifier).
- **Formulaires.** La fiche EC est identique partout. Les fiches formation / parcours peuvent être moins détaillées hors accréditation. Le type **sélectionne** des formulaires définis ailleurs ; il ne les définit pas.
- **Page Diplômes** séparée des autres réglages d'administration ; elle remplace la page Templates actuelle (`/administration/templates`).
- **ORéBUT** a servi d'exemple de structuration ; pas d'import à prévoir dans ce projet.

## 4. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Une page Diplômes : liste, création, modification, duplication, archivage, suppression protégée.
- Un assistant de création d'un type, avec possibilité de partir d'un type existant.
- Une structure décrite par une chaîne de niveaux et des bornes, plus des éléments imposés et des règles, au lieu d'un arbre décrit année par année.
- Une version du type par année universitaire.
- Des dérogations historisées quand une formation sort des bornes.
- Recréer, par configuration seulement, au moins Licence, LP, Master et M2E ; pour le BUT, tout sauf le rattachement multiple (chantier 5).
- Un rendu de formulaire générique, sans chaîne de conditions sur le type de champ (§6.6).

**Non-objectifs (ce projet)**
- Exports Excel, PDF, API, LHEO, Apogée.
- Règles de validation du diplôme (compensation, seuils de note) : à reconsidérer plus tard.
- Spécificités des composantes.
- Import ORéBUT.
- Workflow de validation des formations (CFVU…), droits fins par composante.

**Anti-objectifs (échec même si « ça marche »)**
- Un `if` ou un écran conditionné au nom d'un diplôme.
- Devoir décrire trois fois une année identique.
- Une modification de règle qui change silencieusement des formations d'une année déjà validée.
- Un assistant qui oblige à tout ressaisir quand le nouveau type ressemble à un existant.

## 5. Le cheminement

1. **Page Diplômes.** L'admin voit la liste des types (nom, sigle, type de référence éventuel, durée, format BCC, nombre de formations, version courante). Actions : modifier, dupliquer, archiver, supprimer.
2. **Nouveau diplôme → assistant** en 7 étapes : Informations ; Partir d'un diplôme existant (facultatif) ; Formulaires ; Structure ; MCCC ; Blocs de compétences ; Récapitulatif.
3. **Modifier un diplôme** rouvre les mêmes étapes, sous forme d'onglets, sur une version donnée.
4. **Créer une formation** (chantier 4, admin) : choisir le type et l'année ; le type pilote les étapes et les bornes ; la formation construit ensuite sa maquette dans ces bornes.

## 6. Chantier 1 détaillé

### 6.1 Page Diplômes

- Nouvelle section de navigation « Diplômes », hors du menu Administration (qui garde référentiels, types d'ELP, règles, champs…). La page Templates actuelle disparaît ; son contenu est repris et remanié dans le type de diplôme.
- Accès réservé à l'admin, contrôlé côté serveur. L'audit (`AUDIT.md`, §3) a relevé des routes protégées seulement dans les gabarits ; les nouvelles routes ne doivent pas reproduire ce défaut.
- Garde-fous :
  - **Supprimer** n'est possible que si aucune formation n'utilise le type, toutes versions confondues. Sinon, seul **archiver** est proposé (le type n'est plus proposé à la création, il reste consultable).
  - **Modifier** une version utilisée par des formations affiche d'abord les formations concernées. La modification est présentée comme une correction d'erreur de saisie. Pour un changement de règle, l'interface invite à créer la version de l'année suivante.
  - **Dupliquer** crée un nouveau type à partir de la version choisie.
  - Toute suppression ou archivage demande une confirmation intégrée à la page.

### 6.2 Versions par année universitaire

- Un type de diplôme a une identité stable (nom, sigle) et une ou plusieurs **versions**, chacune rattachée à une année universitaire (ex. « 2026-2027 ») et portant tout le paramétrage (structure, natures, MCCC, BCC, formulaires, options).
- Créer la version d'une nouvelle année part d'une copie de la version précédente.
- Une formation est rattachée à **une version** du type (celle de son année) et ne bascule jamais seule vers une autre.
- Les versions passées restent consultables.

### 6.3 Assistant, étape par étape

**Étape 1 — Informations.** Nom, sigle, code Apogée (1 caractère, comme v2), description, accrédité ou non (« classique »), passage en CFVU, année universitaire de la première version.

**Étape 2 — Partir d'un diplôme existant (facultatif).** Choix d'un type et d'une version ; tout son paramétrage est copié. Les étapes suivantes s'ouvrent pré-remplies ; on ne modifie que les différences. Le récapitulatif signale ce qui diffère du type de départ.

**Étape 3 — Formulaires.** Un formulaire, dans le prototype, est un **type d'ELP** (`NodeType`) : ses capacités activent des champs (`FieldDef`) rangés par onglet, et il s'édite dans `/node-types/{id}/edit`. Les formulaires de la formation et du parcours sont les types de famille « paramètre » (`param_organisation_formation`, `param_presentation_parcours`, `param_bcc_parcours`…) ; ceux de la maquette sont les types de structure (UE, EC, bloc de choix…). L'étape 3 choisit, pour chaque emplacement (formation, parcours, et chaque niveau de la chaîne), le type d'ELP utilisé. On peut ainsi donner à un diplôme hors accréditation une fiche formation allégée. La fiche EC reste commune à tous.

Ce choix suppose que le rendu des formulaires soit vraiment générique. Ce n'est pas encore le cas, voir 6.6.

**Étape 4 — Structure.** Voir 6.4.

**Étape 5 — MCCC.** Pour chaque nature d'élément, la liste des types de MCCC autorisés et le profil de règles appliqué (s'appuie sur `MccType` et ses profils). Options activables : quitus, contrôle d'assiduité, « sans note ». Détail des règles : chantier 2.

**Étape 6 — Blocs de compétences.** Nombre de niveaux (1 à 3, voire plus) et nom de chaque niveau ; niveau auquel les éléments sont reliés (par défaut le dernier). Saisie du contenu dans les formations. Détail : chantier 3.

**Étape 7 — Récapitulatif.** Vue d'ensemble lisible, avec un aperçu d'une maquette minimale générée à partir des bornes, puis enregistrement.

### 6.4 Structure : chaîne de niveaux, bornes, éléments imposés, règles

**Chaîne de niveaux.** Liste ordonnée de types de nœud (`NodeType`), ex. `Parcours › Année › Semestre › UE › EC` pour la Licence, `Parcours › Année › Bloc › EC` pour le M2E (sans semestre). Le niveau Parcours est optionnel selon que le type autorise ou non plusieurs parcours.

**Bornes par niveau**, appliquées **par parent** (« 3 à 6 UE » = dans chaque semestre) :
- nombre : minimum, maximum, ou exactement ;
- ECTS : par nœud (ex. au plus 10 par UE), et somme exacte attendue au niveau parent (ex. 30 par semestre) ;
- pour le niveau temporel de plus haut rang (Année) : une **durée** bornée, ex. Licence « 3 ou 4 ans », LP « 1 ou 2 ans » ; la formation choisit sa durée.
- point de départ : la numérotation peut commencer ailleurs qu'à 1 (une LP commence au S5 si elle est rattachée au cycle Licence). *À confirmer avec l'utilisateur : voir §9.*

**Blocs à choix.** Un bloc « 1 parmi N » compte pour 1 dans les bornes de son niveau. Règles possibles sur ses enfants (mêmes ECTS, nombre de choix).

**Éléments imposés.** Un nœud précis imposé à une position donnée, ex. « UE Stage » dans le dernier semestre, avec ses verrous (non supprimable, non dupliquable — mécanique `locked` déjà présente dans `TemplateApplier`). Un élément imposé peut aussi surcharger une borne pour une position précise (ex. S6 : 2 à 4 UE). *Précisé par A6 et A18 (§10) : la position sert à placer l'élément ; le contrôle vérifie sa présence dans la portée, ou sous chaque hôte en position `all`.*

**Natures d'élément.** Le type déclare les natures disponibles au niveau feuille (EC classique, ressource, SAÉ, stage, projet, mémoire…), chacune pouvant avoir son jeu de MCCC (étape 5).

**Rattachement multiple.** Option du type : un élément peut compter dans plusieurs UE, avec un coefficient dans chacune ; les ECTS sont alors portés par l'UE et non par l'élément. **Hors du chantier 1** : il fait passer la maquette d'un arbre à un graphe (éditeur, calcul des ECTS, complétude) et fera l'objet de son propre chantier (voir §7). Le chantier 1 ne l'enregistre pas encore.

**Règles, écrites par l'admin.** Contraintes supplémentaires : ratio ressources / SAÉ, règle sur les types d'UE, enfants d'un bloc à choix aux mêmes ECTS… L'admin ne saisit ni formule ni JSON. Il choisit un **gabarit de règle** dans un catalogue et le paramètre par un petit formulaire ; le gabarit produit l'AST JSON évalué par `RuleEvaluator` (jamais d'`eval`). C'est le principe déjà utilisé par les « clauses » des profils MCCC (`mcctype_profile_clauses`) et par les deux formes de règles d'onglet (`ValidationRule`), généralisé. Gabarits de départ :
- nombre d'enfants d'un niveau (min / max / exactement) — c'est aussi ainsi que s'expriment les bornes ;
- somme d'un attribut (ECTS, heures) au niveau parent (min / max / exactement) ;
- valeur d'un attribut par nœud (min / max) ;
- ratio entre deux natures ou deux valeurs d'attribut, sur une somme d'attribut (ex. ECTS des SAÉ ≥ 40 % du semestre) ;
- tous les enfants d'un bloc ont la même valeur d'un attribut ;
- présence obligatoire d'un nœud d'une nature ou d'un type donné à une position (élément imposé).

Comme les types de champ (`FieldTypeInterface`), chaque gabarit est une classe taguée découverte par un registre : ajouter un gabarit = écrire une classe, sans toucher au reste. Chaque règle porte une portée (nœud, parent, niveau temporel, parcours) et une gravité (erreur bloquante, avertissement). Le moteur gagne ce qu'il faut pour ces gabarits (portées, agrégats filtrés par nature) ; les opérateurs ET / OU viennent si un gabarit en a besoin.

**Hors bornes → dérogation.** Une action qui ferait sortir la maquette des bornes ou violerait un élément imposé est bloquée. Le responsable peut déposer une demande de dérogation (s'appuie sur `DerogationRequest`), validée ou refusée par SES/admin. Chaque demande et sa décision restent dans l'historique de la formation. Une dérogation accordée autorise l'écart précis demandé, pas les suivants.

### 6.5 Ce qui existe déjà et sera réutilisé

- `NodeType` (types de nœud, capacités, numérotation, blocs de choix), `FieldDef` et le constructeur de formulaires, `MccType` et ses profils, `RuleEvaluator`, `DerogationRequest`, `TemplateApplier` (verrous), `Completion` et les pastilles de statut.
- `StructureTemplate` est absorbé par le type de diplôme : sa chaîne (`structure`), ses surcharges de capacités (`fieldOverrides`), ses profils MCCC (`mcccProfiles`), son format BCC et ses unités de temps deviennent des réglages de la version du type ; son arbre figé est remplacé par bornes + éléments imposés.
- Le référentiel `diplomes` cesse d'être la source de la liste des diplômes.

### 6.6 Préalable : un rendu de formulaire réellement générique

Relu le 20/10/2026. Côté PHP, les types de champ sont bien conçus : une classe par type (`src/FieldType/`), découverte par `FieldTypeRegistry`, qui décide de ses réglages, de ses bornes et de sa validation. Le rendu, lui, ne suit pas ce principe :
- `templates/node/panel.html.twig` (l.320-460 environ) choisit l'affichage par une chaîne `if def.field == 'number' / elseif 'textarea' / elseif 'choice' / 'checkbox' / 'radio' / 'competencies' / 'hours' / 'mccc'` ;
- le même gabarit teste des champs et des types précis : `key == 'ueType'`, `key == 'ecType'`, `key == 'nature'`, `key == 'ficheMatiere'`, `key == 'ects' and node.type.key in ['ue', 'ec']`, `node.type.key == 'ec'`.

Avant de laisser un diplôme choisir ses formulaires, il faut :
- que chaque type de champ fournisse son propre gabarit de rendu (par exemple une méthode qui renvoie le chemin d'un gabarit Twig dédié), et que le panneau se contente de l'inclure ;
- que les comportements liés à un champ ou à un type nommé deviennent des réglages du champ ou du type (texte d'invite, contrôle d'ECTS, aide au choix de la nature…).

Ajouter un type de champ ne doit plus demander de modifier le panneau, et aucun gabarit ne doit tester une clé de champ ou de type précise.

## 7. Chantiers suivants (cadrage seulement)

- **Chantier 2 — MCCC selon le type et la nature.** Règles par profil (Licence stricte, LP / Master souples), seconde chance commune, modalités ressources / SAÉ en % × nombre d'épreuves, options quitus / assiduité / sans note. S'appuie sur la note « Opérationnalisation seconde chance en licence » et le règlement des études.
- **Chantier 3 — BCC à N niveaux.** Format défini par le type, contenu saisi par la formation, lien des éléments au niveau choisi. Remplace `bccFormat` (`blocs` / `but`) et `bccReferentiel`.
- **Chantier 4 — Création de formation guidée par le type**, réservée à l'admin : choix du type et de l'année, durée dans les bornes, avec ou sans parcours, maquette initiale générée depuis les bornes et les éléments imposés.
- **Chantier 5 — Rattachement multiple.** Un élément compte dans plusieurs UE avec un coefficient dans chacune, ECTS portés par l'UE. La maquette passe d'un arbre à un graphe : éditeur, calcul des ECTS, complétude, numérotation. C'est ce qui permet de recréer complètement le BUT. L'ordre entre les chantiers 2 à 5 reste à fixer ; l'utilisateur accepte d'avancer étape par étape.

## 8. Contraintes

- Conventions du dépôt (`AGENTS.md`) : attributs PHP, autowiring, contrôleurs fins, Form et Validator de Symfony, pas de migrations Doctrine dans le prototype (`schema:create`, voir A19), tests `WebTestCase` / `KernelTestCase` (base SQLite dédiée `var/test.db`, déjà en place).
- Protection CSRF et contrôle de rôle côté serveur sur toutes les nouvelles routes (appliqué, voir A17).
- Le prototype reste jetable mais doit rester transposable dans ORéOF v2 : vocabulaire métier, couture avec le DTO `StructureParcours` de v2 (finalité exports), cf. `AUDIT.md` §5.
- Les données de démonstration (fixtures) doivent recréer au moins Licence, LP, Master, M2E et BUT par configuration, pour prouver le principe directeur.

## 9. Laissé au constructeur, et points ouverts

**Laissé au constructeur** : modèle de persistance exact (entités, colonnes JSON ou tables), découpage des services, forme de l'éditeur de bornes, mise en page de l'assistant, migration des formations et templates de démonstration existants.

**Choix faits par le rédacteur** (à vérifier en relisant, modifiables) :
- **Périmètre du contrôle des bornes.** Le chantier 1 inclut la vérification des bornes et des éléments imposés dans l'éditeur de maquette (ajout, suppression, déplacement, ECTS, grille rapide) et dans la page « Vérifier ». Sans cela, les bornes ne serviraient à rien.
- **Action bloquée en attente de dérogation.** L'action est refusée ; le responsable dépose une demande qui décrit l'écart de façon structurée (règle ou borne concernée, nœud, valeur demandée). Une fois la demande accordée, il refait l'action, qui passe parce qu'elle correspond à l'écart accordé. Aucune modification n'est mise en attente.
- **Lien formation ↔ version.** La formation est reliée à sa version, sans copie : bornes, règles et options sont lues en direct depuis la version. Les éléments imposés, eux, existent dans l'arbre de la formation. Une correction d'erreur sur une version utilisée s'applique donc aussitôt aux bornes et aux règles des formations concernées, après l'écran d'impact. Les éléments imposés ajoutés ou modifiés sont propagés sur confirmation, avec la mécanique de propagation existante.
- **Année d'une formation.** Une formation utilise la version de son année universitaire ou, à défaut, la version la plus récente antérieure. Le passage d'une formation à l'année suivante (reconduction) est hors de ce projet.
- **Début de numérotation.** Le niveau temporel peut commencer ailleurs qu'à 1 (une LP numérote S5 et S6), comme `semestreDebut` / `semestreFin` dans ORéOF v2. Le point de départ fait partie des bornes de durée.
- **BCC du M2E.** Le niveau de rattachement est réglable (étape 6) ; les données de démonstration du M2E utilisent le dernier niveau, en attendant la vérification métier.

**Tranchés par l'utilisateur (20/10/2026)** :
- Rattachement multiple : au choix du rédacteur, en avançant étape par étape → chantier 5 séparé.
- Formulaires : ce sont les types d'ELP (ex. `/node-types/18/edit`) ; vérifier que leur rendu est générique → §6.3 étape 3 et §6.6.
- Règles : écrites par l'admin → catalogue de gabarits paramétrables (§6.4).

**Points encore ouverts** : aucun bloquant pour planifier le chantier 1. À vérifier côté métier : niveau de rattachement aux BCC pour le M2E.

## 10. Arbitrages pris pendant la réalisation

Décisions prises pendant l'implémentation du chantier 1 (20-21/10/2026), sur les points que ce document laissait ouverts ou formulait mal. Elles font foi en cas d'écart avec les sections précédentes.

**Modèle**
- **A5 — Nature d'élément.** La nature d'un élément (`ec`, `stage`, `ressource`, `sae`…) est un attribut de nœud distinct, `natureElement`. Elle ne remplace pas l'attribut existant `nature` (obligatoire / à choix). Ses options viennent des natures déclarées par la version (`FieldDef::optionsSource = version_natures`).
- **A6 — Parent d'un élément imposé.** Le niveau parent d'un élément imposé est toujours le niveau juste au-dessus de son type dans la chaîne ; il est déduit, pas saisi. Le stage du BUT est donc imposé sous la dernière UE, pas sous le semestre.
- **A7 — Numérotation.** Le numéro de départ d'un niveau (LP : semestres 5 et 6) change l'indice, pas le format d'affichage (pas de préfixe « S »).
- **A12 — Natures déclarées.** Les natures d'un élément imposé et des paramètres de règle de type « nature » doivent faire partie des natures déclarées par le type. Le BUT déclare `stage`.
- **A15 — Durée et ramification.** La borne de durée se mesure par cursus : pour chaque parcours feuille, ses périodes plus celles de ses parcours parents (portail compris). Un parcours qui a des sous-parcours n'est pas mesuré seul. Sans ramification, rien ne change.
- **A16 — Référentiel national.** Le niveau national d'une année se retrouve par son rang, jamais par un libellé du type « BUT1 ».
- **A18 — Présence d'un élément imposé.** En position `first`, `last` ou numéro, l'élément est présent s'il existe un nœud `_imposed = uid` dans la portée (parcours, ou cursus en ramification) : la position ne sert qu'à le placer. Agrandir la maquette n'est donc pas bloqué. En position `all`, l'élément est attendu sous chaque hôte (violation portée par l'hôte) ; l'éditeur le place d'office sous un nouvel hôte et la propagation complète chaque hôte une seule fois.

**Règles et contrôle**
- **A8 — Ratio.** Un ratio « ≥ / > » est une exigence de minimum (non bloquante) ; « ≤ / < » une exigence de maximum (bloquante) ; une portée sans feuille est conforme.
- **A9 — Paramètres internes.** Un paramètre de gabarit marqué interne (ex. l'identifiant de l'élément imposé de `required_element`) n'est jamais montré à l'admin et est conservé tel quel à l'enregistrement.
- **A10 — Gabarit inconnu.** Une règle dont le gabarit n'existe plus est signalée (« Règle inconnue »), sans bloquer ni lever d'erreur.
- **Contrôle à l'enregistrement.** Seule une violation bloquante nouvelle, ou aggravée, refuse une modification. Une maquette déjà hors bornes n'empêche pas de modifier autre chose. Annuler, rétablir et restaurer une révision ne sont jamais bloqués. Une action refusée doit être refaite une fois la dérogation accordée ; rien n'est mis en attente.
- **Dérogations.** Une dérogation porte une clé de règle, un nœud et une valeur ; elle couvre jusqu'à cette valeur (`:max`), à partir de cette valeur (`:min`), ou sans condition (`imposed:`). Une valeur constatée inconnue n'est jamais couverte.

**Assistant et édition**
- **A11 — Étapes en services.** Chaque étape de l'assistant est une classe (`WizardStepInterface`) découverte automatiquement ; les mêmes classes servent d'onglets à l'édition d'une version.
- **A13 — Onglet Informations en édition.** Il modifie le type lui-même (libellé, description, code Apogée, accrédité, passage en CFVU). Le sigle est figé dès qu'une formation utilise le type ; l'année d'une version ne se modifie pas.
- **Navigation de l'assistant.** « Précédent » et la barre latérale enregistrent l'étape courante avant de changer d'étape. Revenir à l'assistant reprend le brouillon, sauf demande explicite de recommencer.
- **Formulaires de paramètres.** Seul le formulaire de l'emplacement est choisi par le type ; les formulaires des niveaux de structure sont les types d'ELP de la chaîne eux-mêmes.

**Sécurité, données, transition**
- **A1 à A4 — Transition.** Les types de démonstration ont repris les anciens libellés de diplôme le temps de la transition ; les anciens templates de structure (`StructureTemplate`, `TemplateApplier`…) ont ensuite été supprimés.
- **A14 / A19 — Base de données.** Pas de migrations dans le prototype. Après un changement de schéma, `make db-reset` est nécessaire et efface les données locales ; il n'est jamais lancé sans l'accord de l'utilisateur.
- **A17 — CSRF.** Les formulaires de l'assistant et toutes les actions `diplome_*` portent un jeton CSRF (sans état, déjà configuré).
- **Création de formation.** Réservée à l'admin côté serveur ; validée entièrement avant écriture et faite dans une transaction.

**Valeurs de démonstration à confirmer côté métier** : ratio « SAÉ ≥ 40 % des ECTS par semestre » du BUT ; 2 semestres de 30 ECTS par année du BUT ; niveau de rattachement aux BCC du M2E (dernier niveau, en attendant).

