# Veille JO & Cour de cassation

Outil de la tâche planifiée « Veille JO et Cour de cassation » (chaque matin de semaine, session Claude).
Il lit les **données brutes de la DILA** — celles qui alimentent Légifrance — sans clé ni navigateur :

| Fonds | Contenu | Cadence DILA |
|---|---|---|
| `JORF` | Journal officiel « Lois et décrets » : sommaire, notices, articles | chaque nuit (vers 1 h 45), pas de JO le lundi |
| `CASS` | Cour de cassation, arrêts **publiés** au Bulletin, avec titrage et sommaire officiels | par lots, environ chaque semaine, quelques semaines après le prononcé |
| `INCA` | Cour de cassation, arrêts inédits | idem |
| `CONSTIT` | Conseil constitutionnel (QPC, DC…) | par lots |

Les sites `legifrance.gouv.fr` et `courdecassation.fr` refusent les accès automatisés depuis le cloud
(403 / défi JavaScript) : on ne les interroge pas, on donne les liens pour la lecture. Les décisions
mises en ligne **le jour même** par la Cour de cassation ne sont accessibles que par l'API Judilibre
(clé PISTE gratuite, variable d'environnement `JUDILIBRE_KEYID`) : le script l'utilise si la clé est
présente, sinon il le dit dans le rapport.

## Usage

```
python3 -I veille/veille_dila.py rapport [--depuis auto] [--jusqua AAAA-MM-JJTHH:MM] --dossier /tmp/veille-cache --sortie rapport.md --json rapport.json
python3 -I veille/veille_dila.py texte JORFTEXT000054999146 --dossier /tmp/veille-cache     # texte intégral (notice, visas, articles)
python3 -I veille/veille_dila.py decision JURITEXT000054392420 --dossier /tmp/veille-cache  # titrage, sommaire, texte d'un arrêt
```

`--depuis auto` = la veille à 7 h (le lundi : le vendredi à 7 h). Bibliothèque standard seulement.

## Rapport

- **À lire** : lois, ordonnances et décrets numérotés touchant au civil, à la famille, à la procédure ou
  au travail (mots-clés dans le titre ou la notice, ou au moins trois articles visés du code civil, du
  CPC, du code du travail, du COJ, du CPCE) ; textes du ministère de la justice ou du travail dans ces
  domaines ; décisions du Conseil constitutionnel publiées au JO.
- **À signaler** : autres textes de portée générale, arrêtés dans les domaines du cabinet, nominations
  judiciaires en Bretagne.
- **Jurisprudence** : arrêts publiés des 1re, 2e, 3e chambres civiles, chambre sociale, chambre mixte et
  assemblée plénière (titrage + sommaire), QPC/DC avec objet ; les autres (criminel, commercial,
  inédits sans mot-clé) sont comptés seulement.

Le tri est volontairement large : c'est l'agent qui lit les textes retenus (`texte` / `decision`) et
écarte ce qui ne concerne pas le cabinet. Aucune référence n'est écrite sans avoir été lue.

## Réglages

Mots-clés et textes suivis en tête de `veille_dila.py` (`MOTS_CIVIL`, `MOTS_PROCEDURE`, `MOTS_TRAVAIL`,
`TEXTES_SUIVIS`, `MOTS_LOCAUX`). La tâche planifiée elle-même (horaire, destinataire, forme du
courriel) se règle dans l'application Claude (tâches planifiées), pas ici.
