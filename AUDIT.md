# Audit : prototype de maquette vs ORéOF v2

Date : 06/10/2026. Périmètre : `oreof-maquette-proto` (ce dépôt, commit `a2465b0`) comparé à
`oreof-stack/oreofv2` (branche `fix/select-dropdown-ouverture-haut`, release 2.1.4).
Méthode : lecture des documentations des deux projets, inventaires fonctionnels par agents, vérification manuelle
des constats importants (marqués « vérifié »).

## Verdict

- **Le prototype est une bonne preuve de concept pour le cœur de la maquette.** Arbre typé, métamodèle
  administrable, templates par diplôme, règles MCCC en données, numérotation, ramification visuelle : c'est déjà ce
  que décrit `docs/architecture/maquette-modulaire.md` d'ORéOF v2, qui reste à l'état de « proposition, non
  implémentée ».
- **Il couvre environ 30 % du périmètre fonctionnel de v2.** Il manque presque tout le cycle de vie (campagnes,
  workflows, droits, versioning CFVU, publication) et une bonne part de la richesse métier de la maquette (fiche
  matière, MCCC réelles, BUT, mutualisation réelle, héritage d'attributs).
- **L'intégration dans ORéOF est possible, mais pas en remplaçant le code d'un coup.** Il faut une intégration
  incrémentale par adaptateurs (section 5).

---

## 1. Ce que le prototype apporte de plus que v2 (à garder)

| Apport du prototype | Équivalent v2 |
|---|---|
| Hiérarchie libre (`Formation::structure`), unité de temps configurable | figée : année = `ceil(semestre/2)`, ordres 1 à 6 codés en dur (`StructureSemestre.php:74`, `SemestreParcours.php:139`) |
| Métamodèle administrable : `NodeType`, capacités, `FieldDef`, constructeur de formulaire | absent ; tests `libelleCourt === 'BUT'/'M2E'` dispersés (`Ue`, `GetUeEcts`, `ValideStructure`, `ParcoursValide`…) |
| `StructureTemplate` par diplôme, avec verrous et dérogations | `TypeDiplome` aux contraintes partielles (`nbEcParUe` et `mcccObligatoireSurEc` jamais appliqués) |
| Règles MCCC en données (AST JSON + profils par diplôme) | règles codées en dur, dupliquées entre `UniversityHandler.php:585` et `M2EHandler.php:552` |
| Blocs de choix imbricables à l'infini | 2 niveaux maximum (`ueParent`, `ecParent`) |
| Ramification de parcours visuelle, validée côté serveur | entité `ParcoursRamification` présente mais jamais consommée par la maquette |
| Numérotation configurable par type (décimal, alpha, romain, globale) | codée dans `Ue::display()` et `ElementConstitutif::genereCode()` |
| Historique automatique (50 révisions), annuler / rétablir, restauration | versioning manuel ou déclenché par le workflow |
| Phase « consolidation de l'offre » | inexistante en tant que telle |

---

## 2. Ce qui manque au prototype

### P0 : bloquant pour toute intégration

**Maquette**

- **Fiche matière comme entité.** Dans v2 elle est partagée entre parcours, a un responsable, son propre workflow
  (`fiche`) et peut imposer heures, ECTS et MCCC à l'EC (`volumesHorairesImpose`, `ectsImpose`, `mcccImpose`).
  Dans le prototype, ce n'est qu'une valeur de référentiel.
- **Héritage d'attributs.** Non implémenté dans le prototype. v2 combine 4 sources (fiche imposée, EC parent, EC
  spécifique, fiche) avec une priorité différente selon l'attribut (`src/Classes/GetElementConstitutif.php:53-150`).
  Il faut en faire une politique configurable par profil de diplôme.
- **MCCC réelles.**
  - Sessions 1, 2 et seconde chance (session 3).
  - CC / CT / CCI / CC_CT avec type d'épreuve, durée obligatoire si `TypeEpreuve.hasDuree`, justification si
    `hasJustification`.
  - Quitus avec argumentaire, contrôle d'assiduité, « sans note ».
  - CC Licence avec option TP (`cc_has_tp`).
  - Dans le prototype, `secondChance` est stocké mais jamais lu, et les résultats des règles ne sont pas bloquants.
- **Mutualisation réelle.** Dans v2 : un porteur publie un semestre, une UE ou une fiche ; les parcours cibles se
  « raccrochent » sans copie (3 tables d'offre `SemestreMutualisable`, `UeMutualisable`, `FicheMatiereMutualisable`).
  Le prototype copie le sous-arbre (`NodeController.php:360`) et ne garde que `mutualizedFrom = "fid:nid"`.
- **Identité stable des nœuds et intégrité référentielle.** Les compétences sont liées par libellé
  (`panel.html.twig:287`) : renommer une compétence casse le lien. Types, référentiels et `mutualizedFrom` sont liés
  par clé texte, sans contrôle. Aucune requête n'est possible au niveau du nœud sans hydrater toutes les formations.

**Cycle de vie**

- **Campagne / année universitaire.** Toute maquette v2 est rattachée à une `CampagneCollecte` (via `DpeParcours`),
  avec des dates d'ouverture par module (`TimelineDate`, `CampagneAccessibilityService`). Le prototype n'a qu'un
  texte libre `anneeUniversitaire`.
- **Reconduction N → N+1.** v2 clone formations, parcours, structure, MCCC, BCC et droits, puis place les parcours en
  `tacite_reconduction` (`CampagneDuplicationService.php:142`). Le prototype n'a rien d'équivalent.

**Transverse**

- **Authentification et droits.** v2 : CAS / LDAP, `Profil` × `ProfilDroits` × centre (établissement, composante,
  formation, parcours) × campagne ; voters dont les droits d'écriture dépendent de l'état du workflow
  (`RessourceVoter::checkParcours`, l.224). Le prototype n'a qu'un rôle simulé en session, `admin` par défaut.

### P1 : nécessaire pour la mise en production

- **Validation de structure.** ECTS par semestre et par année selon le diplôme, nombre d'UE, EC sans fiche, type
  d'EC, heures, BCC ; règles de parcours (stage, projet, mémoire, situation pro, modalités d'enseignement).
  Branchement sur les bloqueurs de workflow (`DpeStepValidationBlockerProvider`). La page « Vérifier » du prototype
  n'inclut même pas les règles MCCC.
- **BUT.** Référentiel compétences / niveaux / apprentissages critiques importé par API
  (`src/TypeDiplome/Synchronisation/But.php`), une UE par compétence, typage SAE / ressource, ECTS utilisés comme
  coefficients, types d'épreuve fixes.
- **Workflows.** `dpeParcours` (RP → RF → DPE → conseil → SES → CFVU → publication, avec réserves, réouvertures et
  circuit sans CFVU), `dpeFormation` (ouverture et capacités par composante), `fiche`, `changeRf`. La barre
  « process » de l'éditeur du prototype est factice (`editor.html.twig:34-59`).
- **Consolidation réelle de l'offre.** Ouvert / non-ouverture / fermeture définitive par parcours, années ouvertes,
  capacités, plateformes d'admission, comparaison N-1 des capacités (`src/Controller/Offre/*`). La `phase` du
  prototype ne restreint rien et n'est pas tracée dans l'historique.
- **Publication.**
  - Version figée à la CFVU (`versioning_json/`, `ParcoursVersioning.cfvuFlag`).
  - JSON pour le site de l'URCA (`/api/site/web`, API V2 `PublicationController`).
  - LHEO 2.3 validé contre `lheo.xsd`.
  - MCCC en xlsx et PDF (Gotenberg), PDF des fiches, plaquettes.
  - Envoi SOAP des ELP vers Apogée (`app:export-elp-apogee`).
  - Annexes Parcoursup / MonMaster / eCandidat / EEF, export CARIF.
  - Le prototype n'a qu'un export JSON.
- **Codification Apogée.** Codes stables par nœud : diplôme, étape, VET, semestre, UE, EC
  (`src/Classes/Codification/CodificationFormation.php`).
- **Attributs de parcours.** Régime d'inscription, alternance, `typeParcours` (LAS, CPI, portail santé…), parcours
  par défaut, durée, stage / projet / mémoire.

### P2 : confort et pilotage

- Historique avec auteur et diff visuel ; comparateur N / N-1 (roadmap v2 n°5).
- Commentaires par zone.
- Notifications mail et in-app, matrice des destinataires.
- Dérogations avec un outil admin pour lever un verrou (aujourd'hui, seule solution : réappliquer le template ou
  vider la structure).
- Propagation d'une modification de template aux formations existantes (aujourd'hui copie figée par
  `TemplateApplier`).
- Diplômes non classiques (maquette en PDF), DAEU, DU, coefficients M2E.
- Aides contextuelles, navigation par `MenuItem`, recherche.

---

## 3. Bugs et dette du prototype

**Bugs (vérifiés)**

- **Libellé d'ELP temporel effacé.** Le panneau n'envoie pas de champ `label` pour un type temporel
  (`templates/node/panel.html.twig:113`), mais `NodeController.php:66` réécrit le libellé avec une chaîne vide. Or
  `Completion.php:38` exige un libellé. Une Année ou un Semestre enregistré depuis l'interface reste donc incomplet
  pour toujours.
- **Routes d'administration MCCC accessibles au responsable.** Le préfixe `mcctype_` est absent de
  `AdminAccessSubscriber.php:21`. Les routes `formation_settings`, `formation_structure_save`,
  `formation_apply_template` et `formation_reset` ne vérifient pas non plus le rôle côté serveur (masquage seulement
  dans les gabarits).

**Bugs probables (lus dans le code, non testés)**

- `removeNode` ignore silencieusement les nœuds verrouillés (`MaquetteDoc.php:186`) : après une bascule vers « sans
  parcours » ou une remise à zéro, des parcours verrouillés peuvent rester orphelins.
- « Enregistrer comme template » ne copie ni le diplôme ni la chaîne structurelle (`TemplateController.php:289`).

**Dette**

- Aucune protection CSRF (`isCsrfTokenValid` : 0 occurrence), aucun Symfony Form, aucun Validator.
- 0 test (`tests/` ne contient que `bootstrap.php`), 0 migration (base créée par `schema:create`).
- Contrôleurs épais, contrairement aux conventions d'`AGENTS.md`.
- Performance : `findMutualized` et `countNodesOfType` hydratent toutes les formations (`Maquette.php:244-274`) ;
  `McccValidator` fait 2 requêtes par nœud affiché.
- Tailwind chargé par CDN à l'exécution ; `symfony/expression-language`, mailer, notifier et messenger installés
  mais inutilisés.
- README obsolète : il parle encore d'une entité `Node` et de `allowedChildKeys`, et présente comme « à faire » la
  ramification et l'export, qui existent.

---

## 4. Défauts d'ORéOF v2 repérés au passage (vérifiés)

- `can_edit_module('dpe')` (`templates/components/ParcoursHeader.html.twig:168,221`) : `'dpe'` n'existe pas dans
  `CampagneModuleEnum`, donc la fonction renvoie toujours `false` pour un non-admin.
- Transition `reouvrir_tacite` déclarée sans `to` (`config/packages/workflow.yaml:479`).
- `M2EHandler::getValidator()` lève une `LogicException` (l.694) : l'écran semestre V2 plante pour M2E.
- `ValideParcoursLicence` impose 30 ECTS en dur (l.92) et ignore `TypeDiplome.nbEctsParSemestre`.
- Contrôleurs sans garde de rôle (authentification seule) : `ProcessValidationController` (forçage d'état),
  `HistoriqueController` (edit / delete), `TranslationController`, `ProcessReouvertureController`,
  `SyntheseModificationController`, `PvConseilController`.
- `/api/composante` est public (`API/ComposanteController.php:21`).
- `ExportConseil` produit un ZIP vide (TODO de migration vers Gotenberg, l.36-38).
- Aucune CI de tests ni de PHPStan (seulement release-please).
- 3 implémentations parallèles de la duplication de campagne (service admin + 2 commandes).
- Code mort : `StepHandlerRegistry`, `FormationDemande`, `ImpersonationAuditService`, python_worker non branché.

---

## 5. Transition du prototype vers ORéOF : faisable ?

**Oui, mais pas en remplaçant le code d'un coup.** Le couplage de v2 au modèle actuel est fort :

| Élément v2 | Fichiers `src/` qui l'utilisent |
|---|---|
| `Parcours` | 393 |
| `FicheMatiere` | 167 |
| `ElementConstitutif` | 103 |
| `Mccc` | 83 |
| `SemestreParcours` | 70 |
| DTO `StructureParcours` | 39 |

La couture naturelle est le DTO `StructureParcours`. Les exports MCCC, LHEO, Apogée et le JSON URCA en dépendent.
Si l'arbre du prototype sait produire ce DTO, toutes les sorties existantes continuent de fonctionner.

### Décision clé : la persistance

Le stockage « 3 JSON » du prototype (un blob par formation) n'est pas transposable tel quel. v2 a besoin de requêtes
transverses : mutualisation entre formations, fiches partagées, voters par EC ou fiche, codification, recherche, et
plus d'un millier de parcours versionnés.

**Recommandation : modèle hybride.**

- Une table `maquette_node` : liste d'adjacence typée, avec FK vers `parcours`, `fiche_matiere` et `campagne`, et
  une colonne `type_key`.
- Les attributs du nœud dans une colonne JSON.
- Les MCCC et les compétences restent relationnelles.

### Étapes proposées

L'ordre compte : chaque étape s'appuie sur la précédente.

0. **Stabiliser le prototype.**
   - Corriger les bugs de la section 3 ; ajouter des tests sur `Maquette`, `Completion`, `RuleEvaluator` et
     `TemplateApplier`.
   - Passer à des identifiants stables (compétences par id).
   - Ajouter la fiche matière comme entité et une politique d'héritage d'attributs.
   - Établir l'inventaire des règles par diplôme (Licence, BUT, Master/M2E, DAEU, DU, non classique), que
     `maquette-modulaire.md` juge indispensable.
1. **Construire le read model dans v2** (étape 1 de `maquette-modulaire.md`).
   - Un builder `MaquetteTree` à partir des entités existantes, sans migration de données.
   - Y porter `Numbering`, `Completion`, `MaquetteBuilder` et `ParcoursGraph` du prototype.
   - Afficher la consultation du prototype en lecture seule sur les vraies données : c'est le test grandeur nature
     du modèle.
2. **Porter le métamodèle dans v2.**
   - Créer `NodeType`, `FieldDef`, `MccType` (avec profils) et `StructureTemplate`, rattachés à `TypeDiplome`.
     `TypeDiplomeResolver` renvoie alors un profil riche.
   - Passer par des migrations Doctrine et une étape idempotente dans `MigrateV2Command`
     (cf. `docs/architecture/migration-v2.md`).
3. **Remplacer les validateurs par le moteur de règles.**
   - Faire évoluer `RuleEvaluator` : ET / OU / SI, portées (nœud, semestre, année, parcours), accès aux agrégats.
   - Remplacer `ValideParcours*` et `ValideStructure` (aujourd'hui 3 systèmes de validation coexistent dans v2).
   - Brancher le moteur sur `DpeStepValidationBlockerProvider`.
4. **Passer l'écriture au nouveau modèle, diplôme par diplôme.**
   - Commencer par les types dont les handlers v2 sont vides (DU, DAEU, non classique), puis Master/M2E, Licence,
     et le BUT en dernier.
   - Un adaptateur `MaquetteTree` → `StructureParcours` maintient exports, LHEO, Apogée, JSON URCA et versioning.
5. **Migrer les données** avec une étape additive de `app:migrate:v2` :

   | Source v2 | Cible dans l'arbre |
   |---|---|
   | `SemestreParcours` / `Annee` | nœuds temporels |
   | `Ue` / `ueParent` | UE, ou `bloc_choix` |
   | `ElementConstitutif` / `ecParent` | EC (avec FK vers la fiche), ou `bloc_choix` |
   | tables `*Mutualisable` | références de nœud |

   Le cleanup destructif ne se fait qu'après validation fonctionnelle.
6. **Garder le cycle de vie de v2 tel quel.** Workflows, campagnes, droits, notifications et publication restent en
   place. Le prototype n'apporte ici que l'éditeur et la phase de consolidation, à fusionner avec `Offre/*`.

### Alignement technique

| Point | Prototype | v2 | Action |
|---|---|---|---|
| PHP | 8.5 (aucune syntaxe propre à 8.5 détectée) | 8.4 | rien à faire |
| Front | AssetMapper + Tailwind par CDN | Vite + Tailwind v4 compilé | porter les 21 contrôleurs Stimulus et SortableJS dans Vite |
| UI | Twig brut | Twig Components, `docs/ui-conventions` | réécrire les gabarits en composants |
| Base | SQLite | MySQL | colonnes JSON compatibles |
| Sécurité | aucune | voters | remplacer `is_admin()` par `is_granted` et des voters ; ajouter CSRF et Form |
| Vocabulaire | « ELP », « nœud » | `Parcours`, `Ue`, `ElementConstitutif`, `FicheMatiere` | réutiliser les noms métier existants (règle d'`AGENTS.md` v2) |

### Risques principaux

- **Héritage d'attributs et fiches imposées** : le comportement actuel doit être reproduit à l'identique, sinon des
  MCCC déjà votées en CFVU changent.
- **Versioning CFVU** : un diff entre l'ancien et le nouveau modèle est nécessaire pendant la coexistence.
- **Droits par EC et fiche**, liés aux états du workflow.
- **Codes Apogée** : ils doivent rester stables pendant la migration.

### Ordre de grandeur

L'étape 0 et les étapes 1 à 3 représentent plusieurs semaines chacune. Les étapes 4 et 5 se comptent en mois, BUT
compris. Le plus rentable tout de suite est l'étape 1 : risque nul pour la production et validation du modèle sur les
données réelles.
