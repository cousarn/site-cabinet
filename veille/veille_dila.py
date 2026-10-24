#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Veille législative et jurisprudentielle — Cabinet Arnaud Cousin (Rennes).

Lit les données brutes publiées par la DILA (les mêmes que Légifrance), sans clé :
  https://echanges.dila.gouv.fr/OPENDATA/JORF/    Journal officiel « Lois et décrets » (chaque nuit)
  https://echanges.dila.gouv.fr/OPENDATA/CASS/    Cour de cassation, arrêts publiés (sommaires officiels)
  https://echanges.dila.gouv.fr/OPENDATA/INCA/    Cour de cassation, arrêts inédits
  https://echanges.dila.gouv.fr/OPENDATA/CONSTIT/ Conseil constitutionnel
et, si la variable d'environnement JUDILIBRE_KEYID est définie (clé PISTE), l'API
Judilibre de la Cour de cassation pour les décisions du jour.

Usage :
  python3 -I veille_dila.py rapport [--depuis auto|2026-10-23T07:00] [--jusqua 2026-10-24T07:30] [--dossier cache]
  python3 -I veille_dila.py texte JORFTEXT000055012379          # texte intégral d'un texte du JO
  python3 -I veille_dila.py decision JURITEXT000054392420       # sommaire + texte d'un arrêt
  python3 -I veille_dila.py rapport ... --json rapport.json     # en plus du Markdown, un JSON

Aucune dépendance hors bibliothèque standard. Les archives sont mises en cache dans --dossier.
"""
from __future__ import annotations

import argparse
import datetime as dt
import html
import json
import os
import re
import sys
import tarfile
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path

DILA = "https://echanges.dila.gouv.fr/OPENDATA/"
UA = "Mozilla/5.0 (veille-cabinet; +https://arnaudcousin-avocat.fr)"
LEGIFRANCE_JORF = "https://www.legifrance.gouv.fr/jorf/id/"
LEGIFRANCE_JURI = "https://www.legifrance.gouv.fr/juri/id/"
LEGIFRANCE_CONS = "https://www.conseil-constitutionnel.fr/decision/"

# --- Domaines du cabinet -----------------------------------------------------
# Mots-clés cherchés dans le TITRE et la NOTICE des textes (pas dans la rubrique ni le ministère).

MOTS_CIVIL = [
    r"\bcivile?s?\b(?!\s+et\s+militaire)", r"\bfamili(?:al|ale|aux|ales)\b", r"succession", r"indivis", r"\bpartage",
    r"divorce", r"mariage", r"\bpacs\b", r"filiation", r"adoption", r"autorit[ée] parentale", r"\bmineurs?\b",
    r"tutelle", r"curatelle", r"majeurs? prot[ée]g", r"r[ée]gimes? matrimon", r"liquidation", r"r[ée]compense",
    r"r[ée]serve h[ée]r", r"donation", r"testament", r"notaire", r"notarial", r"usufruit", r"nue?-propri[ée]t",
    r"licitation", r"pension alimentaire", r"prestation compensatoire", r"indemnit[ée] d'occupation",
    r"\bbail\b", r"\bbaux\b", r"loyers?\b", r"locati(?:on|f|ve)s?\b", r"copropri[ée]t", r"servitude",
    r"prescription", r"responsabilit[ée] civile", r"surendettement", r"bail rural", r"fermage",
    r"\bsci\b", r"soci[ée]t[ée]s? civiles?", r"m[ée]diation", r"conciliation", r"ordonnance de protection",
    r"violences? (?:conjugale|intrafamiliale|au sein)", r"[ée]tat civil", r"nom de famille", r"droit des contrats",
    r"clause abusive", r"cr[ée]dit immobilier", r"vente immobili", r"troubles? (?:anormaux|de voisinage)",
]
MOTS_PROCEDURE = [
    r"proc[ée]dure civile", r"proc[ée]dures civiles d'ex[ée]cution", r"tribunal judiciaire", r"tribunaux judiciaires",
    r"cour d'appel", r"cours d'appel", r"juge aux affaires familiales", r"\bjaf\b", r"avocats?\b", r"barreau",
    r"aide juridictionnelle", r"commissaires? de justice", r"huissier", r"expertise judiciaire", r"experts? judiciaire",
    r"signification", r"voies? de recours", r"organisation judiciaire", r"juridictions?\b", r"saisie immobili",
    r"mesures? d'ex[ée]cution", r"frais de justice", r"postulation", r"tarif[s]? (?:des|r[ée]glement)",
    r"d[ée]mat[ée]rialis", r"communication [ée]lectronique", r"proc[ée]dure d'appel", r"mise en [ée]tat",
    r"conciliateurs? de justice", r"m[ée]diateurs?\b", r"justice amiable", r"audience de r[èe]glement amiable",
]
MOTS_TRAVAIL = [
    r"droit du travail", r"code du travail", r"inspection du travail", r"m[ée]decine du travail", r"salari[ée]s?\b",
    r"employeurs?\b", r"licenci", r"prud'hom", r"rupture conventionnelle", r"contrats? de travail", r"\bcdd\b",
    r"\bcdi\b", r"heures suppl", r"dur[ée]e du travail", r"\bsmic\b", r"salaire minimum", r"harc[èe]lement",
    r"inaptitude", r"accidents? du travail", r"maladies? professionnelles?", r"assurance ch[ôo]mage",
    r"repr[ée]sentants? du personnel", r"\bcse\b", r"cong[ée]s pay", r"temps partiel", r"t[ée]l[ée]travail",
    r"[ée]galit[ée] professionnelle", r"discrimination", r"n[ée]gociation collective", r"accords? collectifs?",
    r"conventions? collectives?", r"\bsalaires?\b", r"indemnit[ée]s? de licenciement", r"p[ée]riode d'essai",
    r"d[ée]mission", r"transfert d'entreprise", r"int[ée]rim", r"travail temporaire", r"apprentis(?:sage)?\b",
]
MOTS_LOCAUX = [r"\brennes\b", r"saint-malo", r"saint-brieuc", r"\bnantes\b", r"\blorient\b", r"\bvannes\b",
               r"\bquimper\b", r"\bbrest\b", r"bretagne", r"ille-et-vilaine"]

# Textes dont la modification par une loi, une ordonnance ou un décret numéroté est toujours signalée.
TEXTES_SUIVIS = [
    r"^code civil", r"^code de proc[ée]dure civile", r"^code du travail", r"^code de l'organisation judiciaire",
    r"^code des proc[ée]dures civiles d'ex[ée]cution", r"^code rural et de la p[êe]che maritime - .*(?:bail|baux|fermage|livre iv)",
    r"^code de la consommation", r"^code de la construction et de l'habitation",
    r"^loi n° ?71-1130", r"^d[ée]cret n° ?91-1197", r"^d[ée]cret n° ?2005-790", r"^loi n° ?91-647", r"^d[ée]cret n° ?2020-1717",
    r"^loi n° ?89-462", r"^loi n° ?65-557", r"^loi n° ?2016-1547", r"^d[ée]cret n° ?2019-1333", r"^d[ée]cret n° ?2023-1391",
    r"^d[ée]cret n° ?2016-660", r"^loi n° ?2019-222",
]
MINISTERES_SUIVIS = [r"justice", r"travail"]
NATURES_FORTES = {"LOI", "LOI_ORGANIQUE", "LOI_CONSTITUTIONNELLE", "ORDONNANCE", "DECRET"}
FORMATIONS_SUIVIES = {
    "CHAMBRE_CIVILE_1": "1re civ.", "CHAMBRE_CIVILE_2": "2e civ.", "CHAMBRE_CIVILE_3": "3e civ.",
    "CHAMBRE_SOCIALE": "soc.", "CHAMBRE_MIXTE": "ch. mixte", "ASSEMBLEE_PLENIERE": "ass. plén.",
}
FORMATIONS_AUTRES = {"CHAMBRE_COMMERCIALE": "com.", "CHAMBRE_CRIMINELLE": "crim."}


def _rx(liste):
    return [re.compile(p, re.I) for p in liste]


RX_CIVIL, RX_PROC, RX_TRAV, RX_LOC, RX_TEXTES, RX_MIN = (
    _rx(MOTS_CIVIL), _rx(MOTS_PROCEDURE), _rx(MOTS_TRAVAIL), _rx(MOTS_LOCAUX), _rx(TEXTES_SUIVIS), _rx(MINISTERES_SUIVIS))


def themes(texte: str) -> list[str]:
    t = []
    if any(r.search(texte) for r in RX_CIVIL):
        t.append("civil/famille")
    if any(r.search(texte) for r in RX_PROC):
        t.append("procédure/justice")
    if any(r.search(texte) for r in RX_TRAV):
        t.append("travail")
    if any(r.search(texte) for r in RX_LOC):
        t.append("Bretagne")
    return t


# --- Téléchargement ----------------------------------------------------------

def http_get(url: str, timeout: int = 120, headers: dict | None = None) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": UA, **(headers or {})})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def lister_archives(fonds: str) -> list[tuple[dt.datetime, str]]:
    """Archives incrémentales d'un fonds DILA, triées par horodatage."""
    page = http_get(DILA + fonds + "/").decode("utf-8", "ignore")
    res = {}
    for m in re.finditer(fonds + r"_(\d{8})-(\d{6})\.tar\.gz", page):
        nom = m.group(0)
        res[nom] = dt.datetime.strptime(m.group(1) + m.group(2), "%Y%m%d%H%M%S")
    return sorted((h, n) for n, h in res.items())


def telecharger(fonds: str, nom: str, dossier: Path) -> Path:
    cible = dossier / fonds
    cible.mkdir(parents=True, exist_ok=True)
    archive = cible / nom
    if not archive.exists():
        archive.write_bytes(http_get(DILA + fonds + "/" + nom))
    extrait = cible / nom.replace(".tar.gz", "")
    if not extrait.exists():
        extrait.mkdir()
        with tarfile.open(archive) as tar:
            membres = [m for m in tar.getmembers() if m.isfile() and m.name.endswith(".xml") and ".." not in m.name]
            tar.extractall(extrait, members=membres, filter="data")
    return extrait


# --- JORF --------------------------------------------------------------------

def _txt(e, chemin: str) -> str:
    x = e.find(chemin)
    return (x.text or "").strip() if x is not None else ""


def _contenu(e) -> str:
    """Texte brut d'un bloc CONTENU (HTML léger)."""
    if e is None:
        return ""
    s = ET.tostring(e, encoding="unicode", method="html")
    s = re.sub(r"<br\s*/?>", "\n", s)
    s = re.sub(r"</p>|</div>|</li>|</tr>", "\n", s)
    s = re.sub(r"<[^>]+>", "", s)
    s = html.unescape(s)
    return re.sub(r"\n{3,}", "\n\n", s).strip()


def lire_jorf(extrait: Path, date_min: dt.date | None = None) -> list[dict]:
    """Un dictionnaire par texte du JO contenu dans l'archive (plusieurs JO possibles).

    Les archives incrémentales de la DILA contiennent aussi des rééditions d'anciens JO
    (textes consolidés) : seuls les JO publiés à partir de date_min sont retenus.
    """
    textes = []
    versions = {p.stem: p for p in extrait.rglob("texte/version/**/*.xml")}
    for cont in extrait.rglob("conteneur/**/*.xml"):
        racine = ET.parse(cont).getroot()
        jo_titre = _txt(racine, ".//META_CONTENEUR/TITRE")
        jo_num = _txt(racine, ".//META_CONTENEUR/NUM")
        jo_date = _txt(racine, ".//META_CONTENEUR/DATE_PUBLI")
        if date_min and (not jo_date or dt.date.fromisoformat(jo_date) < date_min):
            continue
        jo_url = ""
        if jo_date and jo_num:
            a, m, j = jo_date.split("-")
            jo_url = f"https://www.legifrance.gouv.fr/jorf/jo/{a}/{m}/{j}/{jo_num}"

        def parcourir(e, chemin):
            for c in e:
                if c.tag == "TM":
                    parcourir(c, chemin + [_txt(c, "TITRE_TM")])
                elif c.tag == "LIEN_TXT":
                    ident = c.get("idtxt") or c.get("id") or ""
                    textes.append({"id": ident, "titre": c.get("titretxt") or "", "rubrique": chemin[:],
                                   "jo": jo_titre, "jo_url": jo_url, "jo_date": jo_date, "_dir": str(extrait)})

        st = racine.find("STRUCTURE_TXT")
        if st is not None:
            parcourir(st, [])
    # enrichissement par la version du texte
    for t in textes:
        v = versions.get(t["id"])
        t.update({"nature": "", "nor": "", "ministere": "", "liens": [], "notice": "", "titre_complet": t["titre"],
                  "url": LEGIFRANCE_JORF + t["id"]})
        if v is None:
            continue
        r = ET.parse(v).getroot()
        t["nature"] = _txt(r, ".//META_COMMUN/NATURE")
        t["nor"] = _txt(r, ".//META_TEXTE_CHRONICLE/NOR")
        t["ministere"] = _txt(r, ".//META_TEXTE_VERSION/MINISTERE")
        t["titre_complet"] = _txt(r, ".//META_TEXTE_VERSION/TITREFULL") or t["titre"]
        t["liens"] = sorted({(l.text or "").strip() for l in r.findall(".//META_TEXTE_VERSION/LIENS/LIEN") if l.text})
        t["notice"] = _contenu(r.find("NOTICE/CONTENU"))
        t["url"] = LEGIFRANCE_JORF + t["id"]
    return textes


def classer_jorf(t: dict) -> tuple[str, list[str]]:
    """Niveau A (à lire), B (à signaler en liste), C (le reste) + motifs."""
    rub = " / ".join(t["rubrique"])
    corpus = " ".join([t["titre_complet"], t["notice"]])
    th = [x for x in themes(corpus) if x != "Bretagne"]
    liens_suivis = sorted({re.split(r" - ", l)[0] for l in t["liens"] if any(r.search(l) for r in RX_TEXTES)})
    nb_articles = sum(1 for l in t["liens"] if " - art. " in l and any(r.search(l) for r in RX_TEXTES))
    motifs = (["vise : " + ", ".join(liens_suivis[:4])] if liens_suivis else []) + th
    nominatif = "Mesures nominatives" in rub
    general = "Textes généraux" in rub
    numerote = bool(re.match(r"(?:Loi|Ordonnance|D[ée]cret) n°", t["titre_complet"] or "", re.I))
    forte = t["nature"] in NATURES_FORTES and (numerote or t["nature"] != "DECRET")
    ministere_suivi = any(r.search(t["ministere"] or "") for r in RX_MIN)

    if "Conseil constitutionnel" in rub:
        return "A", ["Conseil constitutionnel"] + th
    if general and forte and (th or nb_articles >= 3):
        return "A", motifs
    if general and ministere_suivi and th and t["nature"] in ("ARRETE", "DECRET", "CIRCULAIRE"):
        return "A", motifs
    if general and forte:
        return "B", ["texte de portée générale"]
    if general and (th or liens_suivis):
        return "B", motifs
    if nominatif and re.search(r"magistrat|avocat|notaire|commissaire de justice|huissier|greffier en chef|b[âa]tonnier",
                               corpus, re.I) and any(r.search(corpus) for r in RX_LOC):
        return "B", ["nomination en Bretagne"]
    return "C", th


def premier_contenu(dossier: str, ident: str, n: int = 500) -> str:
    """Début du premier article (ou du bloc principal) d'un texte du JO, pour les textes sans notice."""
    d = Path(dossier)
    st = list(d.rglob(f"texte/struct/**/{ident}.xml"))
    if st:
        s = ET.parse(st[0]).getroot()
        for a in s.findall(".//LIEN_ART")[:2]:
            fa = list(d.rglob(f"article/**/{a.get('id')}.xml"))
            if fa:
                c = _contenu(ET.parse(fa[0]).getroot().find(".//BLOC_TEXTUEL/CONTENU"))
                if c:
                    return c[:n]
    return ""


def texte_integral(extrait_dirs: list[Path], ident: str) -> str:
    """Texte intégral d'un texte du JO : titre, NOR, notice, visas, articles (cherchés dans toutes les archives)."""
    versions = [v for d in extrait_dirs for v in d.rglob(f"texte/version/**/{ident}.xml")]
    if not versions:
        return f"Texte {ident} introuvable dans les archives téléchargées."
    r = ET.parse(versions[0]).getroot()
    out = [_txt(r, ".//META_TEXTE_VERSION/TITREFULL"), f"NOR : {_txt(r, './/META_TEXTE_CHRONICLE/NOR')}",
           _txt(r, ".//META_TEXTE_CHRONICLE/ORIGINE_PUBLI"), LEGIFRANCE_JORF + ident, ""]
    notice = _contenu(r.find("NOTICE/CONTENU"))
    if notice:
        out += ["NOTICE", notice, ""]
    visas = _contenu(r.find("VISAS/CONTENU"))
    if visas:
        out += ["VISAS", visas, ""]
    def plan(struct_el, titre_niveau=""):
        """Aplatit STRUCT / STRUCTURE_TA : [(titre de section | None, num, id article)]."""
        res = []
        for c in struct_el:
            if c.tag == "LIEN_ART":
                res.append((None, c.get("num") or "", c.get("id")))
            elif c.tag == "LIEN_SECTION_TA":
                res.append(((c.text or "").strip(), None, None))
                sec = [f for d in extrait_dirs for f in d.rglob(f"section_ta/**/{c.get('id')}.xml")]
                if sec:
                    sta = ET.parse(sec[0]).getroot().find("STRUCTURE_TA")
                    if sta is not None:
                        res += plan(sta)
        return res

    arts = []
    for d in extrait_dirs:
        for st in d.rglob(f"texte/struct/**/{ident}.xml"):
            el = ET.parse(st).getroot().find("STRUCT")
            la = plan(el) if el is not None else []
            if len(la) > len(arts):
                arts = la
    for titre_sec, num, aid in arts:
        if titre_sec is not None:
            out += [titre_sec.upper(), ""]
            continue
        fa = [f for d in extrait_dirs for f in d.rglob(f"article/**/{aid}.xml")]
        if fa:
            a = ET.parse(fa[0]).getroot()
            out += [f"Article {num}".strip(), _contenu(a.find(".//BLOC_TEXTUEL/CONTENU")), ""]
        else:
            out += [f"Article {num} : contenu absent des archives téléchargées (voir Légifrance).", ""]
    sig = _contenu(r.find("SIGNATAIRES/CONTENU"))
    if sig:
        out += [sig]
    return "\n".join(out)


# --- Jurisprudence (CASS / INCA / CONSTIT) -----------------------------------

def lire_decisions(extrait: Path, fonds: str) -> list[dict]:
    res = []
    for f in extrait.rglob("*.xml"):
        try:
            r = ET.parse(f).getroot()
        except ET.ParseError:
            continue
        if r.tag not in ("TEXTE_JURI_JUDI", "TEXTE_JURI_CONSTIT", "TEXTE_JURI_ADMIN"):
            continue
        ident = _txt(r, ".//META_COMMUN/ID")
        d = {
            "fonds": fonds, "id": ident, "fichier": str(f),
            "titre": _txt(r, ".//META_JURI/TITRE"), "date": _txt(r, ".//META_JURI/DATE_DEC"),
            "juridiction": _txt(r, ".//META_JURI/JURIDICTION"), "numero": _txt(r, ".//META_JURI/NUMERO"),
            "solution": _txt(r, ".//META_JURI/SOLUTION"),
            "formation": _txt(r, ".//META_JURI_JUDI/FORMATION"),
            "publie": (r.find(".//META_JURI_JUDI/PUBLI_BULL") is not None and
                       r.find(".//META_JURI_JUDI/PUBLI_BULL").get("publie") == "oui"),
            "ecli": _txt(r, ".//META_JURI_JUDI/ECLI"),
            "pourvoi": _txt(r, ".//META_JURI_JUDI/NUMEROS_AFFAIRES/NUMERO_AFFAIRE") or "",
            "titrage": [(s.text or "").strip() for s in r.findall(".//SOMMAIRE/SCT") if s.get("TYPE") == "PRINCIPAL"],
            "analyses": [(a.text or "").strip() for a in r.findall(".//SOMMAIRE/ANA") if a.text],
            "nature": _txt(r, ".//META_JURI_CONSTIT/NATURE_QUALIFIEE") or _txt(r, ".//META_COMMUN/NATURE"),
            "url_cc": _txt(r, ".//META_JURI_CONSTIT/URL_CC"),
        }
        m = re.search(r"(\d{2}-\d{2}\.\d{3})", d["titre"])
        if m and not d["pourvoi"]:
            d["pourvoi"] = m.group(1)
        texte = _contenu(r.find(".//BLOC_TEXTUEL/CONTENU"))
        d["extrait"] = texte[:4000]
        d["url"] = (LEGIFRANCE_JURI + ident) if fonds != "CONSTIT" else (LEGIFRANCE_CONS + ident)
        if fonds == "CONSTIT":
            d["url"] = d["url_cc"] or ("https://www.legifrance.gouv.fr/cons/id/" + ident)
        res.append(d)
    return res


def classer_decision(d: dict) -> tuple[str, list[str]]:
    corpus = " ".join([d["titre"]] + d["titrage"] + d["analyses"] + [d["extrait"][:2500]])
    th = themes(corpus)
    if d["fonds"] == "CONSTIT":
        if d["nature"] not in ("QPC", "DC"):
            return "C", th
        return ("A" if th else "B"), [d["nature"]] + th
    if d["formation"] in FORMATIONS_SUIVIES:
        if d["publie"]:
            return "A", th
        return ("B" if th else "C"), th
    if d["formation"] in FORMATIONS_AUTRES and th and d["publie"]:
        return "B", th
    return "C", th


def decision_integrale(extrait_dirs: list[Path], ident: str) -> str:
    for d in extrait_dirs:
        fs = list(d.rglob(f"{ident}.xml"))
        if not fs:
            continue
        r = ET.parse(fs[0]).getroot()
        out = [_txt(r, ".//META_JURI/TITRE"), "ECLI : " + _txt(r, ".//META_JURI_JUDI/ECLI"),
               "Solution : " + _txt(r, ".//META_JURI/SOLUTION"), LEGIFRANCE_JURI + ident, ""]
        tit = [(s.text or "").strip() for s in r.findall(".//SOMMAIRE/SCT") if s.text]
        ana = [(a.text or "").strip() for a in r.findall(".//SOMMAIRE/ANA") if a.text]
        if tit:
            out += ["TITRAGE", *tit, ""]
        if ana:
            out += ["SOMMAIRE", *ana, ""]
        out += ["TEXTE", _contenu(r.find(".//BLOC_TEXTUEL/CONTENU"))]
        return "\n".join(out)
    return f"Décision {ident} introuvable dans les archives téléchargées."


# --- Judilibre (facultatif, clé PISTE) ---------------------------------------

def judilibre(depuis: dt.datetime, jusqua: dt.datetime) -> list[dict]:
    cle = os.environ.get("JUDILIBRE_KEYID", "").strip()
    if not cle:
        return []
    base = "https://api.piste.gouv.fr/cassation/judilibre/v1.0/export"
    res, lot = [], 0
    while True:
        url = (f"{base}?jurisdiction=cc&date_type=creation&date_start={depuis:%Y-%m-%d}&date_end={jusqua:%Y-%m-%d}"
               f"&batch={lot}&batch_size=100&resolve_references=true")
        try:
            data = json.loads(http_get(url, headers={"KeyId": cle, "accept": "application/json"}))
        except Exception as e:  # noqa: BLE001 — on signale, on ne bloque pas la veille
            return [{"erreur": f"Judilibre : {e}"}]
        for r in data.get("results", []):
            res.append({
                "id": r.get("id"), "date": r.get("decision_date"), "chambre": r.get("chamber"),
                "pourvoi": ", ".join(r.get("numbers") or [r.get("number", "")]), "publication": r.get("publication"),
                "solution": r.get("solution"), "sommaire": (r.get("summary") or "")[:1500],
                "themes": r.get("themes"), "ecli": r.get("ecli"),
                "url": f"https://www.courdecassation.fr/decision/{r.get('id')}",
            })
        lot += 1
        if lot * 100 >= int(data.get("total", 0)) or not data.get("results"):
            break
    return res


# --- Rapport -----------------------------------------------------------------

def rapport(depuis: dt.datetime, jusqua: dt.datetime, dossier: Path, fonds_jp=("CASS", "INCA", "CONSTIT")) -> tuple[str, dict]:
    lignes, data = [], {"depuis": depuis.isoformat(), "jusqua": jusqua.isoformat(), "jorf": [], "decisions": [],
                        "archives": {}, "judilibre": []}
    lignes.append(f"# Veille JO & Cour de cassation — du {depuis:%d/%m/%Y %H:%M} au {jusqua:%d/%m/%Y %H:%M}\n")

    # Journal officiel
    archives = [(h, n) for h, n in lister_archives("JORF") if depuis < h <= jusqua]
    data["archives"]["JORF"] = [n for _, n in archives]
    textes, vus = [], set()
    for _, nom in archives:
        for t in lire_jorf(telecharger("JORF", nom, dossier), depuis.date()):
            if t["id"] and t["id"] not in vus:
                vus.add(t["id"])
                textes.append(t)
    jos = sorted({(t["jo_date"], t["jo"], t["jo_url"]) for t in textes})
    lignes.append("## Journal officiel\n")
    if not jos:
        lignes.append("Aucune édition du JO publiée par la DILA sur la période (le JO ne paraît pas le lundi).\n")
    for _, titre, url in jos:
        lignes.append(f"- {titre} — {url}")
    lignes.append("")
    A = [t for t in textes if classer_jorf(t)[0] == "A"]
    B = [t for t in textes if classer_jorf(t)[0] == "B"]
    for t in textes:
        niv, motifs = classer_jorf(t)
        t["niveau"], t["motifs"] = niv, motifs
        data["jorf"].append({k: t[k] for k in ("id", "titre_complet", "nature", "nor", "ministere", "rubrique", "liens",
                                               "notice", "url", "niveau", "motifs", "jo")})
    lignes.append(f"### À lire ({len(A)})\n")
    if not A:
        lignes.append("Rien ne touche directement le droit civil, la famille, la procédure ou le droit du travail.\n")
    for t in A:
        lignes.append(f"- **{t['titre_complet']}**  \n  {' › '.join(t['rubrique'][-2:])} · NOR {t['nor'] or '—'} · "
                      f"{'; '.join(t['motifs']) or '—'}  \n  {t['url']}")
        if t["notice"]:
            lignes.append("  > " + t["notice"][:600].replace("\n", " "))
        else:
            debut = premier_contenu(t["_dir"], t["id"], 900 if "Conseil constitutionnel" in " / ".join(t["rubrique"]) else 500)
            if debut:
                lignes.append("  > " + debut.replace("\n", " "))
    lignes.append("")
    lignes.append(f"### À signaler ({len(B)})\n")
    for t in B:
        lignes.append(f"- {t['titre_complet']} — {'; '.join(t['motifs'])} — {t['url']}")
    lignes.append("")
    nb_gen = sum(1 for t in textes if "Textes généraux" in " / ".join(t["rubrique"]))
    nb_cc = sum(1 for t in textes if "Conventions collectives" in " / ".join(t["rubrique"]))
    lignes.append(f"_{len(textes)} textes lus, dont {nb_gen} textes généraux et {nb_cc} arrêtés relatifs aux conventions "
                  f"collectives (extensions, non détaillés) ; {len(textes) - len(A) - len(B)} sans lien avec les domaines "
                  f"du cabinet._\n")

    # Jurisprudence DILA
    lignes.append("## Cour de cassation et Conseil constitutionnel (archives DILA)\n")
    tout = []
    for fonds in fonds_jp:
        arch = [(h, n) for h, n in lister_archives(fonds) if depuis < h <= jusqua]
        data["archives"][fonds] = [n for _, n in arch]
        for _, nom in arch:
            tout += lire_decisions(telecharger(fonds, nom, dossier), fonds)
    if not any(data["archives"][f] for f in fonds_jp):
        lignes.append("Aucune nouvelle archive DILA (CASS/INCA/CONSTIT) sur la période ; ces fonds sont publiés par "
                      "lots, en général chaque semaine, avec quelques semaines de délai après le prononcé.\n")
    for d in tout:
        niv, th = classer_decision(d)
        d["niveau"], d["themes"] = niv, th
    tout.sort(key=lambda d: (d["niveau"], d["date"]), reverse=False)
    for niveau, titre in (("A", "Arrêts publiés des chambres civiles et sociale, décisions du Conseil constitutionnel"),
                          ("B", "Autres décisions touchant aux domaines du cabinet")):
        sel = [d for d in tout if d["niveau"] == niveau]
        if not sel:
            continue
        lignes.append(f"### {titre} ({len(sel)})\n")
        for d in sel:
            form = FORMATIONS_SUIVIES.get(d["formation"]) or FORMATIONS_AUTRES.get(d["formation"]) or d["formation"]
            jd = f"{d['date'][8:10]}/{d['date'][5:7]}/{d['date'][:4]}" if len(d["date"]) == 10 else d["date"]
            if d["fonds"] == "CONSTIT":
                lignes.append(f"- **Cons. const., {jd}, n° {d['numero']} {d['nature']}** — {d['titre']} — {d['solution']}  "
                              f"\n  {d['url']}")
            else:
                lignes.append(f"- **{d['juridiction']}, {form}, {jd}, n° {d['pourvoi'] or d['numero']}** "
                              f"({'publié' if d['publie'] else 'inédit'}{', ' + ', '.join(d['themes']) if d['themes'] else ''}) "
                              f"— {d['solution']}  \n  {d['url']}")
            for s in d["titrage"][:2]:
                lignes.append(f"  - _{s}_")
            for a in d["analyses"][:1]:
                lignes.append("  > " + a[:700].replace("\n", " "))
            data["decisions"].append({k: d[k] for k in ("fonds", "id", "titre", "date", "formation", "publie", "pourvoi",
                                                        "solution", "titrage", "analyses", "url", "niveau", "themes")})
        lignes.append("")
    nbC = sum(1 for d in tout if d["niveau"] == "C")
    if tout:
        lignes.append(f"_{len(tout)} décisions lues dans les archives, {nbC} hors domaines (criminel, commercial, "
                      f"inédits sans mot-clé)._\n")

    # Judilibre du jour (si clé)
    jl = judilibre(depuis, jusqua)
    data["judilibre"] = jl
    lignes.append("## Décisions du jour via l'API Judilibre\n")
    if not os.environ.get("JUDILIBRE_KEYID"):
        lignes.append("Clé PISTE absente (variable JUDILIBRE_KEYID) : les décisions mises en ligne le jour même par la "
                      "Cour de cassation ne sont pas interrogées.\n")
    elif jl and "erreur" in jl[0]:
        lignes.append(jl[0]["erreur"] + "\n")
    else:
        for r in jl:
            if r.get("chambre") in ("civ1", "civ2", "civ3", "soc", "mixte", "pl", "avis"):
                lignes.append(f"- {r['chambre']} {r['date']} n° {r['pourvoi']} ({','.join(r.get('publication') or [])}) "
                              f"— {r.get('solution') or ''} — {r['url']}")
                if r.get("sommaire"):
                    lignes.append("  > " + r["sommaire"][:600].replace("\n", " "))
        lignes.append("")
    return "\n".join(lignes), data


def _parse_date(s: str) -> dt.datetime:
    if s == "auto":  # depuis le jour ouvré précédent à 7 h (le lundi : depuis le vendredi)
        jour = dt.date.today() - dt.timedelta(days=3 if dt.date.today().weekday() == 0 else 1)
        return dt.datetime.combine(jour, dt.time(7, 0))
    for fmt in ("%Y-%m-%dT%H:%M", "%Y-%m-%d %H:%M", "%Y-%m-%d"):
        try:
            return dt.datetime.strptime(s, fmt)
        except ValueError:
            pass
    raise argparse.ArgumentTypeError(f"date invalide : {s}")


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    r = sub.add_parser("rapport", help="rapport Markdown de la période")
    r.add_argument("--depuis", type=_parse_date, default="auto",
                   help="début (ex. 2026-10-23T07:00) ; « auto » = jour ouvré précédent 07:00 (défaut)")
    r.add_argument("--jusqua", type=_parse_date, default=dt.datetime.now(), help="fin (défaut : maintenant)")
    r.add_argument("--dossier", type=Path, default=Path("veille-cache"), help="cache des archives")
    r.add_argument("--json", type=Path, help="écrire aussi le détail en JSON")
    r.add_argument("--sortie", type=Path, help="écrire le Markdown dans ce fichier (sinon stdout)")
    for nom in ("texte", "decision"):
        t = sub.add_parser(nom, help=f"{nom} intégral depuis le cache")
        t.add_argument("id")
        t.add_argument("--dossier", type=Path, default=Path("veille-cache"))
    a = p.parse_args(argv)
    if a.cmd == "rapport":
        md, data = rapport(a.depuis, a.jusqua, a.dossier)
        if a.json:
            a.json.write_text(json.dumps(data, ensure_ascii=False, indent=1), encoding="utf-8")
        if a.sortie:
            a.sortie.write_text(md, encoding="utf-8")
            print(f"Rapport écrit : {a.sortie}")
        else:
            print(md)
    else:
        dirs = [d for d in a.dossier.glob("*/*") if d.is_dir()]
        print(texte_integral(dirs, a.id) if a.cmd == "texte" else decision_integrale(dirs, a.id))


if __name__ == "__main__":
    main()
