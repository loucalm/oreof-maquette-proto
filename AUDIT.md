# Audit : prototype de maquette vs ORéOF v2

Mise à jour : 19/11/2026. Première version : 06/10/2026 (commit `a2465b0`).
Périmètre : `oreof-maquette-proto` (ce dépôt, commit `ef3a9ae` plus les champs des fiches d'aide à la rédaction) comparé à
`oreof-stack/oreofv2` (branche `feat/assistance-mails`, commit `02a69505` du 18/11/2026).
Méthode : chaque constat de la version du 06/10 a été revérifié dans le code. Les constats marqués « non revérifié »
reprennent l'audit précédent sans nouvelle lecture.

## Verdict

- **Le prototype sert de référence fonctionnelle, pas de code à transposer tel quel.** Son rôle est de figer le
  modèle de la maquette et les décisions métier. Le cycle de vie (droits, workflows, campagnes, publication) reste
  celui de v2 et n'est pas à refaire dans le prototype.
- **Le modèle de diplôme est abouti.** Types de diplôme versionnés par année, structure par bornes, règles en données,
  dérogations, canevas de formulaires par diplôme, gestion de l'offre : ces briques sont en place et testées.
- **Le modèle de l'EC ne l'est pas encore.** Fiche matière comme entité, héritage fiche → EC, MCCC complètes et
  coefficients restent à faire. C'est le principal frein avant l'implémentation.
- **La transposition vers v2 reste à spécifier** : persistance, adaptateur vers `StructureParcours`, correspondance des
  entités, migration des données (section 5).

---

## 0. Ce qui a changé depuis le 06/10

| Constat du 06/10 | État au 19/11 |
|---|---|
| 0 test | 446 tests PHPUnit (`WebTestCase` et `KernelTestCase`) |
| Compétences liées par libellé | Liées par identifiant de nœud ; les anciens libellés restent reconnus |
| `StructureTemplate` et `TemplateApplier` (copie figée) | Remplacés par `TypeDiplome` et `TypeDiplomeVersion` : une version par année universitaire, assistant de création, structure par bornes min / max / exactement |
| Dérogations sans outil admin | Demande depuis la formation, résolution par l'admin (`/administration/derogations`) |
| Phase de consolidation sans effet | Gestion de l'offre sur le modèle de v2 (`src/Offre/`) : ouverture / non-ouverture / fermeture par parcours, années ouvertes, capacités, plateformes, anomalies |
| Seul un export JSON | Export JSON et tableau des MCCC au format Excel (`src/Export/Mccc/`) |
| Mutualisation par copie sans suivi | Raccrochage à un nœud d'une autre formation, avec empreinte de la source et resynchronisation |
| Formulaires de fiche figés | Canevas de fiches formation / parcours par version de diplôme, variantes de formulaire, règles de validation par onglet, fiche du parcours unique fusionnée avec celle de la formation |
| BCC à 2 niveaux | Niveaux libres par diplôme ; référentiel national en lecture seule (compétence › niveau › AC) |
| Règles MCCC non branchées sur la complétude | `McccValidator` alimente `Completion`, donc les pastilles et la page « Vérifier » |
| Champs des fiches d'aide v2 absents | Sigle, localisations, descriptif (stage, projet tutoré, TER, mise en situation pro), prérequis, secrétariat, codes ROME multiples, limites de 3000 caractères |

---

## 1. Ce que le prototype apporte de plus que v2 (à garder)

| Apport du prototype | Équivalent v2 |
|---|---|
| Hiérarchie libre, unité de temps configurable (année, semestre, trimestre, mois, semaine) | figée : année = `ceil(semestre/2)`, ordres 1 à 6 codés en dur (`StructureSemestre.php:74`, `SemestreParcours.php:139`) |
| Métamodèle administrable : `NodeType`, capacités, `FieldDef`, constructeur de formulaire, variantes | absent ; tests `libelleCourt === 'BUT'/'M2E'` dispersés (`Ue`, `GetUeEcts`, `ValideStructure`, `ParcoursValide`…) |
| Type de diplôme versionné par année, bornes de structure, règles en données (`src/Diplome/Rule/Template/` : somme, valeur, nombre d'enfants, ratio, même valeur…), dérogations historisées | `TypeDiplome` aux contraintes partielles (`nbEcParUe` et `mcccObligatoireSurEc` jamais appliqués) |
| Règles MCCC en données (profils par diplôme) | règles codées en dur, dupliquées entre `UniversityHandler.php` et `M2EHandler.php` |
| Blocs de choix imbricables à l'infini | 2 niveaux maximum (`ueParent`, `ecParent`) |
| Ramification de parcours visuelle, validée côté serveur | entité `ParcoursRamification` présente mais jamais consommée par la maquette |
| Numérotation configurable par type (décimal, alpha, romain, globale) | codée dans `Ue::display()` et `ElementConstitutif::genereCode()` |
| Historique automatique (50 révisions), annuler / rétablir, restauration | versioning manuel ou déclenché par le workflow |
| Canevas de fiches par diplôme et règles de validation par onglet | formulaires propres à chaque handler |

---

## 2. Ce qui manque au prototype

### P0 : à faire dans le prototype avant l'implémentation

Ces points touchent le modèle lui-même. Tant qu'ils ne sont pas tranchés, la spécification pour v2 est incomplète.

- **Fiche matière comme entité.** Décision du 22/10 : une seule fiche EC, dont le type de diplôme active les options.
  Aujourd'hui, la fiche matière n'est qu'une valeur du référentiel `fiches_matiere`. Il manque :
  - référent, sigle, libellés français et anglais, description, objectifs, langues des cours et des supports ;
  - volumes, ECTS et MCCC imposés aux parcours utilisateurs (`volumesHorairesImpose`, `ectsImpose`, `mcccImpose` en v2) ;
  - mutualisation au niveau de la fiche (v2 : `FicheMatiereMutualisable`) ;
  - fiche hors diplôme, accessible à toutes les formations.
- **Héritage d'attributs fiche → EC.** Non implémenté. v2 combine 4 sources (fiche imposée, EC parent, EC spécifique,
  fiche) avec une priorité différente selon l'attribut (`src/Classes/GetElementConstitutif.php:53-150`). À reproduire
  comme une politique configurable, sinon des MCCC déjà votées en CFVU changent à la migration.
- **MCCC complètes.** Saisie actuelle : un type (CCI, CC, CT, CC + CT) et des épreuves (type, durée, pourcentage). Il
  manque :
  - la 2ᵉ session et la seconde chance : le drapeau `secondChance` d'un profil est enregistré mais rien ne le saisit
    ni ne le lit dans la maquette ;
  - « dont TP » et la part du TP dans le CC (v2 : `cc_has_tp`) ;
  - quitus avec argumentaire, contrôle d'assiduité, « sans note » (décision Q16 : options activables partout) ;
  - durée obligatoire et justification selon le type d'épreuve (`TypeEpreuve.hasDuree`, `hasJustification`) ;
  - MCCC figées pour les types qui l'exigent (décision Q14, BUT) : aujourd'hui elles restent saisissables.
- **Coefficients EC × niveau.** Le rattachement « par coefficient » existe dans la configuration d'un diplôme
  (`LevelSpec::EC_COEFFICIENT`), mais l'éditeur ne permet pas de saisir les coefficients (décision Q11).
- **Mutualisation : choisir le modèle.** Le prototype copie le sous-arbre puis le resynchronise
  (`NodeController::attach`, `_sourceHash`). v2 partage une référence sans copie (`SemestreMutualisable`,
  `UeMutualisable`, `FicheMatiereMutualisable`). Les deux ne se comportent pas pareil quand la source change : à
  trancher avant de spécifier.

### P1 : à compléter pour couvrir le métier

- **Types de diplôme.** 5 configurés (Licence, LP, Master, M2E, BUT) sur une quinzaine en v2 (MEEF, DEUST, DAEU, DI,
  CPI, CMI, DNO, DFG, DFA, DE, DU, non classique…). Les configurer tous prouve le principe « tout par configuration ».
- **Référentiel national des compétences.** Stocké dans `Formation::parametres['bccReferentiel']`, alimenté uniquement
  par les fixtures. Aucun écran ne permet de le saisir ni de le pré-remplir (décision Q18).
- **Attributs de parcours.** Restent absents : type de parcours (LAS, CPI, portail santé…), sigle du parcours,
  composante d'inscription à choix multiples, contacts structurés (dénomination, nom, prénom, email, téléphone,
  adresse). La rubrique « Descriptif » s'affiche pour tous les diplômes : à masquer par variante selon le type.
- **Responsables.** Choisis dans un annuaire fictif (`responsables`), sans adresse mail ni compte.
- **Codification Apogée.** Seuls le type de diplôme, la mention (`codeApogee`) et l'EC (code) portent un code. v2
  code diplôme, étape, VET, semestre, UE et EC (`src/Classes/Codification/CodificationFormation.php`).
- **Sorties.**
  - Vérifier que l'export Excel reproduit les 3 gabarits retenus (décision Q21 : BUT par semestre, Licence par année).
  - JSON au format du site URCA, déjà validé avec le prestataire (décision Q22).

### Hors périmètre du prototype (v2 garde l'existant)

Le prototype ne doit pas les reproduire. Le modèle doit seulement les rendre possibles.

| Brique v2 | Contrainte pour le modèle |
|---|---|
| Authentification CAS / LDAP, `Profil` × `ProfilDroits` × centre × campagne, voters | droits d'écriture par EC et par fiche selon l'état du workflow ; le prototype n'a qu'un rôle simulé en session (`RoleController`) |
| Workflows `dpeParcours`, `dpeFormation`, `fiche`, `changeRf` | la barre de progression de l'éditeur est factice |
| Campagnes (`CampagneCollecte`, `TimelineDate`) et reconduction N → N+1 | identité stable des nœuds d'une année sur l'autre ; le prototype n'a qu'un texte `anneeUniversitaire` et aucune reconduction de formation |
| Version figée à la CFVU (`ParcoursVersioning`) | diff possible entre ancien et nouveau modèle pendant la coexistence |
| Publication : LHEO, PDF (Gotenberg), SOAP Apogée, annexes plateformes, CARIF | passer par le DTO `StructureParcours` |
| Historique avec auteur, commentaires, notifications | `FormationRevision` n'a ni auteur ni diff |

---

## 3. Bugs et dette du prototype

**Bugs du 06/10 : tous corrigés**

- Libellé d'un ELP temporel effacé : corrigé, le libellé d'un type temporel n'est plus réécrit
  (`NodeController.php:74-77`).
- Routes MCCC et d'administration accessibles au responsable : corrigé, `mcctype_` et `diplome_` sont réservés à
  l'admin, ainsi que `formation_create`, `formation_structure_save` et `formation_reset`
  (`AdminAccessSubscriber.php:22-31`). `formation_settings` vérifie le rôle côté serveur.
- `removeNode` et les nœuds verrouillés : la méthode refuse désormais la suppression et le signale (retour `false`),
  sauf suppression forcée explicite (`MaquetteDoc.php:192`).
- « Enregistrer comme template » : sans objet, les templates ont été remplacés par les types de diplôme.

**Dette restante**

- **CSRF partiel.** Protection sans état active pour les formulaires Symfony et les actions de la section Diplômes
  (`config/packages/csrf.yaml`). Les POST de l'éditeur (nœuds, fiches, BCC, offre) n'en ont pas.
- **Symfony Form et Validator** utilisés seulement pour l'assistant des diplômes (`src/Form/Diplome/`). Les autres
  écrans lisent la requête à la main.
- **Pas de migrations** : base créée par `doctrine:schema:create`, remise à zéro par `make db-reset`.
- **Contrôleurs épais**, contrairement aux conventions d'`AGENTS.md` : `NodeController` (772 lignes),
  `NodeTypeController` (728), `FormationController` (562).
- **Persistance en JSON par formation** (`parametres`, `dataParcours`, `arbre`, `structure`…) : aucune requête au
  niveau du nœud. `Maquette::findMutualized` et `countNodesOfType` hydratent toutes les formations
  (`Maquette.php:286-312`).
- Tailwind chargé par CDN à l'exécution.
- **README obsolète** : il parle encore d'une entité `Node` et de `allowedChildKeys`, et présente comme absents la
  ramification, le raccrochage d'un nœud mutualisé et les exports.

---

## 4. Défauts d'ORéOF v2 repérés au passage

**Corrigés depuis le 06/10**

- `can_edit_module('dpe')` : `DPE` existe désormais dans `CampagneModuleEnum`.
- Transition `reouvrir_tacite` sans `to` : remplacée par `reouvrir_tacite_mccc`, avec un `to`.
- Absence de CI : `.github/workflows/ci.yml` lance `composer audit`, `lint:twig`, PHPStan et le lint front.

**Toujours présents (vérifiés le 19/11)**

- `M2EHandler` lève des `LogicException` : « No MCCC form for M2E » (l.86) et « No validator available for M2E »
  (l.696).
- `ValideParcoursLicence` impose 30 ECTS par semestre en dur (l.92) et ignore `TypeDiplome.nbEctsParSemestre`.
- `/api/composante` est public (`security.yaml` : `^/api` en `PUBLIC_ACCESS`).
- `ExportConseil` : la génération des PDF de conseil attend toujours la migration vers Gotenberg (l.38).

**Non revérifiés**

- Contrôleurs sans garde de rôle : `ProcessValidationController` (forçage d'état), `HistoriqueController`,
  `TranslationController`, `ProcessReouvertureController`, `SyntheseModificationController`, `PvConseilController`.
- 3 implémentations parallèles de la duplication de campagne (service admin + 2 commandes).
- Code mort : `StepHandlerRegistry`, `FormationDemande`, `ImpersonationAuditService`, python_worker non branché.

---

## 5. Transition du prototype vers ORéOF

**Faisable, mais pas en remplaçant le code d'un coup.** Le couplage de v2 au modèle actuel est fort (chiffres du
06/10, non recomptés) :

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

Le stockage JSON par formation du prototype n'est pas transposable tel quel. v2 a besoin de requêtes transverses :
mutualisation entre formations, fiches partagées, voters par EC ou fiche, codification, recherche, et plus d'un
millier de parcours versionnés.

**Recommandation : modèle hybride.**

- Une table `maquette_node` : liste d'adjacence typée, avec FK vers `parcours`, `fiche_matiere` et `campagne`, et
  une colonne `type_key`.
- Les attributs du nœud dans une colonne JSON.
- Les MCCC et les compétences restent relationnelles.

### Étapes proposées

L'ordre compte : chaque étape s'appuie sur la précédente.

0. **Terminer le modèle dans le prototype.**
   - Fait : tests, compétences par identifiant, types de diplôme versionnés, règles en données, dérogations, offre.
   - Reste : les points P0 de la section 2 (fiche matière, héritage, MCCC complètes, coefficients, choix de la
     mutualisation), puis la configuration des autres types de diplôme.
   - Produire la spécification de transposition : correspondance des entités, adaptateur `StructureParcours`,
     inventaire des règles par diplôme sous forme de configuration.
1. **Construire le read model dans v2.**
   - Un builder `MaquetteTree` à partir des entités existantes, sans migration de données.
   - Y porter `Numbering`, `Completion`, `MaquetteBuilder` et `ParcoursGraph` du prototype.
   - Afficher la consultation du prototype en lecture seule sur les vraies données : c'est le test grandeur nature
     du modèle.
2. **Porter le métamodèle dans v2.**
   - Créer `NodeType`, `FieldDef`, `MccType` (avec profils), `TypeDiplomeVersion` et ses règles, rattachés à
     `TypeDiplome`. `TypeDiplomeResolver` renvoie alors un profil riche.
   - Passer par des migrations Doctrine et une étape idempotente dans `MigrateV2Command`
     (cf. `docs/architecture/migration-v2.md`).
3. **Remplacer les validateurs par le moteur de règles.**
   - Faire évoluer les règles en données : ET / OU / SI, portées (nœud, semestre, année, parcours), agrégats.
   - Remplacer `ValideParcours*` et `ValideStructure` (3 systèmes de validation coexistent dans v2).
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
   place. Le prototype apporte l'éditeur, les types de diplôme et la gestion de l'offre, à fusionner avec `Offre/*`.

### Alignement technique

| Point | Prototype | v2 | Action |
|---|---|---|---|
| PHP | 8.5 (aucune syntaxe propre à 8.5 détectée le 06/10) | 8.4 | rien à faire |
| Front | AssetMapper + Tailwind par CDN | Vite + Tailwind v4 compilé | porter les 37 contrôleurs Stimulus et SortableJS dans Vite |
| UI | Twig brut | Twig Components, `docs/ui-conventions` | réécrire les gabarits en composants |
| Base | SQLite | MySQL | colonnes JSON compatibles |
| Sécurité | rôle simulé, CSRF partiel | voters | remplacer `is_admin()` par `is_granted` et des voters ; CSRF et Form partout |
| Vocabulaire | « ELP », « nœud » | `Parcours`, `Ue`, `ElementConstitutif`, `FicheMatiere` | réutiliser les noms métier existants (règle d'`AGENTS.md` v2) |

### Risques principaux

- **Héritage d'attributs et fiches imposées** : le comportement actuel doit être reproduit à l'identique, sinon des
  MCCC déjà votées en CFVU changent.
- **Mutualisation** : passer d'un modèle à l'autre change ce que voient les parcours utilisateurs quand la source
  évolue.
- **Versioning CFVU** : un diff entre l'ancien et le nouveau modèle est nécessaire pendant la coexistence.
- **Droits par EC et fiche**, liés aux états du workflow.
- **Codes Apogée** : ils doivent rester stables pendant la migration.

### Ordre de grandeur

Les points P0 de l'étape 0 et les étapes 1 à 3 représentent plusieurs semaines chacun. Les étapes 4 et 5 se comptent
en mois, BUT compris. Le plus rentable après l'étape 0 reste l'étape 1 : risque nul pour la production et validation
du modèle sur les données réelles.
