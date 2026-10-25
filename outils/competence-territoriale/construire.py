"""Construit public/data/competence-territoriale.json pour toute la France (métropole et outre-mer).
Sources : ressorts du ministère de la Justice (ct.csv), annuaire service-public (local.json),
codes postaux La Poste (cp.csv), départements (deps.geojson)."""
import json, csv, re, sys, unicodedata, collections
D = sys.argv[1]            # dossier des données brutes
SORTIE = sys.argv[2]
NB = ' '

def sans_accents(s): return unicodedata.normalize('NFD', s).encode('ascii', 'ignore').decode()
def norm(s):
    s = sans_accents(s).lower()
    s = re.sub(r"\bst\b|\bst-", "saint ", s); s = re.sub(r"\bste\b|\bste-", "sainte ", s)
    s = re.sub(r"[^a-z0-9]+", " ", s).strip()
    s = re.sub(r"\b(de|du|des|d|la|le|les|l|en|sur|et|aux|au)\b", "", s)
    return re.sub(r"\s+", " ", s).strip()
def coeur(s):  # pour comparer deux libellés de commune
    s = sans_accents(s).lower(); s = re.sub(r"\bste?\b", lambda m: 'sainte' if m.group(0)=='ste' else 'saint', s)
    s = re.sub(r"[^a-z0-9]+", "", s); return s

lignes = list(csv.DictReader(open(f'{D}/ct.csv', encoding='utf-8'), delimiter=';'))
COLS = [("CA", "Cour d'Appel compétente", "N° CA"), ("TJ", "Tribunal judiciaire compétent", "N° TJ"),
        ("TPRX", "Tribunal de proximité compétent (hors communes exclusives aux TJ)", "N° TPRX"),
        ("CPH", "Conseil de Prud'hommes compétent", "N° CPH")]
juridictions = {}
for r in lignes:
    for t, k, n in COLS:
        if r[k]: juridictions[(t, r[n])] = r[k]

# --- annuaire service-public
sp = json.load(open(next(__import__("glob").iglob(f"{D}/dl/*_local.json")), encoding='utf-8'))['service']
par_type = collections.defaultdict(list); mairies = {}
for i in sp:
    for p in i.get('pivot') or []:
        tp = p.get('type_service_local'); par_type[tp].append(i)
        if tp == 'mairie' and i['nom'].startswith('Mairie - ') and i.get('code_insee_commune'):
            mairies.setdefault(i['code_insee_commune'], i['nom'][9:])
CANDS = {'CA': ['cour_appel'], 'TJ': ['tgi', 'ti'], 'TPRX': ['ti', 'tgi'], 'CPH': ['prudhommes']}
TOUS = [i for tp in ('cour_appel', 'tgi', 'ti', 'prudhommes') for i in par_type[tp]]
MANUEL = {('CA', '33'): ("Cour d'appel de Saint-Denis", '97400'), ('TJ', '217'): ('Tribunal judiciaire de Saint-Denis', '97400'),
          ('CPH', '1215'): ("Conseil de prud'hommes de Saint-Denis", '97400')}
def chercher(t, num, nom):
    if (t, num) in MANUEL:
        n, cp = MANUEL[(t, num)]
        c = [i for i in TOUS if i['nom'] == n and (i.get('adresse') or [{}])[0].get('code_postal') == cp]
        return c[0] if len(c) == 1 else None
    nn = norm(nom)
    for tp in CANDS[t]:
        c = [i for i in par_type[tp] if norm(i['nom']) == nn]
        if len(c) == 1: return c[0]
    c = [i for i in TOUS if norm(i['nom']) == nn]
    return c[0] if len(c) == 1 else None

def titre(s):
    s = s.lower()
    s = re.sub(r"(^|[\s\-(])(\w)", lambda m: m.group(1) + m.group(2).upper(), s)
    s = re.sub(r"\b([dlDL])['’](\w)", lambda m: m.group(1) + "’" + m.group(2).upper(), s)
    s = re.sub(r"(?<=\s)(De|Du|Des|La|Le|Les|Sur|Sous|En|Et|Au|Aux|D|L)(?=[\s'’])", lambda m: m.group(1).lower(), s)
    s = re.sub(r"\bSt\b\.?\s?", "Saint-", s); s = re.sub(r"\bSte\b\.?\s?", "Sainte-", s)
    s = s.replace("'", "’")
    return s

def nom_juridiction(n):
    n = n.replace("Cour d'Appel", "Cour d'appel").replace("Conseil de Prud'hommes", "Conseil de prud'hommes").replace("Conseil de prud'Hommes", "Conseil de prud'hommes")
    n = n.replace("Tribunal de Première Instance", "Tribunal de première instance").replace("Tribunal du Travail", "Tribunal du travail")
    n = n.replace("Tribunal Supérieur d'Appel", "Tribunal supérieur d'appel")
    n = re.sub(r"\bSt-", "Saint-", n)
    n = re.sub(r"Saint-Denis[- ]de[- ]La[- ]Réunion", "Saint-Denis (La Réunion)", n)
    return n.replace("'", "’")

def libelle(n):
    for pref, lib in [("Cour d’appel", "Cour d’appel"), ("Tribunal supérieur d’appel", "Tribunal supérieur d’appel"),
                      ("Tribunal judiciaire", "Tribunal judiciaire"), ("Tribunal de première instance", "Tribunal de première instance"),
                      ("Tribunal de proximité", "Tribunal de proximité"), ("Conseil de prud’hommes", "Conseil de prud’hommes"),
                      ("Tribunal du travail", "Tribunal du travail")]:
        if n.startswith(pref): return lib
    raise SystemExit('libellé inconnu : ' + n)

def horaires(rec):
    parts = []
    for p in rec.get('plage_ouverture') or []:
        j = p['nom_jour_debut'][:3] + ('–' + p['nom_jour_fin'][:3] if p['nom_jour_fin'] != p['nom_jour_debut'] else '')
        hs = []
        for a, b in [(p.get('valeur_heure_debut_1'), p.get('valeur_heure_fin_1')), (p.get('valeur_heure_debut_2'), p.get('valeur_heure_fin_2'))]:
            if a and b and a[:5] != b[:5]: hs.append(a[:5].replace(':', 'h') + '–' + b[:5].replace(':', 'h'))
        if hs: parts.append(j + ' ' + ' · '.join(hs))
    return ' ; '.join(parts) or None

J, index_j, sans_coord = [], {}, []
for (t, num), nom in sorted(juridictions.items(), key=lambda x: (x[0][0], x[1])):
    rec = chercher(t, num, nom)
    if rec is None: raise SystemExit(f'non rapprochée : {t} {num} {nom}')
    a = (rec.get('adresse') or [{}])[0]
    lat = float(a['latitude']) if a.get('latitude') else None; lon = float(a['longitude']) if a.get('longitude') else None
    if lat is None: sans_coord.append(nom)
    n = nom_juridiction(nom)
    tel = (rec.get('telephone') or [{}])[0].get('valeur') if rec.get('telephone') else None
    if tel:  # « 0(590)806363 » → « 05 90 80 63 63 » ; « 00(687)279350 » → « +687 27 93 50 »
        chiffres = re.sub(r'\D', '', tel)
        if tel.strip().startswith('00') and len(chiffres) > 5:
            reste = chiffres[5:]; tel = '+' + chiffres[2:5] + ' ' + ' '.join(reste[k:k + 2] for k in range(0, len(reste), 2))
        elif len(chiffres) == 10 and chiffres.startswith('0'):
            tel = ' '.join(chiffres[k:k + 2] for k in range(0, 10, 2))
    adr = ' '.join(x for x in [a.get('complement1'), a.get('numero_voie')] if x).strip()
    index_j[(t, num)] = len(J)
    cpj = (a.get('code_postal') or '').strip(); cpj = cpj.zfill(5) if cpj.isdigit() and len(cpj) == 4 else cpj
    J.append({'i': f'{t}-{num}', 't': t, 'l': libelle(n), 'n': n, 'a': titre(adr) if adr else '', 'cp': cpj,
              'v': titre(a.get('nom_commune') or ''), 'lat': lat, 'lon': lon, 'tel': tel, 'url': rec.get('url_service_public'), 'h': horaires(rec)})

# --- communes
cps = collections.defaultdict(set)
for r in csv.DictReader(open(f'{D}/cp.csv', encoding='latin-1'), delimiter=';'):
    cps[r['#Code_commune_INSEE']].add(r['Code_postal'])
SPECIAUX = {'98411': 'Îles Saint-Paul et Nouvelle-Amsterdam', '98412': 'Archipel des Kerguelen', '98413': 'Archipel des Crozet',
            '98414': 'Terre Adélie', '98415': 'Îles Éparses de l’océan Indien', '98901': 'Île de Clipperton'}
dico = collections.defaultdict(collections.Counter)
for v in mairies.values():
    for w in re.split(r"[\s\-’']+", v):
        if w: dico[sans_accents(w).lower()][w] += 1
def accentuer(mot):
    c = dico.get(mot.lower())
    if not c: return mot
    v = c.most_common(1)[0][0]
    return v if mot[:1].isupper() == v[:1].isupper() else (v[0].upper() + v[1:] if mot[:1].isupper() else v.lower())
def depuis_ministere(lib):
    m = re.match(r'^(PARIS|LYON|MARSEILLE) (\d+)(ER|EME|E)$', lib)
    if m: return f"{m.group(1).title()} {int(m.group(2))}{'er' if int(m.group(2)) == 1 else 'e'}"
    mots = [w for w in re.split(r"[\s\-'’]+", lib.strip()) if w]
    out = []
    for k, w in enumerate(mots):
        wl = w.lower()
        if k == 0 and wl in ('le', 'la', 'les'): out.append(wl.capitalize() + ' '); continue
        if k == 0 and wl == 'l': out.append('L’'); continue
        if wl in ('d', 'l'): out.append(wl + '’'); continue
        if wl == 'st': out.append('Saint'); continue
        if wl == 'ste': out.append('Sainte'); continue
        petit = k > 0 and wl in ('de', 'du', 'des', 'la', 'le', 'les', 'sur', 'sous', 'en', 'et', 'au', 'aux', 'lès')
        a = accentuer(wl)
        out.append(a.lower() if petit else a[0].upper() + a[1:])
    s = ''
    for k, w in enumerate(out):
        s += w if (k == 0 or s.endswith(' ') or s.endswith('’')) else '-' + w
    return s
C, src = [], collections.Counter()
for r in lignes:
    insee, lib = r['Commune'], r['Libellé Commune']
    if insee in SPECIAUX: nom = SPECIAUX[insee]; src['spécial'] += 1
    elif re.match(r'^(PARIS|LYON|MARSEILLE) \d+(ER|EME|E)$', lib): nom = depuis_ministere(lib); src['arrondissement'] += 1
    elif insee in mairies and coeur(mairies[insee]) == coeur(lib): nom = mairies[insee].replace("'", '’'); src['mairie'] += 1
    else: nom = depuis_ministere(lib); src['ministère'] += 1
    ligne = [insee, nom, ' '.join(sorted(cps.get(insee, []))), index_j[('CA', r['N° CA'])], index_j[('TJ', r['N° TJ'])],
             index_j[('TPRX', r['N° TPRX'])] if r['N° TPRX'] else -1, index_j[('CPH', r['N° CPH'])]]
    C.append(ligne)
C.sort(key=lambda x: sans_accents(x[1]).lower())

# --- départements
g = json.load(open(f'{D}/deps.geojson', encoding='utf-8'))
deps = {f['properties']['code']: f['properties']['nom'].replace("'", '’') for f in g['features']}
deps.update({'971': 'Guadeloupe', '972': 'Martinique', '973': 'Guyane', '974': 'La Réunion', '975': 'Saint-Pierre-et-Miquelon',
             '976': 'Mayotte', '977': 'Saint-Barthélemy', '978': 'Saint-Martin', '984': 'Terres australes et antarctiques françaises',
             '986': 'Wallis-et-Futuna', '987': 'Polynésie française', '988': 'Nouvelle-Calédonie', '989': 'Île de Clipperton'})
manquants = sorted({(c[0][:3] if c[0].startswith(('97', '98')) else c[0][:2]) for c in C} - set(deps))
if manquants: raise SystemExit(f'départements sans nom : {manquants}')

data = {'maj': '2026-07', 'source': 'Ministère de la Justice (data.gouv.fr, juillet 2026) ; annuaire service-public.gouv.fr (25/10/2026) ; La Poste',
        'deps': deps, 'j': J, 'c': C}
s = json.dumps(data, ensure_ascii=False, separators=(',', ':'))
open(SORTIE, 'w', encoding='utf-8').write(s)
print('juridictions', len(J), collections.Counter(j['l'] for j in J))
print('communes', len(C), dict(src), '| sans code postal :', sum(1 for c in C if not c[2]))
print('sans coordonnées :', sans_coord)
print('taille', len(s) // 1024, 'Ko')
