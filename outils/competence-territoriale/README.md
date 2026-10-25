# Données du moteur « Quel tribunal saisir ? »

`construire.py` produit `public/data/competence-territoriale.json` (toute la France, outre-mer compris) à partir de trois sources publiques, à télécharger dans un dossier de travail (hors dépôt) :

| Fichier attendu | Source | Licence |
|---|---|---|
| `ct.csv` | Ministère de la Justice, *Liste des juridictions compétentes pour les communes de France* (data.gouv.fr, jeu `6392017edf7251532fda4bab`, fichier le plus récent) | Licence ouverte |
| `dl/AAAA-MM-JJ_…-data.gouv_local.json` | DILA, base de données locales de service-public.gouv.fr (`https://lecomarquage.service-public.gouv.fr/donnees_locales_v4/all_latest.tar.bz2`, extraire le `…_local.json`) | Licence ouverte |
| `cp.csv` | La Poste, base officielle des codes postaux (`https://data.laposte.fr/data-fair/api/v1/datasets/laposte-hexasmal/raw`) | Licence ouverte |
| `deps.geojson` | Contours simplifiés des départements (gregoiredavid/france-geojson) — seuls les codes et noms sont utilisés | Licence ouverte |

```
python3 -I outils/competence-territoriale/construire.py <dossier-des-données> public/data/competence-territoriale.json
```

Le script s'arrête si une juridiction du fichier ministériel n'est pas retrouvée dans l'annuaire (ajouter alors une ligne à `MANUEL`) ou si un département n'a pas de nom. Après mise à jour : changer le paramètre `?v=` du `fetch` dans `src/pages/competence-territoriale.astro`, la date « juillet 2026 » des textes et les effectifs indiqués au-dessus de la carte.

Mise à jour conseillée : à chaque nouvelle édition du fichier ministériel (une à deux fois par an).
